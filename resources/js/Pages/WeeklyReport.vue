<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import Swal from 'sweetalert2';

const props = defineProps({
    reportData: { type: Array, default: () => [] },
    dateRange: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
    allEventsSummary: { type: Array, default: () => [] },
    allEventsTotals: { type: Object, default: () => ({}) }
});

const startDate = ref(props.dateRange.start_date || '');
const endDate = ref(props.dateRange.end_date || '');
const isLoading = ref(false);

// Set default dates if not provided
onMounted(() => {
    if (!startDate.value || !endDate.value) {
        const today = new Date();
        const startOfWeek = new Date(today);
        startOfWeek.setDate(today.getDate() - today.getDay() + 1); // Monday
        const endOfWeek = new Date(startOfWeek);
        endOfWeek.setDate(startOfWeek.getDate() + 6); // Sunday
        
        startDate.value = startOfWeek.toISOString().split('T')[0];
        endDate.value = endOfWeek.toISOString().split('T')[0];
    }
});

// Quick date range options
const quickRanges = [
    { label: 'This Week (Mon-Sun)', days: 0, type: 'current_week' },
    { label: 'Last 7 Days', days: 7, type: 'last_7_days' },
    { label: 'Last 14 Days', days: 14, type: 'last_14_days' },
    { label: 'Last 30 Days', days: 30, type: 'last_30_days' }
];

const setQuickRange = (range) => {
    const today = new Date();
    
    if (range.type === 'current_week') {
        const startOfWeek = new Date(today);
        startOfWeek.setDate(today.getDate() - today.getDay() + 1); // Monday
        const endOfWeek = new Date(startOfWeek);
        endOfWeek.setDate(startOfWeek.getDate() + 6); // Sunday
        
        startDate.value = startOfWeek.toISOString().split('T')[0];
        endDate.value = endOfWeek.toISOString().split('T')[0];
    } else {
        const startDateObj = new Date(today);
        startDateObj.setDate(today.getDate() - range.days);
        
        startDate.value = startDateObj.toISOString().split('T')[0];
        endDate.value = today.toISOString().split('T')[0];
    }
    
    generateReport();
};

const generateReport = async () => {
    if (!startDate.value || !endDate.value) {
        Swal.fire('Error', 'Please select both start and end dates', 'error');
        return;
    }
    
    if (new Date(startDate.value) > new Date(endDate.value)) {
        Swal.fire('Error', 'Start date cannot be after end date', 'error');
        return;
    }
    
    isLoading.value = true;
    
    try {
        await router.post(route('weekly-report.generate'), {
            start_date: startDate.value,
            end_date: endDate.value
        });
    } catch (error) {
        console.error('Error generating report:', error);
        Swal.fire('Error', 'Failed to generate report', 'error');
    } finally {
        isLoading.value = false;
    }
};

const exportReport = () => {
    if (!startDate.value || !endDate.value) {
        Swal.fire('Error', 'Please select both start and end dates', 'error');
        return;
    }
    
    // Create a form and submit it to trigger the download
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = route('weekly-report.export');
    form.style.display = 'none';
    
    // Add CSRF token
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = '_token';
    csrfInput.value = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || 
                     document.querySelector('input[name="_token"]')?.value;
    
    // Add start date
    const startDateInput = document.createElement('input');
    startDateInput.type = 'hidden';
    startDateInput.name = 'start_date';
    startDateInput.value = startDate.value;
    
    // Add end date
    const endDateInput = document.createElement('input');
    endDateInput.type = 'hidden';
    endDateInput.name = 'end_date';
    endDateInput.value = endDate.value;
    
    form.appendChild(csrfInput);
    form.appendChild(startDateInput);
    form.appendChild(endDateInput);
    
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
    
    Swal.fire('Success', 'Report export initiated', 'success');
};

// Computed properties for better data handling
const hasData = computed(() => props.reportData && props.reportData.length > 0);
const totalSignups = computed(() => props.summary.total_signups || 0);
const totalEvents = computed(() => props.summary.total_events || 0);
const totalLocations = computed(() => props.summary.total_locations || 0);

// Format date for display
const formatDate = (dateString) => {
    if (!dateString || dateString === 'TBA') return 'TBA';
    
    try {
        return new Date(dateString).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    } catch (e) {
        return dateString;
    }
};

// Format time for display
const formatTime = (timeString) => {
    if (!timeString || timeString === 'TBA') return 'TBA';
    
    try {
        // If it's already formatted (contains AM/PM), return as is
        if (timeString.includes('AM') || timeString.includes('PM')) {
            return timeString;
        }
        
        // Try to parse and format
        return new Date(`2000-01-01T${timeString}`).toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });
    } catch (e) {
        return timeString;
    }
};
</script>

<template>
    <Head title="Report" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Report
            </h2>
        </template>

        <div class="py-4 px-2">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <!-- Date Range Selection -->
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold mb-4">Select Date Range</h3>
                        
                        <!-- Quick Range Buttons -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Quick Ranges:</label>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="range in quickRanges"
                                    :key="range.type"
                                    @click="setQuickRange(range)"
                                    class="px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors"
                                >
                                    {{ range.label }}
                                </button>
                            </div>
                        </div>
                        
                        <!-- Custom Date Range -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                                <input
                                    type="date"
                                    v-model="startDate"
                                    class="w-full p-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                                <input
                                    type="date"
                                    v-model="endDate"
                                    class="w-full p-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                />
                            </div>
                            <div class="w-full flex justify-end">
                                <button
                                    @click="generateReport"
                                    :disabled="isLoading"
                                    class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    {{ isLoading ? 'Generating...' : 'Generate Report' }}
                                </button>
                                <!-- <button
                                    @click="exportReport"
                                    :disabled="!hasData"
                                    class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    Export CSV
                                </button> -->
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div v-if="hasData" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div class="p-6">
                            <div class="text-sm font-medium text-gray-500">Total Events</div>
                            <div class="text-2xl font-bold text-gray-900">{{ totalEvents }}</div>
                        </div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div class="p-6">
                            <div class="text-sm font-medium text-gray-500">Total Locations</div>
                            <div class="text-2xl font-bold text-gray-900">{{ totalLocations }}</div>
                        </div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div class="p-6">
                            <div class="text-sm font-medium text-gray-500">Total Signups</div>
                            <div class="text-2xl font-bold text-gray-900">{{ totalSignups }}</div>
                        </div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div class="p-6">
                            <div class="text-sm font-medium text-gray-500">Avg per Event</div>
                            <div class="text-2xl font-bold text-gray-900">{{ summary.average_signups_per_event }}</div>
                        </div>
                    </div>
                </div>

                <!-- Report Data -->
                <div v-if="hasData" class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-semibold">
                                Report for {{ dateRange.start_formatted }} - {{ dateRange.end_formatted }}
                            </h3>
                            <span class="text-sm text-gray-500">
                                {{ reportData.length }} event{{ reportData.length !== 1 ? 's' : '' }}
                            </span>
                        </div>

                        <div class="space-y-6">
                            <div v-for="event in reportData" :key="event.event_id" class="border border-gray-200 rounded-lg p-4">
                                <!-- Event Header -->
                                <div class="flex justify-between items-start mb-4">
                                    <div>
                                        <h4 class="text-lg font-semibold text-gray-900">{{ event.event_name }}</h4>
                                        <p class="text-sm text-gray-600">
                                            Filter Date Range: {{ dateRange.start_formatted }} - {{ dateRange.end_formatted }}
                                        </p>
                                        <!-- <p class="text-sm text-gray-500">
                                            Report Generated: {{ new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) }}
                                        </p> -->
                                    </div>
                                    <div class="text-right">
                                        <div class="text-2xl font-bold text-blue-600">{{ event.total_signups }}</div>
                                        <div class="text-sm text-gray-500">Total Signups</div>
                                        <div v-if="!event.has_signup_form" class="text-xs text-red-500 mt-1">
                                            No signup form
                                        </div>
                                    </div>
                                </div>

                                <!-- Locations -->
                                <div v-if="event.locations && event.locations.length > 0">
                                    <h5 class="text-md font-medium text-gray-700 mb-3">Locations ({{ event.locations.length }})</h5>
                                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                        <div
                                            v-for="location in event.locations"
                                            :key="location.location_id"
                                            class="bg-gray-50 rounded-lg p-3"
                                        >
                                            <div class="flex justify-between items-start mb-2">
                                                <h6 class="font-medium text-gray-900">{{ location.location_name }}</h6>
                                                <span class="text-lg font-bold text-green-600">{{ location.signups }}</span>
                                            </div>
                                            <div class="text-sm text-gray-600">
                                                <div>{{ location.formatted_date }}</div>
                                                <div>{{ location.formatted_time }}</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="text-gray-500 text-sm italic">
                                    No locations found for this event in the selected date range.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- All Events Summary Section -->
                <div v-if="allEventsSummary && allEventsSummary.length > 0" class="overflow-hidden bg-white shadow-sm sm:rounded-lg mt-6">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold mb-4">All Events Summary (Total Data)</h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Event Name</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Locations</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Signups</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Avg per Location</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="event in allEventsSummary" :key="event.event_id" class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">{{ event.event_name }}</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">{{ event.total_locations }}</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-bold text-blue-600">{{ event.total_signups }}</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">{{ event.average_per_location }}</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span v-if="event.status === 'Ongoing'" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                Ongoing
                                            </span>
                                            <span v-else-if="event.status === 'Completed'" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                Completed
                                            </span>
                                            <span v-else-if="event.status === 'Upcoming'" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                Upcoming
                                            </span>
                                            <span v-else-if="event.status === 'TBA'" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                TBA
                                            </span>
                                            <span v-else class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                No Locations
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot class="bg-gray-100">
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">TOTAL</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">{{ allEventsTotals.total_locations }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-blue-600">{{ allEventsTotals.total_signups }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">{{ allEventsTotals.average_per_location }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                                            {{ allEventsSummary.filter(e => e.status === 'Ongoing').length }} Ongoing, {{ allEventsSummary.filter(e => e.status === 'Completed').length }} Completed
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- No Data Message -->
                <div v-else class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-center">
                        <div class="text-gray-500 text-lg mb-2">No data found</div>
                        <div class="text-gray-400 text-sm">
                            No events or signups found for the selected date range.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
