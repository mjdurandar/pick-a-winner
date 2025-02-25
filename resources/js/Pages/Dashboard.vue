<script setup>
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
    import { Head, router } from '@inertiajs/vue3';
    import { ref, computed, watchEffect } from 'vue';
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
        selectedEventId: Number,    
        allDataAttendees: Number,
        eventCount: Number,
        selectedLocationId: String, 
        attendeesSelectedLocation: Number,
        attendeesChartData: { type: Array, default: () => [] },   // ✅ Receive Chart Data from Backend
    });

    const selectedEvent = ref(props.selectedEventId || (props.events.length > 0 ? props.events[0].id : null));
    const selectedLocation = ref(props.selectedLocationId || null);

    // ✅ Filter Locations for Selected Event
    const filteredLocations = computed(() => {
        if (!Array.isArray(props.locations)) return []; // Ensure it's always an array
        if (!selectedEvent.value) return [];
        return props.locations.filter(location => location.event_id === selectedEvent.value);
    });

    // ✅ Chart Data for Attendees Per Location
    const chartData = computed(() => ({
        labels: props.attendeesChartData.map(item => item.location), // Location names
        datasets: [
            {
                label: 'Attendees Per Location',
                data: props.attendeesChartData.map(item => item.count), // Attendee count per location
                backgroundColor: '#4CAF50'
            }
        ]
    }));

    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false
    };

    // ✅ Function to Handle Event Change
    const handleEventChange = () => {
        if (!filteredLocations.value.some(location => location.id === selectedLocation.value)) {
            selectedLocation.value = null;
        }
        props.locations.length = 0;
    };

    // ✅ Function to Handle Filtering
    const filterData = () => {
        if (!selectedEvent.value) {
            alert('Please select an event first.');
            return;
        }
        router.get(route('dashboard.filter', { event: selectedEvent.value, location: selectedLocation.value }));
    };

    const selectedEventName = computed(() => {
        const event = props.events.find(e => e.id === selectedEvent.value);
        return event ? event.event_name : "Select an Event";
    });


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
                                    v-model="selectedEvent" 
                                    @change="handleEventChange"
                                    class="w-full p-2 border rounded">
                                    <option value="" disabled>Select an Event</option>
                                    <option v-for="event in events" :key="event.id" :value="event.id">
                                        {{ event.event_name }}
                                    </option>
                                </select>
                            </div>

                            <!-- ✅ Location Selection -->
                            <div v-if="locations.length > 0" class="me-3">
                                <label class="block text-lg font-semibold mb-2">Location:</label>
                                <select 
                                    v-model="selectedLocation"
                                    class="w-full p-2 border rounded"
                                    :disabled="filteredLocations.length === 0">
                                    <option value="" disabled>Select a Location</option>
                                    <option v-for="location in filteredLocations" :key="location.id" :value="location.id">
                                        {{ location.name }}
                                    </option>
                                </select>
                            </div>

                            <!-- ✅ Filter Button -->
                            <div class="ms-1">
                                <label class="block text-lg font-semibold mb-2">‎</label>
                                <button class="btn btn-primary p-2" @click="filterData">Select</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ✅ Bar Chart for Attendees Per Location -->
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 mt-3">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <h3 class="text-lg font-semibold mb-3">Attendees Per Location</h3>
                        <div class="h-80">
                            <Bar v-if="props.attendeesChartData.length > 0" :data="chartData" :options="chartOptions" />
                            <p v-else class="text-gray-500 text-center">No data available for this event.</p>
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
                    <div class="w-full lg:w-1/3 overflow-hidden bg-white shadow-sm sm:rounded-lg">
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

