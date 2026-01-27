<script setup>
import Swal from 'sweetalert2';
import { ref, computed } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';

// Props from Laravel
const props = defineProps({
    events: Array,
    films: Array
});

// Track if we are editing an event
const isEditing = ref(false);
const page = usePage();
const user = computed(() => page.props.auth.user || null);
const userRole = computed(() => user.value?.role);

// Film filter
const selectedFilmFilter = ref(null);

// Computed property to filter events by selected film
const filteredEvents = computed(() => {
    if (!selectedFilmFilter.value) {
        return props.events;
    }
    return props.events.filter(event => event.film_id === selectedFilmFilter.value);
}); 

// Sheets modal state
const showSheetsModal = ref(false);
const selectedEventForSheets = ref(null);
const sheetsData = ref([]);
const sheetsColumns = ['Location', 'Cinema', 'State', 'Country', 'Date', 'Time', 'Category'];
const rowsToAdd = ref(1);
const originalLocationIds = ref([]); // Track original location IDs to detect deletions

// Undo/Redo functionality
const sheetsHistory = ref([]);
const historyIndex = ref(-1);
const maxHistorySize = 50;

// Form state
const form = useForm({
    id: null,
    event_name: '',
    event_date: '',
    event_year: '',
    event_logo: null,
    event_banner: null, // File input
    event_coordinator: '',
    event_coordinator_email: '',
    event_country: '',
    film_id: null,
    is_enabled: false
});

// File input reference
const handleFileChange = (event) => {
    form.event_banner = event.target.files[0]; // Assign file to form
};

const handleLogoChange = (event) => {
    form.event_logo = event.target.files[0]; // Assign file to form
};

// Open Modal for Creating a New Event
const openCreateModal = () => {
    isEditing.value = false;
    form.reset(); // Clear form
    existingBanner.value = null;
    existingLogo.value = null;
    document.getElementById('event_banner').value = '';
    document.getElementById('event_logo').value = '';
    let modalElement = new bootstrap.Modal(document.getElementById('createEventModal'));
    modalElement.show();
};

// Track existing files when editing
const existingBanner = ref(null);
const existingLogo = ref(null);

// Open Modal for Editing an Existing Event
const openEditModal = (event) => {
    isEditing.value = true;
    form.id = event.id;
    form.event_name = event.event_name;
    form.event_year = event.event_year;
    form.event_date = event.event_date;
    form.event_coordinator = event.event_coordinator;
    form.event_coordinator_email = event.event_coordinator_email;
    form.event_country = event.event_country;
    form.film_id = event.film_id ? parseInt(event.film_id) : null;
    form.is_enabled = event.is_enabled ? true : false;
    form.event_banner = null; // Reset file input - new file will override
    form.event_logo = null; // Reset file input - new file will override
    existingBanner.value = event.event_banner; // Store existing banner path
    existingLogo.value = event.event_logo; // Store existing logo path

    let modalElement = new bootstrap.Modal(document.getElementById('createEventModal'));
    modalElement.show();
};


// Submit the form (Create or Update)
const saveEvent = () => {
    // Validate film_id is selected before submitting
    if (!form.film_id || form.film_id === null) {
        Swal.fire('Error!', 'Please select a film.', 'error');
        return;
    }

    const data = new FormData();
    data.append('event_name', form.event_name);
    data.append('event_year', form.event_year);
    data.append('event_date', form.event_date);
    if (form.event_banner) {
        data.append('event_banner', form.event_banner);
    }
    if (form.event_logo) {
        data.append('event_logo', form.event_logo);
    }
    data.append('event_coordinator', form.event_coordinator);
    data.append('event_coordinator_email', form.event_coordinator_email);
    data.append('event_country', form.event_country);
    // Always append film_id as integer
    data.append('film_id', parseInt(form.film_id));
    // Append is_enabled as 1 or 0
    data.append('is_enabled', form.is_enabled ? '1' : '0');

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

const goToLocationPage = (event) => {
    router.get(route('location.locationpage', { eventId: event.id }));
};

// Format date to "Saturday, September 20, 2025" format
const formatEventDate = (dateString) => {
    if (!dateString) return '';
    
    try {
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return dateString; // Return original if invalid
        
        return date.toLocaleDateString('en-US', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    } catch (e) {
        console.error("Error formatting date:", e);
        return dateString;
    }
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

// Save state to history
const saveToHistory = () => {
    const currentState = JSON.parse(JSON.stringify(sheetsData.value));
    
    // Remove any states after current index (when undoing and then making new changes)
    if (historyIndex.value < sheetsHistory.value.length - 1) {
        sheetsHistory.value = sheetsHistory.value.slice(0, historyIndex.value + 1);
    }
    
    // Add new state
    sheetsHistory.value.push(currentState);
    
    // Limit history size
    if (sheetsHistory.value.length > maxHistorySize) {
        sheetsHistory.value.shift();
    } else {
        historyIndex.value = sheetsHistory.value.length - 1;
    }
};

// Undo
const undoSheets = () => {
    if (historyIndex.value > 0) {
        historyIndex.value--;
        sheetsData.value = JSON.parse(JSON.stringify(sheetsHistory.value[historyIndex.value]));
    }
};

// Redo
const redoSheets = () => {
    if (historyIndex.value < sheetsHistory.value.length - 1) {
        historyIndex.value++;
        sheetsData.value = JSON.parse(JSON.stringify(sheetsHistory.value[historyIndex.value]));
    }
};

// Check if date format (e.g., "Friday, January 23, 2026")
const isDateFormat = (text) => {
    // Check for common date patterns
    const datePatterns = [
        /^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},\s+\d{4}$/i,
        /^\d{1,2}\/\d{1,2}\/\d{4}$/,
        /^\d{4}-\d{2}-\d{2}$/,
        /^(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4}$/i
    ];
    
    return datePatterns.some(pattern => pattern.test(text.trim()));
};

// Open Sheets Modal
const openSheetsModal = async (event) => {
    selectedEventForSheets.value = event;
    showSheetsModal.value = true;
    
    // Reset history
    sheetsHistory.value = [];
    historyIndex.value = -1;
    
    // Load existing locations for this event
    try {
        const response = await axios.get(route('location.getSheetsData', { eventId: event.id }));
        if (response.data.success && response.data.data.length > 0) {
            sheetsData.value = response.data.data;
            // Store original location IDs to track deletions
            originalLocationIds.value = response.data.data
                .map(row => row.id)
                .filter(id => id !== null && id !== undefined);
        } else {
            // Initialize with empty row if no data
            sheetsData.value = [];
            originalLocationIds.value = [];
            addSheetsRowInternal();
        }
    } catch (error) {
        console.error('Error loading sheets data:', error);
        // Initialize with empty row on error
        sheetsData.value = [];
        originalLocationIds.value = [];
        addSheetsRowInternal();
    }
    
    // Save initial state to history
    saveToHistory();
    
    // Use nextTick to ensure modal is rendered before showing
    setTimeout(() => {
        let modalElement = new bootstrap.Modal(document.getElementById('sheetsModal'));
        modalElement.show();
        
        // Add keyboard shortcuts for undo/redo
        const handleKeyDown = (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
                e.preventDefault();
                undoSheets();
            } else if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.key === 'z' && e.shiftKey))) {
                e.preventDefault();
                redoSheets();
            }
        };
        
        document.addEventListener('keydown', handleKeyDown);
        
        // Remove listener when modal is closed
        const modalElementEl = document.getElementById('sheetsModal');
        if (modalElementEl) {
            modalElementEl.addEventListener('hidden.bs.modal', () => {
                document.removeEventListener('keydown', handleKeyDown);
            }, { once: true });
        }
    }, 100);
};

// Add a new row to sheets (internal, without history)
const addSheetsRowInternal = () => {
    sheetsData.value.push({
        id: null, // null for new rows, existing id for updates
        Location: '',
        Cinema: '',
        State: '',
        Country: '',
        Date: '',
        Time: '',
        Category: ''
    });
};

// Add a new row to sheets (public, with history)
const addSheetsRow = () => {
    addSheetsRowInternal();
    saveToHistory();
};

// Add multiple rows at once
const addMultipleRows = () => {
    const numRows = parseInt(rowsToAdd.value) || 1;
    if (numRows > 0 && numRows <= 1000) {
        for (let i = 0; i < numRows; i++) {
            sheetsData.value.push({
                id: null,
                Location: '',
                Cinema: '',
                State: '',
                Country: '',
                Date: '',
                Time: '',
                Category: ''
            });
        }
        rowsToAdd.value = 1; // Reset to 1 after adding
        saveToHistory(); // Save to history once after adding all rows
    } else {
        Swal.fire('Invalid Number', 'Please enter a number between 1 and 1000', 'warning');
    }
};

// Remove a row from sheets
const removeSheetsRow = (index) => {
    sheetsData.value.splice(index, 1);
    saveToHistory();
};

// Handle paste event in cells
const handlePaste = (event, rowIndex, columnIndex) => {
    event.preventDefault();
    const pasteData = event.clipboardData.getData('text');
    const currentColumn = sheetsColumns[columnIndex];
    const lines = pasteData.split('\n').filter(line => line.trim());
    
    // Check if pasting into Date column and if the data looks like dates
    const isDateColumn = currentColumn === 'Date';
    const firstLine = lines[0]?.trim() || '';
    const looksLikeDates = isDateFormat(firstLine) || lines.some(line => isDateFormat(line.trim()));
    
    if (isDateColumn && looksLikeDates) {
        // Paste dates into Date column only, one per row
        lines.forEach((line, lineIndex) => {
            const dateValue = line.trim();
            if (dateValue) {
                const targetRowIndex = rowIndex + lineIndex;
                
                // Add rows if needed
                while (sheetsData.value.length <= targetRowIndex) {
                    addSheetsRowInternal();
                }
                
                if (!sheetsData.value[targetRowIndex]) {
                    sheetsData.value[targetRowIndex] = { id: null };
                }
                // Preserve existing id if it exists
                if (sheetsData.value[targetRowIndex].id === undefined) {
                    sheetsData.value[targetRowIndex].id = null;
                }
                
                sheetsData.value[targetRowIndex]['Date'] = dateValue;
            }
        });
        
        // Force Vue reactivity update
        sheetsData.value = [...sheetsData.value];
        saveToHistory();
        return;
    }
    
    // Regular paste handling
    if (lines.length === 1 && !pasteData.includes('\t') && !pasteData.includes(',')) {
        // Single cell paste - just update the current cell
        const columnKey = sheetsColumns[columnIndex];
        if (!sheetsData.value[rowIndex]) {
            sheetsData.value[rowIndex] = {};
        }
        sheetsData.value[rowIndex][columnKey] = pasteData.trim();
        event.target.value = pasteData.trim();
        saveToHistory();
    } else {
        // Multi-line/cell paste - parse as tab or comma separated
        lines.forEach((line, lineIndex) => {
            // Try tab first, then comma
            const values = line.includes('\t') 
                ? line.split('\t').map(v => v.trim())
                : line.split(',').map(v => v.trim());
            
            const targetRowIndex = rowIndex + lineIndex;
            
            // Add rows if needed
            while (sheetsData.value.length <= targetRowIndex) {
                addSheetsRowInternal();
            }
            
            // Populate cells starting from the clicked column
            values.forEach((value, valueIndex) => {
                const targetColIndex = columnIndex + valueIndex;
                if (targetColIndex < sheetsColumns.length) {
                    const columnKey = sheetsColumns[targetColIndex];
                    if (!sheetsData.value[targetRowIndex]) {
                        sheetsData.value[targetRowIndex] = { id: null };
                    }
                    // Preserve existing id if it exists
                    if (sheetsData.value[targetRowIndex].id === undefined) {
                        sheetsData.value[targetRowIndex].id = null;
                    }
                    sheetsData.value[targetRowIndex][columnKey] = value;
                }
            });
        });
        
        // Force Vue reactivity update
        sheetsData.value = [...sheetsData.value];
        saveToHistory();
    }
};

// Handle cell input change
let cellChangeTimeout = null;
const handleCellChange = (rowIndex, columnKey, value) => {
    if (!sheetsData.value[rowIndex]) {
        sheetsData.value[rowIndex] = {};
    }
    sheetsData.value[rowIndex][columnKey] = value;
    
    // Debounce history save to avoid too many history entries
    clearTimeout(cellChangeTimeout);
    cellChangeTimeout = setTimeout(() => {
        saveToHistory();
    }, 500); // Save to history 500ms after last change
};

// Save sheets data
const saveSheetsData = async () => {
    if (!selectedEventForSheets.value) {
        Swal.fire('Error', 'No event selected', 'error');
        return;
    }

    // Filter out completely empty rows
    const dataToSave = sheetsData.value.filter(row => {
        return row.Location || row.Cinema || row.State || row.Country || row.Date || row.Time || row.Category;
    });

    if (dataToSave.length === 0) {
        Swal.fire('Warning', 'No data to save', 'warning');
        return;
    }

    // Calculate which location IDs should be deleted (were in original but not in current data)
    const currentLocationIds = dataToSave
        .map(row => row.id)
        .filter(id => id !== null && id !== undefined);
    const idsToDelete = originalLocationIds.value.filter(id => !currentLocationIds.includes(id));

    try {
        const response = await axios.post(route('location.saveSheetsData'), {
            event_id: selectedEventForSheets.value.id,
            data: dataToSave,
            ids_to_delete: idsToDelete // Send IDs that should be deleted
        });

        if (response.data.success) {
            Swal.fire('Success!', response.data.message, 'success');
            // Reload the data to get updated IDs
            const reloadResponse = await axios.get(route('location.getSheetsData', { eventId: selectedEventForSheets.value.id }));
            if (reloadResponse.data.success && reloadResponse.data.data.length > 0) {
                sheetsData.value = reloadResponse.data.data;
                // Update original location IDs after save
                originalLocationIds.value = reloadResponse.data.data
                    .map(row => row.id)
                    .filter(id => id !== null && id !== undefined);
            } else {
                sheetsData.value = [];
                originalLocationIds.value = [];
            }
        } else {
            Swal.fire('Error', response.data.error || 'Failed to save data', 'error');
        }
    } catch (error) {
        console.error('Error saving sheets data:', error);
        Swal.fire('Error', error.response?.data?.error || 'Failed to save locations', 'error');
    }
};

// Close sheets modal
const closeSheetsModal = () => {
    showSheetsModal.value = false;
    selectedEventForSheets.value = null;
    sheetsData.value = [];
    sheetsHistory.value = [];
    historyIndex.value = -1;
    originalLocationIds.value = [];
    let modalElement = bootstrap.Modal.getInstance(document.getElementById('sheetsModal'));
    if (modalElement) {
        modalElement.hide();
    }
};

</script>

<template>
    <Head title="Events" />
    <AuthenticatedLayout>
        <template #header>
            <div class="d-flex justify-content-between align-items-center">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Events</h2>
                <div class="d-flex align-items-center gap-2">
                    <select 
                        v-model="selectedFilmFilter" 
                        class="form-select" 
                        style="width: auto; min-width: 200px;"
                    >
                        <option :value="null">All Films</option>
                        <option 
                            v-for="film in props.films" 
                            :key="film.id" 
                            :value="film.id"
                        >
                            {{ film.name }}
                        </option>
                    </select>
                    <button @click="openCreateModal" class="btn" style="background-color: #16C3D9; color: white;" v-if="userRole === 'admin'">
                        <i class="fa-solid fa-plus"></i> Create Event
                    </button>
                </div>
            </div>
        </template>

        <div class="p-4">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="row">
                    <div v-for="event in filteredEvents" :key="event.id" class="col-md-4 mb-4">
                        <div class="card">
                            <img style="height: 200px;" :src="'/storage/' + event.event_banner" class="card-img-top" alt="Event Banner" />
                            <div class="card-body">
                                <h5 class="card-title">{{ event.event_name }}</h5>
                                <p class="card-text mb-1" style="font-size: 14px; font-weight: 500;">{{ event.event_country }}</p>
                                <p class="text-muted">📅 First show at {{ formatEventDate(event.event_date) }}</p>
                                <p class="text-muted">👤 Event Coordinator: {{ event.event_coordinator }}</p>
                                <div class="d-flex justify-content-between align-items-center mt-3" v-if="userRole === 'admin' || userRole === 'host'">
                                    <div class="d-flex align-items-center gap-2">
                                        <button @click="openSheetsModal(event)" class="btn btn-sm" style="background-color: #16C3D9; color: white;">
                                            Master Sheet
                                        </button>
                                        <button @click="goToSignUpForm(event.id)" class="btn btn-sm" style="background-color: #16C3D9; color: white;">
                                            <i class="fa-solid fa-file-lines"></i>
                                        </button>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <button @click="goToLocationPage(event)" class="btn btn-sm" style="background-color: #16C3D9; color: white;" title="Go to Location Page">
                                            Locations
                                        </button>
                                        <button @click="openEditModal(event)" class="btn btn-sm" style="background-color: #16C3D9; color: white;" v-if="userRole === 'admin'">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <!-- <button @click="deleteEvent(event.id)" class="btn btn-sm" style="background-color: #16C3D9; color: white;" v-if="userRole === 'admin'">
                                            <i class="fa-solid fa-trash"></i>
                                        </button> -->
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
                                <label class="form-label">Film <span class="text-danger">*</span></label>
                                <select v-model="form.film_id" class="form-select" required :class="{ 'is-invalid': form.errors.film_id }">
                                    <option :value="null">Select a film</option>
                                    <option 
                                        v-for="film in props.films" 
                                        :key="film.id" 
                                        :value="film.id"
                                    >
                                        {{ film.name }}
                                    </option>
                                </select>
                                <div v-if="form.errors.film_id" class="invalid-feedback">
                                    {{ form.errors.film_id }}
                                </div>
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
                                <label class="form-label">Event Logo</label>
                                <input type="file" @change="handleLogoChange" id="event_logo" class="form-control" />
                                <small v-if="isEditing && existingLogo" class="text-muted">
                                    Current: {{ existingLogo.split('/').pop() }} (leave empty to keep current)
                                </small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Event Banner</label>
                                <input type="file" @change="handleFileChange" id="event_banner" class="form-control" />
                                <small v-if="isEditing && existingBanner" class="text-muted">
                                    Current: {{ existingBanner.split('/').pop() }} (leave empty to keep current)
                                </small>
                            </div>
                            <div class="mb-3">
                                <div class="form-check">
                                    <input 
                                        class="form-check-input" 
                                        type="checkbox" 
                                        v-model="form.is_enabled" 
                                        id="is_enabled"
                                    />
                                    <label class="form-check-label" for="is_enabled">
                                        Show this in the pick a winner dropdown
                                    </label>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn" style="background-color: black; color: white;">
                                    {{ isEditing ? 'Update Event' : 'Save Event' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sheets Modal -->
        <div class="modal fade" id="sheetsModal" tabindex="-1" aria-labelledby="sheetsModalLabel" @hidden="closeSheetsModal">
            <div class="modal-dialog modal-fullscreen-lg-down modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="sheetsModalLabel">
                            <i class="fa-solid fa-file-lines me-2"></i>
                            Master Sheet - {{ selectedEventForSheets?.event_name }}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" style="max-height: 70vh; overflow-y: auto; overflow-x: hidden;">
                        <div class="mb-3">
                            <small class="text-muted">
                                <i class="fa-solid fa-info-circle me-1"></i>
                                You can paste data from Excel/Google Sheets. Use Tab or Comma to separate columns. 
                                When pasting dates into the Date column, they will be placed in that column only. 
                                Use Undo/Redo (Ctrl+Z/Ctrl+Y) to revert changes.
                            </small>
                        </div>
                        <div style="max-height: 60vh; overflow-y: auto; overflow-x: hidden;">
                            <table id="sheetsTable" class="table table-bordered table-sm" style="font-size: 12px; margin-bottom: 0; width: 100%; table-layout: auto;">
                                <thead class="table-light sticky-top" style="position: sticky; top: 0; z-index: 10; background-color: #f8f9fa;">
                                    <tr>
                                        <th style="width: 50px; text-align: center;">#</th>
                                        <th v-for="column in sheetsColumns" :key="column" style="white-space: nowrap;">
                                            {{ column }}
                                        </th>
                                        <th style="width: 80px; text-align: center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, rowIndex) in sheetsData" :key="rowIndex">
                                        <td style="text-align: center; background-color: #f8f9fa; font-weight: bold;">
                                            {{ rowIndex + 1 }}
                                        </td>
                                        <td v-for="(column, colIndex) in sheetsColumns" :key="column" style="padding: 2px;">
                                            <input
                                                type="text"
                                                class="form-control form-control-sm"
                                                :value="row[column] || ''"
                                                @input="handleCellChange(rowIndex, column, $event.target.value)"
                                                @paste="handlePaste($event, rowIndex, colIndex)"
                                                style="border: 1px solid #dee2e6; padding: 4px 8px; width: 100%; font-size: 12px;"
                                                placeholder=""
                                            />
                                        </td>
                                        <td style="text-align: center; vertical-align: middle;">
                                            <button
                                                @click="removeSheetsRow(rowIndex)"
                                                class="btn btn-sm btn-danger"
                                                style="padding: 2px 8px; font-size: 10px;"
                                                :disabled="sheetsData.length === 1"
                                                title="Delete Row"
                                            >
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3 d-flex align-items-center gap-3 flex-wrap">
                            <div class="d-flex align-items-center gap-2">
                                <button 
                                    @click="undoSheets" 
                                    class="btn btn-sm btn-outline-secondary"
                                    :disabled="historyIndex <= 0"
                                    title="Undo (Ctrl+Z)"
                                >
                                    <i class="fa-solid fa-undo me-1"></i> Undo
                                </button>
                                <button 
                                    @click="redoSheets" 
                                    class="btn btn-sm btn-outline-secondary"
                                    :disabled="historyIndex >= sheetsHistory.length - 1"
                                    title="Redo (Ctrl+Y)"
                                >
                                    <i class="fa-solid fa-redo me-1"></i> Redo
                                </button>
                            </div>
                            <button @click="addSheetsRow" class="btn btn-sm btn-primary">
                                <i class="fa-solid fa-plus me-1"></i> Add 1 Row
                            </button>
                            <div class="d-flex align-items-center gap-2">
                                <label class="mb-0" style="font-size: 14px;">Add</label>
                                <input
                                    type="number"
                                    v-model.number="rowsToAdd"
                                    min="1"
                                    max="1000"
                                    class="form-control form-control-sm"
                                    style="width: 80px;"
                                    @keyup.enter="addMultipleRows"
                                />
                                <label class="mb-0" style="font-size: 14px;">rows</label>
                                <button @click="addMultipleRows" class="btn btn-sm btn-success">
                                    <i class="fa-solid fa-plus me-1"></i> Add Rows
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-primary" @click="saveSheetsData">
                            <i class="fa-solid fa-save me-1"></i> Save Locations
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>


