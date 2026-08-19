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
        todayEvents: { type: Object, default: () => ({ locations: [], total_attendees: 0, date: '' }) },
        tomorrowEvents: { type: Object, default: () => ({ locations: [], total_attendees: 0, date: '' }) },
        thisWeekEvents: { type: Object, default: () => ({ locations: [], total_attendees: 0, week_range: '' }) }
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

    // ✅ Chart Data for Attendees Per Location/Event
    const chartData = computed(() => ({
        labels: props.attendeesChartData.map(item => item.location), // Location name or Event name
        datasets: [
            {
                label: selectedEvent.value === 'all' ? 'Attendees Per Event' : 'Attendees Per Location',
                data: props.attendeesChartData.map(item => item.count),
                backgroundColor: '#16C3D9',
                borderColor: '#14b8cc',
                borderWidth: 0,
                borderRadius: 6,
                maxBarThickness: 48
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
                    backgroundColor: '#0f172a',
                    padding: 12,
                    cornerRadius: 8,
                    titleColor: '#fff',
                    bodyColor: '#cbd5e1',
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
                        color: '#64748b',
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
                        color: '#eef2f6'
                    },
                    ticks: {
                        color: '#64748b',
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

    // ✅ KPI stat cards — derived from existing props
    const stats = computed(() => [
        {
            key: 'attendees',
            label: 'Total Event Attendees',
            value: props.allDataAttendees || 0,
            accent: 'cyan',
            icon: 'users'
        },
        {
            key: 'events',
            label: 'Total Events Created',
            value: props.eventCount || 0,
            accent: 'indigo',
            icon: 'calendar'
        },
        {
            key: 'today',
            label: 'Attendees Today',
            value: props.todayEvents.total_attendees || 0,
            sub: `${props.todayEvents.locations.length} location${props.todayEvents.locations.length === 1 ? '' : 's'}`,
            accent: 'blue',
            icon: 'bolt'
        },
        {
            key: 'week',
            label: 'Attendees This Week',
            value: props.thisWeekEvents.total_attendees || 0,
            sub: props.thisWeekEvents.week_range,
            accent: 'purple',
            icon: 'chart'
        }
    ]);

    // ✅ The three event lists, driven by one config so markup stays DRY
    const eventSections = computed(() => [
        {
            key: 'today',
            title: "Today's Events",
            subtitle: props.todayEvents.date,
            empty: 'No events today',
            accent: 'blue',
            showDate: false,
            data: props.todayEvents
        },
        {
            key: 'tomorrow',
            title: "Tomorrow's Events",
            subtitle: props.tomorrowEvents.date,
            empty: 'No events tomorrow',
            accent: 'emerald',
            showDate: false,
            data: props.tomorrowEvents
        },
        {
            key: 'week',
            title: "This Week's Events",
            subtitle: props.thisWeekEvents.week_range,
            empty: 'No events this week',
            accent: 'purple',
            showDate: true,
            data: props.thisWeekEvents
        }
    ]);

    // Clicking one of the today / tomorrow / this week rows opens that location's
    // attendee list — the same place the Location page sends you when you click a
    // location, so the dashboard is a shortcut into it rather than a dead end.
    const openLocation = (location) => {
        if (!location.location_id || !location.event_id) return;

        router.get(route('attendees.location', {
            eventId: location.event_id,
            locationId: location.location_id,
        }));
    };

    // Static class maps so Tailwind keeps the utilities at build time
    const accents = {
        cyan:    { bar: 'bg-cyan-500',    soft: 'bg-cyan-50',    text: 'text-cyan-600',    ring: 'ring-cyan-100' },
        indigo:  { bar: 'bg-indigo-500',  soft: 'bg-indigo-50',  text: 'text-indigo-600',  ring: 'ring-indigo-100' },
        blue:    { bar: 'bg-blue-500',    soft: 'bg-blue-50',    text: 'text-blue-600',    ring: 'ring-blue-100' },
        emerald: { bar: 'bg-emerald-500', soft: 'bg-emerald-50', text: 'text-emerald-600', ring: 'ring-emerald-100' },
        purple:  { bar: 'bg-purple-500',  soft: 'bg-purple-50',  text: 'text-purple-600',  ring: 'ring-purple-100' }
    };

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
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-bold leading-tight text-gray-800">Dashboard</h2>
                    <p class="mt-0.5 text-sm text-gray-500">Overview of events, locations &amp; attendees</p>
                </div>
                <span class="hidden sm:inline-flex items-center gap-2 rounded-full bg-cyan-50 px-3 py-1 text-sm font-medium text-cyan-700 ring-1 ring-inset ring-cyan-100">
                    <span class="h-2 w-2 rounded-full bg-cyan-500"></span>
                    {{ selectedEventName }}
                </span>
            </div>
        </template>

        <div class="min-h-screen bg-slate-50">
            <div class="w-full px-4 py-6 sm:px-6 lg:px-8 space-y-6">

                <!-- ✅ KPI Stat Cards -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div v-for="stat in stats" :key="stat.key"
                         class="relative overflow-hidden rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100 transition hover:shadow-md">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-500">{{ stat.label }}</p>
                                <p class="mt-2 text-4xl font-bold tracking-tight text-gray-900">{{ stat.value }}</p>
                                <p v-if="stat.sub" class="mt-1 text-xs font-medium text-gray-400">{{ stat.sub }}</p>
                            </div>
                            <div :class="['flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ring-4', accents[stat.accent].soft, accents[stat.accent].text, accents[stat.accent].ring]">
                                <!-- users -->
                                <svg v-if="stat.icon === 'users'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a4 4 0 10-3-6.65"/></svg>
                                <!-- calendar -->
                                <svg v-else-if="stat.icon === 'calendar'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                <!-- bolt -->
                                <svg v-else-if="stat.icon === 'bolt'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                <!-- chart -->
                                <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                            </div>
                        </div>
                        <span :class="['absolute inset-x-0 bottom-0 h-1', accents[stat.accent].bar]"></span>
                    </div>
                </div>

                <!-- ✅ Filter Bar -->
                <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                    <div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-end">
                        <!-- Event Selection -->
                        <div class="flex-1 min-w-[200px]">
                            <label class="mb-1.5 block text-sm font-semibold text-gray-700">Event</label>
                            <select
                                v-model="tempSelectedEvent"
                                @change="handleEventChange"
                                class="w-full rounded-lg border-gray-200 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                                <option value="all">All Events</option>
                                <option v-for="event in events" :key="event.id" :value="event.id">
                                    {{ event.event_name }}
                                </option>
                            </select>
                        </div>

                        <!-- Location Selection with OptGroup by Date -->
                        <div v-if="locations.length > 0 && tempSelectedEvent !== 'all'" class="flex-1 min-w-[200px]">
                            <label class="mb-1.5 block text-sm font-semibold text-gray-700">Location</label>
                            <select
                                v-model="tempSelectedLocation"
                                class="w-full rounded-lg border-gray-200 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                                :disabled="filteredLocations.length === 0">
                                <option value="" disabled>Select a Location</option>
                                <template v-for="(dateGroup, dateKey) in groupedLocations" :key="dateKey">
                                    <optgroup :label="dateGroup.formattedDate">
                                        <option v-for="location in dateGroup.locations" :key="location.id" :value="location.id">
                                            {{ location.name }} - {{ location.formattedTime }}
                                        </option>
                                    </optgroup>
                                </template>
                            </select>
                        </div>

                        <!-- Filter Button -->
                        <div>
                            <button
                                @click="filterData"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-cyan-500 px-6 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-cyan-600 sm:w-auto">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.879a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                                Apply Filter
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ✅ Event Lists — side by side, full width -->
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div v-for="section in eventSections" :key="section.key"
                         class="flex flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                        <!-- Card header -->
                        <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                            <div class="flex items-center gap-2.5">
                                <span :class="['h-2.5 w-2.5 rounded-full', accents[section.accent].bar]"></span>
                                <h3 class="text-base font-semibold text-gray-900">{{ section.title }}</h3>
                            </div>
                            <span class="text-xs font-medium text-gray-400">{{ section.subtitle }}</span>
                        </div>

                        <!-- Card body -->
                        <div class="flex flex-1 flex-col p-4">
                            <div v-if="section.data.locations.length === 0"
                                 class="flex flex-1 items-center justify-center py-10 text-sm text-gray-400">
                                {{ section.empty }}
                            </div>

                            <div v-else class="flex flex-1 flex-col">
                                <div class="flex-1 space-y-2 overflow-y-auto pr-1" style="max-height: 340px;">
                                    <button v-for="(location, i) in section.data.locations"
                                         :key="`${section.key}-${i}`"
                                         type="button"
                                         @click="openLocation(location)"
                                         :title="`View attendees for ${location.location_name}`"
                                         :class="['group block w-full cursor-pointer rounded-xl px-3 py-2.5 text-left transition hover:brightness-[0.98] focus:outline-none focus:ring-2 focus:ring-offset-1', accents[section.accent].soft, accents[section.accent].ring]">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-semibold text-gray-900">{{ location.location_name }}</p>
                                                <p class="truncate text-xs text-gray-500">{{ location.event_name }}</p>
                                            </div>
                                            <div class="flex shrink-0 items-center gap-1.5 rounded-full bg-white px-2.5 py-1 text-xs font-bold text-gray-700 shadow-sm">
                                                <svg class="h-3.5 w-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z"/></svg>
                                                {{ location.attendees }}
                                            </div>
                                        </div>
                                        <div class="mt-1.5 flex items-center gap-3 text-xs text-gray-400">
                                            <span v-if="section.showDate" class="inline-flex items-center gap-1">
                                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                                {{ location.date }}
                                            </span>
                                            <span class="inline-flex items-center gap-1">
                                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                {{ location.time }}
                                            </span>
                                            <span class="ml-auto inline-flex items-center gap-1 font-medium opacity-0 transition group-hover:opacity-100 group-focus:opacity-100"
                                                  :class="accents[section.accent].text">
                                                View
                                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                            </span>
                                        </div>
                                    </button>
                                </div>

                                <!-- Total footer -->
                                <div class="mt-3 flex items-center justify-between border-t border-gray-100 pt-3">
                                    <span class="text-sm text-gray-500">Total attendees</span>
                                    <span :class="['text-lg font-bold', accents[section.accent].text]">{{ section.data.total_attendees }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ✅ Selected location metric (only when a location is filtered) -->
                <div v-if="selectedLocation" class="rounded-2xl bg-gradient-to-r from-cyan-500 to-cyan-600 p-6 text-white shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-cyan-50">Total Attendees · Selected Location</p>
                            <p class="mt-1 text-4xl font-bold">{{ attendeesSelectedLocation || 0 }}</p>
                        </div>
                        <svg class="h-12 w-12 text-cyan-200/70" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </div>
                </div>

                <!-- ✅ Attendees Chart — full width -->
                <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-100">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-gray-900">
                            {{ selectedEvent === 'all' ? 'Attendees Per Event' : 'Attendees Per Location' }}
                        </h3>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
                            {{ props.attendeesChartData.length }} {{ selectedEvent === 'all' ? 'events' : 'locations' }}
                        </span>
                    </div>
                    <div class="h-[520px]">
                        <Bar v-if="props.attendeesChartData.length > 0"
                             :data="chartData"
                             :options="chartOptions" />
                        <div v-else class="flex h-full flex-col items-center justify-center text-gray-400">
                            <svg class="h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                            <p class="mt-3 text-sm">{{ selectedEvent === 'all' ? 'No events available.' : 'No data available for this event.' }}</p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
