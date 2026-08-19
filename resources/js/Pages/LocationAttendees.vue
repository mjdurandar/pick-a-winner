<script setup>
import { ref, computed, onMounted } from 'vue';
import Swal from 'sweetalert2';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { debounce } from 'lodash';
import axios from 'axios';

const props = defineProps({
    event: Object,
    location: Object,
    attendees: Array,
    prizes: Array,
    form: Object
});

const searchQuery = ref("");
const debouncedSearchValue = ref('');
const currentPage = ref(1);
const itemsPerPage = 20;
const selectedAttendee = ref(null);
const prizeName = ref('');
const showWinnerModal = ref(false);
const showEditPrizeModal = ref(false);
const selectedPrize = ref(null);
const editPrizeName = ref('');
const mailchimpSettings = ref({
    film_tour: '',
    default_tags: ''
});

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
        const columns = Object.keys(props.attendees[0]).filter(col => !["created_at", "updated_at", "id", "event_id", "location_id", "events_location", "mobile_number_format", "interest_tags"].includes(col));
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

// ✅ Check if attendee is a winner
const isWinner = (attendee) => {
    return props.prizes.some(prize => prize.winner_email === attendee.email_address);
};

// ✅ Get prize name for winner
const getWinnerPrize = (attendee) => {
    const prize = props.prizes.find(prize => prize.winner_email === attendee.email_address);
    return prize ? prize.prize_name : '';
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

// ✅ Fetch Mailchimp settings for tag generation
const fetchMailchimpSettings = async () => {
    try {
        const response = await axios.get(route('mailchimp.autosync.settings'), {
            params: {
                event_id: props.event.id
            }
        });
        const { settings } = response.data;
        mailchimpSettings.value = {
            film_tour: settings.film_tour || '',
            default_tags: Array.isArray(settings.default_tags) ? settings.default_tags.join(', ') : settings.default_tags || ''
        };
    } catch (error) {
        console.error('Failed to fetch Mailchimp settings:', error);
        // Set defaults if fetch fails
        mailchimpSettings.value = {
            film_tour: 'WM',
            default_tags: ''
        };
    }
};

// ✅ Generate location-specific Mailchimp tags
// SOURCE tag never includes state. SHOW: USA only = include state (e.g. SHOW - DENVER, CO); Australia/NZ/other = location only, no state.
const generateLocationTags = (sourceType = 'signup_form') => {
    const tags = [];
    const filmTour = mailchimpSettings.value.film_tour || 'WM';
    const year = props.event?.event_year ?? new Date().getFullYear();
    const locationName = props.location.name;
    // Use event country for tag logic: only USA & Canada events get state in SHOW tag; Australia/NZ/other never do.
    const eventCountry = (props.event.event_country || props.location.country || 'Other').toString().trim();
    const locationCountryUpper = (props.location.country || props.event.event_country || 'Other').toString().trim().toUpperCase();
    const eventCountryUpper = eventCountry.toUpperCase();
    const isUSA = ['USA', 'USA & CANADA', 'USA AND CANADA'].includes(eventCountryUpper);
    
    // Everything before " - " as the location label (e.g. "Bozeman, MT - ..." -> "BOZEMAN, MT")
    const fullLocationTag = locationName.split(' - ')[0].trim().toUpperCase();
    const locationState = (props.location.state || '').toString().trim();

    // SOURCE tag: never include state — strip 2–3 letter abbrev (NSW, VIC) and/or location.state (e.g. Victoria, New South Wales)
    let locationNoState = (fullLocationTag.replace(/,?\s+[A-Z]{2,3}$/i, '').trim()) || fullLocationTag;
    if (locationState) {
        const stateEsc = locationState.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        locationNoState = (locationNoState.replace(new RegExp(`,?\\s*${stateEsc}$`, 'i'), '').trim()) || locationNoState;
    }
    const sourceTagLocation = locationNoState || fullLocationTag;

    // SHOW tag: USA only = include state (e.g. SHOW - DENVER, CO). Australia/NZ/other = location only, no state (e.g. SHOW - SYDNEY, MELBOURNE).
    let showTagLocation = sourceTagLocation;
    if (isUSA) {
        const stateFromLocation = (props.location.state || '').toString().trim().toUpperCase();
        const matchStateInName = fullLocationTag.match(/,?\s+([A-Z]{2,3})$/i);
        const stateInName = matchStateInName ? matchStateInName[1].toUpperCase() : '';
        const state = stateInName || stateFromLocation;
        if (state) {
            const base = (fullLocationTag.replace(/,?\s+[A-Z]{2,3}$/i, '').trim()) || fullLocationTag;
            showTagLocation = `${base}, ${state}`;
        }
    }
    
    // Add COUNTRY tag (for reporting)
    tags.push(`COUNTRY - ${locationCountryUpper}`);
    
    tags.push(`SHOW - ${showTagLocation}`);
    
    // SOURCE: TIX for ticket data, COMP for sign-up form entries (no state)
    const sourceWord = sourceType === 'ticket_data' ? 'TIX' : 'COMP';
    tags.push(`SOURCE - ${filmTour.toUpperCase()} ${sourceTagLocation} ${sourceWord} ${year}`);
    
    // Add any default tags if they exist
    if (mailchimpSettings.value.default_tags) {
        const defaultTags = mailchimpSettings.value.default_tags
            .split(',')
            .map(tag => tag.trim())
            .filter(tag => tag);
        tags.push(...defaultTags);
    }
    
    return tags;
};

// ✅ Initialize component
onMounted(() => {
    fetchMailchimpSettings();
});

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
            router.delete(route('attendees.destroyFromLocation', { 
                attendee: attendeeId, 
                event: eventId, 
                location: props.location.id 
            }), {
                onSuccess: () => {
                    Swal.fire('Deleted!', 'The attendee has been removed.', 'success');
                }
            });
        }
    });
};

// ✅ Mark attendee as winner
const markAsWinner = (attendee) => {
    selectedAttendee.value = attendee;
    prizeName.value = '';
    showWinnerModal.value = true;
};

// ✅ Confirm winner selection
const confirmWinner = () => {
    if (!selectedAttendee.value || !prizeName.value.trim()) {
        Swal.fire('Error', 'Please enter a prize name.', 'error');
        return;
    }

    const data = {
        prize_name: prizeName.value.trim(),
        event_id: props.event.id,
        location_id: props.location.id,
        winner_name: `${selectedAttendee.value.first_name} ${selectedAttendee.value.last_name}`,
        winner_email: selectedAttendee.value.email_address,
        winner_mobile_number: selectedAttendee.value.mobile_number || '',
    };

    router.post(route('prize.store'), data, {
        onSuccess: () => {
            showWinnerModal.value = false;
            Swal.fire('Success!', `${selectedAttendee.value.first_name} has been marked as a winner!`, 'success').then(() => {
                // Reload the page to show updated prize information
                router.reload();
            });
            selectedAttendee.value = null;
            prizeName.value = '';
        },
        onError: (errors) => {
            console.error('Error marking winner:', errors);
            Swal.fire('Error!', 'There was an issue marking the winner.', 'error');
        }
    });
};

// ✅ Close winner modal
const closeWinnerModal = () => {
    showWinnerModal.value = false;
    selectedAttendee.value = null;
    prizeName.value = '';
};

// ✅ Go back to location page
const goBackToLocation = () => {
    router.get(route('location.locationpage', props.event.id));
};

// Export winner/prize data for this location to CSV – only include rows with a winner email
const exportWinnersToCSV = () => {
    const allPrizes = props.prizes || [];
    const hasWinnerEmail = (p) => {
        const email = (p.winner_email || '').toString().trim();
        return email && email !== 'No Winner Yet';
    };
    const prizes = allPrizes.filter(hasWinnerEmail);
    if (prizes.length === 0) {
        Swal.fire('No Data', allPrizes.length ? 'No winners with an email to export.' : 'No winner data for this location to export.', 'info');
        return;
    }
    const locationName = props.location?.name ?? 'Location';
    const headers = ['Location', 'Prize Name', 'Winner Name', 'Winner Email', 'Winner Mobile'];
    const escape = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    let csv = 'data:text/csv;charset=utf-8,' + headers.map(escape).join(',') + '\n';
    prizes.forEach((p) => {
        const row = [
            locationName,
            p.prize_name ?? '',
            p.winner ?? '',
            p.winner_email ?? '',
            p.winner_mobile_number ?? ''
        ];
        csv += row.map(escape).join(',') + '\n';
    });
    const link = document.createElement('a');
    link.setAttribute('href', encodeURI(csv));
    link.setAttribute('download', `winners_${(locationName || 'location').replace(/\s+/g, '_').replace(/[^a-zA-Z0-9_-]/g, '_')}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    Swal.fire('Exported', `Exported ${prizes.length} winner(s) to CSV.`, 'success');
};

// ✅ Edit prize
const editPrize = (attendee) => {
    const prize = props.prizes.find(p => p.winner_email === attendee.email_address);
    if (prize) {
        selectedPrize.value = prize;
        editPrizeName.value = prize.prize_name;
        showEditPrizeModal.value = true;
    }
};

// ✅ Update prize name
const updatePrizeName = () => {
    if (!selectedPrize.value || !editPrizeName.value.trim()) {
        Swal.fire('Error', 'Please enter a prize name.', 'error');
        return;
    }

    const data = {
        prize_name: editPrizeName.value.trim()
    };

    router.patch(route('prize.update', selectedPrize.value.id), data, {
        onSuccess: () => {
            showEditPrizeModal.value = false;
            Swal.fire('Success!', 'Prize name updated successfully!', 'success').then(() => {
                router.reload();
            });
            selectedPrize.value = null;
            editPrizeName.value = '';
        },
        onError: (errors) => {
            console.error('Error updating prize:', errors);
            Swal.fire('Error!', 'There was an issue updating the prize name.', 'error');
        }
    });
};

// ✅ Close edit prize modal
const closeEditPrizeModal = () => {
    showEditPrizeModal.value = false;
    selectedPrize.value = null;
    editPrizeName.value = '';
};

// Show detailed results modal

</script>

<template>
    <Head title="Location Attendees" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div class="flex items-center space-x-3">
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">
                        Attendees for {{ location.name }} - {{ event.event_name }}
                    </h2>
                </div>
                <button 
                    @click="goBackToLocation"
                    class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-700"
                >
                    <i class="fa-solid fa-arrow-left"></i> Back to Locations
                </button>
            </div>
        </template>

        <div class="p-2 pb-5 pt-5">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <!-- Summary Stats -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                            <div class="bg-blue-100 p-4 rounded-lg">
                                <h3 class="text-lg font-semibold text-blue-800">Total Attendees</h3>
                                <p class="text-2xl font-bold text-blue-900">{{ attendees.length }}</p>
                            </div>
                            <div class="bg-green-100 p-4 rounded-lg">
                                <h3 class="text-lg font-semibold text-green-800">Winners</h3>
                                <p class="text-2xl font-bold text-green-900">{{ prizes.length }}</p>
                            </div>
                        </div>

                        <!-- Mailchimp Tags Info -->
                        <div class="bg-purple-100 p-4 rounded-lg mb-6">
                            <h3 class="text-lg font-semibold text-purple-800 mb-2">Mailchimp Export Tags</h3>
                            <p class="text-sm text-purple-700 mb-2">The following tags will be included in the CSV export:</p>
                            <div class="flex flex-wrap gap-2">
                                <span 
                                    v-for="tag in generateLocationTags()" 
                                    :key="tag" 
                                    class="bg-purple-200 text-purple-800 px-2 py-1 rounded text-sm"
                                >
                                    {{ tag }}
                                </span>
                            </div>
                        </div>

                        <div class="flex justify-between mb-3">
                            <!-- ✅ Search Bar -->
                            <input
                                :value="searchQuery"
                                type="text"
                                placeholder="Search attendees..."
                                class="w-full md:w-1/3 p-2 border rounded"
                                @input="handleSearchInput"
                            />
                            <div class="flex gap-2 flex-wrap">
                                <!-- ✅ Export winners for this location -->
                                <button 
                                    v-if="(prizes || []).length > 0"
                                    @click="exportWinnersToCSV" 
                                    class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700"
                                >
                                    <i class="fa-solid fa-trophy"></i> Export winners
                                </button>
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
                                        <th class="border border-gray-300 p-2">Winner Status</th>
                                        <th class="border border-gray-300 p-2">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(attendee, index) in paginatedAttendees" :key="index"
                                        class="text-left even:bg-gray-100"
                                        :class="{ 'bg-green-50': isWinner(attendee) }">
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
                                        <td class="border border-gray-300 p-2 text-center whitespace-nowrap">
                                            <span v-if="isWinner(attendee)" class="bg-green-500 text-white px-2 py-1 rounded text-sm">
                                                🏆 Winner: {{ getWinnerPrize(attendee) }}
                                            </span>
                                            <span v-else class="text-gray-500 text-sm">Not a winner</span>
                                        </td>
                                        <td class="text-center content-center whitespace-nowrap">
                                            <div class="flex gap-2 justify-center">
                                                <button 
                                                    v-if="!isWinner(attendee)"
                                                    @click="markAsWinner(attendee)"
                                                    class="bg-yellow-500 text-white px-2 py-1 rounded hover:bg-yellow-700 text-sm"
                                                    title="Mark as Winner"
                                                >
                                                    <i class="fa-solid fa-trophy"></i>
                                                </button>
                                                <button 
                                                    v-if="isWinner(attendee)"
                                                    @click="editPrize(attendee)"
                                                    class="bg-blue-500 text-white px-2 py-1 rounded hover:bg-blue-700 text-sm"
                                                    title="Edit Prize"
                                                >
                                                    <i class="fa-solid fa-edit"></i>
                                                </button>
                                                <button 
                                                    @click="deleteAttendee(attendee.id, event.id)"
                                                    class="bg-red-500 text-white px-2 py-1 rounded hover:bg-red-700 text-sm"
                                                    title="Delete Attendee"
                                                >
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </div>
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
                            No attendees have registered for this location.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Winner Selection Modal -->
        <div v-if="showWinnerModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-md w-full mx-4">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">Mark as Winner</h3>
                    <button @click="closeWinnerModal" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                
                <div class="space-y-4">
                    <div>
                        <p class="text-gray-600 mb-2">
                            Selected Attendee: <strong>{{ selectedAttendee?.first_name }} {{ selectedAttendee?.last_name }}</strong>
                        </p>
                        <p class="text-gray-600 mb-4">
                            Email: {{ selectedAttendee?.email_address }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Prize Name
                        </label>
                        <input 
                            v-model="prizeName"
                            type="text" 
                            class="w-full border rounded px-3 py-2"
                            placeholder="Enter prize name..."
                            @keyup.enter="confirmWinner"
                        />
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button 
                            @click="closeWinnerModal"
                            class="px-4 py-2 border rounded text-gray-600 hover:bg-gray-50"
                        >
                            Cancel
                        </button>
                        <button 
                            @click="confirmWinner"
                            class="px-4 py-2 bg-yellow-500 text-white rounded hover:bg-yellow-600"
                            :disabled="!prizeName.trim()"
                        >
                            <i class="fa-solid fa-trophy mr-2"></i>
                            Mark as Winner
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Prize Modal -->
        <div v-if="showEditPrizeModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-md w-full mx-4">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">Edit Prize Name</h3>
                    <button @click="closeEditPrizeModal" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                
                <div class="space-y-4">
                    <div>
                        <p class="text-gray-600 mb-2">
                            Winner: <strong>{{ selectedPrize?.winner }}</strong>
                        </p>
                        <p class="text-gray-600 mb-4">
                            Current Prize: {{ selectedPrize?.prize_name }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Prize Name
                        </label>
                        <input 
                            v-model="editPrizeName"
                            type="text" 
                            class="w-full border rounded px-3 py-2"
                            placeholder="Enter new prize name..."
                            @keyup.enter="updatePrizeName"
                        />
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button 
                            @click="closeEditPrizeModal"
                            class="px-4 py-2 border rounded text-gray-600 hover:bg-gray-50"
                        >
                            Cancel
                        </button>
                        <button 
                            @click="updatePrizeName"
                            class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
                            :disabled="!editPrizeName.trim()"
                        >
                            <i class="fa-solid fa-save mr-2"></i>
                            Update Prize
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
