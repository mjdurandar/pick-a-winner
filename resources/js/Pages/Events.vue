<script setup>
import Swal from 'sweetalert2';
import { ref, computed } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

// Props from Laravel
defineProps({
    events: Array
});

// Track if we are editing an event
const isEditing = ref(false);
const page = usePage();
const user = computed(() => page.props.auth.user || null);
const userRole = computed(() => user.value?.role); 

// Form state
const form = useForm({
    id: null,
    event_name: '',
    event_description: '',
    event_date: '',
    event_year: '',
    event_banner: null, // File input
    event_coordinator: '',
    event_coordinator_email: '',
    event_country: ''
});

// File input reference
const handleFileChange = (event) => {
    form.event_banner = event.target.files[0]; // Assign file to form
};

// Open Modal for Creating a New Event
const openCreateModal = () => {
    isEditing.value = false;
    form.reset(); // Clear form
    document.getElementById('event_banner').value = '';
    let modalElement = new bootstrap.Modal(document.getElementById('createEventModal'));
    modalElement.show();
};

// Open Modal for Editing an Existing Event
const openEditModal = (event) => {
    isEditing.value = true;
    form.id = event.id;
    form.event_name = event.event_name;
    form.event_description = event.event_description;
    form.event_year = event.event_year;
    form.event_date = event.event_date;
    form.event_coordinator = event.event_coordinator;
    form.event_coordinator_email = event.event_coordinator_email;
    form.event_country = event.event_country;
    form.event_banner = null; // Reset file input

    let modalElement = new bootstrap.Modal(document.getElementById('createEventModal'));
    modalElement.show();
};

const attendeesPage = (event) => {
    router.get(route('attendees.index', { eventId: event.id }));
};

// Submit the form (Create or Update)
const saveEvent = () => {
    const data = new FormData();
    data.append('event_name', form.event_name);
    data.append('event_description', form.event_description);
    data.append('event_year', form.event_year);
    data.append('event_date', form.event_date);
    if (form.event_banner) {
        data.append('event_banner', form.event_banner);
    }
    data.append('event_coordinator', form.event_coordinator);
    data.append('event_coordinator_email', form.event_coordinator_email);
    data.append('event_country', form.event_country);

    if (isEditing.value) {
        data.append('_method', 'PATCH'); // Use PATCH for updating
        router.post(route('events.update', form.id), data, {
        onSuccess: () => {
            let modalElement = bootstrap.Modal.getInstance(document.getElementById('createEventModal'));
            modalElement.hide();
            Swal.fire('Updated!', 'Event has been updated.', 'success');
            form.reset();
        },
        onError: (errors) => {
            Swal.fire('Error!', 'There was an issue updating the event.', 'error');
            console.log(errors);
        }
    });
    } else {
        router.post(route('events.store'), data, {
            onSuccess: () => {
                let modalElement = bootstrap.Modal.getInstance(document.getElementById('createEventModal'));
                modalElement.hide();
                Swal.fire('Success!', 'Event has been created.', 'success');
                form.reset();
            },
            onError: (errors) => {
                Swal.fire('Error!', 'There was an issue creating the event.', 'error');
                console.log(errors);
            }
        });
    }
};

const allLocationsPage = (event) => {
    router.get(route('pickawinner.alllocation', { event: event.id }));
};

// Delete Event
const deleteEvent = (id) => {
    Swal.fire({
        title: 'Are you sure?',
        text: 'This action cannot be undone! All Data related to this Event will be deleted. Please download the backup first! To confirm, type DELETE below.',
        icon: 'warning',
        input: 'text', // ✅ Require user input
        inputPlaceholder: 'Type DELETE to confirm',
        showCancelButton: true,
        confirmButtonText: 'Delete Event',
        cancelButtonText: 'Cancel',
        inputValidator: (value) => {
            if (value !== 'DELETE') {
                return 'You must type DELETE to confirm!';
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('events.destroy', id), {
                onSuccess: () => {
                    Swal.fire('Deleted!', 'Event has been deleted.', 'success');
                }
            });
        }
    });
};


const goToSignUpForm = (eventId) => {
    router.get(route('signup.index', { eventId }));
};

</script>

<template>
    <Head title="Events" />
    <AuthenticatedLayout>
        <template #header>
            <div class="d-flex justify-content-between align-items-center">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Events</h2>
                <button @click="openCreateModal" class="btn btn-primary" v-if="userRole === 'admin'">
                    Create Event
                </button>
            </div>
        </template>

        <div class="p-4">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="row">
                    <div v-for="event in events" :key="event.id" class="col-md-4 mb-4">
                        <div class="card">
                            <img style="height: 200px;" :src="'/storage/' + event.event_banner" class="card-img-top" alt="Event Banner" />
                            <div class="card-body">
                                <h5 class="card-title">{{ event.event_name }}</h5>
                                <p class="card-text mb-1" style="font-size: 14px; font-weight: 500;">{{ event.event_country }}</p>
                                <p class="text-muted">📅 {{ event.event_date }}</p>
                                <p class="text-muted">👤 {{ event.event_coordinator }}</p>
                                <div class="d-flex justify-content-between mt-3">
                                    <button @click="goToSignUpForm(event.id)" class="btn btn-primary btn-sm me-2" v-if="userRole === 'admin' || userRole === 'host'">
                                        Sign Up Form
                                    </button>
                                    <div>
                                        <button @click="allLocationsPage(event)" class="btn btn-warning btn-sm me-2" v-if="userRole === 'admin'">
                                            <i class="fa-solid fa-users"></i>
                                        </button>
                                        <button @click="attendeesPage(event)" class="btn btn-success btn-sm me-2" v-if="userRole === 'admin'">
                                            <i class="fa-solid fa-database"></i>
                                        </button>
                                        <button @click="openEditModal(event)" class="btn btn-primary btn-sm me-2" v-if="userRole === 'admin'">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <button @click="deleteEvent(event.id)" class="btn btn-danger btn-sm" v-if="userRole === 'admin'">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bootstrap Modal for Create/Edit -->
        <div class="modal fade" id="createEventModal" tabindex="-1" aria-labelledby="createEventModalLabel">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="createEventModalLabel">{{ isEditing ? 'Edit Event' : 'Create Event' }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form @submit.prevent="saveEvent">
                            <div class="mb-3">
                                <label class="form-label">Event Name</label>
                                <input v-model="form.event_name" type="text" class="form-control" required  maxlength="26" />
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea v-model="form.event_description" class="form-control"></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Event Year</label>
                                <input v-model="form.event_year" type="text" class="form-control" maxlength="4" pattern="\d{4}" required />
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Start Date</label>
                                <input v-model="form.event_date" type="date" class="form-control" required />
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Coordinator Name</label>
                                <input v-model="form.event_coordinator" type="text" class="form-control" required />
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Coordinator Email</label>
                                <input v-model="form.event_coordinator_email" type="email" class="form-control" required />
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Country</label>
                                <select v-model="form.event_country" class="form-select" required>
                                    <option value="AUSTRALIA & NEW ZEALAND">AUSTRALIA & NEW ZEALAND</option>
                                    <option value="USA & CANADA">USA & CANADA</option>
                                    <option value="USA">USA</option>
                                    <option value="Canada">Canada</option>
                                    <option value="UK">UK</option>
                                    <option value="Australia">Australia</option>
                                    <option value="New Zealand">New Zealand</option>
                                    <option value="Germany">Germany</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Event Banner</label>
                                <input type="file" @change="handleFileChange" id="event_banner" class="form-control" />
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-success">
                                    {{ isEditing ? 'Update Event' : 'Save Event' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>


