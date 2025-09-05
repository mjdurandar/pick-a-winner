<script setup>
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
    import { Head, router } from '@inertiajs/vue3';
    import { ref, computed } from 'vue';
    import { Bar } from 'vue-chartjs';
    import {
        Chart as ChartJS,
        Title,
        Tooltip,
        Legend,
        BarElement,
        CategoryScale,
        LinearScale
    } from 'chart.js';

    // ✅ Register Chart.js Components
    ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale);

    const props = defineProps({
        events: { type: Array, default: () => [] },              
        locations: { type: Array, default: () => [] },       
        selectedEventId: [String, Number],    
        allDataAttendees: Number,
        eventCount: Number,
        selectedLocationId: String, 
        attendeesSelectedLocation: Number,
        attendeesChartData: { type: Array, default: () => [] },   // ✅ Receive Chart Data from Backend
    });

    const selectedEvent = ref(props.selectedEventId || 'all');
    const selectedLocation = ref(props.selectedLocationId || null);
    
    // Separate reactive values for the dropdowns (temporary selections)
    const tempSelectedEvent = ref(props.selectedEventId || 'all');
    const tempSelectedLocation = ref(props.selectedLocationId || null);

    // ✅ Filter Locations for Selected Event (based on temp selection for dropdown)
    const filteredLocations = computed(() => {
        if (!Array.isArray(props.locations)) return []; // Ensure it's always an array
        if (!tempSelectedEvent.value || tempSelectedEvent.value === 'all') return [];
        return props.locations.filter(location => location.event_id === tempSelectedEvent.value);
    });

    // Group locations by date
    const groupedLocations = computed(() => {
        if (!filteredLocations.value || filteredLocations.value.length === 0) return {};
        
        return filteredLocations.value.reduce((groups, location) => {
            const dateKey = location.date; // Use raw date for grouping key
            if (!groups[dateKey]) {
                groups[dateKey] = {
                    formattedDate: formatDate(location.date),
                    locations: []
                };
            }
            
            // Add formatted time to each location
            const locationWithTime = {
                ...location,
                formattedTime: formatTime(location.time)
            };
            
            groups[dateKey].locations.push(locationWithTime);
            return groups;
        }, {});
    });

    // ✅ Helper function to truncate text
    const truncateText = (text, length) => {
        if (text.length <= length) return text;
        return text.substring(0, length) + '...';
    };

    // ✅ Chart Data for Attendees Per Location/Event
    const chartData = computed(() => ({
        labels: props.attendeesChartData.map(item => item.location), // Location name or Event name
        datasets: [
            {
                label: selectedEvent.value === 'all' ? 'Attendees Per Event' : 'Attendees Per Location',
                data: props.attendeesChartData.map(item => item.count),
                backgroundColor: '#16C3D9',
                borderColor: '#14b8cc',
                borderWidth: 1
            }
        ]
    }));

    // ✅ Dynamic chart options based on data
    const chartOptions = computed(() => {
        // Calculate max value from data
        const maxValue = Math.max(...props.attendeesChartData.map(item => item.count), 0);
        
        // Calculate appropriate step size based on max value
        let stepSize = 1;
        if (maxValue > 1000) {
            stepSize = Math.ceil(maxValue / 20); // Show about 20 ticks max
        } else if (maxValue > 100) {
            stepSize = Math.ceil(maxValue / 15); // Show about 15 ticks max
        } else if (maxValue > 10) {
            stepSize = Math.ceil(maxValue / 10); // Show about 10 ticks max
        }
        
        return {
            responsive: true,
            maintainAspectRatio: false,
            layout: {
                padding: {
                    bottom: 25,
                    left: 10,
                    right: 10
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        title: function(context) {
                            const index = context[0].dataIndex;
                            const item = props.attendeesChartData[index];
                            return item.location;
                        },
                        label: function(context) {
                            const index = context.dataIndex;
                            const item = props.attendeesChartData[index];
                            
                            // Different tooltip content for All Events vs individual event
                            if (selectedEvent.value === 'all') {
                                const tooltipItems = [
                                    `Event: ${item.event_name}`,
                                    `Event Date: ${item.date}`,
                                    `Total Attendees: ${item.count}`,
                                    `Locations: ${item.location_count || 0}`
                                ];
                                
                                // Add note if no signup form exists
                                if (item.has_signup_form === false) {
                                    tooltipItems.push('No signup form created');
                                }
                                
                                return tooltipItems;
                            } else {
                                const labels = [
                                    `Date: ${item.date}`,
                                    `Time: ${item.time}`,
                                    `Attendees: ${item.count}`
                                ];
                                
                                // Add event name if it exists
                                if (item.event_name) {
                                    labels.unshift(`Event: ${item.event_name}`);
                                }
                                
                                return labels;
                            }
                        }
                    }
                },
                legend: {
                    display: false
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        maxRotation: 90, // Vertical labels
                        minRotation: 90, // Vertical labels
                        font: {
                            size: 11 // Slightly larger font since we only show location
                        },
                        autoSkip: false, // Show all labels
                        padding: 5 // Add padding between labels
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: '#e2e8f0'
                    },
                    ticks: {
                        stepSize: stepSize,
                        precision: 0
                    }
                }
            }
        };
    });

    // ✅ Function to Handle Event Change (for dropdown only)
    const handleEventChange = () => {
        if (tempSelectedEvent.value === 'all') {
            tempSelectedLocation.value = null;
        } else if (!filteredLocations.value.some(location => location.id === tempSelectedLocation.value)) {
            tempSelectedLocation.value = null;
        }
    };

    // ✅ Function to Handle Filtering
    const filterData = () => {
        if (!tempSelectedEvent.value) {
            alert('Please select an event first.');
            return;
        }
        
        // Update the actual selected values when filter is applied
        selectedEvent.value = tempSelectedEvent.value;
        selectedLocation.value = tempSelectedLocation.value;
        
        router.get(route('dashboard.filter', { event: tempSelectedEvent.value, location: tempSelectedLocation.value }));
    };

    const selectedEventName = computed(() => {
        if (selectedEvent.value === 'all') {
            return "All Events";
        }
        const event = props.events.find(e => e.id === selectedEvent.value);
        return event ? event.event_name : "Select an Event";
    });

    // Format locations to display date and time in the desired format
    const formattedLocations = computed(() => {
        if (!props.locations || !Array.isArray(props.locations)) return [];
        
        return props.locations.map(location => {
            return {
                ...location,
                formattedDate: formatDate(location.date),
                formattedTime: formatTime(location.time)
            };
        });
    });

    // Function to format date to "March 07, 2025" format
    function formatDate(dateString) {
        if (!dateString) return '';
        
        try {
            // If date is already in a format like "March 7, 2025", no need to reformat
            if (dateString.includes(',')) {
                return dateString;
            }
            
            const date = new Date(dateString);
            if (isNaN(date.getTime())) return dateString; // Return original if invalid
            
            return date.toLocaleDateString('en-US', {
                month: 'long',
                day: '2-digit',
                year: 'numeric'
            });
        } catch (e) {
            console.error("Error formatting date:", e);
            return dateString;
        }
    }

    // Function to format time to "7:00PM" format
    function formatTime(timeString) {
        if (!timeString) return '';
        
        try {
            // If time is already in a format like "7:00 PM", no need to reformat
            if (timeString.includes('AM') || timeString.includes('PM')) {
                return timeString.replace(' ', ''); // Remove space between time and AM/PM
            }
            
            // For 24-hour format "HH:MM"
            if (timeString.includes(':')) {
                const [hours, minutes] = timeString.split(':');
                const date = new Date();
                date.setHours(parseInt(hours));
                date.setMinutes(parseInt(minutes));
                
                return date.toLocaleTimeString('en-US', {
                    hour: 'numeric',
                    minute: '2-digit',
                    hour12: true
                }).replace(' ', ''); // Remove space between time and AM/PM
            }
            
            return timeString;
        } catch (e) {
            console.error("Error formatting time:", e);
            return timeString;
        }
    }
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Dashboard
            </h2>
        </template>

        <div class="py-4 px-2">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 d-flex justify-between">
                        <div class="d-flex">
                            <!-- ✅ Event Selection -->
                            <div class="me-3">
                                <label class="block text-lg font-semibold mb-2">Event:</label>
                                <select 
                                    v-model="tempSelectedEvent" 
                                    @change="handleEventChange"
                                    class="w-full p-2 border rounded">
                                    <option value="all">All Events</option>
                                    <option v-for="event in events" :key="event.id" :value="event.id">
                                        {{ event.event_name }}
                                    </option>
                                </select>
                            </div>

                            <!-- ✅ Location Selection with OptGroup by Date -->
                            <div v-if="locations.length > 0 && tempSelectedEvent !== 'all'" class="me-3">
                                <label class="block text-lg font-semibold mb-2">Location:</label>
                                <select 
                                    v-model="tempSelectedLocation"
                                    class="w-full p-2 border rounded"
                                    :disabled="filteredLocations.length === 0">
                                    <option value="" disabled>Select a Location</option>
                                    
                                    <!-- Group locations by date -->
                                    <template v-for="(dateGroup, dateKey) in groupedLocations" :key="dateKey">
                                        <optgroup :label="dateGroup.formattedDate">
                                            <option v-for="location in dateGroup.locations" :key="location.id" :value="location.id">
                                                {{ location.name }} - {{ location.formattedTime }}
                                            </option>
                                        </optgroup>
                                    </template>
                                </select>
                            </div>

                            <!-- ✅ Filter Button -->
                            <div class="ms-1">
                                <label class="block text-lg font-semibold mb-2">‎</label>
                                <button class="btn p-2" style="background-color: #16C3D9; color: white;" @click="filterData">Select</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ✅ Bar Chart for Attendees Per Location -->
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 mt-3">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <h3 class="text-lg font-semibold mb-3">
                            {{ selectedEvent === 'all' ? 'Attendees Per Event' : 'Attendees Per Location' }}
                        </h3>
                        <div class="h-[550px]"> <!-- Adjusted height since we only show location names -->
                            <Bar v-if="props.attendeesChartData.length > 0" 
                                 :data="chartData" 
                                 :options="chartOptions" />
                            <p v-else class="text-gray-500 text-center">
                                {{ selectedEvent === 'all' ? 'No events available.' : 'No data available for this event.' }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ✅ Responsive Grid for Metrics -->
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 mt-3">
                <div class="flex flex-col lg:flex-row gap-4">
                    <div class="w-full lg:w-1/3 overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div class="p-6 text-gray-900">Total Event Attendees</div>
                        <div class="pt-2 pb-5 d-flex justify-content-center font-semibold" style="font-size: 50px;">
                            {{ allDataAttendees || 0 }}
                        </div>
                    </div>
                    <div v-if="selectedLocation" class="w-full lg:w-1/3 overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div class="p-6 text-gray-900">Total Location Attendees</div>
                        <div class="pt-2 pb-5 d-flex justify-content-center font-semibold" style="font-size: 50px;">
                            {{ attendeesSelectedLocation || 0 }}
                        </div>
                    </div>
                    <div class="w-full lg:w-1/3 overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div class="p-6 text-gray-900">Total Events Created</div>
                        <div class="pt-2 pb-5 d-flex justify-content-center font-semibold" style="font-size: 50px;">
                            {{ eventCount || 0 }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>