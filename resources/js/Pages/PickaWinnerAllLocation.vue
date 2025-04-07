<script setup>
import Swal from 'sweetalert2';
import { ref, computed, onMounted } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import PickaWinnerLayout from '@/Layouts/PickaWinnerLayout.vue';
import { Head } from '@inertiajs/vue3';

// Track if we are editing an event
const isEditing = ref(false);

// ✅ Search Query
const searchQuery = ref('');

// Enhanced search filter with logging
const filteredAttendees = computed(() => {
    // console.log('Search query changed:', searchQuery.value);
    // console.log('Total attendees before filtering:', props.attendees.length);
    
    if (!searchQuery.value) {
        // console.log('No search query - returning all attendees');
        return props.attendees;
    }
    
    const filtered = props.attendees.filter(attendee =>
        `${attendee.first_name} ${attendee.last_name}`.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
        attendee.email_address.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
        attendee.mobile_number.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
        attendee.gender.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
        attendee.location_name?.toLowerCase().includes(searchQuery.value.toLowerCase())
    );
    
    // console.log('Filtered attendees count:', filtered.length);
    return filtered;
});

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
        Swal.fire('No Attendees', 'There are no attendees for this location.', 'warning');
        return;
    }
    if (!eligibleAttendees.value.length) {
        Swal.fire('No Eligible Attendees', 'All attendees have already been chosen as winners.', 'warning');
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

const eligibleAttendees = computed(() => {
    return props.attendees.filter(attendee => 
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
        <div class="p-6 text-white" style="background-color: #151515;">
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
                            <div class="d-flex justify-content-between">
                                <h3 class="text-lg font-semibold mb-4">Attendees at {{ event.event_name }}</h3>
                                <div class="flex items-center space-x-2">
                                    <div class="mb-3">
                                        <input
                                            v-model="searchQuery"
                                            type="text"
                                            placeholder="Search attendees..."
                                            class="w-full p-2 border rounded bg-gray-800 text-white border-gray-700"
                                            style="color: white !important;"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full border-collapse border border-gray-700">
                                    <thead>
                                        <tr class="bg-cyan-500">
                                            <th class="border border-gray-700 p-2 text-white">Name</th>
                                            <th class="border border-gray-700 p-2 text-white">Email</th>
                                            <th class="border border-gray-700 p-2 text-white">Gender</th>
                                            <th class="border border-gray-700 p-2 text-white">Mobile Number</th>
                                            <th class="border border-gray-700 p-2 text-white">Event Location</th>
                                        </tr>
                                    </thead>
                                    <tbody style="background-color: #151515;">
                                        <tr v-for="(attendee, index) in filteredAttendees" :key="index" class="text-left text-white">
                                            <td class="border border-gray-700 p-2">{{ attendee.first_name }} {{ attendee.last_name }}</td>
                                            <td class="border border-gray-700 p-2">{{ attendee.email_address }}</td>
                                            <td class="border border-gray-700 p-2">{{ attendee.gender }}</td>
                                            <td class="border border-gray-700 p-2">{{ attendee.mobile_number }}</td>
                                            <td class="border border-gray-700 p-2">{{ attendee.location_name }}</td>
                                        </tr>
                                        <tr v-if="filteredAttendees.length === 0">
                                            <td colspan="5" class="border border-gray-700 p-4 text-center text-gray-400">
                                                No matching attendees found.
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
                <div class="modal-dialog">
                    <div class="modal-content bg-gray-900 text-white">
                        <div class="modal-header border-gray-700">
                            <h5 class="modal-title">🎉 Picking a Winner 🎉</h5>
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