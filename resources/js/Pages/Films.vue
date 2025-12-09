<script setup>
import Swal from 'sweetalert2';
import { ref, computed, onMounted, nextTick, watch } from 'vue';
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

// Event Ticket Report (single event)
const showEventReportModal = ref(false);
const eventReportData = ref(null);
const isLoadingEventReport = ref(false);
const selectedEventForReport = ref(null);

// Event Mailchimp Summary
const showEventMailchimpModal = ref(false);
const eventMailchimpData = ref(null);
const isLoadingEventMailchimp = ref(false);
const selectedEventForMailchimp = ref(null);

// Compare multiple events
const selectedEventIds = ref([]);
const showEventsCompareModal = ref(false);
const isLoadingEventsCompare = ref(false);
const eventsCompareData = ref([]); // [{ event, summary, emails }]

// Card info modal
const showCardInfoModal = ref(false);
const cardInfoTitle = ref('');
const cardInfoDescription = ref('');

const eventsCompareSummary = computed(() => {
    if (!eventsCompareData.value.length) {
        return null;
    }
    return eventsCompareData.value.reduce((acc, item) => {
        const s = item.summary || {};
        acc.totalEvents += 1;
        acc.ticket += s.ticket_emails_count || 0;
        acc.winForm += s.signup_emails_count || 0;
        acc.duplicates += s.duplicate_emails_count || 0;
        acc.ticketOnly += s.ticket_only_count || 0;
        acc.winFormOnly += s.signup_only_count || 0;
        return acc;
    }, {
        totalEvents: 0,
        ticket: 0,
        winForm: 0,
        duplicates: 0,
        ticketOnly: 0,
        winFormOnly: 0
    });
});

// Total unique emails across all compared events (Ticket + Win Form, de-duplicated)
const eventsCompareTotalUniqueEmails = computed(() => {
    if (!eventsCompareData.value.length) {
        return 0;
    }
    const allEmailsSet = new Set();
    eventsCompareData.value.forEach((item) => {
        if (!item.emails || !item.emails.length) {
            return;
        }
        item.emails.forEach((email) => {
            if (email) {
                allEmailsSet.add(email.toLowerCase().trim());
            }
        });
    });
    return allEmailsSet.size;
});

// Cross-event email overlap: which emails appear in 2 or more compared events
const crossEventEmailDuplicates = computed(() => {
    if (!eventsCompareData.value.length) {
        return [];
    }

    const emailMap = new Map();

    eventsCompareData.value.forEach((item) => {
        const eventId = item.event && item.event.id;
        const eventName = (item.event && item.event.event_name) || 'Unknown Event';
        if (!eventId || !item.emails || !item.emails.length) {
            return;
        }

        item.emails.forEach((email) => {
            if (!email) {
                return;
            }
            const key = email.toLowerCase().trim();
            if (!emailMap.has(key)) {
                emailMap.set(key, { count: 0, events: [] });
            }
            const entry = emailMap.get(key);
            // Only count this event once per email
            if (!entry.events.some((e) => e.id === eventId)) {
                entry.count += 1;
                entry.events.push({ id: eventId, name: eventName });
            }
        });
    });

    // Only keep emails that appear in 2+ events
    const result = [];
    emailMap.forEach((value, email) => {
        if (value.count > 1) {
            result.push({
                email,
                count: value.count,
                events: value.events
            });
        }
    });

    // Sort by count desc, then email
    result.sort((a, b) => {
        if (b.count !== a.count) return b.count - a.count;
        return a.email.localeCompare(b.email);
    });

    return result;
});

// Natural-language summary comparing first and last selected events
const eventsCompareSummarySentence = computed(() => {
    if (eventsCompareData.value.length < 2) {
        return '';
    }

    const first = eventsCompareData.value[0];
    const last = eventsCompareData.value[eventsCompareData.value.length - 1];
    const fs = first.summary || {};
    const ls = last.summary || {};

    const firstTotal = (fs.ticket_emails_count || 0) + (fs.signup_emails_count || 0);
    const lastTotal = (ls.ticket_emails_count || 0) + (ls.signup_emails_count || 0);
    const diff = lastTotal - firstTotal;

    const firstName = first.event?.event_name || 'First event';
    const lastName = last.event?.event_name || 'Last event';

    if (firstTotal === 0 && lastTotal === 0) {
        return `Comparing "${firstName}" and "${lastName}", there were no Ticket or Win Form emails recorded in either event.`;
    }

    const direction = diff > 0 ? 'increase' : (diff < 0 ? 'decrease' : 'no overall change');
    const diffAbs = Math.abs(diff);

    if (diff === 0) {
        return `Comparing "${firstName}" to "${lastName}", total Ticket + Win Form emails stayed the same at ${firstTotal.toLocaleString()}.`;
    }

    return `Comparing "${firstName}" to "${lastName}", total Ticket + Win Form emails changed from ${firstTotal.toLocaleString()} to ${lastTotal.toLocaleString()}, an ${direction} of ${diffAbs.toLocaleString()} emails.`;
});

// Expanded film to show its events
const expandedFilmId = ref(null);

const toggleFilmEvents = (filmId) => {
    // When switching films, clear any selected events for comparison
    if (expandedFilmId.value !== filmId) {
        selectedEventIds.value = [];
    }
    expandedFilmId.value = expandedFilmId.value === filmId ? null : filmId;
};

const toggleEventSelection = (eventId) => {
    const idx = selectedEventIds.value.indexOf(eventId);
    if (idx === -1) {
        selectedEventIds.value.push(eventId);
    } else {
        selectedEventIds.value.splice(idx, 1);
    }
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

// Open card info modal
const openCardInfoModal = (title, description) => {
    cardInfoTitle.value = title;
    cardInfoDescription.value = description;
    showCardInfoModal.value = true;
};

// Close card info modal
const closeCardInfoModal = () => {
    showCardInfoModal.value = false;
    cardInfoTitle.value = '';
    cardInfoDescription.value = '';
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

const openEventMailchimpModal = async (event) => {
    selectedEventForMailchimp.value = event;
    isLoadingEventMailchimp.value = true;
    showEventMailchimpModal.value = true;

    try {
        const response = await axios.get(route('event.mailchimpReport', event.id));
        eventMailchimpData.value = response.data;
    } catch (error) {
        console.error('Error fetching event Mailchimp report:', error);
        Swal.fire('Error', 'Failed to load Mailchimp summary for this event', 'error');
        showEventMailchimpModal.value = false;
    } finally {
        isLoadingEventMailchimp.value = false;
    }
};

const closeEventMailchimpModal = () => {
    showEventMailchimpModal.value = false;
    eventMailchimpData.value = null;
    selectedEventForMailchimp.value = null;
};

const openCompareSelectedEvents = async () => {
    if (selectedEventIds.value.length < 2) {
        Swal.fire('Select Events', 'Please select at least 2 events to compare.', 'info');
        return;
    }

    isLoadingEventsCompare.value = true;
    showEventsCompareModal.value = true;
    eventsCompareData.value = [];

    try {
        const requests = selectedEventIds.value.map((id) =>
            axios.get(route('event.ticketReport', id))
        );
        const responses = await Promise.all(requests);

        eventsCompareData.value = responses.map((res) => {
            const data = res.data || {};
            const event = data.event || {};
            const summary = data.summary || {};

            // Collect all unique emails for this event (Ticket + Win Form)
            const ticketOnly = (data.ticket_only_emails || []).map((e) => (e || '').toLowerCase().trim());
            const winFormOnly = (data.signup_only_emails || []).map((e) => (e || '').toLowerCase().trim());
            const duplicateEmails = (data.duplicate_emails || []).map((d) => (d.email || '').toLowerCase().trim());

            const emailSet = new Set();
            ticketOnly.forEach((e) => e && emailSet.add(e));
            winFormOnly.forEach((e) => e && emailSet.add(e));
            duplicateEmails.forEach((e) => e && emailSet.add(e));

            return {
                event,
                summary,
                emails: Array.from(emailSet)
            };
        });
    } catch (error) {
        console.error('Error fetching compare events report:', error);
        Swal.fire('Error', 'Failed to load comparison report for selected events', 'error');
        showEventsCompareModal.value = false;
    } finally {
        isLoadingEventsCompare.value = false;
    }
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
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <h6 class="mb-0">Events for this Film</h6>
                                                <button
                                                    class="btn btn-sm btn-outline-primary"
                                                    @click="openCompareSelectedEvents"
                                                    :disabled="selectedEventIds.length < 2"
                                                    title="Compare Ticket & Win Form data for selected events"
                                                >
                                                    Compare Selected
                                                </button>
                                            </div>
                                            <div class="list-group small">
                                                <div 
                                                    v-for="event in film.events" 
                                                    :key="event.id" 
                                                    class="list-group-item d-flex justify-content-between align-items-center py-2"
                                                >
                                                    <div class="d-flex align-items-center gap-2">
                                                        <input
                                                            type="checkbox"
                                                            :value="event.id"
                                                            :checked="selectedEventIds.includes(event.id)"
                                                            @change="toggleEventSelection(event.id)"
                                                        />
                                                        <div class="fw-semibold">{{ event.event_name }}</div>
                                                        <div class="text-muted">
                                                            <small>{{ event.event_date }}</small>
                                                        </div>
                                                    </div>
                                                    <div class="d-flex gap-2">
                                                        <button 
                                                            @click="openEventReportModal(event)" 
                                                            class="btn btn-sm btn-outline-secondary"
                                                            title="View Ticket & Win Form report for this event"
                                                        >
                                                            Report
                                                        </button>
                                                        <button 
                                                            @click="openEventMailchimpModal(event)" 
                                                            class="btn btn-sm btn-outline-success"
                                                            title="View Mailchimp import summary for this event"
                                                        >
                                                            <i class="fa-solid fa-envelope-open-text"></i>
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
                                            <!-- <button 
                                                @click="deleteFilm(film.id)" 
                                                class="btn btn-sm" 
                                                style="background-color: #FF5349; color: white;"
                                            >
                                                <i class="fa-solid fa-trash"></i>
                                            </button> -->
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
                        <div class="bg-blue-50 p-4 rounded-lg relative">
                            <button 
                                @click="openCardInfoModal('Ticket Emails', 'Total unique email addresses from Eventbrite ticket data across all locations/events for this film. This represents all people who purchased tickets through Eventbrite.')"
                                class="absolute top-2 right-2 text-blue-600 hover:text-blue-800 cursor-pointer"
                                style="background: none; border: none; padding: 4px;"
                            >
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <h4 class="text-sm font-medium text-blue-800 mb-1">Ticket Emails</h4>
                            <p class="text-2xl font-bold text-blue-600">{{ filmReportData.summary.ticket_emails_count }}</p>
                        </div>
                        <div class="bg-purple-50 p-4 rounded-lg relative">
                            <button 
                                @click="openCardInfoModal('Sign-Up Emails', 'Total unique email addresses from Win Form sign-ups across all locations/events for this film. This represents all people who signed up via the Win Form.')"
                                class="absolute top-2 right-2 text-purple-600 hover:text-purple-800 cursor-pointer"
                                style="background: none; border: none; padding: 4px;"
                            >
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <h4 class="text-sm font-medium text-purple-800 mb-1">Sign-Up Emails</h4>
                            <p class="text-2xl font-bold text-purple-600">{{ filmReportData.summary.signup_emails_count }}</p>
                        </div>
                        <div class="bg-orange-50 p-4 rounded-lg relative">
                            <button 
                                @click="openCardInfoModal('Duplicates', 'Emails that appear in BOTH Ticket data AND Sign-Up data. These are people who both bought tickets and signed up via the Win Form. This shows engagement from both sources.')"
                                class="absolute top-2 right-2 text-orange-600 hover:text-orange-800 cursor-pointer"
                                style="background: none; border: none; padding: 4px;"
                            >
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <h4 class="text-sm font-medium text-orange-800 mb-1">Duplicates</h4>
                            <p class="text-2xl font-bold text-orange-600">{{ filmReportData.summary.duplicate_emails_count }}</p>
                            <p class="text-xs text-orange-600 mt-1">In both</p>
                        </div>
                        <div class="bg-green-50 p-4 rounded-lg relative">
                            <button 
                                @click="openCardInfoModal('Ticket Only', 'Emails that are ONLY in Ticket data (not in Sign-Up). These are people who bought tickets but did NOT sign up via the Win Form.')"
                                class="absolute top-2 right-2 text-green-600 hover:text-green-800 cursor-pointer"
                                style="background: none; border: none; padding: 4px;"
                            >
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <h4 class="text-sm font-medium text-green-800 mb-1">Ticket Only</h4>
                            <p class="text-2xl font-bold text-green-600">{{ filmReportData.summary.ticket_only_count }}</p>
                        </div>
                        <div class="bg-yellow-50 p-4 rounded-lg relative">
                            <button 
                                @click="openCardInfoModal('Sign-Up Only', 'Emails that are ONLY in Sign-Up data (not in Ticket). These are people who signed up via the Win Form but did NOT buy tickets.')"
                                class="absolute top-2 right-2 text-yellow-600 hover:text-yellow-800 cursor-pointer"
                                style="background: none; border: none; padding: 4px;"
                            >
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <h4 class="text-sm font-medium text-yellow-800 mb-1">Sign-Up Only</h4>
                            <p class="text-2xl font-bold text-yellow-600">{{ filmReportData.summary.signup_only_count }}</p>
                        </div>
                        <div class="bg-gray-50 p-4 rounded-lg relative">
                            <button 
                                @click="openCardInfoModal('Total Unique', 'Total unique email addresses across BOTH sources (Ticket + Sign-Up). Formula: Ticket Only + Sign-Up Only + Duplicates. This is the total number of unique people engaged with this film.')"
                                class="absolute top-2 right-2 text-gray-600 hover:text-gray-800 cursor-pointer"
                                style="background: none; border: none; padding: 4px;"
                            >
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
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
                        <div class="bg-blue-50 p-4 rounded-lg relative">
                            <button 
                                @click="openCardInfoModal('Ticket Emails', 'Total unique email addresses from Eventbrite ticket data for this event. This represents all people who purchased tickets through Eventbrite.')"
                                class="absolute top-2 right-2 text-blue-600 hover:text-blue-800 cursor-pointer"
                                style="background: none; border: none; padding: 4px;"
                            >
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <h4 class="text-sm font-medium text-blue-800 mb-1">Ticket Emails</h4>
                            <p class="text-2xl font-bold text-blue-600">{{ eventReportData.summary.ticket_emails_count }}</p>
                        </div>
                        <div class="bg-purple-50 p-4 rounded-lg relative">
                            <button 
                                @click="openCardInfoModal('Win Form Emails', 'Total unique email addresses from Win Form sign-ups for this event. This represents all people who signed up via the Win Form.')"
                                class="absolute top-2 right-2 text-purple-600 hover:text-purple-800 cursor-pointer"
                                style="background: none; border: none; padding: 4px;"
                            >
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <h4 class="text-sm font-medium text-purple-800 mb-1">Win Form Emails</h4>
                            <p class="text-2xl font-bold text-purple-600">{{ eventReportData.summary.signup_emails_count }}</p>
                        </div>
                        <div class="bg-orange-50 p-4 rounded-lg relative">
                            <button 
                                @click="openCardInfoModal('Duplicates', 'Emails that appear in BOTH Ticket data AND Win Form data. These are people who both bought tickets and signed up via the Win Form. This shows engagement from both sources.')"
                                class="absolute top-2 right-2 text-orange-600 hover:text-orange-800 cursor-pointer"
                                style="background: none; border: none; padding: 4px;"
                            >
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <h4 class="text-sm font-medium text-orange-800 mb-1">Duplicates</h4>
                            <p class="text-2xl font-bold text-orange-600">{{ eventReportData.summary.duplicate_emails_count }}</p>
                            <p class="text-xs text-orange-600 mt-1">In both</p>
                        </div>
                        <div class="bg-green-50 p-4 rounded-lg relative">
                            <button 
                                @click="openCardInfoModal('Ticket Only', 'Emails that are ONLY in Ticket data (not in Win Form). These are people who bought tickets but did NOT sign up via the Win Form.')"
                                class="absolute top-2 right-2 text-green-600 hover:text-green-800 cursor-pointer"
                                style="background: none; border: none; padding: 4px;"
                            >
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <h4 class="text-sm font-medium text-green-800 mb-1">Ticket Only</h4>
                            <p class="text-2xl font-bold text-green-600">{{ eventReportData.summary.ticket_only_count }}</p>
                        </div>
                        <div class="bg-yellow-50 p-4 rounded-lg relative">
                            <button 
                                @click="openCardInfoModal('Win Form Only', 'Emails that are ONLY in Win Form data (not in Ticket). These are people who signed up via the Win Form but did NOT buy tickets.')"
                                class="absolute top-2 right-2 text-yellow-600 hover:text-yellow-800 cursor-pointer"
                                style="background: none; border: none; padding: 4px;"
                            >
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
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

        <!-- Compare Multiple Events Report Modal -->
        <div v-if="showEventsCompareModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-6xl w-full mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">
                        Compare Events - Ticket & Win Form Data
                    </h3>
                    <button @click="showEventsCompareModal = false" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>

                <div v-if="isLoadingEventsCompare" class="text-center py-8">
                    <i class="fa-solid fa-spinner fa-spin text-2xl text-blue-500"></i>
                    <p class="mt-2 text-gray-600">Loading comparison report...</p>
                </div>

                <div v-else-if="eventsCompareData.length" class="space-y-6">
                    <!-- Summary across all selected events -->
                    <div v-if="eventsCompareSummary" class="grid grid-cols-4 gap-4">
                        <div class="bg-blue-50 p-4 rounded-lg relative">
                            <button 
                                @click="openCardInfoModal('Total Ticket Emails (All Events)', 'Sum of all unique Ticket emails across all selected events being compared. This shows the total ticket engagement across multiple events.')"
                                class="absolute top-2 right-2 text-blue-600 hover:text-blue-800 cursor-pointer"
                                style="background: none; border: none; padding: 4px;"
                            >
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <h4 class="text-sm font-medium text-blue-800 mb-1">Total Ticket Emails (All Events)</h4>
                            <p class="text-2xl font-bold text-blue-600">{{ eventsCompareSummary.ticket }}</p>
                        </div>
                        <div class="bg-teal-50 p-4 rounded-lg relative">
                            <button 
                                @click="openCardInfoModal('Total Unique Emails (All Events)', 'Total unique email addresses across all selected events (Ticket + Win Form, de-duplicated across events). This is the total number of unique people engaged across all compared events.')"
                                class="absolute top-2 right-2 text-teal-600 hover:text-teal-800 cursor-pointer"
                                style="background: none; border: none; padding: 4px;"
                            >
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <h4 class="text-sm font-medium text-teal-800 mb-1">Total Unique Emails (All Events)</h4>
                            <p class="text-2xl font-bold text-teal-600">{{ eventsCompareTotalUniqueEmails }}</p>
                        </div>
                        <div class="bg-red-50 p-4 rounded-lg relative">
                            <button 
                                @click="openCardInfoModal('Emails in 2+ Compared Events', 'Email addresses that appear in 2 or more of the selected events (shows cross-event engagement). These are people who engaged with multiple events.')"
                                class="absolute top-2 right-2 text-red-600 hover:text-red-800 cursor-pointer"
                                style="background: none; border: none; padding: 4px;"
                            >
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <h4 class="text-sm font-medium text-red-800 mb-1">Emails in 2+ Compared Events</h4>
                            <p class="text-2xl font-bold text-red-600">{{ crossEventEmailDuplicates.length }}</p>
                        </div>
                        <div class="bg-orange-50 p-4 rounded-lg relative">
                            <button 
                                @click="openCardInfoModal('Total Duplicated (Ticket vs Win Form)', 'Total count of emails that appear in BOTH Ticket and Win Form data across all selected events. This shows people who both bought tickets and signed up via Win Form.')"
                                class="absolute top-2 right-2 text-orange-600 hover:text-orange-800 cursor-pointer"
                                style="background: none; border: none; padding: 4px;"
                            >
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <h4 class="text-sm font-medium text-orange-800 mb-1">Total Duplicated (Ticket vs Win Form)</h4>
                            <p class="text-2xl font-bold text-orange-600">{{ eventsCompareSummary.duplicates }}</p>
                        </div>
                    </div>

                    <!-- Per-event comparison table -->
                    <div class="bg-white border rounded-lg overflow-hidden">
                        <div class="max-h-[70vh] overflow-y-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50 sticky top-0">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                                            Event
                                        </th>
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
                                    <tr v-for="(item, index) in eventsCompareData" :key="index" class="hover:bg-gray-50">
                                        <td class="px-4 py-2 text-sm font-medium">
                                            {{ item.event.event_name }}
                                        </td>
                                        <td class="px-4 py-2 text-sm">
                                            <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-xs font-semibold">
                                                {{ item.summary.ticket_emails_count }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-2 text-sm">
                                            <span class="px-2 py-1 bg-purple-100 text-purple-800 rounded-full text-xs font-semibold">
                                                {{ item.summary.signup_emails_count }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-2 text-sm">
                                            <span class="px-2 py-1 bg-orange-100 text-orange-800 rounded-full text-xs font-semibold">
                                                {{ item.summary.duplicate_emails_count }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-2 text-sm">
                                            <span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs font-semibold">
                                                {{ item.summary.ticket_only_count }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-2 text-sm">
                                            <span class="px-2 py-1 bg-yellow-100 text-yellow-800 rounded-full text-xs font-semibold">
                                                {{ item.summary.signup_only_count }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Natural-language summary sentence -->
                    <div v-if="eventsCompareSummarySentence" class="mt-3 text-sm text-gray-700 italic">
                        {{ eventsCompareSummarySentence }}
                    </div>

                    <!-- Cross-event email overlaps -->
                    <div v-if="crossEventEmailDuplicates.length" class="bg-white border rounded-lg overflow-hidden">
                        <div class="px-4 pt-4">
                            <h4 class="text-md font-semibold mb-2">
                                Emails Appearing in Multiple Compared Events
                            </h4>
                            <p class="text-sm text-gray-600 mb-2">
                                These emails are found in at least 2 of the selected events (Ticket and/or Win Form data).
                            </p>
                        </div>
                        <div class="max-h-96 overflow-y-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50 sticky top-0">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                                            Email
                                        </th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                                            # of Events
                                        </th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                                            Events
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="(row, index) in crossEventEmailDuplicates" :key="index" class="hover:bg-gray-50">
                                        <td class="px-4 py-2 text-sm font-medium">
                                            {{ row.email }}
                                        </td>
                                        <td class="px-4 py-2 text-sm">
                                            <span class="px-2 py-1 bg-orange-100 text-orange-800 rounded-full text-xs font-semibold">
                                                {{ row.count }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-2 text-sm">
                                            <div class="flex flex-wrap gap-1">
                                                <span
                                                    v-for="(ev, idx) in row.events"
                                                    :key="idx"
                                                    class="px-2 py-1 bg-gray-100 text-gray-700 rounded-full text-xs"
                                                >
                                                    {{ ev.name }}
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div v-else class="text-center py-4 text-gray-500">
                    <i class="fa-solid fa-info-circle text-blue-500 text-2xl mb-2"></i>
                    <p>No events loaded for comparison.</p>
                </div>
            </div>
        </div>

        <!-- Event Mailchimp Summary Modal -->
        <div v-if="showEventMailchimpModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-3xl w-full mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">
                        Mailchimp Summary - {{ selectedEventForMailchimp?.event_name }}
                    </h3>
                    <button @click="closeEventMailchimpModal" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>

                <div v-if="isLoadingEventMailchimp" class="text-center py-8">
                    <i class="fa-solid fa-spinner fa-spin text-2xl text-blue-500"></i>
                    <p class="mt-2 text-gray-600">Loading Mailchimp summary...</p>
                </div>

                <div v-else-if="eventMailchimpData" class="space-y-6">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="bg-blue-50 p-4 rounded-lg relative">
                            <button 
                                @click="openCardInfoModal('Total Collected Data', 'Total number of records that were attempted to be imported to Mailchimp for this event. This is the total count of all records processed during the import.')"
                                class="absolute top-2 right-2 text-blue-600 hover:text-blue-800 cursor-pointer"
                                style="background: none; border: none; padding: 4px;"
                            >
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <h4 class="text-sm font-medium text-blue-800 mb-1">Total Collected Data</h4>
                            <p class="text-2xl font-bold text-blue-600">
                                {{ eventMailchimpData.summary.total_collected_data ?? eventMailchimpData.summary.total_imports }}
                            </p>
                        </div>
                        <div class="bg-green-50 p-4 rounded-lg relative">
                            <button 
                                @click="openCardInfoModal('NEW from Import', 'Number of new subscribers that were successfully added to Mailchimp (they did not exist in Mailchimp before). These are completely new contacts added to your Mailchimp audience.')"
                                class="absolute top-2 right-2 text-green-600 hover:text-green-800 cursor-pointer"
                                style="background: none; border: none; padding: 4px;"
                            >
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <h4 class="text-sm font-medium text-green-800 mb-1">NEW from Import</h4>
                            <p class="text-2xl font-bold text-green-600">
                                {{ eventMailchimpData.summary.new_from_import ?? eventMailchimpData.summary.successful_imports }}
                            </p>
                        </div>
                        <div class="bg-purple-50 p-4 rounded-lg relative">
                            <button 
                                @click="openCardInfoModal('Updated Data', 'Number of existing Mailchimp subscribers that were updated with new information from the import. These contacts already existed in Mailchimp and their information was refreshed.')"
                                class="absolute top-2 right-2 text-purple-600 hover:text-purple-800 cursor-pointer"
                                style="background: none; border: none; padding: 4px;"
                            >
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <h4 class="text-sm font-medium text-purple-800 mb-1">Updated Data</h4>
                            <p class="text-2xl font-bold text-purple-600">
                                {{ eventMailchimpData.summary.updated_data ?? 0 }}
                            </p>
                        </div>
                        <div class="bg-red-50 p-4 rounded-lg relative">
                            <button 
                                @click="openCardInfoModal('Rejected Data', 'Number of records that were rejected by Mailchimp during import. This usually happens due to invalid data, missing required fields, or API errors. Check the logs for specific rejection reasons.')"
                                class="absolute top-2 right-2 text-red-600 hover:text-red-800 cursor-pointer"
                                style="background: none; border: none; padding: 4px;"
                            >
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <h4 class="text-sm font-medium text-red-800 mb-1">Rejected Data</h4>
                            <p class="text-2xl font-bold text-red-600">
                                {{ eventMailchimpData.summary.rejected_data ?? eventMailchimpData.summary.failed_imports }}
                            </p>
                        </div>
                    </div>

                    <div class="bg-gray-50 p-4 rounded-lg text-sm space-y-1">
                        <div>
                            <span class="font-medium text-gray-700">Last Import:</span>
                            <span class="ml-1 text-gray-800">
                                {{ eventMailchimpData.summary.last_import_at || 'N/A' }}
                            </span>
                        </div>
                        <div v-if="eventMailchimpData.summary.total_imports">
                            <span class="font-medium text-gray-700">Success Rate:</span>
                            <span class="ml-1 text-gray-800">
                                {{
                                    (
                                        (eventMailchimpData.summary.successful_imports /
                                            eventMailchimpData.summary.total_imports) *
                                        100
                                    ).toFixed(1)
                                }}%
                            </span>
                        </div>
                    </div>

                    <div v-if="eventMailchimpData.summary.total_imports === 0" class="text-sm text-gray-500">
                        No Mailchimp import logs have been recorded for this event yet.
                    </div>
                </div>
            </div>
        </div>

        <!-- Card Info Modal -->
        <div v-if="showCardInfoModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-md w-full mx-4">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">{{ cardInfoTitle }}</h3>
                    <button @click="closeCardInfoModal" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                <div class="text-gray-700">
                    <p>{{ cardInfoDescription }}</p>
                </div>
                <div class="mt-6 flex justify-end">
                    <button @click="closeCardInfoModal" class="btn btn-primary">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
