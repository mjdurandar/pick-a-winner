<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, onMounted, nextTick, watch } from 'vue';
import Swal from 'sweetalert2';
import axios from 'axios';

// Lazy load Chart.js to avoid build issues
let Chart = null;
let ChartInitialized = false;

const initChart = async () => {
    if (ChartInitialized) return;
    
    try {
        const chartModule = await import('chart.js');
        Chart = chartModule.Chart;
        const { registerables } = chartModule;
        Chart.register(...registerables);
        ChartInitialized = true;
    } catch (error) {
        console.error('Failed to load Chart.js:', error);
    }
};

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

// Tab management
const activeTab = ref('weekly-report');
const selectedEventId = ref(null);
const eventBreakdown = ref(null);
const isLoadingBreakdown = ref(false);
const selectedEventIdsForSpreadsheet = ref([]);
const availableEvents = computed(() => {
    return props.allEventsSummary.filter(e => e.has_signup_form);
});
const selectAllEventsForSpreadsheet = () => {
    selectedEventIdsForSpreadsheet.value = availableEvents.value.map(e => e.event_id);
};
const clearEventsForSpreadsheet = () => {
    selectedEventIdsForSpreadsheet.value = [];
};

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

const demographicsTableData = ref({ headers: [], rows: [] });
const isLoadingDemographicsTable = ref(false);

const loadDemographicsTable = async () => {
    if (!selectedEventIdsForSpreadsheet.value || selectedEventIdsForSpreadsheet.value.length === 0) {
        Swal.fire('Error', 'Please select at least one event to include.', 'error');
        return;
    }
    isLoadingDemographicsTable.value = true;
    demographicsTableData.value = { headers: [], rows: [] };
    try {
        const res = await axios.get(route('weekly-report.demographics-table'), {
            params: { event_ids: selectedEventIdsForSpreadsheet.value }
        });
        demographicsTableData.value = { headers: res.data.headers || [], rows: res.data.rows || [] };
    } catch (e) {
        Swal.fire('Error', e.response?.data?.message || 'Failed to load demographics table.', 'error');
    } finally {
        isLoadingDemographicsTable.value = false;
    }
};

const copyDemographicsTable = () => {
    const { headers, rows } = demographicsTableData.value;
    if (!headers.length) return;
    const tsv = [headers.join('\t'), ...rows.map(row => row.join('\t'))].join('\n');
    navigator.clipboard.writeText(tsv).then(() => {
        Swal.fire('Copied', 'Table copied to clipboard. Paste into Excel or Google Sheets and columns will align.', 'success');
    }).catch(() => {
        Swal.fire('Error', 'Could not copy. Select the table below and copy manually (Ctrl+C / Cmd+C).', 'error');
    });
};

const exportDemographicsSpreadsheet = () => {
    if (!selectedEventIdsForSpreadsheet.value || selectedEventIdsForSpreadsheet.value.length === 0) {
        Swal.fire('Error', 'Please select at least one event to include in the spreadsheet', 'error');
        return;
    }
    let url = route('weekly-report.export-demographics-spreadsheet');
    const params = new URLSearchParams();
    selectedEventIdsForSpreadsheet.value.forEach(id => params.append('event_ids[]', id));
    url += '?' + params.toString();
    window.open(url, '_blank');
    Swal.fire('Success', 'Demographics spreadsheet download started. Open the new tab if it was blocked.', 'success');
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
    
    Swal.fire('Success', 'CSV export initiated', 'success');
};

const exportPdf = async () => {
    if (!startDate.value || !endDate.value) {
        Swal.fire('Error', 'Please select both start and end dates', 'error');
        return;
    }
    
    try {
        // Get CSRF token
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || 
                         document.querySelector('input[name="_token"]')?.value;
        
        if (!csrfToken) {
            throw new Error('CSRF token not found');
        }
        
        // Use fetch to make the request
        const response = await fetch(route('weekly-report.export-pdf'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/pdf'
            },
            body: JSON.stringify({
                start_date: startDate.value,
                end_date: endDate.value
            })
        });
        
        if (response.ok) {
            const blob = await response.blob();
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `weekly_report_${startDate.value}_to_${endDate.value}.pdf`;
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
            
            Swal.fire('Success', 'PDF exported successfully', 'success');
        } else {
            throw new Error('PDF export failed');
        }
    } catch (error) {
        console.error('Error exporting PDF:', error);
        Swal.fire('Error', `Failed to export PDF: ${error.message}`, 'error');
    }
};

const exportEventBreakdownPdf = async () => {
    if (!selectedEventId.value) {
        Swal.fire('Error', 'Please select an event first', 'error');
        return;
    }
    
    if (!eventBreakdown.value) {
        Swal.fire('Error', 'Please load the event breakdown first', 'error');
        return;
    }
    
    try {
        // Get CSRF token
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || 
                         document.querySelector('input[name="_token"]')?.value;
        
        if (!csrfToken) {
            throw new Error('CSRF token not found');
        }
        
        // Use fetch to make the request
        const response = await fetch(route('weekly-report.event-breakdown.export-pdf'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/pdf'
            },
            body: JSON.stringify({
                event_id: selectedEventId.value
            })
        });
        
        if (response.ok) {
            const blob = await response.blob();
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            const eventName = eventBreakdown.value.event.name.replace(/[^a-z0-9]/gi, '_').toLowerCase();
            a.download = `event_breakdown_${eventName}_${new Date().toISOString().split('T')[0]}.pdf`;
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
            
            Swal.fire('Success', 'PDF exported successfully', 'success');
        } else {
            const errorData = await response.json();
            throw new Error(errorData.error || 'PDF export failed');
        }
    } catch (error) {
        console.error('Error exporting event breakdown PDF:', error);
        Swal.fire('Error', `Failed to export PDF: ${error.message}`, 'error');
    }
};

const doExportEndOfFilmTourPdf = async (lastFilmSignups, lastFilmYear) => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
        document.querySelector('input[name="_token"]')?.value;
    if (!csrfToken) throw new Error('CSRF token not found');
    const body = { event_id: selectedEventId.value };
    if (lastFilmSignups != null && lastFilmYear != null) {
        body.last_film_signups = parseInt(lastFilmSignups, 10);
        body.last_film_year = parseInt(lastFilmYear, 10);
    }
    const response = await fetch(route('weekly-report.end-of-film-tour.export-pdf'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/pdf'
        },
        body: JSON.stringify(body)
    });
    if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.error || 'PDF export failed');
    }
    const blob = await response.blob();
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    const eventName = (eventBreakdown.value?.event?.name || 'event').replace(/[^a-z0-9]/gi, '_').toLowerCase();
    a.download = `end_of_film_tour_${eventName}_${new Date().toISOString().split('T')[0]}.pdf`;
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    window.URL.revokeObjectURL(url);
    document.body.removeChild(a);
    Swal.fire('Success', 'End of film tour report exported', 'success');
};

const exportEndOfFilmTourPdf = () => {
    if (!selectedEventId.value) {
        Swal.fire('Error', 'Please select an event first', 'error');
        return;
    }
    Swal.fire({
        title: 'End of Film Tour Report',
        html: `
            <p class="text-left mb-2 mt-2">How many signups last film?</p>
            <input id="last-signups" type="number" class="swal2-input" placeholder="e.g. 3500" min="0" style="margin-top: 0;">
            <p class="text-left mb-2 mt-4">What year?</p>
            <input id="last-year" type="number" class="swal2-input" placeholder="e.g. 2024" min="1990" max="2100" style="margin-top: 0;">
        `,
        showCancelButton: true,
        confirmButtonText: 'Export PDF',
        preConfirm: () => {
            const signups = document.getElementById('last-signups')?.value?.trim();
            const year = document.getElementById('last-year')?.value?.trim();
            if (!signups || !year) {
                Swal.showValidationMessage('Please fill both fields');
                return false;
            }
            const s = parseInt(signups, 10);
            const y = parseInt(year, 10);
            if (isNaN(s) || s < 0) {
                Swal.showValidationMessage('Enter a valid signup count');
                return false;
            }
            if (isNaN(y) || y < 1990 || y > 2100) {
                Swal.showValidationMessage('Enter a valid year (1990–2100)');
                return false;
            }
            return { lastFilmSignups: s, lastFilmYear: y };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const { lastFilmSignups, lastFilmYear } = result.value || {};
            doExportEndOfFilmTourPdf(lastFilmSignups, lastFilmYear).catch((error) => {
                console.error('Error exporting end of film tour PDF:', error);
                Swal.fire('Error', `Failed to export PDF: ${error.message}`, 'error');
            });
        }
    });
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

// Event breakdown functions
const loadEventBreakdown = async () => {
    if (!selectedEventId.value) {
        Swal.fire('Error', 'Please select an event', 'error');
        return;
    }
    
    isLoadingBreakdown.value = true;
    eventBreakdown.value = null;
    
    // Clear existing charts
    Object.values(chartInstances.value).forEach(chart => {
        if (chart) chart.destroy();
    });
    chartInstances.value = {};
    
    try {
        const response = await axios.get(route('weekly-report.event-breakdown'), {
            params: {
                event_id: selectedEventId.value
            }
        });
        
        eventBreakdown.value = response.data;
        
        // Create charts after data loads
        await nextTick();
        createChartsForBreakdown();
    } catch (error) {
        console.error('Error loading event breakdown:', error);
        Swal.fire('Error', error.response?.data?.error || 'Failed to load event breakdown', 'error');
    } finally {
        isLoadingBreakdown.value = false;
    }
};

const switchTab = (tab) => {
    // Clean up charts when switching tabs
    Object.values(chartInstances.value).forEach(chart => {
        if (chart) {
            try {
                chart.destroy();
            } catch (e) {
                // Ignore destroy errors
            }
        }
    });
    chartInstances.value = {};
    
    activeTab.value = tab;
    if (tab === 'event-breakdown') {
        // Reset when switching to breakdown tab
        selectedEventId.value = null;
        eventBreakdown.value = null;
    }
};

// Format response value to handle objects and ensure it's a string
const formatResponseValue = (value) => {
    if (!value) return '(Empty)';
    if (typeof value === 'object') {
        // If it's an array, join it
        if (Array.isArray(value)) {
            return value.join(', ');
        }
        // If it's an object, try to get a meaningful string
        return JSON.stringify(value);
    }
    return String(value);
};

// Chart creation functions
const chartInstances = ref({});

const createChart = async (canvasId, chartData, chartType) => {
    // Initialize Chart.js if not already done
    await initChart();
    
    if (!Chart) {
        console.error('Chart.js is not available');
        return;
    }
    
    // Destroy existing chart if it exists
    if (chartInstances.value[canvasId]) {
        try {
            chartInstances.value[canvasId].destroy();
        } catch (e) {
            // Ignore destroy errors
        }
        delete chartInstances.value[canvasId];
    }
    
    // Wait for DOM to be ready
    await nextTick();
    
    // Try to find canvas with retry
    let canvas = document.getElementById(canvasId);
    let retries = 0;
    while (!canvas && retries < 10) {
        await new Promise(resolve => setTimeout(resolve, 100));
        canvas = document.getElementById(canvasId);
        retries++;
    }
    
    if (!canvas) {
        console.warn(`Canvas element not found: ${canvasId}`);
        return;
    }
    
    // Verify canvas is still in the DOM
    if (!canvas.isConnected) {
        console.warn(`Canvas element not connected to DOM: ${canvasId}`);
        return;
    }
    
    const ctx = canvas.getContext('2d');
    if (!ctx) {
        console.error(`Could not get 2d context for canvas: ${canvasId}`);
        return;
    }
    
    // Verify context is valid
    try {
        ctx.save();
        ctx.restore();
    } catch (e) {
        console.error(`Canvas context is invalid: ${canvasId}`, e);
        return;
    }
    
    const labels = Object.keys(chartData.data);
    const data = Object.values(chartData.data);
    
    if (labels.length === 0 || data.length === 0) {
        console.warn(`No data for chart: ${canvasId}`);
        return;
    }
    
    // Filter out any zero or null values
    const validData = [];
    const validLabels = [];
    labels.forEach((label, index) => {
        const value = data[index];
        if (value != null && value > 0) {
            validLabels.push(label);
            validData.push(value);
        }
    });
    
    if (validLabels.length === 0 || validData.length === 0) {
        console.warn(`No valid data for chart: ${canvasId}`);
        return;
    }
    
    // Color schemes
    const pieColors = [
        '#3B82F6', // blue
        '#10B981', // green
        '#F59E0B', // amber
        '#EF4444', // red
        '#8B5CF6', // purple
        '#EC4899', // pink
        '#06B6D4', // cyan
        '#84CC16', // lime
    ];
    
    const barColors = [
        '#3B82F6', // blue
        '#10B981', // green
        '#F59E0B', // amber
        '#EF4444', // red
        '#8B5CF6', // purple
    ];
    
    try {
    const config = {
        type: chartType,
        data: {
            labels: validLabels,
            datasets: [{
                label: 'Count',
                data: validData,
                backgroundColor: chartType === 'pie' ? pieColors.slice(0, validLabels.length) : barColors.slice(0, validLabels.length),
                borderColor: chartType === 'pie' ? '#ffffff' : barColors.slice(0, validLabels.length),
                borderWidth: chartType === 'pie' ? 2 : 1
            }]
        },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                animation: {
                    duration: 0 // Disable animation to avoid timing issues
                },
                layout: {
                    padding: chartType === 'pie' ? {
                        top: 10,
                        bottom: 40,
                        left: 10,
                        right: 10
                    } : {}
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        align: 'center',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed?.y !== undefined ? context.parsed.y : context.raw;
                                
                                if (value == null || isNaN(value)) {
                                    return label + ': ' + (value || 0);
                                }
                                
                                const total = validData.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                },
                scales: chartType === 'bar' ? {
                    x: {
                        ticks: {
                            display: false // Hide x-axis labels
                        },
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            precision: 0
                        }
                    }
                } : undefined
            }
        };
        
        // Double-check canvas is still valid before creating chart
        if (!canvas.isConnected || !canvas.parentElement) {
            console.warn(`Canvas removed before chart creation: ${canvasId}`);
            return;
        }
        
        chartInstances.value[canvasId] = new Chart(ctx, config);
    } catch (error) {
        console.error(`Error creating chart ${canvasId}:`, error);
        // Clean up on error
        if (chartInstances.value[canvasId]) {
            delete chartInstances.value[canvasId];
        }
    }
};

// Watch for event breakdown changes and create charts
const createChartsForBreakdown = async () => {
    if (!eventBreakdown.value || !eventBreakdown.value.breakdown) return;
    
    // Initialize Chart.js first
    await initChart();
    
    if (!Chart) {
        console.error('Chart.js is not available');
        return;
    }
    
    // Wait for DOM to update - give it more time
    await nextTick();
    await new Promise(resolve => setTimeout(resolve, 200));
    
    // Verify we're still on the breakdown tab
    if (activeTab.value !== 'event-breakdown') {
        return;
    }
    
    for (const [index, question] of eventBreakdown.value.breakdown.entries()) {
        // Check again if we're still on the right tab
        if (activeTab.value !== 'event-breakdown') {
            break;
        }
        
        if (question.chart_data && question.chart_data.data) {
            const canvasId = `chart-${question.column_name}-${index}`;
            
            // Verify canvas exists before trying to create chart
            const canvas = document.getElementById(canvasId);
            if (!canvas) {
                console.warn(`Canvas not found, skipping: ${canvasId}`);
                continue;
            }
            
            const chartType = question.chart_data.type === 'pie' || question.chart_data.type === 'phone' ? 'pie' : 'bar';
            await createChart(canvasId, question.chart_data, chartType);
            // Small delay between charts
            await new Promise(resolve => setTimeout(resolve, 100));
        }
    }
};

// Watch eventBreakdown to create charts when data loads
watch(() => eventBreakdown.value, async () => {
    if (eventBreakdown.value) {
        await createChartsForBreakdown();
    }
}, { deep: true });
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
                <!-- Tabs -->
                <div class="mb-6 bg-white shadow-sm sm:rounded-lg">
                    <div class="border-b border-gray-200">
                        <nav class="-mb-px flex space-x-8 px-6" aria-label="Tabs">
                            <button
                                @click="switchTab('weekly-report')"
                                :class="[
                                    activeTab === 'weekly-report'
                                        ? 'border-blue-500 text-blue-600'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
                                    'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm'
                                ]"
                            >
                                Weekly Report
                            </button>
                            <button
                                @click="switchTab('event-breakdown')"
                                :class="[
                                    activeTab === 'event-breakdown'
                                        ? 'border-blue-500 text-blue-600'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
                                    'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm'
                                ]"
                            >
                                Event Breakdown
                            </button>
                        </nav>
                    </div>
                </div>

                <!-- Weekly Report Tab Content -->
                <div v-show="activeTab === 'weekly-report'">
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
                            <div class="w-full flex justify-end gap-2">
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
                                <button
                                    @click="exportPdf"
                                    :disabled="!hasData"
                                    class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    Export PDF
                                </button>
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
                            <div class="text-sm font-medium text-gray-500">Average per Event</div>
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

                <!-- Event Breakdown Tab Content -->
                <div v-show="activeTab === 'event-breakdown'">
                    <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg mb-6">
                        <div class="p-6">
                            <h3 class="text-lg font-semibold mb-4">Event Breakdown Report</h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-end mb-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Select Event</label>
                                    <select
                                        v-model="selectedEventId"
                                        class="w-full p-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                        @change="loadEventBreakdown"
                                    >
                                        <option value="">-- Select an event --</option>
                                        <option
                                            v-for="event in availableEvents"
                                            :key="event.event_id"
                                            :value="event.event_id"
                                        >
                                            {{ event.event_name }} ({{ event.total_signups }} signups)
                                        </option>
                                    </select>
                                </div>
                                <div class="flex gap-2">
                                    <button
                                        @click="loadEventBreakdown"
                                        :disabled="!selectedEventId || isLoadingBreakdown"
                                        class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        {{ isLoadingBreakdown ? 'Loading...' : 'Load Breakdown' }}
                                    </button>
                                    <button
                                        @click="exportEventBreakdownPdf"
                                        :disabled="!selectedEventId || !eventBreakdown || isLoadingBreakdown"
                                        class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        Export PDF
                                    </button>
                                    <button
                                        @click="exportEndOfFilmTourPdf"
                                        :disabled="!selectedEventId || isLoadingBreakdown"
                                        class="px-4 py-2 bg-amber-600 text-white rounded-md hover:bg-amber-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                        title="Export end of film tour report (signups, tickets, demographics)"
                                    >
                                        End of film tour report
                                    </button>
                                    </div>
                            </div>

                            <!-- Demographics table: show table to copy and paste (aligns in Sheets/Excel) -->
                            <div class="mt-6 pt-6 border-t border-gray-200">
                                <h4 class="text-sm font-semibold text-gray-700 mb-2">Demographics table (copy & paste)</h4>
                                <p class="text-sm text-gray-500 mb-3">Select events, then show the table. Copy it (button or select + Ctrl+C) and paste into Excel or Google Sheets — columns will align. Event, STATUS (current/past), Total signups, then Gender, Age, Household income, Ski spend.</p>
                                <div class="flex flex-wrap gap-2 mb-3">
                                    <button
                                        type="button"
                                        @click="selectAllEventsForSpreadsheet"
                                        class="px-3 py-1.5 text-sm bg-gray-100 hover:bg-gray-200 rounded-md"
                                    >
                                        Select all
                                    </button>
                                    <button
                                        type="button"
                                        @click="clearEventsForSpreadsheet"
                                        class="px-3 py-1.5 text-sm bg-gray-100 hover:bg-gray-200 rounded-md"
                                    >
                                        Clear
                                    </button>
                                    <button
                                        type="button"
                                        @click="loadDemographicsTable"
                                        :disabled="!selectedEventIdsForSpreadsheet.length || isLoadingDemographicsTable"
                                        class="px-4 py-2 bg-teal-600 text-white rounded-md hover:bg-teal-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        <span v-if="isLoadingDemographicsTable">Loading...</span>
                                        <span v-else>Show demographics table ({{ selectedEventIdsForSpreadsheet.length }} selected)</span>
                                    </button>
                                    <a
                                        v-if="selectedEventIdsForSpreadsheet.length"
                                        :href="route('weekly-report.export-demographics-spreadsheet') + '?' + selectedEventIdsForSpreadsheet.map(id => 'event_ids[]=' + id).join('&')"
                                        target="_blank"
                                        class="px-4 py-2 text-sm border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"
                                    >
                                        Export CSV
                                    </a>
                                </div>
                                <div class="flex flex-wrap gap-x-4 gap-y-2 max-h-40 overflow-y-auto border border-gray-200 rounded p-3 bg-gray-50">
                                    <label
                                        v-for="event in availableEvents"
                                        :key="event.event_id"
                                        class="inline-flex items-center gap-2 text-sm cursor-pointer"
                                    >
                                        <input
                                            type="checkbox"
                                            :value="event.event_id"
                                            v-model="selectedEventIdsForSpreadsheet"
                                            class="rounded border-gray-300 text-teal-600 focus:ring-teal-500"
                                        />
                                        <span class="text-gray-700">{{ event.event_name }} ({{ event.total_signups }})</span>
                                    </label>
                                </div>
                                <!-- Copyable table -->
                                <div v-if="demographicsTableData.headers.length" class="mt-4">
                                    <div class="flex items-center gap-2 mb-2">
                                        <button
                                            type="button"
                                            @click="copyDemographicsTable"
                                            class="px-3 py-1.5 text-sm bg-teal-600 text-white rounded hover:bg-teal-700"
                                        >
                                            Copy table
                                        </button>
                                        <span class="text-xs text-gray-500">Then paste (Ctrl+V / Cmd+V) into a spreadsheet — columns will align.</span>
                                    </div>
                                    <div class="overflow-x-auto border border-gray-200 rounded bg-white">
                                        <table class="w-full text-sm border-collapse" id="demographics-copy-table">
                                            <thead>
                                                <tr>
                                                    <th v-for="(h, i) in demographicsTableData.headers" :key="i" class="border border-gray-200 bg-gray-100 p-2 text-left font-medium whitespace-nowrap">{{ h }}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="(row, ri) in demographicsTableData.rows" :key="ri">
                                                    <td v-for="(cell, ci) in row" :key="ci" class="border border-gray-200 p-2 whitespace-nowrap">{{ cell }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Event Breakdown Results -->
                    <div v-if="eventBreakdown && !isLoadingBreakdown" class="space-y-6">
                        <!-- Event Summary -->
                        <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <h3 class="text-lg font-semibold mb-4">{{ eventBreakdown.event.name }}</h3>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <div class="text-sm font-medium text-gray-500">Total Signups</div>
                                        <div class="text-2xl font-bold text-blue-600">{{ eventBreakdown.event.total_signups }}</div>
                                    </div>
                                    <div>
                                        <div class="text-sm font-medium text-gray-500">Total Questions</div>
                                        <div class="text-2xl font-bold text-gray-900">{{ eventBreakdown.breakdown.length }}</div>
                                    </div>
                                    <div>
                                        <div class="text-sm font-medium text-gray-500">Total Locations</div>
                                        <div class="text-2xl font-bold text-gray-900">{{ eventBreakdown.location_breakdown.length }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Location Breakdown -->
                        <div v-if="eventBreakdown.location_breakdown.length > 0" class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <h4 class="text-md font-semibold mb-4">Location Breakdown</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                    <div
                                        v-for="location in eventBreakdown.location_breakdown"
                                        :key="location.location_id"
                                        class="border border-gray-200 rounded-lg p-4"
                                    >
                                        <div class="flex justify-between items-start mb-2">
                                            <h5 class="font-medium text-gray-900">{{ location.location_name }}</h5>
                                            <span class="text-lg font-bold text-blue-600">{{ location.signups }}</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div
                                                class="bg-blue-600 h-2 rounded-full"
                                                :style="{ width: location.percentage + '%' }"
                                            ></div>
                                        </div>
                                        <div class="text-sm text-gray-500 mt-1">{{ location.percentage }}% of total</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Question Breakdown -->
                        <div v-for="(question, index) in eventBreakdown.breakdown" :key="index" class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <h4 class="text-md font-semibold mb-4">{{ question.question_text }}</h4>
                                <div class="text-sm text-gray-500 mb-4">
                                    Type: {{ question.question_type }} | 
                                    Responses: {{ question.total_responses }} / {{ eventBreakdown.event.total_signups }}
                                    <span v-if="eventBreakdown.event.total_signups > 0">
                                        ({{ ((question.total_responses / eventBreakdown.event.total_signups) * 100).toFixed(1) }}%)
                                    </span>
                                </div>
                                
                                <!-- Chart for Country, Mobile Number, Age, Gender -->
                                <div v-if="question.chart_data && question.chart_data.data" class="mb-6 flex justify-center items-center">
                                    <div class="w-full max-w-2xl flex justify-center" style="max-height: 400px;">
                                        <canvas :id="`chart-${question.column_name}-${index}`"></canvas>
                                    </div>
                                </div>
                                
                                <div v-if="question.responses.length > 0" class="space-y-3">
                                    <div
                                        v-for="(response, respIndex) in question.responses"
                                        :key="respIndex"
                                        class="border border-gray-200 rounded-lg p-4"
                                    >
                                        <div class="flex justify-between items-center mb-2">
                                            <span class="font-medium text-gray-900">{{ formatResponseValue(response.value) }}</span>
                                            <span class="text-lg font-bold text-blue-600">{{ response.count || 0 }}</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div
                                                class="bg-blue-600 h-2 rounded-full"
                                                :style="{ width: (response.percentage || 0) + '%' }"
                                            ></div>
                                        </div>
                                        <div class="text-sm text-gray-500 mt-1">{{ response.percentage || 0 }}% of total signups</div>
                                    </div>
                                </div>
                                <div v-else class="text-gray-500 text-sm italic">
                                    No responses for this question
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Loading State -->
                    <div v-if="isLoadingBreakdown" class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div class="p-6 text-center">
                            <div class="text-gray-500">Loading event breakdown...</div>
                        </div>
                    </div>

                    <!-- No Event Selected -->
                    <div v-if="!eventBreakdown && !isLoadingBreakdown && !selectedEventId" class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div class="p-6 text-center">
                            <div class="text-gray-500 text-lg mb-2">Select an event to view breakdown</div>
                            <div class="text-gray-400 text-sm">
                                Choose an event from the dropdown above to see detailed question responses and statistics.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
