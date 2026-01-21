<script setup>
import Swal from 'sweetalert2';
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import PickaWinnerLayout from '@/Layouts/PickaWinnerLayout.vue';
import { Head } from '@inertiajs/vue3';

// Track if we are editing an event
const isEditing = ref(false);
const isSubmitting = ref(false); // Track submission state

// ✅ Search Query
const searchQuery = ref('');
const showSettingsModal = ref(false);
const showFilters = ref(false);

// ✅ Dynamic Filter System - Support multiple filters based on questions
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

// ✅ Reset all filters
const resetFilters = () => {
    filters.value = [];
};

// ✅ Check if any filters are active
const hasActiveFilters = computed(() => {
    return filters.value.some(f => f.questionColumn && f.filterValue);
});

// ✅ Get question object by column name
const getQuestionByColumn = (columnName) => {
    return availableQuestions.value.find(q => q.column_name === columnName);
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

// ✅ Get today's date in 'YYYY-MM-DD' format
const getTodayDate = () => {
    const today = new Date();
    return today.toISOString().split('T')[0]; // ✅ Ensures 'YYYY-MM-DD' in UTC
};

// ✅ Computed Property to Filter Attendees
const filteredAttendees = computed(() => {
    return props.attendees.filter(attendee => {
        // ✅ Ensure attendee's location matches the selected location
        const matchesLocation = attendee.location_id === props.location.id;

        // ✅ Apply search filtering
        const matchesSearch = searchQuery.value
            ? `${attendee.first_name} ${attendee.last_name}`.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
              attendee.email_address.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
              attendee.gender.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
              attendee.mobile_number.toLowerCase().includes(searchQuery.value.toLowerCase())
            : true;

        return matchesLocation && matchesSearch;
    });
});

const formatLocationDateTime = (date, time) => {
    if (!date || !time) return '';

    const datetime = new Date(`${date}T${time}`);
    return datetime.toLocaleString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
};

const form = useForm({
    id: null,
    event_id: '',
    location_id: '',
    prize_name: '',
});

// Add new refs for prize creation
const showPrizeQuantityModal = ref(false);
const prizeQuantity = ref(1);
const prizeNames = ref([]);
const hasPrizes = computed(() => props.prizes.length > 0);

// Function to initialize prize names array
const initializePrizeNames = () => {
    prizeNames.value = Array(prizeQuantity.value).fill('');
};

// Function to handle quantity change
const handleQuantityChange = () => {
    if (prizeQuantity.value < 1) prizeQuantity.value = 1;
    initializePrizeNames();
};

// Function to save multiple prizes
const saveMultiplePrizes = () => {
    if (prizeNames.value.some(name => !name.trim())) {
        Swal.fire('Error', 'All prize names must be filled!', 'error');
        return;
    }

    // Create a single request with all prizes
    const data = new FormData();
    data.append('prizes', JSON.stringify(prizeNames.value));
    data.append('event_id', props.event.id);
    data.append('location_id', props.location.id);

    router.post(route('prize.storeMultiple'), data, {
        onSuccess: () => {
            let modalElement = bootstrap.Modal.getInstance(document.getElementById('prizeQuantityModal'));
            modalElement.hide();
            Swal.fire('Success!', `All ${prizeNames.value.length} prizes have been created.`, 'success');
            showPrizeQuantityModal.value = false;
            prizeQuantity.value = 1;
            prizeNames.value = [];
        },
        onError: (errors) => {
            console.error('Error creating prizes:', errors);
            Swal.fire('Error!', 'There was an issue creating the prizes.', 'error');
        }
    });
};

// ✅ Define Props (Expecting location, event & attendees list)
const props = defineProps({
    location: Object,  // ✅ Selected location
    event: Object,     // ✅ Event details (includes table_name)
    attendees: Array,   // ✅ List of attendees from the dynamic table
    prizes: Array,      // ✅ List of prizes
    form: Object,       // ✅ Signup form with questions for dynamic filtering
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
    data.append('location_id', props.location.id); // ✅ Ensure location_id is sent

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
        router.post(route('prize.store'), data, {
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

// ✅ Polling to Refresh Table Every 5 Seconds
let pollingInterval;

const fetchAttendees = () => {
    router.reload({
        only: ['attendees'], // ✅ Reloads only the attendees data, not the whole page
        preserveState: true, // ✅ Keeps the existing page state
    });
};

const initializeModals = () => {
    // Initialize all modals with proper configuration
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        new bootstrap.Modal(modal, {
            backdrop: 'static',
            keyboard: false,
            focus: true
        });
    });
};

onMounted(() => {
    pollingInterval = setInterval(fetchAttendees, 5000);
    
    // Initialize modals
    initializeModals();
    
    // Check if there are no prizes and show the modal
    if (props.prizes.length === 0) {
        showPrizeQuantityModal.value = true;
        let modalElement = new bootstrap.Modal(document.getElementById('prizeQuantityModal'));
        modalElement.show();
    }
});

onUnmounted(() => {
    clearInterval(pollingInterval);
    
    // Clean up any open modals
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        const modalInstance = bootstrap.Modal.getInstance(modal);
        if (modalInstance) {
            modalInstance.hide();
        }
    });
});

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

// Prize input for winner
const prizeForWinner = ref('');

// Function to update the prize for a winner
const updatePrizeForWinner = () => {
    if (!selectedPrize.value || !prizeForWinner.value) {
        Swal.fire('Error', 'Prize name cannot be empty!', 'error');
        return;
    }

    const data = new FormData();
    data.append('prize_name', prizeForWinner.value);
    data.append('_method', 'PATCH');

    router.post(route('prize.update', selectedPrize.value.id), data, {
        onSuccess: () => {
            handleModalClose();
            Swal.fire('Updated!', 'Prize has been assigned to the winner.', 'success');
        },
        onError: (errors) => {
            Swal.fire('Error!', 'There was an issue updating the prize.', 'error');
            console.error(errors);
        }
    });
};

// ✅ Open Modal and Start Animation
const openPickWinnerModal = () => {
    if (!props.attendees.length) {
        Swal.fire('No Attendees', 'There are no attendees for this location.', 'warning');
        return;
    }
    if (!eligibleAttendees.value.length) {
        Swal.fire('No Eligible Attendees', 'No attendees entered today or all have already won.', 'warning');
        return;
    }

    selectedPrize.value = null;
    selectedWinner.value = null;
    winnerDisplay.value = 'Searching for a winner...';
    isPicking.value = true;

    let modalElement = new bootstrap.Modal(document.getElementById('pickWinnerModal'));
    modalElement.show();

    animationInterval = setInterval(() => {
        const randomIndex = Math.floor(Math.random() * eligibleAttendees.value.length);
        const randomAttendee = eligibleAttendees.value[randomIndex];
        winnerDisplay.value = `${randomAttendee.first_name} ${randomAttendee.last_name}`;
    }, 100);

    setTimeout(() => {
        clearInterval(animationInterval);
        isPicking.value = false;

        const finalIndex = Math.floor(Math.random() * eligibleAttendees.value.length);
        selectedWinner.value = eligibleAttendees.value[finalIndex];
        winnerDisplay.value = `${selectedWinner.value.first_name} ${selectedWinner.value.last_name}`;
    }, 3000);
};

// Add this computed property to find the attendee data
const findAttendeeByEmail = (email) => {
    return props.attendees.find(attendee => attendee.email_address === email);
};

// Update the openPrizeDetailsModal function
const openPrizeDetailsModal = (winner) => {
    const attendeeData = findAttendeeByEmail(winner.winner_email);
    selectedPrize.value = {
        ...winner,
        winner_gender: attendeeData?.gender || '',
        winner_age: attendeeData?.age || ''
    };
    prizeForWinner.value = ''; // Reset the prize input
    
    let modalElement = new bootstrap.Modal(document.getElementById('prizeDetailsModal'));
    modalElement.show();
    
    // Focus on the prize input after modal is shown
    nextTick(() => {
        if (prizeInput.value) {
            prizeInput.value.focus();
        }
    });
};

// ✅ Save the Winner (without prize initially)
const confirmWinner = () => {
    if (!selectedWinner.value) {
        Swal.fire('Error', 'No winner selected.', 'error');
        return;
    }

    if (isSubmitting.value) {
        return; // Prevent double submission
    }

    isSubmitting.value = true;

    // Create a new entry with winner but empty prize
    const data = new FormData();
    data.append('prize_name', 'null'); // Prize will be set later - using 'null' string
    data.append('event_id', props.event.id);
    data.append('location_id', props.location.id);
    data.append('winner_name', selectedWinner.value.first_name + " " + selectedWinner.value.last_name);
    data.append('winner_email', selectedWinner.value.email_address);
    data.append('winner_mobile_number', selectedWinner.value.mobile_number);
    data.append('winner_gender', selectedWinner.value.gender);
    data.append('winner_age', selectedWinner.value.age);

    router.post(route('prize.store'), data, {
        onSuccess: () => {
            let modalElement = bootstrap.Modal.getInstance(document.getElementById('pickWinnerModal'));
            modalElement.hide();
            Swal.fire('Winner Selected!', `${selectedWinner.value.first_name} has been selected!`, 'success');
            isSubmitting.value = false;
        },
        onError: (errors) => {
            console.error('Error saving winner:', errors);
            Swal.fire('Error!', 'There was an issue saving the winner.', 'error');
            isSubmitting.value = false;
        }
    });
};

const pickAgain = () => {
    if (!eligibleAttendees.value.length) {
        Swal.fire('No Eligible Attendees', 'No attendees entered today or all have already won.', 'warning');
        return;
    }

    selectedPrize.value = null;
    selectedWinner.value = null;
    winnerDisplay.value = 'Searching for a winner...';
    isPicking.value = true;

    // Remove backdrop if any (manual force cleanup)
    // const backdrop = document.querySelector('.modal-backdrop');
    // if (backdrop) {
    //     backdrop.remove();
    // }

    // Restart animation
    if (animationInterval) clearInterval(animationInterval);

    animationInterval = setInterval(() => {
        const randomIndex = Math.floor(Math.random() * eligibleAttendees.value.length);
        const randomAttendee = eligibleAttendees.value[randomIndex];
        winnerDisplay.value = `${randomAttendee.first_name} ${randomAttendee.last_name}`;
    }, 100);

    setTimeout(() => {
        clearInterval(animationInterval);
        isPicking.value = false;

        const finalIndex = Math.floor(Math.random() * eligibleAttendees.value.length);
        selectedWinner.value = eligibleAttendees.value[finalIndex];
        winnerDisplay.value = `${selectedWinner.value.first_name} ${selectedWinner.value.last_name}`;
    }, 3000);
};


// ✅ Compute attendees who have NOT been picked as winners yet
const eligibleAttendees = computed(() => {
    return props.attendees.filter(attendee => {
        const isWinner = props.prizes.some(prize => prize.winner_email === attendee.email_address);
        const matchesLocation = attendee.location_id === props.location.id;

        // Apply dynamic filters based on questions
        if (!hasActiveFilters.value) {
            return !isWinner && matchesLocation;
        }

        const activeFilters = filters.value.filter(f => f.questionColumn && f.filterValue);
        
        if (activeFilters.length === 0) {
            return !isWinner && matchesLocation;
        }

        let matchesFilter = true;

        if (filterCondition.value === 'AND') {
            // All filters must match
            matchesFilter = activeFilters.every(filter => {
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
                // Case-insensitive comparison
                return fieldValue.toString().toLowerCase().includes(filter.filterValue.toLowerCase());
            });
        } else {
            // OR: At least one filter must match
            matchesFilter = activeFilters.some(filter => {
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
                // Case-insensitive comparison
                return fieldValue.toString().toLowerCase().includes(filter.filterValue.toLowerCase());
            });
        }

        return !isWinner && matchesLocation && matchesFilter;
    });
});

// const openSettingsModal = () => {
//     const modalEl = document.getElementById('settingsModal');
//     if (modalEl) {
//         const modal = new bootstrap.Modal(modalEl);
//         modal.show();
//     }
// };

// Add these to your script setup section:
const prizeInput = ref(null);

// Add this new method to handle modal closing
const handleModalClose = () => {
    // Clear focus before closing
    if (document.activeElement instanceof HTMLElement) {
        document.activeElement.blur();
    }
    
    // Reset form values
    prizeForWinner.value = '';
    selectedPrize.value = null;
    
    // Hide modal
    const modalElement = document.getElementById('prizeDetailsModal');
    if (modalElement) {
        const modalInstance = bootstrap.Modal.getInstance(modalElement);
        if (modalInstance) {
            modalInstance.hide();
        }
    }
};

</script>
<style>
/* Global modal styles - not scoped to ensure it affects all modals */
.modal-backdrop {
    background-color: rgba(0, 0, 0, 0.5) !important;
    backdrop-filter: blur(2px);
}

.modal-content {
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.5);
}
</style>
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

.pick-winner-btn {
    margin: 25px auto;
    display: block;
    padding: 15px 30px;
    font-size: 1.2rem;
    font-weight: bold;
}

.winners-table {
    margin-top: 20px;
}
</style>
<template>
    <Head title="Pick a Winner Location Page" />

    <PickaWinnerLayout>
        <div class="p-2 text-white" style="background-color: #151515;">
            <div class="mt-3 p-2">
                <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div class="d-flex flex-column flex-md-row justify-content-between items-center pb-4">
                        <div class="mb-3 mb-md-0 text-center text-md-start">
                            <img 
                            :src="`/storage/${event.event_logo}`" 
                            alt="Event Logo" 
                            class="img-fluid" 
                            style="max-width: 200px; max-height: 300px;"
                            >
                        </div>
                        <h2 class="text-2xl font-bold text-center text-white">
                            {{ event.event_name }} <br> {{ location.name }} <br> {{ formatLocationDateTime(location.date, location.time) }}

                        </h2>
                        <div class="mt-2 bg-cyan-700 px-3 py-1 rounded">
                            <span class="font-bold">Participants: {{ filteredAttendees.length }}</span>
                        </div>
                    </div>

                    <!-- Filter Section -->
                    <div class="d-flex justify-content-center flex-wrap gap-3 mt-3 mb-3">
                        <!-- Filter Toggle Button -->
                        <button 
                            @click="showFilters = !showFilters"
                            class="px-4 py-2 rounded transition-colors text-white"
                            :class="hasActiveFilters ? 'bg-orange-500 hover:bg-orange-600' : 'bg-gray-600 hover:bg-gray-700'"
                        >
                            <i class="fas fa-filter mr-2"></i>
                            Filters {{ hasActiveFilters ? `(${filters.filter(f => f.questionColumn && f.filterValue).length})` : '' }}
                        </button>
                    </div>

                    <!-- Dynamic Filters Panel -->
                    <div v-if="showFilters" class="mb-4 p-4 bg-gray-800 border border-gray-600 rounded" style="max-width: 1200px; margin: 0 auto;">
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
                        
                        <!-- Reset Filters Button -->
                        <div v-if="hasActiveFilters" class="text-center mt-3">
                            <button 
                                @click="resetFilters"
                                class="btn btn-outline-warning"
                            >
                                <i class="fas fa-undo mr-2"></i>
                                Reset All Filters
                            </button>
                        </div>
                    </div>

                    <!-- Centered Pick a Winner Button -->
                    <div class="d-flex justify-content-center gap-3 my-5 flex-wrap">
                        <button class="btn btn-primary btn-lg" @click="openPickWinnerModal">
                            🎉 Pick a Winner 🎉
                        </button>
                    </div>


                    
                    <div v-if="prizes.length > 0" class="overflow-hidden border-gray-700 shadow-sm" style="background-color: #151515;">
                        <div class="text-white">
                            <div class="mt-3 overflow-x-auto winners-table">
                                <table class="min-w-full border-collapse border border-gray-700">
                                    <thead>
                                        <tr class="bg-cyan-500">
                                            <!-- <th class="border border-gray-700 p-2 text-white">Prize</th> -->
                                            <th class="border border-gray-700 p-2 text-white">Name</th>
                                            <th class="border border-gray-700 p-2 text-white">Prize</th>
                                            <!-- <th class="border border-gray-700 p-2 text-white">Email</th>
                                            <th class="border border-gray-700 p-2 text-white">Mobile Number</th> -->
                                            <th class="border border-gray-700 p-2 text-white">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(prize, index) in prizes" :key="index" class="text-left" 
                                        :class="{'bg-cyan-700': prize.winner_email}">
                                            <!-- <td class="border border-gray-700 p-2">{{ prize.prize_name }}</td> -->
                                            <td class="border border-gray-700 p-2">{{ prize.winner }}</td>
                                            <td class="border border-gray-700 p-2">{{ (prize.prize_name && prize.prize_name !== 'null') ? prize.prize_name : '' }}</td>
                                            <!-- <td class="border border-gray-700 p-2">{{ prize.winner_email || 'No Winner Yet' }}</td>
                                            <td class="border border-gray-700 p-2">{{ prize.winner_mobile_number || 'No Winner Yet' }}</td> -->
                                            <td class="border border-gray-700 p-2 text-center">
                                                <a class="btn btn-success me-2" @click="openPrizeDetailsModal(prize)">
                                                    <i class="fa-solid fa-trophy"></i> Prize
                                                </a>
                                                <a class="btn btn-danger" @click="destroy(prize.id)"><i class="fa-solid fa-trash"></i></a>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pick Winner Modal -->
            <div class="modal fade" id="pickWinnerModal" tabindex="-1" aria-labelledby="pickWinnerModalLabel" 
                data-bs-backdrop="static" data-bs-keyboard="false">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content bg-gray-800 text-white">
                        <div class="modal-header border-gray-700">
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body text-center">
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
                                <p><strong>Gender:</strong> {{ selectedWinner.gender }}</p>
                                <p><strong>Phone:</strong> {{ selectedWinner.mobile_number }}</p>
                                <p><strong>Age:</strong> {{ selectedWinner.age }}</p>
                            </template>
                        </div>
                        <div class="modal-footer border-gray-700 justify-content-center">
                            <div class="d-block w-100 text-center">
                                <button 
                                    type="button"
                                    class="btn btn-success btn-lg d-block mb-3" 
                                    style="font-size: 17px; height: 50px; width: 150px; margin: 0 auto;"
                                    v-if="!isPicking" 
                                    @click="confirmWinner()"
                                    :disabled="isSubmitting"
                                >
                                    <span v-if="isSubmitting" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                                    {{ isSubmitting ? 'Saving...' : 'Save Winner' }}
                                </button>
                                <button type="button" style="width: 140px; margin: 0 auto; font-size: 12px;" class="btn btn-secondary d-block" v-if="!isPicking" @click="pickAgain()">Nope! Pick Again...</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Prize Details Modal -->
            <div class="modal fade" id="prizeDetailsModal" tabindex="-1" aria-labelledby="prizeDetailsModalLabel"
                data-bs-backdrop="static" data-bs-keyboard="false" role="dialog" aria-modal="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content bg-gray-900 text-white">
                        <div class="modal-header border-gray-700">
                            <h5 class="modal-title" id="prizeDetailsModalLabel">🏆 Winner Details & Prize 🏆</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" @click="handleModalClose"></button>
                        </div>
                        <div class="modal-body">
                            <div v-if="selectedPrize" class="winner-details mb-4">
                                <h5 class="text-xl font-bold mb-3">Winner Information</h5>
                                <p><strong>Name:</strong> {{ selectedPrize.winner }}</p>
                                <p><strong>Email:</strong> {{ selectedPrize.winner_email }}</p>
                                <p><strong>Phone:</strong> {{ selectedPrize.winner_mobile_number }}</p>
                                <p><strong>Gender:</strong> {{ selectedPrize.winner_gender || selectedPrize.gender }}</p>
                                <p><strong>Age:</strong> {{ selectedPrize.winner_age || selectedPrize.age }}</p>
                            </div>
                            
                            <div class="form-group mt-4">
                                <label for="prizeDetailInput" class="form-label">Prize Description</label>
                                <input 
                                    type="text" 
                                    class="form-control bg-gray-800 text-white" 
                                    id="prizeDetailInput" 
                                    v-model="prizeForWinner" 
                                    placeholder="Enter the prize name for this winner"
                                    ref="prizeInput"
                                >
                                <small class="text-muted">Enter the prize details for this winner</small>
                            </div>
                        </div>
                        <div class="modal-footer border-gray-700">
                            <button type="button" class="btn btn-secondary" @click="handleModalClose">Cancel</button>
                            <button type="button" class="btn btn-success" @click="updatePrizeForWinner">
                                Update Prize
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Create Prize Modal (keeping it for manual prize creation if needed) -->
            <!-- <div class="modal fade" id="createPrizeModal" tabindex="-1" aria-labelledby="createPrizeModalLabel">
                <div class="modal-dialog">
                    <div class="modal-content bg-gray-900 text-white">
                        <div class="modal-header border-gray-700">
                            <h5 class="modal-title">{{ isEditing ? 'Edit Prize' : 'Add a Prize' }}</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="form-group">
                                <label for="prizeName" class="form-label">Prize Name</label>
                                <input 
                                    type="text" 
                                    class="form-control bg-gray-800 text-white" 
                                    id="prizeName" 
                                    v-model="form.prize_name" 
                                    placeholder="Enter prize name"
                                >
                            </div>
                        </div>
                        <div class="modal-footer border-gray-700">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" @click="savePrize">
                                {{ isEditing ? 'Update Prize' : 'Save Prize' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>           -->
            <!-- <div class="modal fade" id="settingsModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content bg-dark text-white">
                    <div class="modal-header">
                        <h5 class="modal-title">🎛 Winner Settings</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label">Filter by Gender:</label>
                        <select class="form-select" v-model="genderFilter">
                        <option value="">No Filter</option>
                        <option value="male">Only Male</option>
                        <option value="female">Only Female</option>
                        <option value="Nonbinary/Other">Nonbinary/Other</option>
                        </select>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                    </div>
                </div>
            </div> -->

        </div>
    </PickaWinnerLayout>
</template>