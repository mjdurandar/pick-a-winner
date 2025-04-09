<script setup>
import Swal from 'sweetalert2';
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import PickaWinnerLayout from '@/Layouts/PickaWinnerLayout.vue';
import { Head } from '@inertiajs/vue3';

// Track if we are editing an event
const isEditing = ref(false);
const ageFilter = ref(''); // 'under21', '22to44', '45plus', ''
const filterCondition = ref('AND'); // 'AND' or 'OR'

// ✅ Search Query
const searchQuery = ref('');
const showSettingsModal = ref(false);
const genderFilter = ref(''); // '' = no filter, 'male', 'female'

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

onMounted(() => {
    pollingInterval = setInterval(fetchAttendees, 5000); // ✅ Fetch attendees every 5 seconds
    
    // Check if there are no prizes and show the modal
    if (props.prizes.length === 0) {
        showPrizeQuantityModal.value = true;
        let modalElement = new bootstrap.Modal(document.getElementById('prizeQuantityModal'));
        modalElement.show();
    }
});

onUnmounted(() => {
    clearInterval(pollingInterval); // ✅ Stop polling when component is destroyed
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
            let modalElement = bootstrap.Modal.getInstance(document.getElementById('prizeDetailsModal'));
            modalElement.hide();
            Swal.fire('Updated!', 'Prize has been assigned to the winner.', 'success');
            prizeForWinner.value = '';
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

// Add a new function to open the prize details modal for a winner
const openPrizeDetailsModal = (winner) => {
    selectedPrize.value = winner;
    prizeForWinner.value = winner.prize_name || '';
    
    let modalElement = new bootstrap.Modal(document.getElementById('prizeDetailsModal'));
    modalElement.show();
};

// ✅ Save the Winner (without prize initially)
const confirmWinner = () => {
    if (!selectedWinner.value) {
        Swal.fire('Error', 'No winner selected.', 'error');
        return;
    }

    // Create a new entry with winner but null prize
    const data = new FormData();
    data.append('prize_name', null); // Prize will be set later
    data.append('event_id', props.event.id);
    data.append('location_id', props.location.id);
    data.append('winner_name', selectedWinner.value.first_name + " " + selectedWinner.value.last_name);
    data.append('winner_email', selectedWinner.value.email_address);
    data.append('winner_mobile_number', selectedWinner.value.mobile_number);

    router.post(route('prize.store'), data, {
        onSuccess: () => {
            let modalElement = bootstrap.Modal.getInstance(document.getElementById('pickWinnerModal'));
            modalElement.hide();
            Swal.fire('Winner Selected!', `${selectedWinner.value.first_name} has been selected!`, 'success');
        },
        onError: (errors) => {
            console.error('Error saving winner:', errors);
            Swal.fire('Error!', 'There was an issue saving the winner.', 'error');
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
    console.log(props.attendees);
    return props.attendees.filter(attendee => {
        const isWinner = props.prizes.some(prize => prize.winner_email === attendee.email_address);
        const matchesLocation = attendee.location_id === props.location.id;

        const normalizedAttendeeGender = attendee.gender?.toLowerCase() || '';
        const normalizedGenderFilter = genderFilter.value.toLowerCase();
        const hasGenderFilter = !!genderFilter.value;
        const matchesGender = hasGenderFilter ? normalizedAttendeeGender === normalizedGenderFilter : true;

        const hasAgeFilter = !!ageFilter.value;
        const matchesAge = hasAgeFilter
            ? (attendee.age === 'Under 21' && ageFilter.value === 'under21') ||
              (attendee.age === '22-44' && ageFilter.value === '22to44') ||
              (attendee.age === '45+' && ageFilter.value === '45plus')
            : true;

        let matchesFilter = true;

        if (filterCondition.value === 'AND') {
            matchesFilter = matchesGender && matchesAge;
        } else if (filterCondition.value === 'OR') {
            matchesFilter = hasGenderFilter || hasAgeFilter
                ? matchesGender || matchesAge
                : true;
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
                            {{ event.event_name }} <br> {{ location.name }} 
                        </h2>
                    </div>

                    <!-- Filter Section -->
                    <div class="d-flex justify-content-center flex-wrap gap-3 mb-3">
                        <!-- Gender Filter -->
                        <div class="form-group text-white">
                            <label class="form-label me-2">Filter by Gender:</label>
                            <select class="form-select" v-model="genderFilter" style="background-color: #1f2937; color: white; border-color: #374151;">
                                <option value="">No Filter</option>
                                <option value="male">Only Male</option>
                                <option value="female">Only Female</option>
                                <option value="Nonbinary/Other">Nonbinary/Other</option>
                            </select>
                        </div>

                        <!-- Age Filter -->
                        <div class="form-group text-white">
                            <label class="form-label me-2">Filter by Age:</label>
                            <select class="form-select" v-model="ageFilter" style="background-color: #1f2937; color: white; border-color: #374151;">
                                <option value="">No Filter</option>
                                <option value="under21">Under 21</option>
                                <option value="22to44">22-44</option>
                                <option value="45plus">45+</option>
                            </select>
                        </div>
 
                        
                        <!-- Filter Condition -->
                        <div class="form-group text-white">
                            <label class="form-label me-2">Condition:</label>
                            <select class="form-select" v-model="filterCondition" style="background-color: #1f2937; color: white; border-color: #374151;">
                                <option value="AND">AND</option>
                                <option value="OR">OR</option>
                            </select>
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
                            </template>
                        </div>
                        <div class="modal-footer border-gray-700">
                            <button type="button" class="btn btn-secondary" v-if="!isPicking" @click="pickAgain()">Pick Again</button>
                            <button type="button" class="btn btn-success" v-if="!isPicking" @click="confirmWinner()">
                                Congratulations!
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Prize Details Modal -->
            <div class="modal fade" id="prizeDetailsModal" tabindex="-1" aria-labelledby="prizeDetailsModalLabel"
                data-bs-backdrop="static" data-bs-keyboard="false">
                <div class="modal-dialog">
                    <div class="modal-content bg-gray-900 text-white">
                        <div class="modal-header border-gray-700">
                            <h5 class="modal-title">🏆 Winner Details & Prize 🏆</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div v-if="selectedPrize" class="winner-details mb-4">
                                <h5 class="text-xl font-bold mb-3">Winner Information</h5>
                                <p><strong>Name:</strong> {{ selectedPrize.winner }}</p>
                                <p><strong>Email:</strong> {{ selectedPrize.winner_email }}</p>
                                <p><strong>Mobile:</strong> {{ selectedPrize.winner_mobile_number }}</p>
                            </div>
                            
                            <div class="form-group mt-4">
                                <label for="prizeDetailInput" class="form-label">Prize Description</label>
                                <input 
                                    type="text" 
                                    class="form-control bg-gray-800 text-white" 
                                    id="prizeDetailInput" 
                                    v-model="prizeForWinner" 
                                    placeholder="Enter prize details"
                                >
                                <small class="text-muted">Enter the prize details for this winner</small>
                            </div>
                        </div>
                        <div class="modal-footer border-gray-700">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-success" @click="updatePrizeForWinner()">
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