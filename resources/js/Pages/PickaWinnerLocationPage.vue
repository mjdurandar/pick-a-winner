<script setup>
import Swal from 'sweetalert2';
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import PickaWinnerLayout from '@/Layouts/PickaWinnerLayout.vue';
import { Head } from '@inertiajs/vue3';

// Track if we are editing an event
const isEditing = ref(false);

// ✅ Search Query
const searchQuery = ref('');

// ✅ Checkbox to filter today's attendees (default: checked)
const onlyTodayEntries = ref(true);

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

// ✅ Open Modal and Start Animation
const openPickWinnerModal = (prize) => {
    if (!props.attendees.length) {
        Swal.fire('No Attendees', 'There are no attendees for this location.', 'warning');
        return;
    }
    if (!eligibleAttendees.value.length) {
        Swal.fire('No Eligible Attendees', 'No attendees entered today or all have already won.', 'warning');
        return;
    }

    if (prize.winner_email) {
        Swal.fire({
            title: 'Already Has a Winner!',
            text: `This prize already has a winner (${prize.winner}). Would you like to pick a new winner?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, Pick Again',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                proceedToPickWinner(prize);
            }
        });
    } else {
        proceedToPickWinner(prize);
    }
};

// ✅ Function to Start the Winner Picking Animation
const proceedToPickWinner = (prize) => {
    selectedPrize.value = prize;
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

// ✅ Compute attendees who have NOT been picked as winners yet AND match today's date if the checkbox is checked
const eligibleAttendees = computed(() => {
    return props.attendees.filter(attendee => {
        const isWinner = props.prizes.some(prize => prize.winner_email === attendee.email_address);

        let entryDate = null;
        if (attendee.created_at) {
            try {
                entryDate = new Date(attendee.created_at).toISOString().split('T')[0]; // ✅ Convert to UTC
            } catch (error) {
                console.error("Error parsing created_at:", attendee.created_at);
            }
        }

        // ✅ Ensure the attendee is from the selected location
        const matchesLocation = attendee.location_id === props.location.id;

        return !isWinner && matchesLocation && (!onlyTodayEntries.value || entryDate === getTodayDate());
    });
});

// const reload = () => {
//     window.location.reload();
// };
</script>

<template>
    <Head title="Pick a Winner Location Page" />

    <PickaWinnerLayout>
        <div class="p-6">
            <h2 class="text-2xl font-bold mb-6 text-center">
                Pick a Winner for {{ location.name }} - {{ event.event_name }}
            </h2>
            <!-- ✅ Prizes Button -->
            <div class="mt-3 p-2">
                <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div class="p-6 text-gray-900">
                            <div class="d-flex justify-content-between">
                                <!-- ✅ Checkbox: Filter by Today's Entries -->
                                <label class="flex items-center space-x-2">
                                    <input type="checkbox" v-model="onlyTodayEntries" class="form-checkbox text-green-500">
                                    <span>Only pick winners from today's entries.</span>
                                </label>
                                <button 
                                    class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-700"
                                    @click="openAddPrizeModal()"
                                >
                                    Add Prizes
                                </button>
                            </div>
                            
                            <div class="mt-3 overflow-x-auto">
                                <table class="min-w-full border-collapse border border-gray-300">
                                    <thead>
                                        <tr class="bg-green-200">
                                            <th class="border border-gray-300 p-2">Prizes</th>
                                            <th class="border border-gray-300 p-2">Name</th>
                                            <th class="border border-gray-300 p-2">Email</th>
                                            <th class="border border-gray-300 p-2">Mobile Number</th>
                                            <th class="border border-gray-300 p-2">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(prize, index) in prizes" :key="index" class="text-left">
                                            <td class="border border-gray-300 p-2">{{ prize.prize_name }}</td>
                                            <td class="border border-gray-300 p-2">{{ prize.winner }}</td>
                                            <td class="border border-gray-300 p-2">{{ prize.winner_email || 'No Winner Yet' }}</td>
                                            <td class="border border-gray-300 p-2">{{ prize.winner_mobile_number || 'No Winner Yet' }}</td>
                                            <td class="border border-gray-300 p-2 flex flex-col md:flex-row gap-2 justify-center">
                                                <a class="btn btn-success" @click="openPickWinnerModal(prize)">Pick a Winner</a>
                                                <a class="btn btn-primary" @click="openEditModal(prize)"><i class="fa-solid fa-pen-to-square"></i></a>
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
            <!-- ✅ Attendees Table -->
            <div class="p-2 pb-5">
                <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div class="p-6 text-gray-900">
                            <div class="d-flex justify-content-between">
                                <h3 class="text-lg font-semibold mb-4">Attendees at {{ location.name }}</h3>
                                <div class="flex items-center space-x-2">
                                    <!-- <button class="btn btn-primary mb-3" @click="reload"><i class="fa-solid fa-arrows-rotate"></i></button> -->
                                    <!-- ✅ Search Bar -->
                                    <div class="mb-3">
                                        <input
                                            v-model="searchQuery"
                                            type="text"
                                            placeholder="Search attendees..."
                                            class="w-full p-2 border rounded"
                                        />
                                    </div>
                                </div>
                            </div>
                            <!-- ✅ Attendees Table -->
                            <div class="overflow-x-auto">
                                <table class="w-full border-collapse border border-gray-300">
                                    <thead>
                                        <tr class="bg-gray-200">
                                            <th class="border border-gray-300 p-2">Name</th>
                                            <th class="border border-gray-300 p-2">Email</th>
                                            <th class="border border-gray-300 p-2">Gender</th>
                                            <th class="border border-gray-300 p-2">Mobile Number</th>
                                            <th class="border border-gray-300 p-2">Event Location</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(attendee, index) in filteredAttendees" :key="index" class="text-left">
                                            <td class="border border-gray-300 p-2">{{ attendee.first_name }} {{ attendee.last_name }}</td>
                                            <td class="border border-gray-300 p-2">{{ attendee.email_address }}</td>
                                            <td class="border border-gray-300 p-2">{{ attendee.gender }}</td>
                                            <td class="border border-gray-300 p-2">{{ attendee.mobile_number }}</td>
                                            <td class="border border-gray-300 p-2">{{ attendee.location_name }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <!-- ✅ If No Attendees Found -->
                            <!-- <div v-if="filteredAttendees.length === 0" class="text-gray-600 text-center mt-4">
                                No matching attendees found.
                            </div> -->
                            <!-- ✅ If No Attendees Found -->
                            <div v-if="attendees.length === 0" class="text-gray-600 text-center mt-4">
                                No attendees have registered for this location.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pick Winner Modal -->
            <div class="modal fade" id="pickWinnerModal" tabindex="-1" aria-labelledby="pickWinnerModalLabel" 
                data-bs-backdrop="static" data-bs-keyboard="false">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">🎉 Picking a Winner 🎉</h5>
                            <!-- <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" v-if="!isPicking"></button> -->
                        </div>
                        <div class="modal-body text-center">
                            <h3 class="text-xl font-bold text-green-600">
                                <template v-if="isPicking">
                                    🔄 Searching...
                                </template>
                                <template v-else>
                                    🎉 Winner Selected! 🎉
                                </template>
                            </h3>

                            <div class="text-2xl font-bold text-blue-500 mt-3">
                                <span v-if="isPicking" class="animate-pulse">{{ winnerDisplay }}</span>
                                <span v-else class="text-green-500">{{ winnerDisplay }}</span>
                            </div>

                            <template v-if="selectedWinner && !isPicking">
                                <p class="mt-3"><strong>Name:</strong> {{ selectedWinner.first_name }} {{ selectedWinner.last_name }}</p>
                                <p><strong>Email:</strong> {{ selectedWinner.email_address }}</p>
                                <p><strong>Phone:</strong> {{ selectedWinner.mobile_number }}</p>
                            </template>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" v-if="!isPicking" @click="confirmCancel()">Cancel</button>
                            <button type="button" class="btn btn-success" v-if="!isPicking" @click="confirmWinner()">
                                Confirm Winner
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bootstrap Modal for Create/Edit -->
            <div class="modal fade" id="createPrizeModal" tabindex="-1" aria-labelledby="createPrizeModalLabel">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="createPrizeModalLabel">{{ isEditing ? 'Edit Prize' : 'Create Prize' }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form @submit.prevent="savePrize">
                                <div class="mb-3">
                                    <label class="form-label">Prize</label>
                                    <input v-model="form.prize_name" type="text" class="form-control" required />
                                </div>

                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-success">
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
