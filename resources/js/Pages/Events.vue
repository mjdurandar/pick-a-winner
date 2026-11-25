<script setup>
import Swal from 'sweetalert2';
import { ref, computed, watch, onUnmounted } from 'vue';
import { useForm, router, usePage, Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ScrollDatePicker from '@/Components/ScrollDatePicker.vue';
import Modal from '@/Components/Modal.vue';
import Dropdown from '@/Components/Dropdown.vue';
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

// Filters
const selectedFilmFilter = ref(null);
const search = ref('');
const onlyLive = ref(false);

// film_id arrives as a string from some rows and a number from others (see the
// parseInt in openEditModal), so it is compared as text.
const sameFilm = (a, b) => String(a ?? '') === String(b ?? '');

const filteredEvents = computed(() => {
    const q = search.value.trim().toLowerCase();

    return props.events.filter((event) => {
        if (selectedFilmFilter.value && !sameFilm(event.film_id, selectedFilmFilter.value)) return false;
        if (onlyLive.value && !event.is_enabled) return false;
        if (q && !`${event.event_name ?? ''} ${event.event_country ?? ''} ${event.event_coordinator ?? ''}`
            .toLowerCase().includes(q)) return false;

        return true;
    });
});

const totalScreenings = computed(() =>
    props.events.reduce((n, e) => n + (e.locations_count ?? 0), 0));

// Twelve fills a row evenly at two, three and four columns.
const perPage = 12;

// Shown when a film has no banner of its own, and swapped in if the one it has
// fails to load — a broken-image icon tells nobody anything.
const DEFAULT_BANNER = '/images/default-film-banner.svg';

const useDefaultBanner = (event) => {
    if (! event.target.src.endsWith(DEFAULT_BANNER)) {
        event.target.src = DEFAULT_BANNER;
    }
};
const currentPage = ref(1);

const totalPages = computed(() => Math.max(1, Math.ceil(filteredEvents.value.length / perPage)));

const paginatedEvents = computed(() => {
    const start = (currentPage.value - 1) * perPage;
    return filteredEvents.value.slice(start, start + perPage);
});

// Jump back to the first page whenever the filters change
watch([selectedFilmFilter, search, onlyLive], () => {
    currentPage.value = 1;
});

// Clamp the current page if the number of pages shrinks
watch(totalPages, () => {
    if (currentPage.value > totalPages.value) {
        currentPage.value = totalPages.value;
    }
});

const goToPage = (page) => {
    if (page < 1 || page > totalPages.value) return;
    currentPage.value = page;
};

// Panels, held in state rather than driven through bootstrap.Modal.
const showEventModal = ref(false);

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
    event_end_date: '',
    event_year: '',
    event_logo: null,
    event_banner: null, // File input
    event_coordinator: '',
    event_coordinator_email: '',
    event_coordinator_phone: '',
    event_country: '',
    film_id: null,
    instructions_password: '',
    is_enabled: false,
    show_all_locations: false,
    show_all_locations_draw: false
});

// Object URLs for a newly picked file, revoked on replace so they don't leak.
const bannerPreview = ref(null);
const logoPreview = ref(null);

const setPreview = (previewRef, file) => {
    if (previewRef.value) URL.revokeObjectURL(previewRef.value);
    previewRef.value = file ? URL.createObjectURL(file) : null;
};

// File input reference
// Largest image the server will accept, mirrored from EventsController so an
// oversized file is refused on selection rather than after a slow upload.
const MAX_IMAGE_KB = 2048;
const maxImageMb = (MAX_IMAGE_KB / 1024).toFixed(1).replace(/\.0$/, '');
const fileErrors = ref({ logo: null, banner: null });

const sizeLabel = (bytes) => bytes >= 1048576
    ? `${(bytes / 1048576).toFixed(1)} MB`
    : `${Math.max(1, Math.round(bytes / 1024))} KB`;

// The alert used to show a generic line for anything but a date problem, which
// hid real reasons like an image being over the size limit. Show what the
// server actually said.
const firstError = (errors, fallback) => errors.event_end_date
    || Object.values(errors ?? {}).find((m) => typeof m === 'string' && m)
    || fallback;

const tooBig = (file, which, inputEl) => {
    fileErrors.value[which] = null;

    if (file && file.size > MAX_IMAGE_KB * 1024) {
        fileErrors.value[which] = `That image is ${sizeLabel(file.size)}. The limit is ${maxImageMb} MB — try resizing or exporting it at a lower quality.`;
        if (inputEl) inputEl.value = '';

        return true;
    }

    return false;
};

const handleFileChange = (event) => {
    const file = event.target.files[0] || null;

    if (tooBig(file, 'banner', event.target)) {
        form.event_banner = null;
        setPreview(bannerPreview, null);

        return;
    }

    form.event_banner = file;
    setPreview(bannerPreview, form.event_banner);
};

const handleLogoChange = (event) => {
    const picked = event.target.files[0] || null;

    if (tooBig(picked, 'logo', event.target)) {
        form.event_logo = null;
        setPreview(logoPreview, null);

        return;
    }

    form.event_logo = picked;
    setPreview(logoPreview, form.event_logo);
};

// Open Modal for Creating a New Event
const openCreateModal = () => {
    isEditing.value = false;
    form.reset(); // Clear form
    existingBanner.value = null;
    existingLogo.value = null;
    fileErrors.value = { logo: null, banner: null };
    setPreview(bannerPreview, null);
    setPreview(logoPreview, null);
    clearFileInputs();
    showEventModal.value = true;
};

// The file controls only exist while the panel is open, so clearing them is
// guarded — reopening mounts empty ones anyway.
function clearFileInputs() {
    const banner = document.getElementById('event_banner');
    const logo = document.getElementById('event_logo');
    if (banner) banner.value = '';
    if (logo) logo.value = '';
}

// Track existing files when editing
const existingBanner = ref(null);
const existingLogo = ref(null);

// Preview the newly picked file if there is one, otherwise the image already on the event.
// Resolved by the server, which checks the file is actually on disk and encodes
// the path. The old version built "/storage/" + path by hand, so a missing file
// or a space in the name showed a broken-image icon with no explanation.
const bannerPreviewSrc = computed(() => bannerPreview.value || existingBanner.value);
const logoPreviewSrc = computed(() => logoPreview.value || existingLogo.value);


// Open Modal for Editing an Existing Event
const openEditModal = (event) => {
    isEditing.value = true;
    form.id = event.id;
    form.event_name = event.event_name;
    form.event_year = event.event_year;
    form.event_date = event.event_date;
    form.event_end_date = event.event_end_date || '';
    form.event_coordinator = event.event_coordinator;
    form.event_coordinator_email = event.event_coordinator_email;
    form.event_coordinator_phone = event.event_coordinator_phone || '';
    form.event_country = event.event_country;
    form.film_id = event.film_id ? parseInt(event.film_id) : null;
    form.instructions_password = event.instructions_password || '';
    form.is_enabled = event.is_enabled ? true : false;
    form.show_all_locations = event.show_all_locations ? true : false;
    form.show_all_locations_draw = event.show_all_locations_draw ? true : false;
    form.event_banner = null; // Reset file input - new file will override
    form.event_logo = null; // Reset file input - new file will override
    // Also clear the inputs themselves, otherwise a file picked for a previously
    // edited event lingers in the control while form.event_banner is null.
    clearFileInputs();
    setPreview(bannerPreview, null);
    setPreview(logoPreview, null);
    existingBanner.value = event.event_banner_url; // Resolved URL, or null when absent
    existingLogo.value = event.event_logo_url;
    fileErrors.value = { logo: null, banner: null };

    showEventModal.value = true;
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
    // Always sent (even when blank) so clearing the field reopens sign-ups.
    data.append('event_end_date', form.event_end_date ?? '');
    if (form.event_banner) {
        data.append('event_banner', form.event_banner);
    }
    if (form.event_logo) {
        data.append('event_logo', form.event_logo);
    }
    data.append('event_coordinator', form.event_coordinator);
    data.append('event_coordinator_email', form.event_coordinator_email);
    // Always sent (even when blank) so clearing the field clears the stored number.
    data.append('event_coordinator_phone', form.event_coordinator_phone ?? '');
    data.append('event_country', form.event_country);
    // Always append film_id as integer
    data.append('film_id', parseInt(form.film_id));
    if (form.instructions_password) {
        data.append('instructions_password', form.instructions_password);
    }
    // Append is_enabled as 1 or 0
    data.append('is_enabled', form.is_enabled ? '1' : '0');
    data.append('show_all_locations', form.show_all_locations ? '1' : '0');
    data.append('show_all_locations_draw', form.show_all_locations_draw ? '1' : '0');

    if (isEditing.value) {
        data.append('_method', 'PATCH'); // Use PATCH for updating
        router.post(route('events.update', form.id), data, {
        onSuccess: () => {
            showEventModal.value = false;
            Swal.fire('Updated!', 'Event has been updated.', 'success');
            form.reset();
            setPreview(bannerPreview, null);
            setPreview(logoPreview, null);
        },
        onError: (errors) => {
            Swal.fire('Error!', firstError(errors, 'There was an issue updating the event.'), 'error');
            console.log(errors);
        }
    });
    } else {
        router.post(route('events.store'), data, {
            onSuccess: () => {
                showEventModal.value = false;
                Swal.fire('Success!', 'Event has been created.', 'success');
                form.reset();
                setPreview(bannerPreview, null);
                setPreview(logoPreview, null);
            },
            onError: (errors) => {
                Swal.fire('Error!', firstError(errors, 'There was an issue creating the event.'), 'error');
                console.log(errors);
            }
        });
    }
};

const goToLocationPage = (event) => {
    router.get(route('location.locationpage', { eventId: event.id }));
};

// Share the public host instruction guide (how to run a draw + passwords).
const shareHostGuide = (event) => {
    const url = route('pickawinner.hostguide', { event_uuid: event.event_uuid });
    const pwd = event.instructions_password || '';
    Swal.fire({
        title: 'Host instruction link',
        html: `<div style="text-align:left;">
                 <p style="font-size:14px;color:#555;margin:0 0 16px;">Share this link with your host. It explains how to open Pick a Winner, run a draw and record the winner — and lists the passwords for this event.</p>
                 <label style="display:block;font-size:12px;font-weight:600;color:#555;margin:0 0 4px;">Link</label>
                 <input id="hostGuideUrl" readonly value="${url}" style="display:block;width:100%;box-sizing:border-box;font-size:13px;padding:9px 11px;border:1px solid #d9d9d9;border-radius:6px;color:#333;">
                 <label style="display:block;font-size:12px;font-weight:600;color:#555;margin:14px 0 4px;">Guide password</label>
                 <input id="hostGuidePwd" readonly value="${pwd}" style="display:block;width:100%;box-sizing:border-box;font-size:15px;font-weight:700;letter-spacing:1px;padding:9px 11px;border:1px solid #d9d9d9;border-radius:6px;color:#333;">
                 <p style="font-size:12px;color:#888;margin:10px 0 0;">The host needs both the link and this password to view the guide.</p>
               </div>`,
        showDenyButton: true,
        showCancelButton: true,
        confirmButtonText: 'Copy link',
        denyButtonText: 'Open',
        cancelButtonText: 'Close',
        confirmButtonColor: '#16C3D9',
        denyButtonColor: '#6c757d',
        didOpen: () => {
            const input = document.getElementById('hostGuideUrl');
            if (input) input.select();
        },
    }).then((result) => {
        if (result.isConfirmed) {
            const done = () => Swal.fire({
                icon: 'success',
                title: 'Copied!',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 1500,
            });
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url).then(done).catch(done);
            } else {
                const input = document.getElementById('hostGuideUrl');
                if (input) { input.select(); document.execCommand('copy'); }
                done();
            }
        } else if (result.isDenied) {
            window.open(url, '_blank');
        }
    });
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

// Deleting a film was disabled here long ago — the button was commented out, so
// the handler had nothing to call. Removed with it. The events.destroy route is
// still there if it should come back.

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

// Check if date format (e.g. "Friday, January 23, 2026" or "Thursday, 5 March 2026")
const isDateFormat = (text) => {
    const datePatterns = [
        // "Thursday, March 5, 2026" – weekday, month, day, year
        /^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},\s+\d{4}$/i,
        // "Thursday, 5 March 2026" – weekday, day, month, year (no comma after day)
        /^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),\s+\d{1,2}\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4}$/i,
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

    // Undo / redo while the sheet is open. Dropped again on close, and on
    // unmount, so it cannot outlive the panel.
    bindSheetKeys();
};

// Keyboard shortcuts for undo/redo, held so they can be unbound.
let sheetKeyHandler = null;

function bindSheetKeys() {
    unbindSheetKeys();

    sheetKeyHandler = (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
            e.preventDefault();
            undoSheets();
        } else if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.key === 'z' && e.shiftKey))) {
            e.preventDefault();
            redoSheets();
        }
    };

    document.addEventListener('keydown', sheetKeyHandler);
}

function unbindSheetKeys() {
    if (sheetKeyHandler) {
        document.removeEventListener('keydown', sheetKeyHandler);
        sheetKeyHandler = null;
    }
}

onUnmounted(() => unbindSheetKeys());

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

const escapeHtml = (value) =>
    String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[char]);

// Spell out exactly what each removed screening is taking with it. The master sheet
// is the source of truth for what the sign-up form offers, so a location taken off it
// really is deleted — and the entries people already submitted go with it.
const confirmDeletesWithData = async (locationsWithData) => {
    const rows = locationsWithData.map(loc => {
        const parts = [];
        if (loc.signups) parts.push(`${loc.signups} sign-up${loc.signups === 1 ? '' : 's'}`);
        if (loc.tickets) parts.push(`${loc.tickets} ticket attendee${loc.tickets === 1 ? '' : 's'}`);
        return `<li class="mb-1"><b>${escapeHtml(loc.name)}</b>${loc.date ? ` (${escapeHtml(loc.date)})` : ''}
                <br><span style="color:#b91c1c;">${parts.join(' + ')}</span></li>`;
    }).join('');

    const total = locationsWithData.reduce((sum, loc) => sum + loc.signups + loc.tickets, 0);

    const result = await Swal.fire({
        title: 'Delete these locations and their data?',
        html: `<p class="text-start">You removed ${locationsWithData.length} location${locationsWithData.length === 1 ? '' : 's'}
                  that already ${locationsWithData.length === 1 ? 'has' : 'have'} entries. Saving deletes
                  ${locationsWithData.length === 1 ? 'it' : 'them'} along with <b>${total} attendee record${total === 1 ? '' : 's'}</b>.</p>
               <ul class="text-start" style="max-height:240px; overflow-y:auto;">${rows}</ul>
               <p class="text-start"><b>This cannot be undone.</b> Cancel to leave the sheet as it was —
                  nothing is saved either way until you choose.</p>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete permanently',
        cancelButtonText: 'Cancel',
        focusCancel: true,
    });

    return result.isConfirmed;
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

    const post = (confirmDeletes) => axios.post(route('location.saveSheetsData'), {
        event_id: selectedEventForSheets.value.id,
        data: dataToSave,
        ids_to_delete: idsToDelete, // Send IDs that should be deleted
        confirm_deletes: confirmDeletes,
    });

    try {
        let response = await post(false);

        // Nothing was written yet — the server is asking about the attendee data the
        // removed locations hold. Answering yes replays the identical save.
        if (response.data.needs_confirmation) {
            if (!await confirmDeletesWithData(response.data.locations_with_data)) {
                Swal.fire('Nothing saved', 'You cancelled, so the sheet was left as it was.', 'info');
                return;
            }
            response = await post(true);
        }

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
    unbindSheetKeys();
};

</script>

<template>
    <Head title="Films" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-end gap-4">
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-semibold text-gray-500">
                        {{ events.length }} {{ events.length === 1 ? 'film' : 'films' }} ·
                        {{ totalScreenings.toLocaleString() }} screenings
                    </p>
                    <h2 class="truncate text-2xl font-extrabold tracking-tight text-gray-900">Films</h2>
                </div>
                <button
                    v-if="userRole === 'admin'"
                    type="button" @click="openCreateModal"
                    class="h-10 rounded-lg bg-[#16C3D9] px-4 text-sm font-bold text-[#06242A] hover:brightness-105"
                >
                    New film
                </button>
            </div>
        </template>

        <div class="mx-auto max-w-full px-4 py-6 sm:px-6 lg:px-8">
            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-3 rounded-xl bg-white p-4 shadow-card">
                <label class="flex h-10 min-w-[220px] flex-1 items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 sm:max-w-xs">
                    <svg class="h-4 w-4 shrink-0 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                    <span class="sr-only">Search films</span>
                    <input v-model="search" type="search" placeholder="Film, country or coordinator" class="w-full border-0 bg-transparent p-0 text-sm focus:ring-0" />
                </label>

                <select
                    v-model="selectedFilmFilter"
                    class="h-10 rounded-lg border-gray-200 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]"
                    aria-label="Filter by website"
                >
                    <option :value="null">All websites</option>
                    <option v-for="film in films" :key="film.id" :value="film.id">{{ film.name }}</option>
                </select>

                <button
                    type="button" @click="onlyLive = !onlyLive"
                    class="h-10 rounded-full border px-4 text-sm font-semibold"
                    :class="onlyLive
                        ? 'border-[#16C3D9] bg-[#ECFAFD] text-[#0B7A8A]'
                        : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'"
                >
                    Running only
                </button>

                <span class="flex-1"></span>
                <p class="text-xs text-gray-500">
                    Showing {{ paginatedEvents.length }} of {{ filteredEvents.length }}
                </p>
            </div>


            <!-- The films, one card each -->
            <div class="mt-5 grid gap-5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                <!-- Not overflow-hidden: the card's own menu has to be able to
                     drop below it. The banner carries the rounded top instead. -->
                <article
                    v-for="event in paginatedEvents" :key="event.id"
                    class="flex flex-col rounded-xl bg-white shadow-card"
                >
                    <div class="relative">
                        <img
                            :src="event.event_banner_url || DEFAULT_BANNER"
                            @error="useDefaultBanner"
                            :alt="`${event.event_name} banner`"
                            class="h-40 w-full rounded-t-xl object-cover"
                        />
                        <span
                            class="absolute left-3 top-3 inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11.5px] font-bold shadow"
                            :class="event.is_enabled ? 'bg-emerald-600 text-white' : 'bg-gray-800 text-gray-200'"
                        >
                            <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                            {{ event.is_enabled ? 'Running' : 'Not running' }}
                        </span>
                    </div>

                    <div class="flex flex-1 flex-col gap-3 p-4">
                        <div class="min-w-0">
                            <h3 class="truncate text-base font-extrabold tracking-tight text-gray-900">{{ event.event_name }}</h3>
                            <p class="truncate text-xs text-gray-500">
                                {{ event.event_country }}<span v-if="event.event_year"> · {{ event.event_year }}</span>
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-1.5">
                            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700">
                                {{ event.locations_count }} location{{ event.locations_count === 1 ? '' : 's' }}
                            </span>
                            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700">
                                {{ event.prizes_count }} prize{{ event.prizes_count === 1 ? '' : 's' }}
                            </span>
                            <span v-if="!event.locations_count" class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-bold text-amber-800">
                                no locations yet
                            </span>
                        </div>

                        <p class="text-xs text-gray-500">
                            First show {{ formatEventDate(event.event_date) }}
                            <span v-if="event.event_end_date" class="block">
                                Sign-ups close after {{ formatEventDate(event.event_end_date) }}
                            </span>
                        </p>

                        <div class="mt-auto flex items-center gap-2 pt-1">
                            <button
                                type="button" @click="openSheetsModal(event)"
                                class="h-10 flex-1 rounded-lg bg-[#16C3D9] px-3 text-sm font-bold text-[#06242A] hover:brightness-105"
                            >Master Sheet</button>
                            <button
                                type="button" @click="openEditModal(event)"
                                class="h-10 rounded-lg border border-gray-200 px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                            >Edit</button>

                            <Dropdown align="right" width="56">
                                <template #trigger>
                                    <button
                                        type="button"
                                        class="grid h-10 w-10 place-items-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 hover:text-gray-900"
                                        :aria-label="`More for ${event.event_name}`"
                                    >
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round"><circle cx="5" cy="12" r="1.5" /><circle cx="12" cy="12" r="1.5" /><circle cx="19" cy="12" r="1.5" /></svg>
                                    </button>
                                </template>
                                <template #content>
                                    <button type="button" @click="goToLocationPage(event)" class="block w-full px-4 py-2 text-start text-sm text-gray-700 hover:bg-gray-100">Locations</button>
                                    <button type="button" @click="goToSignUpForm(event.id)" class="block w-full px-4 py-2 text-start text-sm text-gray-700 hover:bg-gray-100">Sign-up form</button>
                                    <button type="button" @click="shareHostGuide(event)" class="block w-full px-4 py-2 text-start text-sm text-gray-700 hover:bg-gray-100">Share host guide</button>
                                </template>
                            </Dropdown>
                        </div>
                    </div>
                </article>
            </div>

            <p v-if="!paginatedEvents.length" class="mt-5 rounded-xl bg-white px-4 py-14 text-center text-sm text-gray-400 shadow-card">
                No films match these filters.
            </p>

            <!-- Pagination, only when there is more than one page -->
            <div v-if="totalPages > 1" class="mt-6 flex flex-wrap items-center justify-center gap-1.5">
                <button
                    type="button" @click="goToPage(currentPage - 1)" :disabled="currentPage === 1"
                    class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 disabled:opacity-40 hover:enabled:bg-gray-50"
                >Previous</button>
                <button
                    v-for="p in totalPages" :key="p" type="button" @click="goToPage(p)"
                    class="min-w-[34px] rounded-lg border px-2.5 py-1.5 text-xs font-semibold"
                    :class="p === currentPage
                        ? 'border-[#16C3D9] bg-[#ECFAFD] text-[#0B7A8A]'
                        : 'border-gray-200 text-gray-700 hover:bg-gray-50'"
                >{{ p }}</button>
                <button
                    type="button" @click="goToPage(currentPage + 1)" :disabled="currentPage === totalPages"
                    class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 disabled:opacity-40 hover:enabled:bg-gray-50"
                >Next</button>
            </div>
        </div>

        <!-- ============================================================
             Create / edit a film
             ============================================================ -->
        <Modal :show="showEventModal" max-width="4xl" :title="isEditing ? 'Edit film' : 'New film'" @close="showEventModal = false">
            <!-- The buttons live in the footer, outside this form, and reach it
                 by id — so required fields are still checked on submit. -->
            <form id="film-form" @submit.prevent="saveEvent" class="p-6">
                <!-- Details -->
                <p class="border-b border-gray-200 pb-1 text-[10.5px] font-bold uppercase tracking-[0.09em] text-gray-500">Details</p>
                <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <label class="block sm:col-span-2 lg:col-span-1">
                        <span class="text-xs font-bold text-gray-600">Event name</span>
                        <input v-model="form.event_name" type="text" required class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" />
                    </label>

                    <label class="block">
                        <span class="text-xs font-bold text-gray-600">Website <span class="text-red-600">*</span></span>
                        <select
                            v-model="form.film_id" required
                            class="mt-1 w-full rounded-lg text-sm focus:ring-[#16C3D9]"
                            :class="form.errors.film_id ? 'border-red-400' : 'border-gray-300 focus:border-[#16C3D9]'"
                        >
                            <option :value="null" disabled>Choose a website</option>
                            <option v-for="film in films" :key="film.id" :value="film.id">{{ film.name }}</option>
                        </select>
                        <span v-if="form.errors.film_id" class="mt-1 block text-xs font-semibold text-red-700">{{ form.errors.film_id }}</span>
                    </label>

                    <label class="block">
                        <span class="text-xs font-bold text-gray-600">Event year</span>
                        <input v-model="form.event_year" type="text" maxlength="4" pattern="\d{4}" required class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" />
                    </label>

                    <div>
                        <span class="text-xs font-bold text-gray-600">Start date</span>
                        <ScrollDatePicker v-model="form.event_date" :min-year="2015" :max-year="new Date().getFullYear() + 5" />
                    </div>

                    <div>
                        <span class="text-xs font-bold text-gray-600">End date</span>
                        <ScrollDatePicker v-model="form.event_end_date" :min-year="2015" :max-year="new Date().getFullYear() + 5" />
                        <span class="mt-1 block text-xs text-gray-500">Last day people can sign up. After this date the sign-up form closes. Leave blank to keep it open.</span>
                    </div>

                    <label class="block">
                        <span class="text-xs font-bold text-gray-600">Country</span>
                        <select v-model="form.event_country" required class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]">
                            <option value="AUSTRALIA &amp; NEW ZEALAND">AUSTRALIA &amp; NEW ZEALAND</option>
                            <option value="USA &amp; CANADA">USA &amp; CANADA</option>
                            <option value="USA">USA</option>
                            <option value="Canada">Canada</option>
                            <option value="UK">UK</option>
                            <option value="Australia">Australia</option>
                            <option value="New Zealand">New Zealand</option>
                            <option value="Germany">Germany</option>
                        </select>
                    </label>
                </div>

                <!-- Host and coordinator -->
                <p class="mt-7 border-b border-gray-200 pb-1 text-[10.5px] font-bold uppercase tracking-[0.09em] text-gray-500">
                    Host and coordinator <span class="font-medium normal-case tracking-normal text-gray-400">— shown on the shared host guide</span>
                </p>
                <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <label class="block">
                        <span class="text-xs font-bold text-gray-600">Host guide password</span>
                        <input v-model="form.instructions_password" type="text" maxlength="255" placeholder="Auto-generated if left blank" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" />
                        <span class="mt-1 block text-xs text-gray-500">Password hosts enter to open the shared instruction guide.</span>
                    </label>
                    <label class="block">
                        <span class="text-xs font-bold text-gray-600">Coordinator name</span>
                        <input v-model="form.event_coordinator" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" />
                    </label>
                    <label class="block">
                        <span class="text-xs font-bold text-gray-600">Coordinator email</span>
                        <input v-model="form.event_coordinator_email" type="email" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" />
                    </label>
                    <label class="block">
                        <span class="text-xs font-bold text-gray-600">Coordinator phone</span>
                        <input v-model="form.event_coordinator_phone" type="tel" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" />
                    </label>
                </div>

                <!-- Branding -->
                <p class="mt-7 border-b border-gray-200 pb-1 text-[10.5px] font-bold uppercase tracking-[0.09em] text-gray-500">Branding</p>
                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-600">Event logo</span>
                            <input id="event_logo" type="file" accept="image/*" @change="handleLogoChange" class="mt-1 w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-gray-700" />
                        </label>
                        <span v-if="fileErrors.logo" class="mt-1 block text-xs font-semibold text-red-700">{{ fileErrors.logo }}</span>
                        <span v-else class="mt-1 block text-xs text-gray-500">Up to {{ maxImageMb }} MB.</span>
                        <img v-if="logoPreviewSrc" :src="logoPreviewSrc" alt="" class="mt-2 h-16 w-auto max-w-[180px] rounded-md object-contain" />
                    </div>
                    <div>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-600">Event banner</span>
                            <input id="event_banner" type="file" accept="image/*" @change="handleFileChange" class="mt-1 w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-gray-700" />
                        </label>
                        <span v-if="fileErrors.banner" class="mt-1 block text-xs font-semibold text-red-700">{{ fileErrors.banner }}</span>
                        <span v-else class="mt-1 block text-xs text-gray-500">Up to {{ maxImageMb }} MB.</span>
                        <img v-if="bannerPreviewSrc" :src="bannerPreviewSrc" alt="" class="mt-2 h-16 w-auto max-w-[260px] rounded-md object-cover" />
                    </div>
                </div>

                <!-- Visibility -->
                <p class="mt-7 border-b border-gray-200 pb-1 text-[10.5px] font-bold uppercase tracking-[0.09em] text-gray-500">
                    Visibility <span class="font-medium normal-case tracking-normal text-gray-400">— where this event and its locations appear</span>
                </p>
                <div class="mt-3 grid gap-3 lg:grid-cols-3">
                    <label class="flex gap-3 rounded-xl border border-gray-200 p-3">
                        <input v-model="form.is_enabled" id="is_enabled" type="checkbox" class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-[#16C3D9] focus:ring-[#16C3D9]" />
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-gray-900">Show this in the Pick a Winner dropdown</span>
                            <span class="mt-0.5 block text-xs text-gray-500">Controls the event list, not the locations. When off, this event is hidden from the Pick a Winner login and no one can open its draw. Does not affect the sign-up form.</span>
                        </span>
                    </label>
                    <label class="flex gap-3 rounded-xl border border-gray-200 p-3">
                        <input v-model="form.show_all_locations" id="show_all_locations" type="checkbox" class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-[#16C3D9] focus:ring-[#16C3D9]" />
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-gray-900">Show all locations on the sign-up form</span>
                            <span class="mt-0.5 block text-xs text-gray-500">Includes finished locations. When off, the sign-up form only lists locations from 2 days ago to 3 weeks ahead.</span>
                        </span>
                    </label>
                    <label class="flex gap-3 rounded-xl border border-gray-200 p-3">
                        <input v-model="form.show_all_locations_draw" id="show_all_locations_draw" type="checkbox" class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-[#16C3D9] focus:ring-[#16C3D9]" />
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-gray-900">Show all locations on the Pick a Winner login</span>
                            <span class="mt-0.5 block text-xs text-gray-500">Includes finished locations. When off, the draw only lists locations from 7 days ago onwards.</span>
                        </span>
                    </label>
                </div>
            </form>

            <template #footer>
                <button type="button" @click="showEventModal = false" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" form="film-form" class="rounded-lg bg-[#16C3D9] px-4 py-2 text-sm font-bold text-[#06242A] hover:brightness-105">
                    {{ isEditing ? 'Update film' : 'Save film' }}
                </button>
            </template>
        </Modal>

        <!-- ============================================================
             The master sheet — a grid on a full-width panel, not a
             spreadsheet squeezed into a dialog box.
             ============================================================ -->
        <Modal :show="showSheetsModal" max-width="7xl" @close="closeSheetsModal">
            <template #header>
                <div class="flex flex-wrap items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold text-gray-500">{{ selectedEventForSheets?.event_name }}</p>
                        <h2 class="truncate text-lg font-extrabold tracking-tight text-gray-900">Master Sheet</h2>
                    </div>
                    <button type="button" @click="undoSheets" :disabled="historyIndex <= 0" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 disabled:opacity-40 hover:enabled:bg-gray-50">Undo</button>
                    <button type="button" @click="redoSheets" :disabled="historyIndex >= sheetsHistory.length - 1" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 disabled:opacity-40 hover:enabled:bg-gray-50">Redo</button>
                    <span class="text-xs text-gray-400">⌘Z / ⌘⇧Z</span>
                </div>
            </template>

            <!-- Straight into the dialog's scrolling body, with no wrapper of its
                 own, so the column headings stay stuck to the top of it. -->
            <table class="w-full border-collapse text-sm">
                        <thead class="sticky top-0 z-10">
                            <tr class="bg-gray-100">
                                <th scope="col" class="w-12 border-b border-gray-200 px-2 py-2 text-center text-[10.5px] font-bold uppercase tracking-wider text-gray-500">#</th>
                                <th v-for="column in sheetsColumns" :key="column" scope="col" class="whitespace-nowrap border-b border-gray-200 px-2 py-2 text-left text-[10.5px] font-bold uppercase tracking-wider text-gray-500">
                                    {{ column }}
                                </th>
                                <th scope="col" class="w-16 border-b border-gray-200 px-2 py-2"><span class="sr-only">Remove</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, rowIndex) in sheetsData" :key="rowIndex" class="border-b border-gray-100 hover:bg-gray-50">
                                <td class="px-2 py-1 text-center text-xs tabular-nums text-gray-400">{{ rowIndex + 1 }}</td>
                                <td v-for="(column, columnIndex) in sheetsColumns" :key="column" class="px-1 py-1">
                                    <input
                                        :value="row[column]"
                                        type="text"
                                        class="w-full min-w-[110px] rounded-md border-gray-200 px-2 py-1.5 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]"
                                        @input="handleCellChange(rowIndex, column, $event.target.value)"
                                        @paste="handlePaste($event, rowIndex, columnIndex)"
                                    />
                                </td>
                                <td class="px-2 py-1 text-center">
                                    <button
                                        type="button" @click="removeSheetsRow(rowIndex)"
                                        class="rounded-md px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50"
                                        :aria-label="`Remove row ${rowIndex + 1}`"
                                    >Remove</button>
                                </td>
                            </tr>
                            <tr v-if="!sheetsData.length">
                                <td :colspan="sheetsColumns.length + 2" class="px-4 py-10 text-center text-sm text-gray-400">
                                    No rows yet. Add one below, or paste straight from your spreadsheet.
                                </td>
                            </tr>
                        </tbody>
            </table>

            <template #footer>
                    <button type="button" @click="addSheetsRow" class="rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50">+ Add a row</button>
                    <label class="flex items-center gap-2 text-xs text-gray-600">
                        Add
                        <input v-model="rowsToAdd" type="number" min="1" max="1000" class="w-20 rounded-lg border-gray-200 px-2 py-1.5 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" aria-label="How many rows to add" />
                        rows
                        <button type="button" @click="addMultipleRows" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">Add</button>
                    </label>

                    <span class="flex-1"></span>
                    <p class="text-xs text-gray-500">{{ sheetsData.length }} row{{ sheetsData.length === 1 ? '' : 's' }}</p>
                    <button type="button" @click="closeSheetsModal" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Close</button>
                    <button type="button" @click="saveSheetsData" class="rounded-lg bg-[#16C3D9] px-4 py-2 text-sm font-bold text-[#06242A] hover:brightness-105">Save master sheet</button>
            </template>
        </Modal>
    </AuthenticatedLayout>
</template>
