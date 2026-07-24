<script setup>
import Swal from 'sweetalert2';
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import PickaWinnerLayout from '@/Layouts/PickaWinnerLayout.vue';
import { Head } from '@inertiajs/vue3';
import { debounce } from 'lodash';
import { syncState, enqueue, removeItem, pruneSynced, processQueue, startAutoSync } from '@/stores/winnerSync';

// Track if we are editing an event
const isEditing = ref(false);

// ✅ Search Query and Pagination
const searchQuery = ref('');
const currentPage = ref(1);
const itemsPerPage = 20;
const debouncedSearchValue = ref('');

// ✅ Advanced Filter Options - Dynamic question-based filtering
const showFilters = ref(false);
const filters = ref([]); // Array of filter objects: { questionColumn: '', questionText: '', filterValue: '' }
const filterCondition = ref('AND'); // 'AND' or 'OR'

// ✅ Get all available questions for filtering (excluding system fields)
const availableQuestions = computed(() => {
    if (!props.form || !props.form.questions) return [];
    
    const questions = JSON.parse(props.form.questions || '[]');
    const systemFields = ['id', 'event_id', 'location_id', 'created_at', 'updated_at', 'events_location'];
    
    return questions.filter(question => 
        !systemFields.includes(question.column_name) &&
        props.attendees.length > 0 && 
        props.attendees[0][question.column_name] !== undefined
    );
});

// ✅ Get unique values for a specific question field
const getUniqueValuesForQuestion = (columnName) => {
    const values = [...new Set(props.attendees.map(a => a[columnName]).filter(v => v !== null && v !== undefined && v !== ''))];
    return values.sort();
};

// ✅ Add a new filter
const addFilter = () => {
    filters.value.push({
        questionColumn: '',
        questionText: '',
        filterValue: ''
    });
};

// ✅ Remove a filter
const removeFilter = (index) => {
    filters.value.splice(index, 1);
};

// ✅ Get question object by column name
const getQuestionByColumn = (columnName) => {
    return availableQuestions.value.find(q => q.column_name === columnName);
};

// ✅ Check if question has options (dropdown with choices)
const questionHasOptions = (columnName) => {
    const question = getQuestionByColumn(columnName);
    return question && question.type === 'dropdown' && question.options && question.options.length > 0;
};

// ✅ Get options for a question (or age ranges for Date of Birth)
const getQuestionOptions = (columnName) => {
    if (isDateOfBirthQuestion(columnName)) {
        return [
            'Under 18',
            '18+',
            'Over 60'
        ];
    }
    const question = getQuestionByColumn(columnName);
    return question && question.options ? question.options : [];
};

// ✅ Check if question is Date of Birth
const isDateOfBirthQuestion = (columnName) => {
    const question = getQuestionByColumn(columnName);
    if (!question) return false;
    // Check if text contains "Date of Birth" or column name is date_of_birth/dob
    const textLower = (question.text || '').toLowerCase();
    const columnLower = (question.column_name || '').toLowerCase();
    return textLower.includes('date of birth') || 
           columnLower.includes('date_of_birth') || 
           columnLower.includes('dob') ||
           textLower.includes('birthday') ||
           textLower.includes('birth date');
};

// ✅ Calculate age from date of birth
const calculateAge = (dateOfBirth) => {
    if (!dateOfBirth) return null;
    
    try {
        const birthDate = new Date(dateOfBirth);
        const today = new Date();
        let age = today.getFullYear() - birthDate.getFullYear();
        const monthDiff = today.getMonth() - birthDate.getMonth();
        
        // Adjust age if birthday hasn't occurred this year
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
            age--;
        }
        
        return age;
    } catch (e) {
        console.error('Error calculating age:', e);
        return null;
    }
};

// ✅ Check if age matches the selected age range
const ageMatchesRange = (age, ageRange) => {
    if (age === null || age === undefined) return false;
    
    switch (ageRange) {
        case 'Under 18':
            return age < 18;
        case '18+':
            return age >= 18 && age <= 60;
        case 'Over 60':
            return age > 60;
        default:
            return false;
    }
};

// ✅ Update question text when column is selected
const updateQuestionText = (filterIndex) => {
    const filter = filters.value[filterIndex];
    if (filter.questionColumn) {
        const question = getQuestionByColumn(filter.questionColumn);
        if (question) {
            filter.questionText = question.text;
        }
    } else {
        filter.questionText = '';
    }
    filter.filterValue = ''; // Reset filter value when question changes
};

// ✅ Get filter explanation text
const getFilterExplanation = computed(() => {
    const activeFilters = filters.value.filter(f => f.questionColumn && f.filterValue);
    
    if (activeFilters.length === 0) {
        return 'No filters applied. All attendees are eligible.';
    }
    
    const filterDescriptions = activeFilters.map(filter => {
        const question = getQuestionByColumn(filter.questionColumn);
        const questionText = question ? question.text : filter.questionColumn;
        
        // Special handling for Date of Birth - show as age range
        if (isDateOfBirthQuestion(filter.questionColumn)) {
            return `${questionText} (Age: ${filter.filterValue})`;
        }
        
        return `${questionText} = "${filter.filterValue}"`;
    });
    
    if (filterCondition.value === 'AND') {
        return `Only attendees matching ALL of the following filters will be eligible: ${filterDescriptions.join(' AND ')}`;
    } else {
        return `Attendees matching ANY of the following filters will be eligible: ${filterDescriptions.join(' OR ')}`;
    }
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

// ✅ Advanced filtering with search and filter options
const filteredAttendees = computed(() => {
    let filtered = props.attendees;

    // ✅ Apply search filter
    if (debouncedSearchValue.value) {
        filtered = filtered.filter(attendee => {
            const searchFields = [
                `${attendee.first_name} ${attendee.last_name}`,
                attendee.email_address,
                attendee.mobile_number,
                attendee.location_name
            ].map(field => (field || '').toLowerCase());

            return searchFields.some(field => field.includes(debouncedSearchValue.value));
        });
    }

    // ✅ Apply dynamic question-based filters
    const activeFilters = filters.value.filter(f => f.questionColumn && f.filterValue);
    
    if (activeFilters.length > 0) {
        filtered = filtered.filter(attendee => {
            if (filterCondition.value === 'AND') {
                // All filters must match
                return activeFilters.every(filter => {
                    // Special handling for Date of Birth - calculate age from date
                    if (isDateOfBirthQuestion(filter.questionColumn)) {
                        const dateOfBirth = attendee[filter.questionColumn];
                        if (!dateOfBirth) return false;
                        const age = calculateAge(dateOfBirth);
                        return ageMatchesRange(age, filter.filterValue);
                    }
                    
                    // Regular field filtering
                    const fieldValue = attendee[filter.questionColumn];
                    if (fieldValue === null || fieldValue === undefined || fieldValue === '') {
                        return false;
                    }
                    
                    // For dropdown fields with predefined options, use exact match
                    // For text fields, use substring match
                    if (questionHasOptions(filter.questionColumn)) {
                        // Exact match (case-insensitive) for dropdowns
                        return fieldValue.toString().toLowerCase().trim() === filter.filterValue.toLowerCase().trim();
                    } else {
                        // Substring match (case-insensitive) for text fields
                        return fieldValue.toString().toLowerCase().includes(filter.filterValue.toLowerCase());
                    }
                });
            } else {
                // OR: At least one filter must match
                return activeFilters.some(filter => {
                    // Special handling for Date of Birth - calculate age from date
                    if (isDateOfBirthQuestion(filter.questionColumn)) {
                        const dateOfBirth = attendee[filter.questionColumn];
                        if (!dateOfBirth) return false;
                        const age = calculateAge(dateOfBirth);
                        return ageMatchesRange(age, filter.filterValue);
                    }
                    
                    // Regular field filtering
                    const fieldValue = attendee[filter.questionColumn];
                    if (fieldValue === null || fieldValue === undefined || fieldValue === '') {
                        return false;
                    }
                    
                    // For dropdown fields with predefined options, use exact match
                    // For text fields, use substring match
                    if (questionHasOptions(filter.questionColumn)) {
                        // Exact match (case-insensitive) for dropdowns
                        return fieldValue.toString().toLowerCase().trim() === filter.filterValue.toLowerCase().trim();
                    } else {
                        // Substring match (case-insensitive) for text fields
                        return fieldValue.toString().toLowerCase().includes(filter.filterValue.toLowerCase());
                    }
                });
            }
        });
    }

    return filtered;
});

// ✅ Paginated attendees
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

// ✅ Filter management functions
const resetFilters = () => {
    filters.value = [];
    currentPage.value = 1;
};

const toggleFilters = () => {
    showFilters.value = !showFilters.value;
};

const applyFilters = () => {
    currentPage.value = 1; // Reset to first page when filters change
};

// ✅ Check if any filters are active
const hasActiveFilters = computed(() => {
    return filters.value.some(f => f.questionColumn && f.filterValue);
});

// ✅ Get filter summary
const filterSummary = computed(() => {
    return filters.value
        .filter(f => f.questionColumn && f.filterValue)
        .map(f => `${f.questionText || f.questionColumn}: ${f.filterValue}`);
});

// ✅ Dynamic columns based on active filters
const visibleColumns = computed(() => {
    const baseColumns = [
        { key: 'name', label: 'Name', always: true },
        { key: 'email_address', label: 'Email', always: true },
        { key: 'mobile_number', label: 'Mobile Number', always: true }
    ];
    
    const conditionalColumns = [];
    
    // Show location column if there are multiple locations
    const uniqueLocations = [...new Set(props.attendees.map(a => a.location_name).filter(Boolean))];
    if (uniqueLocations.length > 1) {
        conditionalColumns.push({ key: 'location_name', label: 'Event Location' });
    }
    
    // Show columns for active filters
    const activeFilters = filters.value.filter(f => f.questionColumn && f.filterValue);
    activeFilters.forEach(filter => {
        const question = availableQuestions.value.find(q => q.column_name === filter.questionColumn);
        if (question) {
        conditionalColumns.push({ 
                key: filter.questionColumn, 
                label: question.text,
            isCustom: true
        });
    }
    });
    
    return [...baseColumns, ...conditionalColumns];
});

// ✅ Helper function to get field value
const getFieldValue = (attendee, columnName) => {
    if (attendee[columnName] !== undefined && attendee[columnName] !== null && attendee[columnName] !== '') {
        return attendee[columnName];
    }
    return '-';
};

const form = useForm({
    id: null,
    event_id: '',
    prize_name: '',
});
// ✅ Define Props (Expecting location, event & attendees list)
const props = defineProps({
    event: Object,     // ✅ Event details (includes table_name)
    attendees: Array,   // ✅ List of attendees from the dynamic table
    prizes: Array,      // ✅ List of prizes
    form: Object,       // ✅ Signup form with questions
});

// Open Modal for Add a Prize
const openAddPrizeModal = () => {
    isEditing.value = false;
    form.reset(); // Clear form
    let modalElement = new bootstrap.Modal(document.getElementById('createPrizeModal'));
    modalElement.show();
};

// ✅ Save Prize
const savePrize = () => {
    if (!form.prize_name) {
        Swal.fire('Error', 'Prize name cannot be empty!', 'error');
        return;
    }

    const data = new FormData();
    data.append('prize_name', form.prize_name); // ✅ Corrected
    data.append('event_id', props.event.id); // ✅ Ensure event_id is sent

    if (isEditing.value) {
        data.append('_method', 'PATCH'); // ✅ Use PATCH for updating
        router.post(route('prize.update', form.id), data, {
            onSuccess: () => {
                let modalElement = bootstrap.Modal.getInstance(document.getElementById('createPrizeModal'));
                modalElement.hide();
                Swal.fire('Updated!', 'Prize has been updated.', 'success');
                form.reset();
            },
            onError: (errors) => {
                Swal.fire('Error!', 'There was an issue updating the prize.', 'error');
                console.log(errors);
            }
        });
    } else {
        router.post(route('prize.storeAllLocation'), data, {
            onSuccess: () => {
                let modalElement = bootstrap.Modal.getInstance(document.getElementById('createPrizeModal'));
                modalElement.hide();
                Swal.fire('Success!', 'Prize has been created.', 'success');
                form.reset();
            },
            onError: (errors) => {
                Swal.fire('Error!', 'There was an issue creating the prize.', 'error');
            }
        });
    }
};

const openEditModal = (prize) => {
    isEditing.value = true;
    form.id = prize.id;
    form.prize_name = prize.prize_name;

    let modalElement = new bootstrap.Modal(document.getElementById('createPrizeModal'));
    modalElement.show();
};

// Delete Prize
// ✅ Queued so it works offline: the row disappears immediately and the
// database delete syncs in the background.
const destroy = (prize) => {
    Swal.fire({
        title: 'Are you sure?',
        text: 'This action cannot be undone! If you delete this prize the winner for this Prize will be gone! To confirm, type DELETE below.',
        icon: 'warning',
        input: 'text', // ✅ Require user input
        inputPlaceholder: 'Type DELETE to confirm',
        showCancelButton: true,
        confirmButtonText: 'Delete Prize',
        cancelButtonText: 'Cancel',
        inputValidator: (value) => {
            if (value !== 'DELETE') {
                return 'You must type DELETE to confirm!';
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            removeItem('assign-' + prize.id); // Drop any queued winner assignment for this prize
            enqueue({
                key: 'delete-' + prize.id,
                url: route('prize.destroyQueued'),
                payload: {
                    event_id: props.event.id,
                    prize_id: prize.id,
                    client_uuid: prize.client_uuid || null,
                },
                meta: {
                    type: 'delete-winner',
                    event_id: props.event.id,
                    prize_id: prize.id,
                    client_uuid: prize.client_uuid || null,
                },
            });
            const offline = typeof navigator !== 'undefined' && !navigator.onLine;
            Swal.fire(
                'Deleted!',
                offline
                    ? 'Prize has been removed and will be deleted from the server once internet is back.'
                    : 'Prize has been deleted.',
                'success'
            );
        }
    });
};

// ✅ Track selected prize and winner
const selectedPrize = ref(null);
const selectedWinner = ref(null);
const isPicking = ref(false); // ✅ Controls animation state
const winnerDisplay = ref(''); // ✅ Displays random names
let animationInterval = null;

// ✅ Open Modal and Start Animation
const openPickWinnerModal = (prize) => {
    if (!props.attendees.length) {
        Swal.fire('No Attendees', 'There are no attendees for this event.', 'warning');
        return;
    }
    if (!eligibleAttendees.value.length) {
        const message = hasActiveFilters.value 
            ? 'No eligible attendees found with the current filters applied. Try adjusting your filters or all filtered attendees may have already won prizes.'
            : 'All attendees have already been chosen as winners.';
        Swal.fire('No Eligible Attendees', message, 'warning');
        return;
    }
    selectedPrize.value = prize;
    selectedWinner.value = null;
    winnerDisplay.value = 'Searching for a winner...';
    isPicking.value = true;

    let modalElement = new bootstrap.Modal(document.getElementById('pickWinnerModal'));
    modalElement.show();

    // ✅ Start Random Name Animation
    animationInterval = setInterval(() => {
        const randomIndex = Math.floor(Math.random() * eligibleAttendees.value.length);
        const randomAttendee = eligibleAttendees.value[randomIndex];
        winnerDisplay.value = `${randomAttendee.first_name} ${randomAttendee.last_name}`;
    }, 100);

    // ✅ Stop Animation After 1.5 Seconds and Pick Winner
    setTimeout(() => {
        clearInterval(animationInterval);
        isPicking.value = false;

        const finalIndex = Math.floor(Math.random() * eligibleAttendees.value.length);
        selectedWinner.value = eligibleAttendees.value[finalIndex];
        winnerDisplay.value = `${selectedWinner.value.first_name} ${selectedWinner.value.last_name}`;
    }, 500);
};

// ✅ Save the Winner to the Prize
// Saved locally right away (offline sync queue) so slow/no internet never
// blocks the draw — it syncs to the server in the background.
const confirmWinner = () => {
    if (!selectedPrize.value || !selectedWinner.value) {
        Swal.fire('Error', 'No prize or winner selected.', 'error');
        return;
    }

    enqueue({
        key: 'assign-' + selectedPrize.value.id,
        url: route('prize.assignWinner', selectedPrize.value.id),
        payload: {
            winner_name: selectedWinner.value.first_name + " " + selectedWinner.value.last_name,
            winner_email: selectedWinner.value.email_address,
            winner_mobile_number: selectedWinner.value.mobile_number
        },
        meta: {
            type: 'assign-winner',
            event_id: props.event.id,
            prize_id: selectedPrize.value.id,
        },
    });

    let modalElement = bootstrap.Modal.getInstance(document.getElementById('pickWinnerModal'));
    modalElement.hide();

    const offline = typeof navigator !== 'undefined' && !navigator.onLine;
    Swal.fire(
        'Winner Selected!',
        offline
            ? `${selectedWinner.value.first_name} has been saved on this device and will sync automatically once internet is back.`
            : `${selectedWinner.value.first_name} has been chosen.`,
        'success'
    );
};

const confirmCancel = () => {
    Swal.fire({
        title: 'Are you sure?',
        text: 'The winner selection process will be canceled!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, cancel it',
        cancelButtonText: 'No',
    }).then((result) => {
        if (result.isConfirmed) {
            let modalElement = bootstrap.Modal.getInstance(document.getElementById('pickWinnerModal'));
            modalElement.hide();
            Swal.fire('Canceled', 'The winner selection has been canceled.', 'info');
        }
    });
};

// ✅ Server prizes overlaid with locally saved (not-yet-synced) winner
// assignments, minus any prizes with a queued delete.
const displayPrizes = computed(() => {
    return props.prizes
        .filter(prize => !syncState.items.some(item =>
            item.meta?.type === 'delete-winner' && item.meta.prize_id === prize.id
        ))
        .map(prize => {
            const queued = syncState.items.find(item =>
                item.meta?.type === 'assign-winner' && item.meta.prize_id === prize.id
            );
            if (queued && prize.winner_email !== queued.payload.winner_email) {
                return {
                    ...prize,
                    winner: queued.payload.winner_name,
                    winner_email: queued.payload.winner_email,
                    winner_mobile_number: queued.payload.winner_mobile_number,
                    __pending: queued.status === 'pending',
                };
            }
            return prize;
        });
});

// ✅ Count of winner assignments / deletes still waiting to reach the server
const pendingSyncCount = computed(() =>
    syncState.items.filter(item =>
        ['assign-winner', 'delete-winner'].includes(item.meta?.type) &&
        item.meta.event_id === props.event.id &&
        item.status === 'pending'
    ).length
);

// ✅ Eligible attendees considering both filters and winners
// Uses displayPrizes so locally saved (not-yet-synced) winners are excluded too.
const eligibleAttendees = computed(() => {
    return filteredAttendees.value.filter(attendee =>
        !displayPrizes.value.some(prize => prize.winner_email === attendee.email_address)
    );
});

// ✅ Background sync + light polling to refresh prizes from the server
let prizesPollingInterval = null;

onMounted(() => {
    startAutoSync();
    processQueue();
    prizesPollingInterval = setInterval(() => {
        if (typeof navigator !== 'undefined' && !navigator.onLine) return;
        router.reload({
            only: ['prizes'],
            preserveState: true,
            onSuccess: () => pruneSynced(props.prizes),
        });
    }, 10000);
});

onUnmounted(() => {
    if (prizesPollingInterval) clearInterval(prizesPollingInterval);
});
</script>

<style scoped>
/* Sticky offline-sync banner — stays visible above the table while scrolling */
.paw-sync-banner {
    top: 0;
    z-index: 1080;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.4);
}

/* Override Bootstrap's input focus styles */
.form-control:focus {
    background-color: #1f2937 !important;
    color: white !important;
    border-color: #374151 !important;
    box-shadow: 0 0 0 0.25rem rgba(192, 199, 201, 0.25) !important;
}

/* Ensure placeholder text is visible on dark backgrounds */
::placeholder {
    color: #9ca3af !important;
    opacity: 1;
}

/* Fix for autofill background in Chrome */
input:-webkit-autofill,
input:-webkit-autofill:hover, 
input:-webkit-autofill:focus,
input:-webkit-autofill:active {
    -webkit-box-shadow: 0 0 0 30px #1f2937 inset !important;
    -webkit-text-fill-color: white !important;
    transition: background-color 5000s ease-in-out 0s;
}
</style>

<template>
    <Head title="Pick a Winner All Location Page" />

    <PickaWinnerLayout>
        <div class="min-h-screen p-6 text-white bg-[#151515]">
            <!-- Prizes Section -->
            <div class="mt-3 p-2">
                <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div class="d-flex flex-column flex-md-row justify-content-between items-center pb-4">
                        <div class="mb-3 mb-md-0 text-center text-md-start">
                            <img 
                            :src="'/storage/' + props.event?.event_logo" 
                            alt="Event Logo" 
                            class="img-fluid" 
                            style="max-width: 200px; max-height: 300px;"
                            >
                        </div>
                        <h2 class="text-2xl font-bold text-center text-white">
                            {{ event.event_name }}
                        </h2>
                    </div>

                    <!-- Offline sync status banner — sticky + loud so unsynced winners can't be missed -->
                    <div v-if="pendingSyncCount > 0" class="paw-sync-banner sticky-top text-center py-2 px-3 fw-bold"
                        :class="syncState.isOnline ? 'bg-warning text-dark' : 'bg-danger text-white'">
                        <span v-if="!syncState.isOnline">
                            ⚠️ 📴 Offline — {{ pendingSyncCount }} winner(s) are ONLY on this device.
                            Keep this page open until they sync — they will send automatically when internet is back.
                        </span>
                        <span v-else>
                            ⏳ Syncing {{ pendingSyncCount }} winner(s) to the server — please keep this page open until it finishes.
                        </span>
                    </div>
                    <div class="overflow-hidden border border-gray-700 shadow-sm" style="background-color: #151515;">
                        <div class="p-6 text-white">
                            <div class="d-flex justify-content-between">
                                <div class="flex items-center space-x-2">
                                    <!-- We'll keep the checkbox hidden for all locations page -->
                                </div>
                                <button 
                                    class="bg-cyan-500 text-white px-4 py-2 rounded hover:bg-cyan-600"
                                    @click="openAddPrizeModal()"
                                >
                                    Add Prizes
                                </button>
                            </div>
                            
                            <div class="mt-3 overflow-x-auto">
                                <table class="min-w-full border-collapse border border-gray-700">
                                    <thead>
                                        <tr class="bg-cyan-500">
                                            <th class="border border-gray-700 p-2 text-white">Prizes</th>
                                            <th class="border border-gray-700 p-2 text-white">Name</th>
                                            <th class="border border-gray-700 p-2 text-white">Email</th>
                                            <th class="border border-gray-700 p-2 text-white">Mobile Number</th>
                                            <th class="border border-gray-700 p-2 text-white">Actions</th>
                                        </tr>
                                    </thead>

                                    <!-- :class="{'bg-cyan-700': prize.winner_email} -->
                                    <tbody style="background-color: #151515;">
                                        <tr v-for="(prize, index) in displayPrizes"
                                            :key="prize.id || index"
                                            class="text-left"

                                        >
                                            <td class="border border-gray-700 p-2">{{ prize.prize_name }}</td>
                                            <td class="border border-gray-700 p-2">
                                                {{ prize.winner }}
                                                <span v-if="prize.__pending" class="badge bg-warning text-dark ms-1" title="Saved on this device — waiting for internet to sync">⏳ syncing</span>
                                            </td>
                                            <td class="border border-gray-700 p-2">{{ prize.winner_email || 'No Winner Yet' }}</td>
                                            <td class="border border-gray-700 p-2">{{ prize.winner_mobile_number || 'No Winner Yet' }}</td>
                                            <td class="border border-gray-700 p-2 flex flex-col md:flex-row gap-2 justify-center">
                                                <a class="btn btn-success" @click="openPickWinnerModal(prize)">Pick a Winner</a>
                                                <a class="btn btn-primary" @click="openEditModal(prize)"><i class="fa-solid fa-pen-to-square"></i></a>
                                                <a class="btn btn-danger" @click="destroy(prize)"><i class="fa-solid fa-trash"></i></a>
                                            </td>
                                        </tr>
                                        <tr v-if="prizes.length === 0">
                                            <td colspan="5" class="border border-gray-700 p-4 text-center text-gray-400">
                                                No prizes have been created yet.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attendees Table -->
            <div class="p-2 pb-5">
                <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div class="overflow-hidden border border-gray-700 shadow-sm" style="background-color: #151515;">
                        <div class="p-6 text-white">
                            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start mb-4">
                                <div class="mb-3 mb-md-0">
                                    <h3 class="text-lg font-semibold">Attendees at {{ event.event_name }}</h3>
                                    <p class="text-sm text-gray-400">{{ filteredAttendees.length }} of {{ props.attendees.length }} attendees</p>
                                </div>
                                
                                <div class="d-flex flex-column flex-md-row gap-2 align-items-end">
                                    <!-- Search Input -->
                                    <div>
                                        <input
                                            v-model="searchQuery"
                                            type="text"
                                            placeholder="Search attendees..."
                                            class="p-2 border rounded bg-gray-800 text-white border-gray-700"
                                            style="color: white !important; min-width: 200px;"
                                            @input="handleSearchInput"
                                        />
                                    </div>
                                    
                                    <!-- Filter Toggle Button -->
                                    <button 
                                        @click="toggleFilters"
                                        class="px-4 py-2 rounded transition-colors"
                                        :class="hasActiveFilters ? 'bg-orange-500 hover:bg-orange-600' : 'bg-gray-600 hover:bg-gray-700'"
                                    >
                                        <i class="fas fa-filter mr-2"></i>
                                        Filters {{ hasActiveFilters ? `(${filterSummary.length})` : '' }}
                                    </button>
                                </div>
                            </div>

                            <!-- Advanced Filters Panel -->
                            <div v-if="showFilters" class="mb-4 p-4 bg-gray-800 border border-gray-600 rounded">
                                <div class="mb-3 d-flex justify-content-between align-items-center">
                                    <h5 class="text-white mb-0">Filter Attendees by Question</h5>
                                    <button @click="addFilter" class="btn btn-sm btn-primary">
                                        <i class="fa fa-plus me-1"></i> Add Filter
                                    </button>
                                    </div>

                                <!-- Filter Condition Selector (shown when multiple filters exist) -->
                                <div v-if="filters.length > 1" class="mb-3 p-3 bg-cyan-900 rounded">
                                    <div class="d-flex align-items-center gap-3">
                                        <label class="form-label text-white mb-0 font-weight-bold">Filter Logic:</label>
                                        <select 
                                            v-model="filterCondition" 
                                            class="form-select" 
                                            style="background-color: #1f2937; color: white; border-color: #374151; max-width: 300px;"
                                            @change="applyFilters"
                                        >
                                            <option value="AND">AND - All filters must match</option>
                                            <option value="OR">OR - Any filter can match</option>
                                        </select>
                                        <span class="text-gray-300 text-sm">
                                            <i class="fa fa-info-circle me-1"></i>
                                            {{ filterCondition === 'AND' ? 'Attendee must match ALL selected filters' : 'Attendee can match ANY selected filter' }}
                                        </span>
                                    </div>
                                    </div>

                                <div v-if="filters.length === 0" class="text-gray-400 text-center py-3">
                                    No filters added. Click "Add Filter" to start filtering.
                                    </div>

                                <div v-for="(filter, index) in filters" :key="index" class="mb-3 p-3 bg-gray-700 rounded">
                                    <div class="row g-3">
                                        <!-- Question Selection -->
                                        <div class="col-md-4">
                                            <label class="form-label text-sm text-white">Question</label>
                                            <select 
                                                v-model="filter.questionColumn" 
                                                @change="updateQuestionText(index)"
                                                class="form-control bg-gray-600 text-white border-gray-500"
                                            >
                                                <option value="">Select a question...</option>
                                                <option 
                                                    v-for="question in availableQuestions" 
                                                    :key="question.column_name" 
                                                    :value="question.column_name"
                                                >
                                                {{ question.text }}
                                            </option>
                                        </select>
                                    </div>

                                        <!-- Filter Value - Show dropdown if question has options or is Date of Birth, otherwise text input -->
                                        <div class="col-md-6">
                                            <label class="form-label text-sm text-white">
                                                Filter Value
                                                <span v-if="filter.questionColumn && isDateOfBirthQuestion(filter.questionColumn)" class="text-xs text-cyan-300">
                                                    (Age calculated from date)
                                                </span>
                                            </label>
                                            <!-- Dropdown for Date of Birth (age ranges) -->
                                            <select 
                                                v-if="filter.questionColumn && isDateOfBirthQuestion(filter.questionColumn)"
                                                v-model="filter.filterValue"
                                                class="form-control bg-gray-600 text-white border-gray-500"
                                                @change="applyFilters"
                                            >
                                                <option value="">Select an age range...</option>
                                                <option 
                                                    v-for="option in getQuestionOptions(filter.questionColumn)" 
                                                    :key="option" 
                                                    :value="option"
                                                >
                                                    {{ option }}
                                                </option>
                                            </select>
                                            <!-- Dropdown for questions with options -->
                                            <select 
                                                v-else-if="filter.questionColumn && questionHasOptions(filter.questionColumn)"
                                                v-model="filter.filterValue"
                                                class="form-control bg-gray-600 text-white border-gray-500"
                                                @change="applyFilters"
                                            >
                                                <option value="">Select an option...</option>
                                                <option 
                                                    v-for="option in getQuestionOptions(filter.questionColumn)" 
                                                    :key="option" 
                                                    :value="option"
                                                >
                                                    {{ option }}
                                                </option>
                                            </select>
                                            <!-- Text input for questions without options -->
                                        <input 
                                                v-else-if="filter.questionColumn"
                                                v-model="filter.filterValue"
                                            type="text"
                                                :placeholder="`Enter value to filter ${filter.questionText || 'by'}...`"
                                                class="form-control bg-gray-600 text-white border-gray-500"
                                                list="values-list"
                                            @input="applyFilters"
                                        />
                                            <datalist id="values-list" v-if="filter.questionColumn && !questionHasOptions(filter.questionColumn) && !isDateOfBirthQuestion(filter.questionColumn)">
                                                <option 
                                                    v-for="value in getUniqueValuesForQuestion(filter.questionColumn)" 
                                                    :key="value" 
                                                    :value="value"
                                                />
                                            </datalist>
                                            <input 
                                                v-else
                                                type="text"
                                                placeholder="Select a question first..."
                                                class="form-control bg-gray-600 text-white border-gray-500"
                                                disabled
                                        />
                                    </div>

                                        <!-- Remove Button -->
                                        <div class="col-md-2 d-flex align-items-end">
                                        <button 
                                                @click="removeFilter(index)"
                                                class="btn btn-sm btn-danger w-100"
                                        >
                                                <i class="fa fa-trash"></i> Remove
                                        </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Filter Explanation -->
                                <div v-if="hasActiveFilters" class="mt-4 p-3 bg-blue-900 rounded border border-blue-700">
                                    <h6 class="text-white font-weight-bold mb-2">
                                        <i class="fa fa-info-circle me-2"></i>Filter Explanation:
                                    </h6>
                                    <p class="text-white mb-0">{{ getFilterExplanation }}</p>
                                    <p class="text-gray-300 text-sm mt-2 mb-0">
                                        Eligible attendees: <strong class="text-white">{{ eligibleAttendees.length }}</strong> out of <strong class="text-white">{{ filteredAttendees.length }}</strong> total attendees
                                    </p>
                                </div>

                                <!-- Active Filters Summary -->
                                <div v-if="hasActiveFilters" class="mt-3">
                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                        <span class="text-sm text-gray-300">Active filters:</span>
                                        <span 
                                            v-for="filter in filterSummary" 
                                            :key="filter"
                                            class="badge bg-cyan-600 text-white"
                                        >
                                            {{ filter }}
                                        </span>
                                    </div>
                                    <div class="text-center">
                                        <button 
                                            @click="resetFilters"
                                            class="btn btn-outline-warning"
                                        >
                                            <i class="fas fa-undo mr-2"></i>
                                            Reset All Filters
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full border-collapse border border-gray-700">
                                    <thead>
                                        <tr class="bg-cyan-500">
                                            <th 
                                                v-for="column in visibleColumns" 
                                                :key="column.key"
                                                class="border border-gray-700 p-2 text-white"
                                            >
                                                {{ column.label }}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody style="background-color: #151515;">
                                        <tr v-for="(attendee, index) in paginatedAttendees" :key="index" class="text-left text-white">
                                            <td 
                                                v-for="column in visibleColumns" 
                                                :key="column.key"
                                                class="border border-gray-700 p-2"
                                            >
                                                <template v-if="column.key === 'name'">
                                                    {{ attendee.first_name }} {{ attendee.last_name }}
                                                </template>
                                                <template v-else>
                                                    {{ getFieldValue(attendee, column.key) }}
                                                </template>
                                            </td>
                                        </tr>
                                        <tr v-if="paginatedAttendees.length === 0">
                                            <td :colspan="visibleColumns.length" class="border border-gray-700 p-4 text-center text-gray-400">
                                                No matching attendees found.
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
                                    class="px-4 py-2 bg-gray-700 text-white rounded hover:bg-gray-600 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    Previous
                                </button>
                                
                                <span class="text-white">Page {{ currentPage }} of {{ totalPages }}</span>
                                
                                <button 
                                    @click="goToPage(currentPage + 1)" 
                                    :disabled="currentPage === totalPages" 
                                    class="px-4 py-2 bg-gray-700 text-white rounded hover:bg-gray-600 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    Next
                                </button>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            <!-- Pick Winner Modal -->
            <div class="modal fade" id="pickWinnerModal" tabindex="-1" aria-labelledby="pickWinnerModalLabel" 
                data-bs-backdrop="static" data-bs-keyboard="false">
                <div class="modal-dialog">
                    <div class="modal-content bg-gray-900 text-white">
                        <div class="modal-header border-gray-700">
                            <h5 class="modal-title">🎉 Picking a Winner 🎉</h5>
                        </div>
                        <div class="modal-body text-center">
                            <!-- Pool Information -->
                            <div class="mb-3 p-2 bg-gray-800 rounded">
                                <p class="text-sm text-gray-300 mb-1">
                                    Selecting from {{ eligibleAttendees.length }} eligible attendees
                                    <template v-if="hasActiveFilters">(filtered pool)</template>
                                </p>
                                <div v-if="hasActiveFilters" class="text-xs text-cyan-400">
                                    Active filters: {{ filterSummary.join(', ') }}
                                </div>
                            </div>
                            <h3 class="text-xl font-bold text-green-500">
                                <template v-if="isPicking">
                                    🔄 Searching...
                                </template>
                                <template v-else>
                                    🎉 Winner Selected! 🎉
                                </template>
                            </h3>

                            <div class="text-2xl font-bold text-blue-400 mt-3">
                                <span v-if="isPicking" class="animate-pulse">{{ winnerDisplay }}</span>
                                <span v-else class="text-green-400">{{ winnerDisplay }}</span>
                            </div>

                            <template v-if="selectedWinner && !isPicking">
                                <p class="mt-3"><strong>Name:</strong> {{ selectedWinner.first_name }} {{ selectedWinner.last_name }}</p>
                                <p><strong>Email:</strong> {{ selectedWinner.email_address }}</p>
                                <p><strong>Phone:</strong> {{ selectedWinner.mobile_number }}</p>
                            </template>
                        </div>
                        <div class="modal-footer border-gray-700">
                            <button type="button" class="btn btn-secondary" v-if="!isPicking" @click="confirmCancel()">Cancel</button>
                            <button type="button" class="btn" style="background-color: cyan;" v-if="!isPicking" @click="confirmWinner()">
                                Confirm Winner
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bootstrap Modal for Create/Edit -->
            <div class="modal fade" id="createPrizeModal" tabindex="-1" aria-labelledby="createPrizeModalLabel">
                <div class="modal-dialog">
                    <div class="modal-content bg-gray-900 text-white">
                        <div class="modal-header border-gray-700">
                            <h5 class="modal-title" id="createPrizeModalLabel">{{ isEditing ? 'Edit Prize' : 'Create Prize' }}</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form @submit.prevent="savePrize">
                                <div class="mb-3">
                                    <label class="form-label">Prize</label>
                                    <input v-model="form.prize_name" type="text" class="form-control bg-gray-800 text-white border-gray-700" 
                                           style="color: white !important;" 
                                           required />
                                </div>

                                <div class="modal-footer border-gray-700">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn" style="background-color: cyan;">
                                        {{ isEditing ? 'Update Prize' : 'Save Prize' }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </PickaWinnerLayout>
</template>