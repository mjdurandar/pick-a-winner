<script setup>
import Swal from 'sweetalert2';
import { ref, computed } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';

// Props from Laravel
const props = defineProps({
    films: Array
});

// Track if we are editing a film
const isEditing = ref(false);
const page = usePage();
const user = computed(() => page.props.auth.user || null);
const userRole = computed(() => user.value?.role);

// Form state
const form = useForm({
    id: null,
    name: '',
    country: ''
});

// Open Modal for Creating a New Film
const openCreateModal = () => {
    isEditing.value = false;
    form.reset(); // Clear form
    let modalElement = new bootstrap.Modal(document.getElementById('createFilmModal'));
    modalElement.show();
};

// Open Modal for Editing an Existing Film
const openEditModal = (film) => {
    isEditing.value = true;
    form.id = film.id;
    form.name = film.name;
    form.country = film.country || '';

    let modalElement = new bootstrap.Modal(document.getElementById('createFilmModal'));
    modalElement.show();
};

// Submit the form (Create or Update)
const saveFilm = () => {
    if (isEditing.value) {
        form.put(route('films.update', form.id), {
            onSuccess: () => {
                let modalElement = bootstrap.Modal.getInstance(document.getElementById('createFilmModal'));
                modalElement.hide();
                Swal.fire('Updated!', 'Film has been updated.', 'success');
                form.reset();
            },
            onError: (errors) => {
                Swal.fire('Error!', 'There was an issue updating the film.', 'error');
                console.log(errors);
            }
        });
    } else {
        form.post(route('films.store'), {
            onSuccess: () => {
                let modalElement = bootstrap.Modal.getInstance(document.getElementById('createFilmModal'));
                modalElement.hide();
                Swal.fire('Created!', 'Film has been created.', 'success');
                form.reset();
            },
            onError: (errors) => {
                Swal.fire('Error!', 'There was an issue creating the film.', 'error');
                console.log(errors);
            }
        });
    }
};

// Film Ticket Report
const showFilmReportModal = ref(false);
const filmReportData = ref(null);
const isLoadingFilmReport = ref(false);
const selectedFilmForReport = ref(null);

// Event Ticket Report
const showEventReportModal = ref(false);
const eventReportData = ref(null);
const isLoadingEventReport = ref(false);
const selectedEventForReport = ref(null);

// Expanded film to show its events
const expandedFilmId = ref(null);

const toggleFilmEvents = (filmId) => {
    expandedFilmId.value = expandedFilmId.value === filmId ? null : filmId;
};

const openFilmReportModal = async (film) => {
    selectedFilmForReport.value = film;
    isLoadingFilmReport.value = true;
    showFilmReportModal.value = true;
    
    try {
        const response = await axios.get(route('film.ticketReport', film.id));
        filmReportData.value = response.data;
    } catch (error) {
        console.error('Error fetching film ticket report:', error);
        Swal.fire('Error', 'Failed to load film ticket report', 'error');
        showFilmReportModal.value = false;
    } finally {
        isLoadingFilmReport.value = false;
    }
};

const closeFilmReportModal = () => {
    showFilmReportModal.value = false;
    filmReportData.value = null;
    selectedFilmForReport.value = null;
};

const openEventReportModal = async (event) => {
    selectedEventForReport.value = event;
    isLoadingEventReport.value = true;
    showEventReportModal.value = true;
    
    try {
        const response = await axios.get(route('event.ticketReport', event.id));
        eventReportData.value = response.data;
    } catch (error) {
        console.error('Error fetching event ticket report:', error);
        Swal.fire('Error', 'Failed to load event ticket report', 'error');
        showEventReportModal.value = false;
    } finally {
        isLoadingEventReport.value = false;
    }
};

const closeEventReportModal = () => {
    showEventReportModal.value = false;
    eventReportData.value = null;
    selectedEventForReport.value = null;
};

const goToEventLocations = (event) => {
    router.get(route('location.locationpage', event.id));
};

// Delete Film
const deleteFilm = (filmId) => {
    Swal.fire({
        title: 'Are you sure?',
        text: "You won't be able to revert this!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('films.destroy', filmId), {
                onSuccess: () => {
                    Swal.fire('Deleted!', 'Film has been deleted.', 'success');
                },
                onError: () => {
                    Swal.fire('Error!', 'There was an issue deleting the film.', 'error');
                }
            });
        }
    });
};
</script>

<template>
    <Head title="Films" />

    <AuthenticatedLayout>
        <template #header>
            <div class="d-flex justify-content-between align-items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Films</h2>
                <button 
                    v-if="userRole === 'admin'"
                    @click="openCreateModal" 
                    class="btn" 
                    style="background-color: #16C3D9; color: white;"
                >
                    <i class="fa-solid fa-plus me-2"></i>Create Film
                </button>
            </div>
        </template>

        <div class="p-4">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="row">
                            <div v-for="film in props.films" :key="film.id" class="col-md-4 mb-4">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h5 class="card-title mb-1">{{ film.name }}</h5>
                                                <p class="text-muted mb-1" v-if="film.country">
                                                    <small>{{ film.country }}</small>
                                                </p>
                                                <p class="text-muted mb-2">
                                                    <small>{{ film.events_count || 0 }} Event(s)</small>
                                                </p>
                                            </div>
                                            <button 
                                                v-if="film.events && film.events.length"
                                                @click="toggleFilmEvents(film.id)"
                                                class="btn btn-sm btn-outline-secondary"
                                                :title="expandedFilmId === film.id ? 'Hide Events' : 'Show Events'"
                                            >
                                                <i :class="expandedFilmId === film.id ? 'fa-solid fa-chevron-up' : 'fa-solid fa-chevron-down'"></i>
                                            </button>
                                        </div>

                                        <!-- Connected Events for this Film -->
                                        <div v-if="film.events && film.events.length && expandedFilmId === film.id" class="mt-3 border-top pt-3">
                                            <h6 class="mb-2">Events for this Film</h6>
                                            <div class="list-group small">
                                                <div 
                                                    v-for="event in film.events" 
                                                    :key="event.id" 
                                                    class="list-group-item d-flex justify-content-between align-items-center py-2"
                                                >
                                                    <div>
                                                        <div class="fw-semibold">{{ event.event_name }}</div>
                                                        <div class="text-muted">
                                                            <small>{{ event.event_date }}</small>
                                                        </div>
                                                    </div>
                                                    <div class="d-flex gap-2">
                                                        <button 
                                                            @click="openEventReportModal(event)" 
                                                            class="btn btn-sm btn-outline-secondary"
                                                            title="View Report for Ticket & Win Form"
                                                        >
                                                            Report
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="d-flex justify-content-end mt-3" v-if="userRole === 'admin'">
                                            <button 
                                                @click="openFilmReportModal(film)" 
                                                class="btn btn-sm me-2" 
                                                style="background-color: #6c757d; color: white;"
                                                title="View Report for Ticket & Win Form"
                                            >
                                                Report
                                            </button>
                                            <button 
                                                @click="openEditModal(film)" 
                                                class="btn btn-sm me-2" 
                                                style="background-color: #16C3D9; color: white;"
                                            >
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </button>
                                            <button 
                                                @click="deleteFilm(film.id)" 
                                                class="btn btn-sm" 
                                                style="background-color: #FF5349; color: white;"
                                            >
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-if="props.films.length === 0" class="text-center py-5">
                            <p class="text-gray-500">No films found. Create your first film!</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bootstrap Modal for Create/Edit -->
        <div class="modal fade" id="createFilmModal" tabindex="-1" aria-labelledby="createFilmModalLabel">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="createFilmModalLabel">{{ isEditing ? 'Edit Film' : 'Create Film' }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form @submit.prevent="saveFilm">
                            <div class="mb-3">
                                <label class="form-label">Film Name</label>
                                <input 
                                    v-model="form.name" 
                                    type="text" 
                                    class="form-control" 
                                    required 
                                    maxlength="255"
                                    :class="{ 'is-invalid': form.errors.name }"
                                />
                                <div v-if="form.errors.name" class="invalid-feedback">
                                    {{ form.errors.name }}
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Country</label>
                                <select v-model="form.country" class="form-select" :class="{ 'is-invalid': form.errors.country }">
                                    <option value="">Select a country (optional)</option>
                                    <option value="AUSTRALIA & NEW ZEALAND">AUSTRALIA & NEW ZEALAND</option>
                                    <option value="USA & CANADA">USA & CANADA</option>
                                    <option value="USA">USA</option>
                                    <option value="Canada">Canada</option>
                                    <option value="UK">UK</option>
                                    <option value="Australia">Australia</option>
                                    <option value="New Zealand">New Zealand</option>
                                    <option value="Germany">Germany</option>
                                </select>
                                <div v-if="form.errors.country" class="invalid-feedback">
                                    {{ form.errors.country }}
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn" style="background-color: #16C3D9; color: white;" :disabled="form.processing">
                                    <span v-if="form.processing" class="spinner-border spinner-border-sm me-2"></span>
                                    {{ isEditing ? 'Update' : 'Create' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Film Ticket Report Modal -->
        <div v-if="showFilmReportModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-7xl w-full mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">Film Data Report - {{ selectedFilmForReport?.name }}</h3>
                    <button @click="closeFilmReportModal" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                
                <div v-if="isLoadingFilmReport" class="text-center py-8">
                    <i class="fa-solid fa-spinner fa-spin text-2xl text-blue-500"></i>
                    <p class="mt-2 text-gray-600">Loading report...</p>
                </div>
                
                <div v-else-if="filmReportData" class="space-y-6">
                    <!-- Overall Summary Cards -->
                    <div class="grid grid-cols-6 gap-4">
                        <div class="bg-blue-50 p-4 rounded-lg">
                            <h4 class="text-sm font-medium text-blue-800 mb-1">Ticket Emails</h4>
                            <p class="text-2xl font-bold text-blue-600">{{ filmReportData.summary.ticket_emails_count }}</p>
                        </div>
                        <div class="bg-purple-50 p-4 rounded-lg">
                            <h4 class="text-sm font-medium text-purple-800 mb-1">Sign-Up Emails</h4>
                            <p class="text-2xl font-bold text-purple-600">{{ filmReportData.summary.signup_emails_count }}</p>
                        </div>
                        <div class="bg-orange-50 p-4 rounded-lg">
                            <h4 class="text-sm font-medium text-orange-800 mb-1">Duplicates</h4>
                            <p class="text-2xl font-bold text-orange-600">{{ filmReportData.summary.duplicate_emails_count }}</p>
                            <p class="text-xs text-orange-600 mt-1">In both</p>
                        </div>
                        <div class="bg-green-50 p-4 rounded-lg">
                            <h4 class="text-sm font-medium text-green-800 mb-1">Ticket Only</h4>
                            <p class="text-2xl font-bold text-green-600">{{ filmReportData.summary.ticket_only_count }}</p>
                        </div>
                        <div class="bg-yellow-50 p-4 rounded-lg">
                            <h4 class="text-sm font-medium text-yellow-800 mb-1">Sign-Up Only</h4>
                            <p class="text-2xl font-bold text-yellow-600">{{ filmReportData.summary.signup_only_count }}</p>
                        </div>
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <h4 class="text-sm font-medium text-gray-800 mb-1">Total Unique</h4>
                            <p class="text-2xl font-bold text-gray-600">{{ filmReportData.summary.total_unique_emails }}</p>
                        </div>
                    </div>

                    <!-- Per Location Statistics -->
                    <div v-if="filmReportData.location_stats && filmReportData.location_stats.length > 0">
                        <h4 class="text-md font-semibold mb-3">Per Location Statistics</h4>
                        <div class="bg-white border rounded-lg overflow-hidden">
                            <div class="max-h-96 overflow-y-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50 sticky top-0">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Location</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Event</th>
                                            <th 
                                                class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase"
                                                title="Unique emails from Ticket data (Eventbrite)"
                                            >
                                                Ticket
                                            </th>
                                            <th 
                                                class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase"
                                                title="Unique emails from Win Form sign-ups"
                                            >
                                                Win Form
                                            </th>
                                            <th 
                                                class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase"
                                                title="Emails that appear in both Ticket and Win Form"
                                            >
                                                Duplicates
                                            </th>
                                            <th 
                                                class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase"
                                                title="Emails only in Ticket (not in Win Form)"
                                            >
                                                Ticket Only
                                            </th>
                                            <th 
                                                class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase"
                                                title="Emails only in Win Form (not in Ticket)"
                                            >
                                                Win Form Only
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <tr v-for="(stat, index) in filmReportData.location_stats" :key="index" class="hover:bg-gray-50">
                                            <td class="px-4 py-2 text-sm font-medium">{{ stat.location_name }}</td>
                                            <td class="px-4 py-2 text-sm text-gray-600">{{ stat.event_name }}</td>
                                            <td class="px-4 py-2 text-sm">
                                                <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-xs font-semibold">
                                                    {{ stat.ticket_emails_count }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-2 text-sm">
                                                <span class="px-2 py-1 bg-purple-100 text-purple-800 rounded-full text-xs font-semibold">
                                                    {{ stat.signup_emails_count }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-2 text-sm">
                                                <span class="px-2 py-1 bg-orange-100 text-orange-800 rounded-full text-xs font-semibold">
                                                    {{ stat.duplicate_emails_count }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-2 text-sm">
                                                <span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs font-semibold">
                                                    {{ stat.ticket_only_count }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-2 text-sm">
                                                <span class="px-2 py-1 bg-yellow-100 text-yellow-800 rounded-full text-xs font-semibold">
                                                    {{ stat.signup_only_count }}
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Duplicate Emails Across All Locations (emails in both ticket and sign-up) -->
                    <div v-if="filmReportData.duplicate_emails && filmReportData.duplicate_emails.length > 0">
                        <h4 class="text-md font-semibold mb-3 text-orange-800">
                            Duplicate Emails - Found in Both Ticket & Sign-Up ({{ filmReportData.duplicate_emails.length }})
                        </h4>
                        <div class="bg-white border rounded-lg overflow-hidden">
                            <div class="max-h-96 overflow-y-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50 sticky top-0">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ticket Data</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Sign-Up Data</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <tr v-for="(duplicate, index) in filmReportData.duplicate_emails" :key="index" class="hover:bg-gray-50">
                                            <td class="px-4 py-2 text-sm font-medium">{{ duplicate.email }}</td>
                                            <td class="px-4 py-2 text-sm">
                                                <div v-if="duplicate.ticket_data" class="bg-blue-50 p-2 rounded">
                                                    <div class="font-semibold text-blue-800">{{ duplicate.ticket_data.first_name }} {{ duplicate.ticket_data.last_name }}</div>
                                                    <div class="text-xs text-blue-600">{{ duplicate.ticket_data.phone || 'No phone' }}</div>
                                                    <div class="text-xs text-blue-500 mt-1">{{ duplicate.ticket_data.location_name }} - {{ duplicate.ticket_data.event_name }}</div>
                                                    <div class="text-xs text-blue-400 mt-1">{{ duplicate.ticket_data.source }}</div>
                                                </div>
                                                <div v-else class="text-gray-400">No ticket data</div>
                                            </td>
                                            <td class="px-4 py-2 text-sm">
                                                <div v-if="duplicate.signup_data && duplicate.signup_data.length > 0" class="space-y-2">
                                                    <div v-for="(signup, idx) in duplicate.signup_data" :key="idx" class="bg-purple-50 p-2 rounded">
                                                        <div class="font-semibold text-purple-800">{{ signup.first_name }} {{ signup.last_name }}</div>
                                                        <div class="text-xs text-purple-600">{{ signup.phone || 'No phone' }}</div>
                                                        <div class="text-xs text-purple-500 mt-1">{{ signup.location_name }} - {{ signup.event_name }}</div>
                                                        <div class="text-xs text-purple-400 mt-1">{{ signup.source }}</div>
                                                    </div>
                                                </div>
                                                <div v-else class="text-gray-400">No sign-up data</div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                        <div v-else class="text-center py-4 text-gray-500">
                            <i class="fa-solid fa-check-circle text-green-500 text-2xl mb-2"></i>
                            <p>No duplicate emails found between ticket and Win Form data!</p>
                        </div>
                    </div>
                </div>
        </div>

        <!-- Event Ticket Report Modal -->
        <div v-if="showEventReportModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-6xl w-full mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">
                        Event Ticket Report - {{ selectedEventForReport?.event_name }}
                    </h3>
                    <button @click="closeEventReportModal" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                
                <div v-if="isLoadingEventReport" class="text-center py-8">
                    <i class="fa-solid fa-spinner fa-spin text-2xl text-blue-500"></i>
                    <p class="mt-2 text-gray-600">Loading report...</p>
                </div>
                
                <div v-else-if="eventReportData" class="space-y-6">
                    <!-- Overall Summary Cards -->
                    <div class="grid grid-cols-5 gap-4">
                        <div class="bg-blue-50 p-4 rounded-lg">
                            <h4 class="text-sm font-medium text-blue-800 mb-1">Ticket Emails</h4>
                            <p class="text-2xl font-bold text-blue-600">{{ eventReportData.summary.ticket_emails_count }}</p>
                        </div>
                        <div class="bg-purple-50 p-4 rounded-lg">
                            <h4 class="text-sm font-medium text-purple-800 mb-1">Win Form Emails</h4>
                            <p class="text-2xl font-bold text-purple-600">{{ eventReportData.summary.signup_emails_count }}</p>
                        </div>
                        <div class="bg-orange-50 p-4 rounded-lg">
                            <h4 class="text-sm font-medium text-orange-800 mb-1">Duplicates</h4>
                            <p class="text-2xl font-bold text-orange-600">{{ eventReportData.summary.duplicate_emails_count }}</p>
                            <p class="text-xs text-orange-600 mt-1">In both</p>
                        </div>
                        <div class="bg-green-50 p-4 rounded-lg">
                            <h4 class="text-sm font-medium text-green-800 mb-1">Ticket Only</h4>
                            <p class="text-2xl font-bold text-green-600">{{ eventReportData.summary.ticket_only_count }}</p>
                        </div>
                        <div class="bg-yellow-50 p-4 rounded-lg">
                            <h4 class="text-sm font-medium text-yellow-800 mb-1">Win Form Only</h4>
                            <p class="text-2xl font-bold text-yellow-600">{{ eventReportData.summary.signup_only_count }}</p>
                        </div>
                    </div>

                    <!-- Per Location Statistics -->
                    <div v-if="eventReportData.location_stats && eventReportData.location_stats.length > 0">
                        <h4 class="text-md font-semibold mb-3">Per Location Statistics</h4>
                        <div class="bg-white border rounded-lg overflow-hidden">
                            <div class="max-h-96 overflow-y-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50 sticky top-0">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Location</th>
                                            <th 
                                                class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase"
                                                title="Unique emails from Ticket data (Eventbrite)"
                                            >
                                                Ticket
                                            </th>
                                            <th 
                                                class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase"
                                                title="Unique emails from Win Form sign-ups"
                                            >
                                                Win Form
                                            </th>
                                            <th 
                                                class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase"
                                                title="Emails that appear in both Ticket and Win Form"
                                            >
                                                Duplicates
                                            </th>
                                            <th 
                                                class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase"
                                                title="Emails only in Ticket (not in Win Form)"
                                            >
                                                Ticket Only
                                            </th>
                                            <th 
                                                class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase"
                                                title="Emails only in Win Form (not in Ticket)"
                                            >
                                                Win Form Only
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <tr v-for="(stat, index) in eventReportData.location_stats" :key="index" class="hover:bg-gray-50">
                                            <td class="px-4 py-2 text-sm font-medium">{{ stat.location_name }}</td>
                                            <td class="px-4 py-2 text-sm">
                                                <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-xs font-semibold">
                                                    {{ stat.ticket_emails_count }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-2 text-sm">
                                                <span class="px-2 py-1 bg-purple-100 text-purple-800 rounded-full text-xs font-semibold">
                                                    {{ stat.signup_emails_count }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-2 text-sm">
                                                <span class="px-2 py-1 bg-orange-100 text-orange-800 rounded-full text-xs font-semibold">
                                                    {{ stat.duplicate_emails_count }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-2 text-sm">
                                                <span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs font-semibold">
                                                    {{ stat.ticket_only_count }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-2 text-sm">
                                                <span class="px-2 py-1 bg-yellow-100 text-yellow-800 rounded-full text-xs font-semibold">
                                                    {{ stat.signup_only_count }}
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Duplicate Emails (both ticket and Win Form) -->
                    <div v-if="eventReportData.duplicate_emails && eventReportData.duplicate_emails.length > 0">
                        <h4 class="text-md font-semibold mb-3 text-orange-800">
                            Duplicate Emails - Found in Both Ticket & Win Form ({{ eventReportData.duplicate_emails.length }})
                        </h4>
                        <div class="bg-white border rounded-lg overflow-hidden">
                            <div class="max-h-96 overflow-y-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50 sticky top-0">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ticket Data</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Win Form Data</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <tr v-for="(duplicate, index) in eventReportData.duplicate_emails" :key="index" class="hover:bg-gray-50">
                                            <td class="px-4 py-2 text-sm font-medium">{{ duplicate.email }}</td>
                                            <td class="px-4 py-2 text-sm">
                                                <div v-if="duplicate.ticket_data" class="bg-blue-50 p-2 rounded">
                                                    <div class="font-semibold text-blue-800">
                                                        {{ duplicate.ticket_data.first_name }} {{ duplicate.ticket_data.last_name }}
                                                    </div>
                                                    <div class="text-xs text-blue-600">
                                                        {{ duplicate.ticket_data.phone || 'No phone' }}
                                                    </div>
                                                    <div class="text-xs text-blue-500 mt-1">
                                                        {{ duplicate.ticket_data.location_name }} - {{ duplicate.ticket_data.event_name }}
                                                    </div>
                                                    <div class="text-xs text-blue-400 mt-1">
                                                        {{ duplicate.ticket_data.source }}
                                                    </div>
                                                </div>
                                                <div v-else class="text-gray-400">No ticket data</div>
                                            </td>
                                            <td class="px-4 py-2 text-sm">
                                                <div v-if="duplicate.signup_data && duplicate.signup_data.length > 0" class="space-y-2">
                                                    <div v-for="(signup, idx) in duplicate.signup_data" :key="idx" class="bg-purple-50 p-2 rounded">
                                                        <div class="font-semibold text-purple-800">
                                                            {{ signup.first_name }} {{ signup.last_name }}
                                                        </div>
                                                        <div class="text-xs text-purple-600">
                                                            {{ signup.phone || 'No phone' }}
                                                        </div>
                                                        <div class="text-xs text-purple-500 mt-1">
                                                            {{ signup.location_name }} - {{ signup.event_name }}
                                                        </div>
                                                        <div class="text-xs text-purple-400 mt-1">
                                                            {{ signup.source }}
                                                        </div>
                                                    </div>
                                                </div>
                                                <div v-else class="text-gray-400">No Win Form data</div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-center py-4 text-gray-500">
                        <i class="fa-solid fa-check-circle text-green-500 text-2xl mb-2"></i>
                        <p>No duplicate emails found between ticket and Win Form data!</p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
