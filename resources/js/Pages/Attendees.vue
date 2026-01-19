<script setup>
import { ref, computed } from 'vue';
import Swal from 'sweetalert2';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { debounce } from 'lodash';

const props = defineProps({
    event: Object,
    attendees: Array,
    form: Object
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

// ✅ Extract column names (exclude unwanted columns)
const columnHeaders = computed(() => {
    if (props.attendees.length > 0) {
        const columns = Object.keys(props.attendees[0]).filter(col => !["created_at", "updated_at", "id", "event_id", "location_id", "events_location", "mobile_number_format"].includes(col));
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

// ✅ Show export modal
const showExportModalDialog = () => {
    if (filteredAttendees.value.length === 0) {
        Swal.fire('No Data', 'No attendees found to export.', 'warning');
        return;
    }
    exportTags.value = '';
    defaultSourceWord.value = 'WM'; // Reset to default
    showExportModal.value = true;
};

// ✅ Export filtered data to CSV with tags
const exportToCSV = () => {
    if (filteredAttendees.value.length === 0) {
        Swal.fire('No Data', 'No attendees found to export.', 'warning');
        return;
    }

    let csvContent = "data:text/csv;charset=utf-8,";

    // Add headers using questions instead of column names, plus Opt In Date and Tags columns
    const headers = [
        ...columnHeaders.value.map(col => `"${getQuestionText(col)}"`), 
        '"Opt In Date"',
        '"Tags"'
    ];
    csvContent += headers.join(",") + "\n";

    // Process manual tags - convert to uppercase and split by comma
    const manualTags = exportTags.value
        .split(',')
        .map(tag => tag.trim().toUpperCase())
        .filter(tag => tag);

    // Add data rows
    filteredAttendees.value.forEach(attendee => {
        const dataRow = columnHeaders.value.map(col => `"${attendee[col] || ''}"`);
        
        // Add Opt In Date (formatted created_at) before Tags
        const optInDate = formatOptInDate(attendee.created_at);
        dataRow.push(`"${optInDate}"`);
        
        // Generate automated tags for this attendee
        const automatedTags = generateAutomatedTags(attendee);
        
        // Combine automated and manual tags
        const allTags = [...automatedTags, ...manualTags];
        const finalTags = allTags.join(', ');
        
        dataRow.push(`"${finalTags}"`); // Add tags column
        csvContent += dataRow.join(",") + "\n";
    });

    // Create a downloadable link
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", `attendees_${props.event.event_name}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    // Close modal and show success message
    showExportModal.value = false;
    Swal.fire('Success!', 'CSV file has been exported with tags.', 'success');
};

// ✅ Close export modal
const closeExportModal = () => {
    showExportModal.value = false;
    exportTags.value = '';
    defaultSourceWord.value = 'WM'; // Reset to default
};

// ✅ Extract location name from Location Name column
const extractLocationName = (locationName) => {
    if (!locationName) return '';
    // Get the part before the dash and trim whitespace
    const parts = locationName.split(' - ');
    return parts[0] ? parts[0].trim() : locationName.trim();
};

// ✅ Generate automated tags based on location and default word
const generateAutomatedTags = (attendee) => {
    const locationName = attendee.location_name || '';
    const extractedLocation = extractLocationName(locationName);
    const eventYear = props.event?.event_year || new Date().getFullYear();
    
    const automatedTags = [];
    
    if (extractedLocation) {
        // Check if event country is USA or CANADA
        const eventCountry = (props.event?.event_country || '').toUpperCase();
        const isUsaOrCanada = ['USA', 'CANADA', 'USA & CANADA'].includes(eventCountry);
        
        // Extract state from location (last 2-3 letter word) for USA/CANADA
        let state = '';
        let locationWithoutState = extractedLocation.toUpperCase();
        
        if (isUsaOrCanada) {
            const locationParts = extractedLocation.toUpperCase().split(' ').filter(Boolean);
            if (locationParts.length > 1) {
                const lastPart = locationParts[locationParts.length - 1];
                // Check if last part is a state code (2-3 uppercase letters)
                if (/^[A-Z]{2,3}$/.test(lastPart)) {
                    state = lastPart;
                    locationWithoutState = locationParts.slice(0, -1).join(' ').trim();
                }
            }
        }
        
        // Format tags
        const showTagLocation = isUsaOrCanada && state 
            ? `${locationWithoutState}, ${state}` 
            : extractedLocation.toUpperCase();
        const sourceTagLocation = isUsaOrCanada && state 
            ? locationWithoutState 
            : extractedLocation.toUpperCase();
        
        // Add SHOW - {LOCATION} tag
        automatedTags.push(`SHOW - ${showTagLocation}`);
        
        // Add SOURCE - {DEFAULT_WORD} {LOCATION} COMP {YEAR} tag (without state)
        automatedTags.push(`SOURCE - ${defaultSourceWord.value.toUpperCase()} ${sourceTagLocation} COMP ${eventYear}`);
    }
    
    return automatedTags;
};

// ✅ Format created_at date as Opt In Date with time
const formatOptInDate = (dateString) => {
    if (!dateString) return '';
    
    try {
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return dateString; // Return original if invalid
        
        // Format as YYYY-MM-DD HH:MM:SS (standard date and time format)
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        const seconds = String(date.getSeconds()).padStart(2, '0');
        
        return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
    } catch (e) {
        console.error("Error formatting opt in date:", e);
        return dateString;
    }
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
                            <!-- ✅ Export Button -->
                            <button 
                                @click="showExportModalDialog" 
                                class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-700"
                                title="Export to CSV with Tags"
                            >
                                <i class="fa-solid fa-file-csv"></i>
                            </button>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full border-collapse border border-gray-300">
                                <thead class="bg-gray-200 sticky top-0">
                                    <tr>
                                        <th v-for="(col, index) in columnHeaders" :key="index" class="border border-gray-300 p-2 whitespace-nowrap">
                                            {{ getQuestionText(col) }}
                                        </th>
                                        <th class="border border-gray-300 p-2">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(attendee, index) in paginatedAttendees" :key="index" class="text-left even:bg-gray-100">
                                        <td v-for="(col, index) in columnHeaders" :key="index" class="border border-gray-300 p-2 whitespace-nowrap overflow-hidden text-ellipsis">
                                            {{ attendee[col] }}
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
                    </div>
                </div>
            </div>
        </div>

        <!-- Export Tags Modal -->
        <div v-if="showExportModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-md w-full mx-4">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">Export CSV with Tags</h3>
                    <button @click="closeExportModal" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                
                <div class="space-y-4">
                    <p class="text-gray-600">
                        Configure automated tags and add additional manual tags for the exported CSV file.
                    </p>

                    <!-- Default Source Word Input -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Default Source Word
                        </label>
                        <input 
                            type="text" 
                            v-model="defaultSourceWord"
                            class="w-full border rounded px-3 py-2"
                            placeholder="e.g., WM, RUNNATION, etc."
                        />
                        <p class="text-sm text-gray-500 mt-1">
                            This will be used in the SOURCE tag: SOURCE - {WORD} {LOCATION} COMP {{ props.event?.event_year || new Date().getFullYear() }}
                        </p>
                    </div>

                    <!-- Automated Tags Preview -->
                    <div class="mb-4 p-3 bg-blue-50 rounded-lg">
                        <h4 class="font-semibold text-blue-800 mb-2">Automated Tags Preview:</h4>
                        <div class="text-sm text-blue-700">
                            <div v-if="filteredAttendees.length > 0">
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
                            <div v-else class="text-gray-500">
                                No attendees found to preview
                            </div>
                        </div>
                    </div>

                    <!-- Manual Tags Input -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Additional Manual Tags (comma-separated)
                        </label>
                        <input 
                            type="text" 
                            v-model="exportTags"
                            class="w-full border rounded px-3 py-2"
                            placeholder="e.g., 2025, FILM TOUR - WARREN MILLER, SPECIAL EVENT"
                        />
                        <p class="text-sm text-gray-500 mt-1">
                            Enter additional tags separated by commas. These will be added to the automated tags.
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
                            @click="exportToCSV"
                            class="px-4 py-2 bg-green-500 text-white rounded hover:bg-green-600"
                        >
                            <i class="fa-solid fa-file-csv mr-2"></i>
                            Export CSV
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
