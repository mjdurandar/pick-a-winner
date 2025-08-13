<script setup>
import Swal from 'sweetalert2';
import { ref, computed, onMounted } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import PickaWinnerLayout from '@/Layouts/PickaWinnerLayout.vue';
import { Head } from '@inertiajs/vue3';
import { debounce } from 'lodash';

// Track if we are editing an event
const isEditing = ref(false);

// ✅ Search Query and Pagination
const searchQuery = ref('');
const currentPage = ref(1);
const itemsPerPage = 20;
const debouncedSearchValue = ref('');

// ✅ Advanced Filter Options
const showFilters = ref(false);
const filters = ref({
    location: '',
    gender: '',
    ageRange: '',
    customQuestion: '',
    customValue: ''
});

// ✅ Get unique values for filter dropdowns
const uniqueLocations = computed(() => {
    const locations = [...new Set(props.attendees.map(a => a.location_name).filter(Boolean))];
    return locations.sort();
});

const uniqueGenders = computed(() => {
    const genders = [...new Set(props.attendees.map(a => a.gender).filter(Boolean))];
    return genders.sort();
});

// ✅ Get available custom questions (excluding standard ones)
const availableCustomQuestions = computed(() => {
    if (!props.form || !props.form.questions) return [];
    
    const questions = JSON.parse(props.form.questions);
    const standardFields = ['first_name', 'last_name', 'email_address', 'gender', 'mobile_number', 'age', 'events_location'];
    
    return questions.filter(question => 
        !standardFields.includes(question.column_name) && 
        props.attendees.length > 0 && 
        props.attendees[0][question.column_name] !== undefined
    );
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
                attendee.gender,
                attendee.location_name
            ].map(field => (field || '').toLowerCase());

            return searchFields.some(field => field.includes(debouncedSearchValue.value));
        });
    }

    // ✅ Apply location filter
    if (filters.value.location) {
        filtered = filtered.filter(attendee => 
            attendee.location_name === filters.value.location
        );
    }

    // ✅ Apply gender filter
    if (filters.value.gender) {
        filtered = filtered.filter(attendee => 
            attendee.gender && attendee.gender.toLowerCase() === filters.value.gender.toLowerCase()
        );
    }



    // ✅ Apply age range filter
    if (filters.value.ageRange) {
        filtered = filtered.filter(attendee => {
            if (!attendee.age) return false;
            const age = parseInt(attendee.age);
            switch (filters.value.ageRange) {
                case 'under21':
                    return age < 21;
                case '21to30':
                    return age >= 21 && age <= 30;
                case '31to40':
                    return age >= 31 && age <= 40;
                case '41to50':
                    return age >= 41 && age <= 50;
                case 'over50':
                    return age > 50;
                default:
                    return true;
            }
        });
    }

    // ✅ Apply custom question filter
    if (filters.value.customQuestion && filters.value.customValue) {
        // Find the question object to get the column_name
        const selectedQuestion = availableCustomQuestions.value.find(q => q.text === filters.value.customQuestion);
        if (selectedQuestion) {
            filtered = filtered.filter(attendee => {
                const fieldValue = attendee[selectedQuestion.column_name];
                if (!fieldValue) return false;
                return fieldValue.toString().toLowerCase().includes(filters.value.customValue.toLowerCase());
            });
        }
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
    filters.value = {
        location: '',
        gender: '',
        ageRange: '',
        customQuestion: '',
        customValue: ''
    };
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
    return Object.values(filters.value).some(value => value !== '');
});

// ✅ Get filter summary
const filterSummary = computed(() => {
    const activeFilters = [];
    if (filters.value.location) activeFilters.push(`Location: ${filters.value.location}`);
    if (filters.value.gender) activeFilters.push(`Gender: ${filters.value.gender}`);
    if (filters.value.ageRange) {
        const ageLabels = {
            'under21': 'Under 21',
            '21to30': '21-30',
            '31to40': '31-40',
            '41to50': '41-50',
            'over50': 'Over 50'
        };
        activeFilters.push(`Age: ${ageLabels[filters.value.ageRange]}`);
    }
    if (filters.value.customQuestion && filters.value.customValue) {
        activeFilters.push(`${filters.value.customQuestion}: ${filters.value.customValue}`);
    }
    return activeFilters;
});

// ✅ Dynamic columns based on active filters
const visibleColumns = computed(() => {
    const baseColumns = [
        { key: 'name', label: 'Name', always: true },
        { key: 'email_address', label: 'Email', always: true },
        { key: 'mobile_number', label: 'Mobile Number', always: true }
    ];
    
    const conditionalColumns = [];
    
    // Show gender column if gender filter is active or there are multiple genders
    if (filters.value.gender || uniqueGenders.value.length > 1) {
        conditionalColumns.push({ key: 'gender', label: 'Gender' });
    }
    
    // Show location column if location filter is active or there are multiple locations
    if (filters.value.location || uniqueLocations.value.length > 1) {
        conditionalColumns.push({ key: 'location_name', label: 'Event Location' });
    }
    
    // Show age column if age filter is active
    if (filters.value.ageRange) {
        conditionalColumns.push({ key: 'age', label: 'Age' });
    }
    
    // Show custom field column if custom filter is active
    if (filters.value.customQuestion) {
        conditionalColumns.push({ 
            key: filters.value.customQuestion, 
            label: filters.value.customQuestion,
            isCustom: true
        });
    }
    
    return [...baseColumns, ...conditionalColumns];
});

// ✅ Helper function to get custom field value
const getCustomFieldValue = (attendee, questionText) => {
    // Find the question to get the column name
    const question = availableCustomQuestions.value.find(q => q.text === questionText);
    if (question && attendee[question.column_name]) {
        return attendee[question.column_name];
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
const destroy = (id) => {
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
            router.delete(route('prize.destroy', id), {
                onSuccess: () => {
                    Swal.fire('Deleted!', 'Prize has been deleted.', 'success');
                }
            });
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

    // ✅ Stop Animation After 3 Seconds and Pick Winner
    setTimeout(() => {
        clearInterval(animationInterval);
        isPicking.value = false;

        const finalIndex = Math.floor(Math.random() * eligibleAttendees.value.length);
        selectedWinner.value = eligibleAttendees.value[finalIndex];
        winnerDisplay.value = `${selectedWinner.value.first_name} ${selectedWinner.value.last_name}`;
    }, 3000);
};

// ✅ Save the Winner to the Prize
const confirmWinner = () => {
    if (!selectedPrize.value || !selectedWinner.value) {
        Swal.fire('Error', 'No prize or winner selected.', 'error');
        return;
    }

    // ✅ Send update request to backend
    router.post(route('prize.assignWinner', selectedPrize.value.id), {
        winner_name: selectedWinner.value.first_name + " " + selectedWinner.value.last_name,
        winner_email: selectedWinner.value.email_address,
        winner_mobile_number: selectedWinner.value.mobile_number
    }, {
        onSuccess: () => {
            let modalElement = bootstrap.Modal.getInstance(document.getElementById('pickWinnerModal'));
            modalElement.hide();
            Swal.fire('Winner Selected!', `${selectedWinner.value.first_name} has been chosen.`, 'success');
        }
    });
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

// ✅ Eligible attendees considering both filters and winners
const eligibleAttendees = computed(() => {
    return filteredAttendees.value.filter(attendee => 
        !props.prizes.some(prize => prize.winner_email === attendee.email_address)
    );
});
</script>

<style scoped>
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
                                        <tr v-for="(prize, index) in prizes" 
                                            :key="index" 
                                            class="text-left"
                                            
                                        >
                                            <td class="border border-gray-700 p-2">{{ prize.prize_name }}</td>
                                            <td class="border border-gray-700 p-2">{{ prize.winner }}</td>
                                            <td class="border border-gray-700 p-2">{{ prize.winner_email || 'No Winner Yet' }}</td>
                                            <td class="border border-gray-700 p-2">{{ prize.winner_mobile_number || 'No Winner Yet' }}</td>
                                            <td class="border border-gray-700 p-2 flex flex-col md:flex-row gap-2 justify-center">
                                                <a class="btn btn-success" @click="openPickWinnerModal(prize)">Pick a Winner</a>
                                                <a class="btn btn-primary" @click="openEditModal(prize)"><i class="fa-solid fa-pen-to-square"></i></a>
                                                <a class="btn btn-danger" @click="destroy(prize.id)"><i class="fa-solid fa-trash"></i></a>
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
                                <div class="row g-3">
                                    <!-- Location Filter -->
                                    <div class="col-md-3">
                                        <label class="form-label text-sm">Location</label>
                                        <select v-model="filters.location" class="form-control bg-gray-700 text-white border-gray-600" @change="applyFilters">
                                            <option value="">All Locations</option>
                                            <option v-for="location in uniqueLocations" :key="location" :value="location">
                                                {{ location }}
                                            </option>
                                        </select>
                                    </div>

                                    <!-- Gender Filter -->
                                    <div class="col-md-3">
                                        <label class="form-label text-sm">Gender</label>
                                        <select v-model="filters.gender" class="form-control bg-gray-700 text-white border-gray-600" @change="applyFilters">
                                            <option value="">All Genders</option>
                                            <option v-for="gender in uniqueGenders" :key="gender" :value="gender">
                                                {{ gender }}
                                            </option>
                                        </select>
                                    </div>



                                    <!-- Age Range Filter -->
                                    <div class="col-md-3">
                                        <label class="form-label text-sm">Age Range</label>
                                        <select v-model="filters.ageRange" class="form-control bg-gray-700 text-white border-gray-600" @change="applyFilters">
                                            <option value="">All Ages</option>
                                            <option value="under21">Under 21</option>
                                            <option value="21to30">21-30</option>
                                            <option value="31to40">31-40</option>
                                            <option value="41to50">41-50</option>
                                            <option value="over50">Over 50</option>
                                        </select>
                                    </div>

                                    <!-- Custom Question Filter -->
                                    <div class="col-md-4" v-if="availableCustomQuestions.length > 0">
                                        <label class="form-label text-sm">Custom Question</label>
                                        <select v-model="filters.customQuestion" class="form-control bg-gray-700 text-white border-gray-600" @change="applyFilters">
                                            <option value="">Select Question</option>
                                            <option v-for="question in availableCustomQuestions" :key="question.column_name" :value="question.text">
                                                {{ question.text }}
                                            </option>
                                        </select>
                                    </div>

                                    <div class="col-md-4" v-if="filters.customQuestion">
                                        <label class="form-label text-sm">Answer Contains</label>
                                        <input 
                                            v-model="filters.customValue"
                                            type="text"
                                            placeholder="Enter value to filter by..."
                                            class="form-control bg-gray-700 text-white border-gray-600"
                                            @input="applyFilters"
                                        />
                                    </div>

                                    <!-- Reset Button -->
                                    <div class="col-md-4 d-flex align-items-end">
                                        <button 
                                            @click="resetFilters"
                                            class="btn btn-outline-warning w-100"
                                            :disabled="!hasActiveFilters"
                                        >
                                            <i class="fas fa-undo mr-2"></i>
                                            Reset Filters
                                        </button>
                                    </div>
                                </div>

                                <!-- Active Filters Summary -->
                                <div v-if="hasActiveFilters" class="mt-3">
                                    <div class="d-flex flex-wrap gap-2">
                                        <span class="text-sm text-gray-300">Active filters:</span>
                                        <span 
                                            v-for="filter in filterSummary" 
                                            :key="filter"
                                            class="badge bg-cyan-600 text-white"
                                        >
                                            {{ filter }}
                                        </span>
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
                                                <template v-else-if="column.isCustom">
                                                    {{ getCustomFieldValue(attendee, column.key) }}
                                                </template>
                                                <template v-else>
                                                    {{ attendee[column.key] || '-' }}
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