<script setup>
import Swal from 'sweetalert2';
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

// Track if we are editing an event
const isEditing = ref(false);

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
        text: 'This action cannot be undone! If you delete this prize the winner for this Prize will be gone! Please Download the backup! To confirm, type DELETE below.',
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


</script>

<template>
    <Head title="Pick a Winner Location Page" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Pick a Winner for {{ location.name }} - {{ event.event_name }} <!-- ✅ Display Location & Event Name -->
            </h2>
        </template>
        <!-- ✅ Prizes Button -->
        <div class="mt-3 p-2">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="d-flex justify-content-end">
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
                                        <th class="border border-gray-300 p-2">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(prize, index) in prizes" :key="index" class="text-left">
                                        <td class="border border-gray-300 p-2">{{ prize.prize_name }}</td>
                                        <td class="border border-gray-300 p-2">{{ prize.winner_name || 'Not Selected' }}</td>
                                        <td class="border border-gray-300 p-2">{{ prize.winner_email || 'Not Selected' }}</td>
                                        <td class="border border-gray-300 p-2 flex flex-col md:flex-row gap-2 justify-center">
                                            <a class="btn btn-success">Pick a Winner</a>
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
        <div class="p-2">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <h3 class="text-lg font-semibold mb-4">Attendees at {{ location.name }}</h3>

                        <table class="w-full border-collapse border border-gray-300">
                            <thead>
                                <tr class="bg-gray-200">
                                    <th class="border border-gray-300 p-2">Name</th>
                                    <th class="border border-gray-300 p-2">Email</th>
                                    <th class="border border-gray-300 p-2">Phone Number</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(attendee, index) in attendees" :key="index" class="text-left">
                                    <td class="border border-gray-300 p-2">{{ attendee.first_name }} {{ attendee.last_name }}</td>
                                    <td class="border border-gray-300 p-2">{{ attendee.email_address }}</td>
                                    <td class="border border-gray-300 p-2">{{ attendee.mobile_number }}</td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- ✅ If No Attendees Found -->
                        <div v-if="attendees.length === 0" class="text-gray-600 text-center mt-4">
                            No attendees have registered for this location.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bootstrap Modal for Create/Edit -->
        <div class="modal fade" id="createPrizeModal" tabindex="-1" aria-labelledby="createPrizeModalLabel">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="createPrizeModalLabel">{{ isEditing ? 'Edit Event' : 'Create Event' }}</h5>
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
    </AuthenticatedLayout>
</template>
