<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import Swal from 'sweetalert2';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { debounce } from 'lodash';
import axios from 'axios';

const props = defineProps({
    event: Object,
    attendees: Array,
    form: Object,
    prizes: { type: Array, default: () => [] },
    resubBreakdown: { type: Array, default: () => [] },
    totalResubscribed: { type: Number, default: 0 }
});

const searchQuery = ref("");
const debouncedSearchValue = ref('');
const currentPage = ref(1);
const itemsPerPage = 20;

// Export tags modal
const showExportModal = ref(false);
const exportTags = ref('');
const defaultSourceWord = ref('WM'); // Default word for SOURCE tag

// Debounce the search to prevent excessive filtering
const updateDebouncedSearch = debounce((value) => {
    debouncedSearchValue.value = value.toLowerCase();
}, 300);

// Watch for search query changes
const handleSearchInput = (event) => {
    searchQuery.value = event.target.value;
    updateDebouncedSearch(event.target.value);
    currentPage.value = 1; // Reset to first page on search
};

const excludedExportColumns = ["created_at", "updated_at", "id", "event_id", "location_id", "events_location", "mobile_number_format", "interest_tags"];

// ✅ Extract column names for display (exclude unwanted columns)
const columnHeaders = computed(() => {
    if (props.attendees.length > 0) {
        const columns = Object.keys(props.attendees[0]).filter(col => !excludedExportColumns.includes(col));
        return columns;
    }
    return [];
});

// ✅ Get question text for a column name
const getQuestionText = (columnName) => {
    if (!props.form || !props.form.questions) return formatHeader(columnName);
    
    const questions = JSON.parse(props.form.questions);
    const question = questions.find(q => q.column_name === columnName);
    return question ? question.text : formatHeader(columnName);
};

// ✅ Format column headers (fallback for columns without questions)
const formatHeader = (header) => {
    return header.replace(/_/g, " ").replace(/\b\w/g, (char) => char.toUpperCase());
};

// ✅ Optimized filtered attendees based on search query
const filteredAttendees = computed(() => {
    if (!debouncedSearchValue.value) {
        return props.attendees;
    }

    const excludedColumns = ["created_at", "updated_at", "id", "event_id", "events_location", "mobile_number_format"];
    
    return props.attendees.filter(attendee => {
        return Object.entries(attendee)
            .filter(([key]) => !excludedColumns.includes(key))
            .some(([key, value]) => {
                if (!value) return false;
                if (key === "gender") {
                    return value.toLowerCase().trim() === debouncedSearchValue.value.trim();
                }
                return value.toString().toLowerCase().includes(debouncedSearchValue.value);
            });
    });
});

// ✅ Paginate filtered attendees
const paginatedAttendees = computed(() => {
    const start = (currentPage.value - 1) * itemsPerPage;
    return filteredAttendees.value.slice(start, start + itemsPerPage);
});

// ✅ Total pages
const totalPages = computed(() => {
    return Math.ceil(filteredAttendees.value.length / itemsPerPage);
});

// ✅ Navigate pages
const goToPage = (page) => {
    if (page >= 1 && page <= totalPages.value) {
        currentPage.value = page;
    }
};

// ✅ Open a server-side export URL in a new tab (browser triggers the download)
const openExportUrl = (url) => {
    window.open(url, '_blank');
};

// Export dropdown state
const showExportDropdown = ref(false);
const exportDropdownRef = ref(null);

const toggleExportDropdown = () => {
    showExportDropdown.value = !showExportDropdown.value;
};
const closeExportDropdown = () => {
    showExportDropdown.value = false;
};
const handleExportDropdownOutsideClick = (e) => {
    if (exportDropdownRef.value && !exportDropdownRef.value.contains(e.target)) {
        closeExportDropdown();
    }
};
onMounted(() => document.addEventListener('mousedown', handleExportDropdownOutsideClick));
onBeforeUnmount(() => document.removeEventListener('mousedown', handleExportDropdownOutsideClick));

// Tracks which export the modal is configuring: 'all' | 'win' | 'tix'
const pendingExportType = ref(null);

const openExportModal = (type) => {
    pendingExportType.value = type;
    exportTags.value = '';
    defaultSourceWord.value = 'WM';
    showExportModal.value = true;
};

const exportTypeLabel = computed(() => {
    if (pendingExportType.value === 'all') return 'All Data (Win/Tix)';
    if (pendingExportType.value === 'tix') return 'Tix Data';
    if (pendingExportType.value === 'win') return 'Win Data';
    return '';
});

// Wrappers used by dropdown items so the menu closes after a selection
const exportAllWinTix = () => {
    closeExportDropdown();
    openExportModal('all');
};
const exportWinDataCsv = () => {
    closeExportDropdown();
    openExportModal('win');
};
const exportTixDataCsv = () => {
    closeExportDropdown();
    openExportModal('tix');
};
const exportWinnersFiltered = () => {
    closeExportDropdown();
    exportWinnersToCSV();
};

// Triggered by the modal's confirm button — routes to the right endpoint with tag config
const confirmExport = () => {
    const params = {
        eventId: props.event.id,
        defaultSourceWord: defaultSourceWord.value || 'WM',
        exportTags: exportTags.value || '',
    };
    let routeName = 'attendees.exportCsv';
    if (pendingExportType.value === 'all') routeName = 'attendees.exportAllWinTix';
    else if (pendingExportType.value === 'tix') routeName = 'attendees.exportTickets';
    openExportUrl(route(routeName, params));
    closeExportModal();
    Swal.fire('Export started', 'Your CSV is downloading.', 'success');
};

// ✅ Close export modal
const closeExportModal = () => {
    showExportModal.value = false;
    exportTags.value = '';
    defaultSourceWord.value = 'WM'; // Reset to default
};

// Export all winners to CSV (inside Database page) – only include rows with a winner email
const exportWinnersToCSV = () => {
    const allPrizes = props.prizes || [];
    const hasWinnerEmail = (p) => {
        const email = (p.winner_email || '').toString().trim();
        return email && email !== 'No Winner Yet';
    };
    const prizes = allPrizes.filter(hasWinnerEmail);
    if (prizes.length === 0) {
        Swal.fire('No Data', allPrizes.length ? 'No winners with an email to export.' : 'No winner data to export.', 'info');
        return;
    }
    const headers = ['Location', 'Prize Name', 'Winner Name', 'Winner Email', 'Winner Mobile'];
    const escape = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    let csv = 'data:text/csv;charset=utf-8,' + headers.map(escape).join(',') + '\n';
    prizes.forEach((p) => {
        const row = [
            p.location_name ?? '',
            p.prize_name ?? '',
            p.winner ?? '',
            p.winner_email ?? '',
            p.winner_mobile_number ?? ''
        ];
        csv += row.map(escape).join(',') + '\n';
    });
    const link = document.createElement('a');
    link.setAttribute('href', encodeURI(csv));
    link.setAttribute('download', `winners_${(props.event?.event_name || 'event').replace(/\s+/g, '_')}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    Swal.fire('Exported', `Exported ${prizes.length} winner(s) to CSV.`, 'success');
};

// ✅ Delete Attendee with Confirmation
const deleteAttendee = (attendeeId, eventId) => {
    Swal.fire({
        title: 'Are you sure?',
        text: 'This action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('attendees.destroy', { attendee: attendeeId, event: eventId }), {
                onSuccess: () => {
                    Swal.fire('Deleted!', 'The attendee has been removed.', 'success');
                }
            });
        }
    });
};
</script>

<template>
    <Head title="Attendees" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Attendees for {{ event.event_name }}
            </h2>
        </template>

        <div class="p-2 pb-5 pt-5">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="flex justify-between mb-3">
                            <!-- ✅ Search Bar -->
                            <input
                                :value="searchQuery"
                                type="text"
                                placeholder="Search attendees..."
                                class="w-full md:w-1/3 p-2 border rounded"
                                @input="handleSearchInput"
                            />
                            <div class="flex gap-2">
                                <!-- ✅ Export Dropdown (consolidates all export options) -->
                                <div ref="exportDropdownRef" class="relative">
                                    <button
                                        @click="toggleExportDropdown"
                                        class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 inline-flex items-center gap-2"
                                        title="Choose what to export"
                                    >
                                        <i class="fa-solid fa-file-export"></i>
                                        Export
                                        <i class="fa-solid fa-chevron-down text-xs"></i>
                                    </button>
                                    <div
                                        v-if="showExportDropdown"
                                        class="absolute right-0 mt-2 w-72 bg-white border border-gray-200 rounded-md shadow-lg z-50 py-1"
                                    >
                                        <button
                                            @click="exportAllWinTix"
                                            class="w-full text-left px-4 py-2 hover:bg-gray-100 text-sm flex items-center gap-2"
                                            title="Combined sign-up + ticket data, deduplicated by email"
                                        >
                                            <i class="fa-solid fa-file-csv text-purple-600 w-4"></i>
                                            <span>All Data (Win/Tix) — CSV</span>
                                        </button>
                                        <button
                                            @click="exportWinDataCsv"
                                            class="w-full text-left px-4 py-2 hover:bg-gray-100 text-sm flex items-center gap-2"
                                        >
                                            <i class="fa-solid fa-file-csv text-amber-600 w-4"></i>
                                            <span>Win Data — CSV</span>
                                        </button>
                                        <button
                                            @click="exportTixDataCsv"
                                            class="w-full text-left px-4 py-2 hover:bg-gray-100 text-sm flex items-center gap-2"
                                        >
                                            <i class="fa-solid fa-file-csv text-sky-600 w-4"></i>
                                            <span>Tix Data — CSV</span>
                                        </button>
                                        <div class="border-t border-gray-100 my-1"></div>
                                        <button
                                            v-if="(prizes || []).length > 0"
                                            @click="exportWinnersFiltered"
                                            class="w-full text-left px-4 py-2 hover:bg-gray-100 text-sm flex items-center gap-2"
                                            title="Only winners that have an email"
                                        >
                                            <i class="fa-solid fa-trophy text-green-600 w-4"></i>
                                            <span>Winners with email — CSV</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full border-collapse border border-gray-300">
                                <thead class="bg-gray-200 sticky top-0">
                                    <tr>
                                        <th v-for="(col, index) in columnHeaders" :key="index" class="border border-gray-300 p-2 whitespace-nowrap">
                                            {{ getQuestionText(col) }}
                                        </th>
                                        <th class="border border-gray-300 p-2 whitespace-nowrap">Interest Tags</th>
                                        <th class="border border-gray-300 p-2">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(attendee, index) in paginatedAttendees" :key="index" class="text-left even:bg-gray-100">
                                        <td v-for="(col, index) in columnHeaders" :key="index" class="border border-gray-300 p-2 whitespace-nowrap overflow-hidden text-ellipsis">
                                            {{ attendee[col] }}
                                        </td>
                                        <td class="border border-gray-300 p-2 whitespace-nowrap">
                                            <div v-if="attendee.interest_tags && attendee.interest_tags.length" class="flex flex-wrap gap-1">
                                                <span
                                                    v-for="tag in attendee.interest_tags"
                                                    :key="tag"
                                                    class="inline-block px-2 py-0.5 text-xs font-semibold rounded bg-cyan-100 text-cyan-800 border border-cyan-200"
                                                >
                                                    {{ tag }}
                                                </span>
                                            </div>
                                            <span v-else class="text-gray-400 text-sm">—</span>
                                        </td>
                                        <td class="text-center content-center whitespace-nowrap">
                                            <button class="btn btn-danger m-1" @click="deleteAttendee(attendee.id, event.id)">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- ✅ Pagination Controls -->
                        <div class="flex justify-between items-center mt-4">
                            <button 
                                @click="goToPage(currentPage - 1)" 
                                :disabled="currentPage === 1" 
                                class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400 disabled:opacity-50"
                            >
                                Previous
                            </button>
                            
                            <span class="text-gray-700">Page {{ currentPage }} of {{ totalPages }}</span>
                            
                            <button 
                                @click="goToPage(currentPage + 1)" 
                                :disabled="currentPage === totalPages" 
                                class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400 disabled:opacity-50"
                            >
                                Next
                            </button>
                        </div>

                        <div v-if="attendees.length === 0" class="text-gray-600 text-center mt-4">
                            No attendees have registered for this event.
                        </div>

                        <!-- Newsletter resubscribes by location -->
                        <div v-if="(resubBreakdown || []).length > 0" class="mt-8 pt-6 border-t border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-800 mb-3">
                                <i class="fa-solid fa-rotate mr-2"></i> Newsletter resubscribes by location
                            </h3>
                            <p class="text-sm text-gray-600 mb-3">
                                How many previously unsubscribed contacts were re-added to the newsletter via the signup form, broken down by location.
                            </p>
                            <div class="overflow-x-auto">
                                <table class="w-full border-collapse border border-gray-300 text-sm">
                                    <thead class="bg-gray-100">
                                        <tr>
                                            <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Location</th>
                                            <th class="border border-gray-300 p-2 text-right whitespace-nowrap">Resubscribed</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="row in resubBreakdown"
                                            :key="row.location_id"
                                            class="text-left even:bg-gray-50"
                                        >
                                            <td class="border border-gray-300 p-2">{{ row.location_name || '—' }}</td>
                                            <td class="border border-gray-300 p-2 text-right">{{ row.total_resubscribed }}</td>
                                        </tr>
                                    </tbody>
                                    <tfoot>
                                        <tr class="bg-purple-50 font-semibold">
                                            <td class="border border-gray-300 p-2 text-right">Total</td>
                                            <td class="border border-gray-300 p-2 text-right text-purple-700">{{ totalResubscribed }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Export Tags Modal -->
        <div v-if="showExportModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-md w-full mx-4">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">Export {{ exportTypeLabel }} — Film Tour Code &amp; Tags</h3>
                    <button @click="closeExportModal" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>

                <div class="space-y-4">
                    <p class="text-gray-600">
                        Configure the tags that will be added to the <strong>{{ exportTypeLabel }}</strong> CSV.
                    </p>

                    <!-- Film Tour Code Input -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Film Tour Code
                        </label>
                        <input
                            type="text"
                            v-model="defaultSourceWord"
                            class="w-full border rounded px-3 py-2"
                            placeholder="e.g., WM, RUNNATION, etc."
                        />
                        <p class="text-sm text-gray-500 mt-1">
                            Used in the SOURCE tag: SOURCE - {CODE} {LOCATION} COMP {{ props.event?.event_year || new Date().getFullYear() }}
                        </p>
                    </div>

                    <!-- Automated Tags Preview -->
                    <div class="mb-4 p-3 bg-blue-50 rounded-lg">
                        <h4 class="font-semibold text-blue-800 mb-2">Automated Tags Preview:</h4>
                        <div class="text-sm text-blue-700">
                            <div class="mb-1">
                                <strong>SHOW - {LOCATION}</strong> (extracted from Location Name before the dash)
                            </div>
                            <div class="mb-1">
                                <strong>SOURCE - {{ defaultSourceWord.toUpperCase() }} {LOCATION} COMP {{ props.event?.event_year || new Date().getFullYear() }}</strong>
                            </div>
                            <div class="text-xs text-blue-600 mt-2">
                                Example: If Location Name is "Melbourne - Classic Cinema", it will generate:
                                <br>• SHOW - MELBOURNE
                                <br>• SOURCE - {{ defaultSourceWord.toUpperCase() }} MELBOURNE COMP {{ props.event?.event_year || new Date().getFullYear() }}
                            </div>
                        </div>
                    </div>

                    <!-- Default Tags Input -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Default Tags (comma-separated)
                        </label>
                        <input
                            type="text"
                            v-model="exportTags"
                            class="w-full border rounded px-3 py-2"
                            placeholder="e.g., 2025, FILM TOUR - WARREN MILLER, SPECIAL EVENT"
                        />
                        <p class="text-sm text-gray-500 mt-1">
                            Tags added to every row in addition to the automated ones above.
                        </p>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button
                            @click="closeExportModal"
                            class="px-4 py-2 border rounded text-gray-600 hover:bg-gray-50"
                        >
                            Cancel
                        </button>
                        <button
                            @click="confirmExport"
                            class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700"
                        >
                            <i class="fa-solid fa-file-csv mr-2"></i>
                            Export
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
