<script setup>
import { ref, computed, onMounted } from 'vue';
import Swal from 'sweetalert2';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { debounce } from 'lodash';
import axios from 'axios';

const props = defineProps({
    event: Object,
    location: Object,
    attendees: Array,
    prizes: Array,
    form: Object
});

const searchQuery = ref("");
const debouncedSearchValue = ref('');
const currentPage = ref(1);
const itemsPerPage = 20;
const selectedAttendee = ref(null);
const prizeName = ref('');
const showWinnerModal = ref(false);
const showEditPrizeModal = ref(false);
const selectedPrize = ref(null);
const editPrizeName = ref('');
const mailchimpSettings = ref({
    film_tour: '',
    default_tags: ''
});

// Mailchimp import variables
const showMailchimpModal = ref(false);
const isOpeningMailchimpModal = ref(false); // true while loading modal (fetching lists)
const mailchimpLists = ref([]);
const selectedList = ref('');
const isLoadingMailchimpLists = ref(false);
const isImporting = ref(false);
const customTags = ref('');
// Use semicolon to separate tags so commas can appear inside a tag (e.g. "SHOW - SEATTLE, WA")
const TAG_SEP_DISPLAY = '; ';
function parseTagsFromInput(str) {
    return (str || '').split(/[;\n]/).map((t) => t.trim()).filter(Boolean);
}

// Auto-select Mailchimp account from event/location country: USA/CANADA → usa, Australia/NZ → anz
const mailchimpAccount = computed(() => {
    const country = (props.location?.country || props.event?.event_country || '').toUpperCase().trim();
    const usaCountries = ['USA', 'USA & CANADA', 'CANADA'];
    const anzCountries = ['AUSTRALIA', 'NEW ZEALAND', 'AUSTRALIA & NEW ZEALAND'];
    if (usaCountries.includes(country)) return 'usa';
    if (anzCountries.includes(country)) return 'anz';
    return 'anz'; // default
});
const availableMergeFields = ref([]);
const mergeFieldsWithValidation = ref([]); // for manual column mapping (same as Import All)
const sourceColumns = ref([]);
const fieldMapping = ref({}); // { FNAME: 'first_name', ADDRESSWIN: 'address_full', ... }
const showMappingSection = ref(false);
const isLoadingMergeFields = ref(false);
const missingFields = ref([]);
const fieldSuggestions = ref({});
// When set, Mailchimp modal uses these subscribers (e.g. from CSV import) instead of filteredAttendees
const mailchimpImportSubscribers = ref(null);

// Ticket import modal: 'eventbrite' | 'csv' (manual CSV / Event Groove)
const ticketImportTab = ref('eventbrite');
// CSV import variables (used inside ticket modal when tab is 'csv')
const csvImportStep = ref(1);
const csvFile = ref(null);
const csvHeaders = ref([]);
const csvPreviewRows = ref([]);
const csvColumnMapping = ref({ email: '', first_name: '', last_name: '', phone: '', city: '', state: '', country: '' });
const isImportingCsv = ref(false);
const importedCsvCount = ref(0);
const CSV_FIELDS = [
    { key: 'email', label: 'Email (required)', required: true },
    { key: 'first_name', label: 'First name', required: false },
    { key: 'last_name', label: 'Last name', required: false },
    { key: 'phone', label: 'Phone', required: false },
    { key: 'city', label: 'City', required: false },
    { key: 'state', label: 'State', required: false },
    { key: 'country', label: 'Country', required: false },
];

// Debounce the search to prevent excessive filtering
const updateDebouncedSearch = debounce((value) => {
    debouncedSearchValue.value = value.toLowerCase();
}, 300);

// Watch for search query changes
const handleSearchInput = (event) => {
    searchQuery.value = event.target.value;
    updateDebouncedSearch(event.target.value);
    currentPage.value = 1; // Reset to first page on search
};

// ✅ Extract column names (exclude unwanted columns)
const columnHeaders = computed(() => {
    if (props.attendees.length > 0) {
        const columns = Object.keys(props.attendees[0]).filter(col => !["created_at", "updated_at", "id", "event_id", "location_id", "events_location", "mobile_number_format"].includes(col));
        return columns;
    }
    return [];
});

// ✅ Get question text for a column name
const getQuestionText = (columnName) => {
    if (!props.form || !props.form.questions) return formatHeader(columnName);
    
    const questions = JSON.parse(props.form.questions);
    const question = questions.find(q => q.column_name === columnName);
    return question ? question.text : formatHeader(columnName);
};

// ✅ Format column headers (fallback for columns without questions)
const formatHeader = (header) => {
    return header.replace(/_/g, " ").replace(/\b\w/g, (char) => char.toUpperCase());
};

// ✅ Check if attendee is a winner
const isWinner = (attendee) => {
    return props.prizes.some(prize => prize.winner_email === attendee.email_address);
};

// ✅ Get prize name for winner
const getWinnerPrize = (attendee) => {
    const prize = props.prizes.find(prize => prize.winner_email === attendee.email_address);
    return prize ? prize.prize_name : '';
};

// ✅ Optimized filtered attendees based on search query
const filteredAttendees = computed(() => {
    if (!debouncedSearchValue.value) {
        return props.attendees;
    }

    const excludedColumns = ["created_at", "updated_at", "id", "event_id", "events_location", "mobile_number_format"];
    
    return props.attendees.filter(attendee => {
        return Object.entries(attendee)
            .filter(([key]) => !excludedColumns.includes(key))
            .some(([key, value]) => {
                if (!value) return false;
                if (key === "gender") {
                    return value.toLowerCase().trim() === debouncedSearchValue.value.trim();
                }
                return value.toString().toLowerCase().includes(debouncedSearchValue.value);
            });
    });
});

// ✅ Paginate filtered attendees
const paginatedAttendees = computed(() => {
    const start = (currentPage.value - 1) * itemsPerPage;
    return filteredAttendees.value.slice(start, start + itemsPerPage);
});

// ✅ Total pages
const totalPages = computed(() => {
    return Math.ceil(filteredAttendees.value.length / itemsPerPage);
});

// Attendees count for Mailchimp modal (signup form or ticket/CSV import)
const mailchimpAttendeeCount = computed(() => {
    if (mailchimpImportSubscribers.value && mailchimpImportSubscribers.value.length > 0) {
        return mailchimpImportSubscribers.value.length;
    }
    return filteredAttendees.value.length;
});

// Attendees we'll import (same as handleMailchimpImport) – for mapping preview
const mailchimpPreviewAttendees = computed(() => {
    if (mailchimpImportSubscribers.value && mailchimpImportSubscribers.value.length > 0) {
        return mailchimpImportSubscribers.value;
    }
    return filteredAttendees.value;
});

// Build concatenated address from attendee (same logic as backend) for preview when mapping to "Address (concatenated)"
function buildAddressFull(attendee) {
    const parts = [
        (attendee.street_address ?? '').trim(),
        (attendee.street_address_2 ?? '').trim(),
        (attendee.city ?? '').trim(),
        (attendee.state ?? '').trim(),
        (attendee.zip_code ?? attendee.postal_code ?? '').trim(),
        (attendee.country ?? '').trim(),
    ].filter(Boolean);
    return parts.length ? parts.join(', ') : '';
}

// Preview value for a Mailchimp field: what will be sent for the first attendee (so user can verify mapping)
function getMappingPreviewValue(mailchimpTag, mappedOurKey) {
    const list = mailchimpPreviewAttendees.value;
    if (!list || list.length === 0) return '—';
    const first = list[0];
    let val;
    if (mailchimpTag === 'EMAIL') {
        val = first.email_address ?? first.email ?? '';
    } else if (!mappedOurKey) {
        return '—';
    } else if (mappedOurKey === 'address_full') {
        val = (first.address_full && String(first.address_full).trim()) || buildAddressFull(first);
    } else {
        val = first[mappedOurKey];
    }
    if (val == null || val === '') return '—';
    const s = String(val).trim();
    return s.length > 50 ? s.slice(0, 50) + '…' : s;
}

// ✅ Navigate pages
const goToPage = (page) => {
    if (page >= 1 && page <= totalPages.value) {
        currentPage.value = page;
    }
};

// ✅ Fetch Mailchimp settings for tag generation
const fetchMailchimpSettings = async () => {
    try {
        const response = await axios.get(route('mailchimp.autosync.settings'), {
            params: {
                event_id: props.event.id
            }
        });
        const { settings } = response.data;
        mailchimpSettings.value = {
            film_tour: settings.film_tour || '',
            default_tags: Array.isArray(settings.default_tags) ? settings.default_tags.join(', ') : settings.default_tags || ''
        };
    } catch (error) {
        console.error('Failed to fetch Mailchimp settings:', error);
        // Set defaults if fetch fails
        mailchimpSettings.value = {
            film_tour: 'WM',
            default_tags: ''
        };
    }
};

// ✅ Generate location-specific Mailchimp tags
// SOURCE tag never includes state. SHOW: USA only = include state (e.g. SHOW - DENVER, CO); Australia/NZ/other = location only, no state.
// sourceType: 'signup_form' -> SOURCE uses COMP; 'ticket_data' (Eventbrite/CSV) -> SOURCE uses TIX
const generateLocationTags = (sourceType = 'signup_form') => {
    const tags = [];
    const filmTour = mailchimpSettings.value.film_tour || 'WM';
    const year = props.event?.event_year ?? new Date().getFullYear();
    const locationName = props.location.name;
    // Use event country for tag logic: only USA & Canada events get state in SHOW tag; Australia/NZ/other never do.
    const eventCountry = (props.event.event_country || props.location.country || 'Other').toString().trim();
    const locationCountryUpper = (props.location.country || props.event.event_country || 'Other').toString().trim().toUpperCase();
    const eventCountryUpper = eventCountry.toUpperCase();
    const isUSA = ['USA', 'USA & CANADA', 'USA AND CANADA'].includes(eventCountryUpper);
    
    // Everything before " - " as the location label (e.g. "Bozeman, MT - ..." -> "BOZEMAN, MT")
    const fullLocationTag = locationName.split(' - ')[0].trim().toUpperCase();
    const locationState = (props.location.state || '').toString().trim();

    // SOURCE tag: never include state — strip 2–3 letter abbrev (NSW, VIC) and/or location.state (e.g. Victoria, New South Wales)
    let locationNoState = (fullLocationTag.replace(/,?\s+[A-Z]{2,3}$/i, '').trim()) || fullLocationTag;
    if (locationState) {
        const stateEsc = locationState.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        locationNoState = (locationNoState.replace(new RegExp(`,?\\s*${stateEsc}$`, 'i'), '').trim()) || locationNoState;
    }
    const sourceTagLocation = locationNoState || fullLocationTag;

    // SHOW tag: USA only = include state (e.g. SHOW - DENVER, CO). Australia/NZ/other = location only, no state (e.g. SHOW - SYDNEY, MELBOURNE).
    let showTagLocation = sourceTagLocation;
    if (isUSA) {
        const stateFromLocation = (props.location.state || '').toString().trim().toUpperCase();
        const matchStateInName = fullLocationTag.match(/,?\s+([A-Z]{2,3})$/i);
        const stateInName = matchStateInName ? matchStateInName[1].toUpperCase() : '';
        const state = stateInName || stateFromLocation;
        if (state) {
            const base = (fullLocationTag.replace(/,?\s+[A-Z]{2,3}$/i, '').trim()) || fullLocationTag;
            showTagLocation = `${base}, ${state}`;
        }
    }
    
    // Add COUNTRY tag (for reporting)
    tags.push(`COUNTRY - ${locationCountryUpper}`);
    
    tags.push(`SHOW - ${showTagLocation}`);
    
    // SOURCE: TIX for ticket/Eventbrite/CSV import, COMP for signup form import (no state)
    const sourceWord = sourceType === 'ticket_data' ? 'TIX' : 'COMP';
    tags.push(`SOURCE - ${filmTour.toUpperCase()} ${sourceTagLocation} ${sourceWord} ${year}`);
    
    // Add any default tags if they exist
    if (mailchimpSettings.value.default_tags) {
        const defaultTags = mailchimpSettings.value.default_tags
            .split(',')
            .map(tag => tag.trim())
            .filter(tag => tag);
        tags.push(...defaultTags);
    }
    
    return tags;
};

// Store last Mailchimp import results in-memory so we can reopen the report
const lastMailchimpImportResults = ref(null);

// ✅ Watch for list selection changes to fetch merge fields (for selected account)
const fetchMergeFields = async (listId) => {
    if (!listId) {
        availableMergeFields.value = [];
        return;
    }
    
    isLoadingMergeFields.value = true;
    try {
        const [mergeRes, sourceRes] = await Promise.all([
            axios.get(route('location.mailchimpMergeFields'), {
                params: { list_id: listId, account: mailchimpAccount.value }
            }),
            axios.get(route('event.importSourceColumns', props.event.id))
        ]);
        availableMergeFields.value = mergeRes.data.merge_fields || [];
        mergeFieldsWithValidation.value = mergeRes.data.merge_fields_with_validation ?? mergeRes.data.merge_fields ?? [];
        sourceColumns.value = sourceRes.data?.source_columns ?? [];
        missingFields.value = mergeRes.data.missing_required_fields || [];
        fieldSuggestions.value = mergeRes.data.field_mapping || {};
        const sourceCols = sourceRes.data?.source_columns ?? [];
        // Default mapping by Mailchimp tag (same as Import All)
        const tagToDefault = {
            FNAME: 'first_name', LNAME: 'last_name',
            PHONE: 'mobile_number', SMSPHONE: 'mobile_number', MERGE4: 'mobile_number', MERGE30: 'mobile_number',
            ADDRESSWIN: 'address_full', MMERGE10: 'address_full', MERGE10: 'address_full', MERGE11: 'address_full',
            SHOWCITY: 'city', CITY: 'city', MERGE3: 'city', MERGE5: 'city',
            STATEWIN: 'state', STATE: 'state', MERGE6: 'state',
            ZIPCODEWIN: 'zip_code', ZIPCODE: 'zip_code', MERGE7: 'zip_code',
            COUNTRYWIN: 'country', COUNTRY: 'country', MERGE8: 'country',
            GENDER: 'gender', MERGE17: 'gender',
            AGEWIN: 'age', MERGE14: 'age', MMERGE14: 'age'
        };
        // Name/label aliases for auto-matching when tag is not in tagToDefault (e.g. Birthday, Company)
        const nameToCandidateKeys = {
            birthday: ['date_of_birth', 'dob', 'birthday', 'birth_date'],
            company: ['company', 'company_name', 'organization'],
            gender: ['gender'],
            age: ['age'],
            first: ['first_name', 'firstname', 'fname'],
            last: ['last_name', 'lastname', 'lname', 'surname'],
            email: ['email_address', 'email'],
            phone: ['mobile_number', 'phone', 'mobile', 'cell'],
            mobile: ['mobile_number', 'phone', 'mobile'],
            address: ['address_full', 'street_address', 'address'],
            city: ['city'],
            state: ['state'],
            zip: ['zip_code', 'postal_code', 'zip'],
            postal: ['zip_code', 'postal_code'],
            country: ['country'],
            attend: ['where_are_you_attending', 'attending', 'location'] // e.g. "Where are you attending F3T 2025?"
        };
        const normalize = (s) => (s || '').toLowerCase().replace(/[^a-z0-9]/g, ' ');
        const mapping = {};
        (mergeRes.data.merge_fields || []).forEach((f) => {
            const tag = (typeof f === 'object' && f?.tag) ? f.tag : (f?.tag || f);
            if (tag === 'EMAIL') return;
            let chosen = tagToDefault[tag] ?? '';
            if (!chosen && sourceCols.length > 0) {
                const name = (typeof f === 'object' && f?.name) ? f.name : '';
                const combined = `${name} ${tag}`.trim().toLowerCase();
                const normalizedName = normalize(name);
                const normalizedTag = normalize(tag);
                for (const [concept, candidates] of Object.entries(nameToCandidateKeys)) {
                    const matches = combined.includes(concept) || normalizedName.includes(concept) || normalizedTag.includes(concept);
                    const birthdayMatch = (concept === 'birthday') && (combined.includes('birth') || combined.includes('dob') || normalizedName.includes('birth') || normalizedName.includes('dob'));
                    if (matches || birthdayMatch) {
                        const found = sourceCols.find((sc) => {
                            const k = (sc.key || '').toLowerCase();
                            const l = (sc.label || '').toLowerCase();
                            return candidates.some((c) => k === c || l.includes(c) || k.includes(c));
                        });
                        if (found) {
                            chosen = found.key;
                            break;
                        }
                    }
                }
                if (!chosen) {
                    const nameWords = normalizedName.split(/\s+/).filter(Boolean);
                    const found = sourceCols.find((sc) => {
                        const k = (sc.key || '').toLowerCase();
                        const l = (sc.label || '').toLowerCase();
                        return nameWords.some((w) => w.length >= 2 && (k.includes(w) || l.includes(w)));
                    });
                    if (found) chosen = found.key;
                }
            }
            mapping[tag] = chosen;
        });
        fieldMapping.value = mapping;
    } catch (error) {
        console.error('Failed to fetch merge fields:', error);
        availableMergeFields.value = [];
        mergeFieldsWithValidation.value = [];
        sourceColumns.value = [];
    } finally {
        isLoadingMergeFields.value = false;
    }
};

// ✅ Custom notification function that won't interfere with modals
const showCustomNotification = (message, type = 'success') => {
    // Remove any existing notification
    const existingNotification = document.getElementById('custom-notification');
    if (existingNotification) {
        existingNotification.remove();
    }
    
    // Create notification element
    const notification = document.createElement('div');
    notification.id = 'custom-notification';
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? '#10B981' : '#EF4444'};
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        z-index: 9999;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        transform: translateX(100%);
        transition: transform 0.3s ease;
        max-width: 300px;
        word-wrap: break-word;
    `;
    notification.textContent = message;
    
    // Add to page
    document.body.appendChild(notification);
    
    // Animate in
    setTimeout(() => {
        notification.style.transform = 'translateX(0)';
    }, 10);
    
    // Auto remove after 3 seconds
    setTimeout(() => {
        notification.style.transform = 'translateX(100%)';
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 300);
    }, 3000);
};

// ✅ Initialize component
onMounted(() => {
    fetchMailchimpSettings();
});

// Eventbrite import variables
const showEventbriteModal = ref(false);
const eventbriteLink = ref('');
const isFetchingEventbrite = ref(false);
const eventbriteAttendees = ref([]);
const eventbriteEventId = ref('');

// Extract Event ID from Eventbrite link
const extractEventIdFromLink = (link) => {
    try {
        const url = new URL(link);
        const pathParts = url.pathname.split('/');
        
        for (let i = pathParts.length - 1; i >= 0; i--) {
            const part = pathParts[i];
            if (/^\d+$/.test(part)) {
                return part;
            }
            const match = part.match(/-(\d+)$/);
            if (match) {
                return match[1];
            }
        }
        
        return null;
    } catch (error) {
        console.error('Error extracting event ID:', error);
        return null;
    }
};

// Open ticket import modal (Eventbrite or manual CSV)
const openEventbriteModal = () => {
    ticketImportTab.value = 'eventbrite';
    eventbriteLink.value = '';
    eventbriteAttendees.value = [];
    eventbriteEventId.value = '';
    csvImportStep.value = 1;
    csvFile.value = null;
    csvHeaders.value = [];
    csvPreviewRows.value = [];
    csvColumnMapping.value = { email: '', first_name: '', last_name: '', phone: '', city: '', state: '', country: '' };
    importedCsvCount.value = 0;
    showEventbriteModal.value = true;
};

// Close ticket import modal
const closeEventbriteModal = () => {
    showEventbriteModal.value = false;
    eventbriteLink.value = '';
    eventbriteAttendees.value = [];
    eventbriteEventId.value = '';
    ticketImportTab.value = 'eventbrite';
    csvImportStep.value = 1;
    csvFile.value = null;
};

// Parse a single CSV line (handles quoted commas)
const parseCsvLine = (line) => {
    const result = [];
    let current = '';
    let inQuotes = false;
    for (let i = 0; i < line.length; i++) {
        const c = line[i];
        if (c === '"') {
            inQuotes = !inQuotes;
        } else if ((c === ',' && !inQuotes) || (c === '\r' && !inQuotes)) {
            result.push(current.trim());
            current = '';
        } else {
            current += c;
        }
    }
    result.push(current.trim());
    return result;
};

// Parse CSV text into { headers, rows }
const parseCsvText = (text) => {
    const lines = text.split('\n').filter(l => l.length > 0);
    if (lines.length === 0) return { headers: [], rows: [] };
    const headers = parseCsvLine(lines[0].replace(/^\uFEFF/, ''));
    const rows = lines.slice(1, 6).map(parseCsvLine);
    return { headers, rows };
};

// Guess default column mapping from CSV headers
const guessColumnMapping = (headers) => {
    const lower = headers.map(h => (h || '').toLowerCase());
    const map = { email: '', first_name: '', last_name: '', phone: '', city: '', state: '', country: '' };
    lower.forEach((h, i) => {
        const orig = headers[i];
        if (/email|e-mail|e_mail/.test(h)) map.email = orig;
        else if (/first|fname|firstname|given/.test(h)) map.first_name = orig;
        else if (/last|lname|lastname|surname|family/.test(h)) map.last_name = orig;
        else if (/phone|mobile|cell|tel/.test(h)) map.phone = orig;
        else if (/^city$|town/.test(h)) map.city = orig;
        else if (/state|region|province/.test(h)) map.state = orig;
        else if (/country/.test(h)) map.country = orig;
    });
    return map;
};

// Switch to CSV tab inside ticket modal (no separate modal)
const switchToCsvTab = () => {
    ticketImportTab.value = 'csv';
    csvImportStep.value = 1;
    csvFile.value = null;
    csvHeaders.value = [];
    csvPreviewRows.value = [];
    csvColumnMapping.value = { email: '', first_name: '', last_name: '', phone: '', city: '', state: '', country: '' };
    importedCsvCount.value = 0;
};

const onCsvFileSelected = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    csvFile.value = file;
    const reader = new FileReader();
    reader.onload = (e) => {
        const { headers, rows } = parseCsvText(e.target.result || '');
        csvHeaders.value = headers;
        csvPreviewRows.value = rows;
        csvColumnMapping.value = guessColumnMapping(headers);
        csvImportStep.value = 2;
    };
    reader.readAsText(file, 'UTF-8');
};

const submitCsvImport = async () => {
    if (!csvFile.value || !csvColumnMapping.value.email) {
        Swal.fire('Error', 'Please map at least the Email column.', 'error');
        return;
    }
    isImportingCsv.value = true;
    try {
        const formData = new FormData();
        formData.append('csv_file', csvFile.value);
        formData.append('location_id', props.location.id);
        formData.append('column_mapping', JSON.stringify(csvColumnMapping.value));

        const response = await axios.post(route('location.importCsvTicketAttendees'), formData, {
            headers: { 'X-XSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '', 'Accept': 'application/json' },
            withCredentials: true,
        });
        importedCsvCount.value = response.data.saved ?? 0;
        csvImportStep.value = 3;
        if (response.data.saved > 0) {
            router.reload();
        }
    } catch (err) {
        Swal.fire('Error', err.response?.data?.error || 'Failed to import CSV.', 'error');
    } finally {
        isImportingCsv.value = false;
    }
};

const openMailchimpWithTicketAttendees = async () => {
    try {
        const response = await axios.get(route('location.getTicketAttendees', { locationId: props.location.id }));
        mailchimpImportSubscribers.value = response.data.subscribers || [];
        closeEventbriteModal();
        await openMailchimpImportModal();
    } catch (err) {
        Swal.fire('Error', err.response?.data?.error || 'Failed to load attendees for Mailchimp.', 'error');
    }
};

// Fetch Eventbrite attendees (saves to this location; then you can use Import to Mailchimp)
const fetchEventbriteAttendees = async () => {
    if (!eventbriteLink.value.trim()) {
        Swal.fire('Error', 'Please enter an Eventbrite link', 'error');
        return;
    }

    const eventId = extractEventIdFromLink(eventbriteLink.value);
    if (!eventId) {
        Swal.fire('Error', 'Could not extract event ID from the link. Please check the link format.', 'error');
        return;
    }

    eventbriteEventId.value = eventId;
    isFetchingEventbrite.value = true;

    try {
        Swal.fire({
            title: 'Fetching Attendees',
            html: 'Please wait while we fetch attendee data from Eventbrite. This may take a minute for large events.',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        const response = await axios.post(route('location.fetchEventbriteAttendees'), {
            event_id: eventId,
            location_id: props.location.id
        }, { timeout: 120000 });

        if (response.data.error) {
            await Swal.close();
            Swal.fire('Error', response.data.error, 'error');
            return;
        }

        const attendees = Array.isArray(response.data.attendees) ? response.data.attendees : [];
        eventbriteAttendees.value = attendees;

        await Swal.close();

        if (attendees.length === 0) {
            Swal.fire('Info', 'No attendees found for this event.', 'info');
        } else {
            // Map to Mailchimp subscriber shape (same as getTicketAttendees)
            const subscribers = attendees.map((a) => ({
                email_address: a.email || '',
                first_name: a.first_name ?? '',
                last_name: a.last_name ?? '',
                mobile_number: a.phone ?? '',
                city: a.city ?? '',
                state: a.state ?? '',
                country: a.country ?? ''
            }));
            mailchimpImportSubscribers.value = subscribers;
            closeEventbriteModal();
            await openMailchimpImportModal();
        }
    } catch (error) {
        await Swal.close();
        console.error('Error fetching Eventbrite attendees:', error);
        const msg = error.code === 'ECONNABORTED'
            ? 'The request took too long. Check your link and try again, or the event may have many attendees.'
            : (error.response?.data?.error || error.message || 'Failed to fetch attendees from Eventbrite.');
        Swal.fire('Error', msg, 'error');
    } finally {
        isFetchingEventbrite.value = false;
    }
};

// ✅ Delete Attendee with Confirmation
const deleteAttendee = (attendeeId, eventId) => {
    Swal.fire({
        title: 'Are you sure?',
        text: 'This action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('attendees.destroyFromLocation', { 
                attendee: attendeeId, 
                event: eventId, 
                location: props.location.id 
            }), {
                onSuccess: () => {
                    Swal.fire('Deleted!', 'The attendee has been removed.', 'success');
                }
            });
        }
    });
};

// ✅ Mark attendee as winner
const markAsWinner = (attendee) => {
    selectedAttendee.value = attendee;
    prizeName.value = '';
    showWinnerModal.value = true;
};

// ✅ Confirm winner selection
const confirmWinner = () => {
    if (!selectedAttendee.value || !prizeName.value.trim()) {
        Swal.fire('Error', 'Please enter a prize name.', 'error');
        return;
    }

    const data = {
        prize_name: prizeName.value.trim(),
        event_id: props.event.id,
        location_id: props.location.id,
        winner_name: `${selectedAttendee.value.first_name} ${selectedAttendee.value.last_name}`,
        winner_email: selectedAttendee.value.email_address,
        winner_mobile_number: selectedAttendee.value.mobile_number || '',
    };

    router.post(route('prize.store'), data, {
        onSuccess: () => {
            showWinnerModal.value = false;
            Swal.fire('Success!', `${selectedAttendee.value.first_name} has been marked as a winner!`, 'success').then(() => {
                // Reload the page to show updated prize information
                router.reload();
            });
            selectedAttendee.value = null;
            prizeName.value = '';
        },
        onError: (errors) => {
            console.error('Error marking winner:', errors);
            Swal.fire('Error!', 'There was an issue marking the winner.', 'error');
        }
    });
};

// ✅ Close winner modal
const closeWinnerModal = () => {
    showWinnerModal.value = false;
    selectedAttendee.value = null;
    prizeName.value = '';
};

// ✅ Go back to location page
const goBackToLocation = () => {
    router.get(route('location.locationpage', props.event.id));
};

// Export winner/prize data for this location to CSV – only include rows with a winner email
const exportWinnersToCSV = () => {
    const allPrizes = props.prizes || [];
    const hasWinnerEmail = (p) => {
        const email = (p.winner_email || '').toString().trim();
        return email && email !== 'No Winner Yet';
    };
    const prizes = allPrizes.filter(hasWinnerEmail);
    if (prizes.length === 0) {
        Swal.fire('No Data', allPrizes.length ? 'No winners with an email to export.' : 'No winner data for this location to export.', 'info');
        return;
    }
    const locationName = props.location?.name ?? 'Location';
    const headers = ['Location', 'Prize Name', 'Winner Name', 'Winner Email', 'Winner Mobile'];
    const escape = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    let csv = 'data:text/csv;charset=utf-8,' + headers.map(escape).join(',') + '\n';
    prizes.forEach((p) => {
        const row = [
            locationName,
            p.prize_name ?? '',
            p.winner ?? '',
            p.winner_email ?? '',
            p.winner_mobile_number ?? ''
        ];
        csv += row.map(escape).join(',') + '\n';
    });
    const link = document.createElement('a');
    link.setAttribute('href', encodeURI(csv));
    link.setAttribute('download', `winners_${(locationName || 'location').replace(/\s+/g, '_').replace(/[^a-zA-Z0-9_-]/g, '_')}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    Swal.fire('Exported', `Exported ${prizes.length} winner(s) to CSV.`, 'success');
};

// ✅ Edit prize
const editPrize = (attendee) => {
    const prize = props.prizes.find(p => p.winner_email === attendee.email_address);
    if (prize) {
        selectedPrize.value = prize;
        editPrizeName.value = prize.prize_name;
        showEditPrizeModal.value = true;
    }
};

// ✅ Update prize name
const updatePrizeName = () => {
    if (!selectedPrize.value || !editPrizeName.value.trim()) {
        Swal.fire('Error', 'Please enter a prize name.', 'error');
        return;
    }

    const data = {
        prize_name: editPrizeName.value.trim()
    };

    router.patch(route('prize.update', selectedPrize.value.id), data, {
        onSuccess: () => {
            showEditPrizeModal.value = false;
            Swal.fire('Success!', 'Prize name updated successfully!', 'success').then(() => {
                router.reload();
            });
            selectedPrize.value = null;
            editPrizeName.value = '';
        },
        onError: (errors) => {
            console.error('Error updating prize:', errors);
            Swal.fire('Error!', 'There was an issue updating the prize name.', 'error');
        }
    });
};

// ✅ Close edit prize modal
const closeEditPrizeModal = () => {
    showEditPrizeModal.value = false;
    selectedPrize.value = null;
    editPrizeName.value = '';
};

// ✅ Mailchimp import functions
const closeMailchimpModal = () => {
    showMailchimpModal.value = false;
    mailchimpImportSubscribers.value = null;
    mergeFieldsWithValidation.value = [];
    sourceColumns.value = [];
    fieldMapping.value = {};
    showMappingSection.value = false;
};

const loadMailchimpListsForAccount = async (account) => {
    isLoadingMailchimpLists.value = true;
    mailchimpLists.value = [];
    selectedList.value = '';
    availableMergeFields.value = [];
    mergeFieldsWithValidation.value = [];
    sourceColumns.value = [];
    fieldMapping.value = {};
    missingFields.value = [];
    try {
        const response = await axios.get(route('location.mailchimpLists'), {
            params: { account: account ?? mailchimpAccount.value }
        });
        mailchimpLists.value = response.data.lists || [];
    } catch (error) {
        console.error('Failed to fetch Mailchimp lists:', error);
        Swal.fire('Error', error.response?.data?.error || 'Failed to load Mailchimp audiences', 'error');
    } finally {
        isLoadingMailchimpLists.value = false;
    }
};

const openMailchimpImportModal = async () => {
    isOpeningMailchimpModal.value = true;
    try {
        const isTicketImport = !!(mailchimpImportSubscribers.value && mailchimpImportSubscribers.value.length);
        const sourceType = isTicketImport ? 'ticket_data' : 'signup_form';
        const locationTags = generateLocationTags(sourceType);
        customTags.value = locationTags.join(TAG_SEP_DISPLAY);
        // Load lists for account auto-selected from event/location country
        await loadMailchimpListsForAccount(mailchimpAccount.value);
        showMailchimpModal.value = true;
    } catch (error) {
        console.error('Failed to open Mailchimp modal:', error);
        Swal.fire('Error', 'Failed to load Mailchimp', 'error');
    } finally {
        isOpeningMailchimpModal.value = false;
    }
};

const handleMailchimpImport = async () => {
    if (!selectedList.value) {
        Swal.fire('Error!', 'Please select a Mailchimp audience.', 'error');
        return;
    }

    const attendeesToUse = (mailchimpImportSubscribers.value && mailchimpImportSubscribers.value.length)
        ? mailchimpImportSubscribers.value
        : filteredAttendees.value;

    if (attendeesToUse.length === 0) {
        Swal.fire('Error!', 'No attendees found to import.', 'error');
        return;
    }

    isImporting.value = true;

    try {
        // Get all attendees data (from CSV/ticket attendees or signup form)
        const attendeesToImport = attendeesToUse;
        const totalAttendees = attendeesToImport.length;

        // Use the editable tags field (split by semicolon so commas inside a tag are preserved)
        let allTags = [];
        if (customTags.value.trim()) {
            allTags = parseTagsFromInput(customTags.value);
        }
        // Send a plain array so the server receives all tags (no reactive/serialization quirks)
        allTags = Array.isArray(allTags) ? [...allTags] : [];

        console.log('Final tags for import:', allTags);

        // Process in chunks
        const chunkSize = totalAttendees <= 10 ? totalAttendees : 10;
        const totalChunks = Math.ceil(attendeesToImport.length / chunkSize);
        let successCount = 0;
        let failureCount = 0;
        let updateCount = 0;
        let newCount = 0;
        let errors = [];
        let importedAttendees = [];
        let updatedAttendees = [];
        let newAttendees = [];
        let errorDetails = [];
        let rejectedFields = [];
        let rejectedFieldsCount = 0;

        // Create and show enhanced progress modal
        Swal.fire({
            title: 'Importing Attendees to Mailchimp',
            html: `
                <div class="text-left space-y-2">
                    <div class="flex justify-between">
                        <span>Progress:</span>
                        <span><strong>0 of ${totalAttendees}</strong> processed</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                        <div class="bg-blue-600 h-2.5 rounded-full" style="width: 0%"></div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mt-3 text-sm">
                        <div class="bg-green-100 p-2 rounded">
                            <div class="text-green-800 font-medium">✓ Success</div>
                            <div class="text-green-600">0</div>
                        </div>
                        <div class="bg-blue-100 p-2 rounded">
                            <div class="text-blue-800 font-medium">↻ Updated</div>
                            <div class="text-blue-600">0</div>
                        </div>
                        <div class="bg-red-100 p-2 rounded">
                            <div class="text-red-800 font-medium">✗ Failed</div>
                            <div class="text-red-600">0</div>
                        </div>
                    </div>
                    <div class="mt-2 text-sm text-gray-600">
                        Processing attendees...
                    </div>
                </div>
            `,
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        // Process each chunk
        let lastResponse = null;
        for (let i = 0; i < totalChunks; i++) {
            const start = i * chunkSize;
            const end = Math.min(start + chunkSize, attendeesToImport.length);
            const chunk = attendeesToImport.slice(start, end);
            
            try {
                console.log('Posting chunk to Mailchimp with tags:', allTags);
                const fm = { ...fieldMapping.value };
                Object.keys(fm).forEach((k) => { if (fm[k] === '') delete fm[k]; });
                const response = await axios.post(route('location.manualImportToMailchimp'), {
                    subscribers: chunk,
                    list_id: selectedList.value,
                    mailchimp_account: mailchimpAccount.value,
                    tags: allTags,
                    location_id: props.location.id,
                    field_mapping: Object.keys(fm).length ? fm : null
                });
                
                console.log('Mailchimp response', response);
                lastResponse = response; // Store the last response for copy-paste data
                
                // Process response details
                if (response.data.details.success > 0) {
                    importedAttendees = importedAttendees.concat(chunk);
                }
                
                // Enhanced response processing
                if (response.data.details.updated) {
                    updateCount += response.data.details.updated;
                    updatedAttendees = updatedAttendees.concat(
                        chunk.filter((_, index) => response.data.details.updatedIndices?.includes(index) || response.data.details.updated > 0)
                    );
                }
                
                if (response.data.details.new) {
                    newCount += response.data.details.new;
                    newAttendees = newAttendees.concat(
                        chunk.filter((_, index) => !response.data.details.updatedIndices?.includes(index) || response.data.details.new > 0)
                    );
                }
                
                successCount += response.data.details.success;
                failureCount += response.data.details.failed;
                errors = errors.concat(response.data.details.errors);
                
                // Collect detailed error information
                if (response.data.details.errorDetails) {
                    errorDetails = errorDetails.concat(response.data.details.errorDetails);
                }
                
                // Collect rejected fields information
                if (response.data.details.rejectedFields) {
                    rejectedFields = rejectedFields.concat(response.data.details.rejectedFields);
                }
                if (response.data.details.rejectedFieldsCount) {
                    rejectedFieldsCount += response.data.details.rejectedFieldsCount;
                }

                // Update enhanced progress
                const processed = Math.min((i + 1) * chunkSize, totalAttendees);
                const progressPercent = (processed / totalAttendees * 100).toFixed(1);
                
                await Swal.update({
                    html: `
                        <div class="text-left space-y-2">
                            <div class="flex justify-between">
                                <span>Progress:</span>
                                <span><strong>${processed} of ${totalAttendees}</strong> processed (${progressPercent}%)</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2.5">
                                <div class="bg-blue-600 h-2.5 rounded-full transition-all" style="width: ${progressPercent}%"></div>
                            </div>
                            <div class="grid grid-cols-3 gap-2 mt-3 text-sm">
                                <div class="bg-green-100 p-2 rounded">
                                    <div class="text-green-800 font-medium">✓ Success</div>
                                    <div class="text-green-600">${successCount}</div>
                                </div>
                                <div class="bg-blue-100 p-2 rounded">
                                    <div class="text-blue-800 font-medium">↻ Updated</div>
                                    <div class="text-blue-600">${updateCount}</div>
                                </div>
                                <div class="bg-red-100 p-2 rounded">
                                    <div class="text-red-800 font-medium">✗ Failed</div>
                                    <div class="text-red-600">${failureCount}</div>
                                </div>
                            </div>
                            <div class="mt-2 text-sm text-gray-600">
                                Processing chunk ${i + 1} of ${totalChunks}...
                            </div>
                        </div>
                    `
                });
                console.log('Processed', processed);
            } catch (error) {
                console.error('Chunk import error:', error);
                errors.push(`Chunk ${i + 1} failed: ${error.message}`);
            }
        }

        // One log per import: save cumulative stats and optional import file for download
        try {
            await axios.post(route('location.logMailchimpImport'), {
                location_id: props.location.id,
                total_data: totalAttendees,
                new_contacts: newCount,
                updated_data: updateCount,
                data_with_error: failureCount,
                errors: errors,
                failed_rows: errorDetails.length ? errorDetails.map((d) => ({
                    email: d.email,
                    error: d.error,
                    subscriber_data: d.subscriber_data,
                })) : undefined,
                tags: allTags.slice(0),
                subscribers: attendeesToImport,
                source: (mailchimpImportSubscribers.value && mailchimpImportSubscribers.value.length) ? 'ticket_data' : 'signup_form',
                mailchimp_account: mailchimpAccount.value,
                list_id: selectedList.value,
                list_name: (mailchimpLists.value || []).find(l => l.id === selectedList.value)?.name || '',
            }, {
                headers: { 'X-XSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '', 'Accept': 'application/json' },
                withCredentials: true,
            });
        } catch (err) {
            console.error('Failed to log Mailchimp import', err);
        }

        // Close progress modal
        await Swal.close();
        console.log('Progress modal closed');
        
        // Generate copy-paste data with cumulative totals
        let copyPasteData = null;
        try {
            // Create the import data with cumulative totals
            const importDataForSpreadsheet = {
                totalSubscribers: totalAttendees,
                successCount: successCount,
                failureCount: failureCount,
                updateCount: updateCount,
                newCount: newCount,
                errors: errors,
                errorDetails: errorDetails,
                rejectedFields: rejectedFields,
                rejectedFieldsCount: rejectedFieldsCount
            };
            
            // Generate copy-paste data using the cumulative totals
            const response = await axios.post('/location/generate-spreadsheet-data', {
                location_id: props.location.id,
                import_data: importDataForSpreadsheet,
                tags: allTags
            });
            
            if (response.data.copy_paste_data) {
                copyPasteData = response.data.copy_paste_data;
                console.log('Copy-paste data generated with cumulative totals');
            }
        } catch (error) {
            console.error('Failed to generate copy-paste data:', error);
            // Fallback to last response if available
            if (lastResponse && lastResponse.data.details.copy_paste_data) {
                copyPasteData = lastResponse.data.details.copy_paste_data;
                console.log('Using fallback copy-paste data from last response');
            }
        }
        
        // Build results object for this import
        const resultsPayload = {
            totalAttendees,
            successCount,
            failureCount,
            updateCount,
            newCount,
            errors,
            errorDetails,
            importedAttendees,
            updatedAttendees,
            newAttendees,
            rejectedFields,
            rejectedFieldsCount,
            copyPasteData
        };

        // Cache results so user can re-open the report without re-importing (same session)
        lastMailchimpImportResults.value = resultsPayload;

        // Show comprehensive final results
        await showDetailedResults(resultsPayload);
        console.log('Final results shown'); 
        
        // Close the Mailchimp modal and reset form
        showMailchimpModal.value = false;
        mailchimpImportSubscribers.value = null;
        selectedList.value = '';
        customTags.value = '';
        isImporting.value = false;
        
        // If import was successful, refresh the page to update the import status indicator
        if (successCount > 0) {
            setTimeout(() => {
                router.reload();
            }, 2000); // Wait 2 seconds to let user see the results
        }
    } catch (error) {
        console.error('Import error:', error);
        await Swal.fire('Error!', error.response?.data?.error || 'Failed to import data to Mailchimp.', 'error');
        isImporting.value = false;
    }
};

// Show detailed results modal
const showDetailedResults = async (results) => {
    const {
        totalAttendees,
        successCount,
        failureCount,
        updateCount,
        newCount,
        errors,
        errorDetails,
        importedAttendees,
        updatedAttendees,
        newAttendees,
        rejectedFields,
        rejectedFieldsCount,
        copyPasteData
    } = results;

    // Create tabs content
    const summaryTab = `
        <div class="text-left space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-blue-50 p-4 rounded-lg">
                    <h4 class="font-semibold text-blue-800 mb-2">📊 Import Summary</h4>
                    <div class="space-y-1 text-sm">
                        <div class="flex justify-between">
                            <span>Total Processed:</span>
                            <span class="font-medium">${totalAttendees}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>✅ Successfully Imported:</span>
                            <span class="font-medium text-green-600">${successCount}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>❌ Failed:</span>
                            <span class="font-medium text-red-600">${failureCount}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>📈 Success Rate:</span>
                            <span class="font-medium">${((successCount / totalAttendees) * 100).toFixed(1)}%</span>
                        </div>
                    </div>
                </div>
                <div class="bg-green-50 p-4 rounded-lg">
                    <h4 class="font-semibold text-green-800 mb-2">🎯 Import Breakdown</h4>
                    <div class="space-y-1 text-sm">
                        <div class="flex justify-between">
                            <span>🆕 New Subscribers:</span>
                            <span class="font-medium text-blue-600">${newCount}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>🔄 Updated Existing:</span>
                            <span class="font-medium text-orange-600">${updateCount}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>📍 Location:</span>
                            <span class="font-medium">${props.location.name}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>📋 Tags Applied:</span>
                            <span class="font-medium">${customTags.value ? parseTagsFromInput(customTags.value).length : 0}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;

    const successTab = `
        <div class="text-left">
            <h4 class="font-semibold text-green-800 mb-3">✅ Successfully Imported (${successCount})</h4>
            <div class="max-h-60 overflow-y-auto">
                ${importedAttendees.length > 0 ? `
                    <div class="space-y-2">
                        ${importedAttendees.slice(0, 50).map((attendee, index) => `
                            <div class="bg-green-50 p-2 rounded text-sm">
                                <div class="font-medium">${attendee.first_name || 'N/A'} ${attendee.last_name || 'N/A'}</div>
                                <div class="text-gray-600">${attendee.email_address || 'No email'}</div>
                                ${attendee.city ? `<div class="text-gray-500">${attendee.city}</div>` : ''}
                            </div>
                        `).join('')}
                        ${importedAttendees.length > 50 ? `<div class="text-center text-gray-500 text-sm mt-2">... and ${importedAttendees.length - 50} more</div>` : ''}
                    </div>
                ` : '<div class="text-gray-500 text-center py-4">No successful imports</div>'}
            </div>
        </div>
    `;

    const errorsTab = `
        <div class="text-left">
            <h4 class="font-semibold text-red-800 mb-3">❌ Import Errors (${failureCount})</h4>
            <div class="max-h-60 overflow-y-auto">
                ${errors.length > 0 ? `
                    <div class="space-y-2">
                        ${errors.slice(0, 20).map((error, index) => `
                            <div class="bg-red-50 p-2 rounded text-sm">
                                <div class="text-red-700">${error}</div>
                            </div>
                        `).join('')}
                        ${errors.length > 20 ? `<div class="text-center text-gray-500 text-sm mt-2">... and ${errors.length - 20} more errors</div>` : ''}
                    </div>
                ` : '<div class="text-gray-500 text-center py-4">No errors occurred</div>'}
            </div>
        </div>
    `;

    const rejectedTab = `
        <div class="text-left">
            <h4 class="font-semibold text-yellow-800 mb-3">🚫 Rejected Fields (${rejectedFieldsCount} fields from ${rejectedFields.length} subscribers)</h4>
            <div class="max-h-60 overflow-y-auto">
                ${rejectedFields.length > 0 ? `
                    <div class="space-y-3">
                        ${rejectedFields.map((subscriber, index) => `
                            <div class="bg-yellow-50 p-3 rounded border border-yellow-200">
                                <div class="font-medium text-yellow-900 mb-2">
                                    ${subscriber.name || 'N/A'} (${subscriber.email})
                                </div>
                                <div class="space-y-1">
                                    ${subscriber.rejected_fields.map(field => `
                                        <div class="bg-white p-2 rounded text-xs border-l-4 border-yellow-400">
                                            <div class="font-medium text-gray-900">${field.field_name} (${field.field})</div>
                                            <div class="text-gray-600">
                                                <strong>Reason:</strong> ${field.reason}
                                            </div>
                                            ${field.value ? `<div class="text-gray-500"><strong>Value:</strong> ${field.value}</div>` : ''}
                                            <div class="text-gray-400 text-xs"><strong>Source:</strong> ${field.data_source}</div>
                                        </div>
                                    `).join('')}
                                </div>
                            </div>
                        `).join('')}
                    </div>
                ` : '<div class="text-gray-500 text-center py-4">No fields were rejected</div>'}
            </div>
            ${rejectedFields.length > 0 ? `
                <div class="mt-3 p-3 bg-blue-50 rounded text-sm">
                    <div class="font-medium text-blue-800 mb-1">💡 How to Fix Rejected Fields:</div>
                    <ul class="text-blue-700 space-y-1 text-xs">
                        <li>• <strong>SMS Phone:</strong> Add Australian phone numbers (+61 or 04 prefix) for SMS marketing</li>
                        <li>• <strong>Missing Data:</strong> Ensure your signup form collects all required information</li>
                        <li>• <strong>Field Setup:</strong> Add missing fields to your Mailchimp audience settings</li>
                    </ul>
                </div>
            ` : ''}
        </div>
    `;

    const updatesTab = `
        <div class="text-left">
            <h4 class="font-semibold text-orange-800 mb-3">🔄 Updated Subscribers (${updateCount})</h4>
            <div class="max-h-60 overflow-y-auto">
                ${updatedAttendees.length > 0 ? `
                    <div class="space-y-2">
                        ${updatedAttendees.slice(0, 50).map((attendee, index) => `
                            <div class="bg-orange-50 p-2 rounded text-sm">
                                <div class="font-medium">${attendee.first_name || 'N/A'} ${attendee.last_name || 'N/A'}</div>
                                <div class="text-gray-600">${attendee.email_address || 'No email'}</div>
                                <div class="text-orange-600 text-xs">Updated existing subscriber</div>
                            </div>
                        `).join('')}
                        ${updatedAttendees.length > 50 ? `<div class="text-center text-gray-500 text-sm mt-2">... and ${updatedAttendees.length - 50} more</div>` : ''}
                    </div>
                ` : '<div class="text-gray-500 text-center py-4">No existing subscribers were updated</div>'}
            </div>
        </div>
    `;

    // Show the comprehensive results modal
    await Swal.fire({
        title: 'Import Results',
        html: `
            <div class="text-left">
                <div class="border-b border-gray-200 mb-4">
                    <nav class="-mb-px flex space-x-8">
                        <button onclick="showTab('summary')" id="tab-summary" class="tab-button active border-b-2 border-blue-500 py-2 px-1 text-sm font-medium text-blue-600">
                            📊 Summary
                        </button>
                        <button onclick="showTab('success')" id="tab-success" class="tab-button border-b-2 border-transparent py-2 px-1 text-sm font-medium text-gray-500 hover:text-gray-700">
                            ✅ Success (${successCount})
                        </button>
                        <button onclick="showTab('updates')" id="tab-updates" class="tab-button border-b-2 border-transparent py-2 px-1 text-sm font-medium text-gray-500 hover:text-gray-700">
                            🔄 Updates (${updateCount})
                        </button>
                        <button onclick="showTab('errors')" id="tab-errors" class="tab-button border-b-2 border-transparent py-2 px-1 text-sm font-medium text-gray-500 hover:text-gray-700">
                            ❌ Errors (${failureCount})
                        </button>
                        <button onclick="showTab('rejected')" id="tab-rejected" class="tab-button border-b-2 border-transparent py-2 px-1 text-sm font-medium text-gray-500 hover:text-gray-700">
                            🚫 Rejected Fields (${rejectedFieldsCount})
                        </button>
                    </nav>
                </div>
                <div id="tab-content-summary" class="tab-content">${summaryTab}</div>
                <div id="tab-content-success" class="tab-content hidden">${successTab}</div>
                <div id="tab-content-updates" class="tab-content hidden">${updatesTab}</div>
                <div id="tab-content-errors" class="tab-content hidden">${errorsTab}</div>
                <div id="tab-content-rejected" class="tab-content hidden">${rejectedTab}</div>
                <!-- Copy-Paste Data Section -->
                <div class="mt-6 p-4 bg-gray-50 rounded-lg">
                    <h4 class="font-semibold text-gray-800 mb-3">📋 Spreadsheet Data</h4>
                    <div class="mb-4 p-3 bg-white border rounded-lg">
                        <div class="text-sm text-gray-600 mb-2">Copy-paste data for spreadsheet:</div>
                        <div class="select-all cursor-pointer hover:bg-gray-100 transition-colors p-2 bg-gray-50 rounded font-mono text-xs whitespace-pre-wrap" id="spreadsheet-data">${copyPasteData || 'No data available'}</div>
                    </div>
                    <div class="flex justify-center">
                        <button onclick="copyTabSeparated()" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg text-sm font-medium transition-colors shadow-md">
                            📋 Copy Separated Data
                        </button>
                    </div>
                </div>
            </div>
        `,
        width: '800px',
        confirmButtonText: 'Close',
        confirmButtonColor: '#059669',
        didOpen: () => {
            // Add tab switching functionality
            window.showTab = (tabName) => {
                // Hide all tab content
                document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
                document.querySelectorAll('.tab-button').forEach(el => {
                    el.classList.remove('active', 'border-blue-500', 'text-blue-600');
                    el.classList.add('border-transparent', 'text-gray-500');
                });
                
                // Show selected tab
                document.getElementById('tab-content-' + tabName).classList.remove('hidden');
                const button = document.getElementById('tab-' + tabName);
                button.classList.add('active', 'border-blue-500', 'text-blue-600');
                button.classList.remove('border-transparent', 'text-gray-500');
            };
            
            // Add click handler for spreadsheet data selection
            const spreadsheetDataElement = document.getElementById('spreadsheet-data');
            if (spreadsheetDataElement) {
                spreadsheetDataElement.addEventListener('click', function() {
                    const range = document.createRange();
                    range.selectNodeContents(this);
                    const selection = window.getSelection();
                    selection.removeAllRanges();
                    selection.addRange(range);
                });
            }
            
            // Add copy functions
            window.copyTabSeparated = function() {
                console.log('Copy function called');
                const dataElement = document.getElementById('spreadsheet-data');
                if (dataElement) {
                    const text = dataElement.textContent;
                    console.log('Raw text content:', text);
                    const lines = text.split('\n');
                    console.log('Split lines:', lines);
                    
                    // Find the line that contains "COPY THIS LINE"
                    const copyLineIndex = lines.findIndex(line => line.includes('COPY THIS LINE'));
                    console.log('Copy line index:', copyLineIndex);
                    if (copyLineIndex !== -1 && copyLineIndex + 1 < lines.length) {
                        // Get the line after "COPY THIS LINE" which contains the tab-separated data
                        const tabLine = lines[copyLineIndex + 1].trim();
                        console.log('Tab line found:', tabLine);
                        console.log('Tab line length:', tabLine.length);
                        console.log('Tab line contains tabs:', tabLine.includes('\t'));
                        console.log('Tab line split by tabs:', tabLine.split('\t'));
                        
                        // Verify this line contains tab-separated data (should have multiple tabs)
                        // Also check for other common separators that might be used
                        const hasTabs = tabLine.includes('\t');
                        const hasCommas = tabLine.includes(',');
                        const tabSplit = tabLine.split('\t');
                        const commaSplit = tabLine.split(',');
                        
                        if (hasTabs && tabSplit.length > 1) {
                            console.log('Valid tab-separated data found, copying to clipboard');
                            navigator.clipboard.writeText(tabLine).then(() => {
                                // Show a custom notification that won't interfere with the modal
                                showCustomNotification('Tab-separated data copied to clipboard!', 'success');
                            }).catch(err => {
                                console.error('Failed to copy to clipboard:', err);
                                // Fallback: show the data in an alert for manual copying
                                Swal.fire({
                                    title: 'Copy Failed',
                                    html: `
                                        <div class="text-left">
                                            <p class="mb-3">Please manually copy this data:</p>
                                            <div class="bg-gray-100 p-3 rounded text-sm font-mono break-all">
                                                ${tabLine}
                                            </div>
                                        </div>
                                    `,
                                    confirmButtonText: 'OK'
                                });
                            });
                        } else if (hasCommas && commaSplit.length > 1) {
                            console.log('Found comma-separated data, converting to tab-separated');
                            // Convert comma-separated to tab-separated
                            const tabSeparatedLine = commaSplit.join('\t');
                            navigator.clipboard.writeText(tabSeparatedLine).then(() => {
                                // Show a custom notification that won't interfere with the modal
                                showCustomNotification('Comma-separated data converted and copied!', 'success');
                            });
                        } else {
                            console.log('No tabs or commas found in line, searching for tab-separated data');
                            // If no tabs found, try to find the actual tab-separated line
                            for (let i = copyLineIndex + 1; i < lines.length; i++) {
                                const line = lines[i].trim();
                                console.log(`Checking line ${i}:`, line);
                                if (line && (line.includes('\t') || line.split('\t').length > 1)) {
                                    console.log('Found tab-separated data on line', i);
                                    navigator.clipboard.writeText(line).then(() => {
                                        // Show a custom notification that won't interfere with the modal
                                        showCustomNotification('Tab-separated data copied to clipboard!', 'success');
                                    });
                                    return;
                                } else if (line && (line.includes(',') || line.split(',').length > 1)) {
                                    console.log('Found comma-separated data on line', i, 'converting to tab-separated');
                                    const tabSeparatedLine = line.split(',').join('\t');
                                    navigator.clipboard.writeText(tabSeparatedLine).then(() => {
                                        // Show a custom notification that won't interfere with the modal
                                        showCustomNotification('Comma-separated data converted and copied!', 'success');
                                    });
                                    return;
                                }
                            }
                            // If still not found, show error with more details
                            console.log('No tab-separated or comma-separated data found');
                            Swal.fire({
                                title: 'Error',
                                html: `
                                    <div class="text-left">
                                        <p class="mb-3">Could not find properly separated data in the copy-paste content.</p>
                                        <p class="text-sm text-gray-600 mb-3">Raw content found:</p>
                                        <div class="bg-gray-100 p-2 rounded text-xs font-mono max-h-32 overflow-y-auto">
                                            ${tabLine}
                                        </div>
                                    </div>
                                `,
                                icon: 'error'
                            });
                        }
                    } else {
                        console.log('Could not find COPY THIS LINE or no data after it');
                        Swal.fire({
                            title: 'Error',
                            text: 'Could not find the copy-paste data format.',
                            icon: 'error'
                        });
                    }
                } else {
                    console.log('Data element not found');
                }
            };
        },
        icon: failureCount > 0 ? 'warning' : 'success'
    });
};

</script>

<template>
    <Head title="Location Attendees" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div class="flex items-center space-x-3">
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">
                        Attendees for {{ location.name }} - {{ event.event_name }}
                    </h2>
                    <!-- Mailchimp import status: Win data and/or Ticket -->
                    <div v-if="location.imported_win_to_mailchimp || location.imported_ticket_to_mailchimp" class="flex items-center space-x-2">
                        <span v-if="location.imported_win_to_mailchimp" class="flex items-center space-x-1.5 bg-teal-100 text-teal-800 px-3 py-1 rounded-full text-sm">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Win Data</span>
                        </span>
                        <span v-if="location.imported_ticket_to_mailchimp" class="flex items-center space-x-1.5 bg-amber-100 text-amber-800 px-3 py-1 rounded-full text-sm">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Ticket data</span>
                        </span>
                    </div>
                </div>
                <button 
                    @click="goBackToLocation"
                    class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-700"
                >
                    <i class="fa-solid fa-arrow-left"></i> Back to Locations
                </button>
            </div>
        </template>

        <div class="p-2 pb-5 pt-5">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <!-- Summary Stats -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                            <div class="bg-blue-100 p-4 rounded-lg">
                                <h3 class="text-lg font-semibold text-blue-800">Total Attendees</h3>
                                <p class="text-2xl font-bold text-blue-900">{{ attendees.length }}</p>
                            </div>
                            <div class="bg-green-100 p-4 rounded-lg">
                                <h3 class="text-lg font-semibold text-green-800">Winners</h3>
                                <p class="text-2xl font-bold text-green-900">{{ prizes.length }}</p>
                            </div>
                        </div>

                        <!-- Mailchimp Tags Info -->
                        <div class="bg-purple-100 p-4 rounded-lg mb-6">
                            <h3 class="text-lg font-semibold text-purple-800 mb-2">Mailchimp Export Tags</h3>
                            <p class="text-sm text-purple-700 mb-2">The following tags will be included in the CSV export:</p>
                            <div class="flex flex-wrap gap-2">
                                <span 
                                    v-for="tag in generateLocationTags()" 
                                    :key="tag" 
                                    class="bg-purple-200 text-purple-800 px-2 py-1 rounded text-sm"
                                >
                                    {{ tag }}
                                </span>
                            </div>
                        </div>

                        <div class="flex justify-between mb-3">
                            <!-- ✅ Search Bar -->
                            <input
                                :value="searchQuery"
                                type="text"
                                placeholder="Search attendees..."
                                class="w-full md:w-1/3 p-2 border rounded"
                                @input="handleSearchInput"
                            />
                            <div class="flex gap-2 flex-wrap">
                                <!-- ✅ Export winners for this location -->
                                <button 
                                    v-if="(prizes || []).length > 0"
                                    @click="exportWinnersToCSV" 
                                    class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700"
                                >
                                    <i class="fa-solid fa-trophy"></i> Export winners
                                </button>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full border-collapse border border-gray-300">
                                <thead class="bg-gray-200 sticky top-0">
                                    <tr>
                                        <th v-for="(col, index) in columnHeaders" :key="index" class="border border-gray-300 p-2 whitespace-nowrap">
                                            {{ getQuestionText(col) }}
                                        </th>
                                        <th class="border border-gray-300 p-2">Winner Status</th>
                                        <th class="border border-gray-300 p-2">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(attendee, index) in paginatedAttendees" :key="index" 
                                        class="text-left even:bg-gray-100" 
                                        :class="{ 'bg-green-50': isWinner(attendee) }">
                                        <td v-for="(col, index) in columnHeaders" :key="index" class="border border-gray-300 p-2 whitespace-nowrap overflow-hidden text-ellipsis">
                                            {{ attendee[col] }}
                                        </td>
                                        <td class="border border-gray-300 p-2 text-center whitespace-nowrap">
                                            <span v-if="isWinner(attendee)" class="bg-green-500 text-white px-2 py-1 rounded text-sm">
                                                🏆 Winner: {{ getWinnerPrize(attendee) }}
                                            </span>
                                            <span v-else class="text-gray-500 text-sm">Not a winner</span>
                                        </td>
                                        <td class="text-center content-center whitespace-nowrap">
                                            <div class="flex gap-2 justify-center">
                                                <button 
                                                    v-if="!isWinner(attendee)"
                                                    @click="markAsWinner(attendee)"
                                                    class="bg-yellow-500 text-white px-2 py-1 rounded hover:bg-yellow-700 text-sm"
                                                    title="Mark as Winner"
                                                >
                                                    <i class="fa-solid fa-trophy"></i>
                                                </button>
                                                <button 
                                                    v-if="isWinner(attendee)"
                                                    @click="editPrize(attendee)"
                                                    class="bg-blue-500 text-white px-2 py-1 rounded hover:bg-blue-700 text-sm"
                                                    title="Edit Prize"
                                                >
                                                    <i class="fa-solid fa-edit"></i>
                                                </button>
                                                <button 
                                                    @click="deleteAttendee(attendee.id, event.id)"
                                                    class="bg-red-500 text-white px-2 py-1 rounded hover:bg-red-700 text-sm"
                                                    title="Delete Attendee"
                                                >
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- ✅ Pagination Controls -->
                        <div class="flex justify-between items-center mt-4">
                            <button 
                                @click="goToPage(currentPage - 1)" 
                                :disabled="currentPage === 1" 
                                class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400 disabled:opacity-50"
                            >
                                Previous
                            </button>
                            
                            <span class="text-gray-700">Page {{ currentPage }} of {{ totalPages }}</span>
                            
                            <button 
                                @click="goToPage(currentPage + 1)" 
                                :disabled="currentPage === totalPages" 
                                class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400 disabled:opacity-50"
                            >
                                Next
                            </button>
                        </div>

                        <div v-if="attendees.length === 0" class="text-gray-600 text-center mt-4">
                            No attendees have registered for this location.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Winner Selection Modal -->
        <div v-if="showWinnerModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-md w-full mx-4">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">Mark as Winner</h3>
                    <button @click="closeWinnerModal" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                
                <div class="space-y-4">
                    <div>
                        <p class="text-gray-600 mb-2">
                            Selected Attendee: <strong>{{ selectedAttendee?.first_name }} {{ selectedAttendee?.last_name }}</strong>
                        </p>
                        <p class="text-gray-600 mb-4">
                            Email: {{ selectedAttendee?.email_address }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Prize Name
                        </label>
                        <input 
                            v-model="prizeName"
                            type="text" 
                            class="w-full border rounded px-3 py-2"
                            placeholder="Enter prize name..."
                            @keyup.enter="confirmWinner"
                        />
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button 
                            @click="closeWinnerModal"
                            class="px-4 py-2 border rounded text-gray-600 hover:bg-gray-50"
                        >
                            Cancel
                        </button>
                        <button 
                            @click="confirmWinner"
                            class="px-4 py-2 bg-yellow-500 text-white rounded hover:bg-yellow-600"
                            :disabled="!prizeName.trim()"
                        >
                            <i class="fa-solid fa-trophy mr-2"></i>
                            Mark as Winner
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Prize Modal -->
        <div v-if="showEditPrizeModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-md w-full mx-4">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">Edit Prize Name</h3>
                    <button @click="closeEditPrizeModal" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                
                <div class="space-y-4">
                    <div>
                        <p class="text-gray-600 mb-2">
                            Winner: <strong>{{ selectedPrize?.winner }}</strong>
                        </p>
                        <p class="text-gray-600 mb-4">
                            Current Prize: {{ selectedPrize?.prize_name }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Prize Name
                        </label>
                        <input 
                            v-model="editPrizeName"
                            type="text" 
                            class="w-full border rounded px-3 py-2"
                            placeholder="Enter new prize name..."
                            @keyup.enter="updatePrizeName"
                        />
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button 
                            @click="closeEditPrizeModal"
                            class="px-4 py-2 border rounded text-gray-600 hover:bg-gray-50"
                        >
                            Cancel
                        </button>
                        <button 
                            @click="updatePrizeName"
                            class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
                            :disabled="!editPrizeName.trim()"
                        >
                            <i class="fa-solid fa-save mr-2"></i>
                            Update Prize
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mailchimp Import Modal -->
        <div v-if="showMailchimpModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">Import Attendees to Mailchimp</h3>
                    <button @click="closeMailchimpModal" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                
                <div class="space-y-4">
                    <p class="text-gray-600">
                        Import <strong>{{ mailchimpAttendeeCount }}</strong> attendees from <strong>{{ location.name }}</strong> to Mailchimp
                        <span v-if="mailchimpImportSubscribers?.length" class="text-sm text-blue-600">(from imported CSV/ticket data)</span>
                    </p>

                    <!-- Editable Tags Section -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fa-solid fa-tags mr-1"></i>
                            Tags to Import (Editable)
                        </label>
                        <textarea 
                            v-model="customTags"
                            class="w-full border rounded px-3 py-2 h-24"
                            placeholder="Enter tags separated by semicolons (e.g. TAG1; TAG2; SHOW - SEATTLE, WA)"
                            :disabled="isImporting"
                        ></textarea>
                        <div class="mt-2 flex items-start justify-between">
                            <p class="text-sm text-gray-600">
                                <strong>Auto-populated</strong> with location-specific tags. Separate tags with <strong>semicolons</strong> so commas inside a tag (e.g. SHOW - SEATTLE, WA) stay as one tag.
                            </p>
                            <button 
                                type="button"
                                @click="customTags = generateLocationTags(mailchimpImportSubscribers?.length ? 'ticket_data' : 'signup_form').join(TAG_SEP_DISPLAY)"
                                class="text-sm bg-gray-100 hover:bg-gray-200 px-2 py-1 rounded"
                                :disabled="isImporting"
                            >
                                <i class="fa-solid fa-refresh mr-1"></i>
                                Reset to Auto-Generated
                            </button>
                        </div>
                        
                        <!-- Live Preview of Tags -->
                        <div v-if="customTags.trim()" class="mt-3 p-3 bg-blue-50 rounded-lg">
                            <h5 class="text-sm font-medium text-blue-800 mb-2">
                                <i class="fa-solid fa-eye mr-1"></i>
                                Preview: {{ parseTagsFromInput(customTags).length }} tags will be applied
                            </h5>
                            <div class="flex flex-wrap gap-2">
                                <span 
                                    v-for="tag in parseTagsFromInput(customTags)" 
                                    :key="tag" 
                                    class="bg-blue-200 text-blue-800 px-2 py-1 rounded text-sm"
                                >
                                    {{ tag }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <p class="text-sm text-gray-600 mb-2">
                            Using Mailchimp <strong>{{ mailchimpAccount === 'usa' ? 'USA' : 'ANZ' }}</strong> (based on event country: {{ location.country || event.event_country || '—' }}).
                        </p>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Select Mailchimp audience
                        </label>
                        <select 
                            v-model="selectedList"
                            class="w-full border rounded px-3 py-2"
                            :disabled="isImporting || isLoadingMailchimpLists"
                            @change="fetchMergeFields(selectedList)"
                        >
                            <option value="">{{ isLoadingMailchimpLists ? 'Loading audiences...' : 'Select an audience...' }}</option>
                            <option 
                                v-for="list in mailchimpLists" 
                                :key="list.id" 
                                :value="list.id"
                            >
                                {{ list.name }} ({{ list.stats.member_count }} members)
                            </option>
                        </select>
                        
                        <!-- Loading indicator for merge fields -->
                        <div v-if="isLoadingMergeFields" class="mt-2 text-sm text-gray-600">
                            <i class="fa-solid fa-spinner fa-spin mr-1"></i>
                            Loading merge fields...
                        </div>
                    </div>

                    <!-- Available Merge Fields Display -->
                    <div v-if="availableMergeFields.length > 0" class="mb-4 p-4 bg-blue-50 rounded-lg">
                        <h4 class="font-semibold mb-2 text-blue-800">
                            <i class="fa-solid fa-info-circle mr-1"></i>
                            Available Mailchimp Fields for this Audience
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
                            <div v-for="field in availableMergeFields" :key="field.tag" class="bg-white p-2 rounded border">
                                <div class="font-medium text-blue-900">{{ field.name }}</div>
                                <div class="text-gray-600 text-xs">Tag: {{ field.tag }} | Type: {{ field.type }}</div>
                                <div v-if="field.help_text" class="text-gray-500 text-xs italic">{{ field.help_text }}</div>
                            </div>
                        </div>
                        <div class="mt-3 text-xs text-blue-700">
                            💡 The system will automatically map your form data to these available fields
                        </div>
                    </div>

                    <!-- Missing Fields Warning -->
                    <div v-if="missingFields.length > 0" class="mb-4 p-4 bg-orange-50 border border-orange-200 rounded-lg">
                        <h4 class="font-semibold mb-2 text-orange-800">
                            <i class="fa-solid fa-exclamation-triangle mr-1"></i>
                            Missing Fields in Your Mailchimp Audience
                        </h4>
                        <div class="text-sm text-orange-700 mb-3">
                            Some data won't be imported because these fields don't exist in your Mailchimp audience:
                        </div>
                        <div class="grid grid-cols-1 gap-2 text-sm">
                            <div v-for="field in missingFields" :key="field" class="bg-white p-2 rounded border border-orange-200">
                                <div class="font-medium text-orange-900">{{ field }}</div>
                                <div class="text-orange-600 text-xs">{{ fieldSuggestions[field] }}</div>
                            </div>
                        </div>
                        <div class="mt-3 text-xs text-orange-700">
                            💡 Go to your Mailchimp audience settings to add these fields for complete data import
                        </div>
                    </div>

                    <!-- Warning if no audience selected -->
                    <div v-if="selectedList && availableMergeFields.length === 0 && !isLoadingMergeFields" class="mb-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                        <div class="text-yellow-800">
                            <i class="fa-solid fa-exclamation-triangle mr-1"></i>
                            <strong>Warning:</strong> Could not load merge fields for this audience. The import may fail if field names don't match.
                        </div>
                    </div>

                    <!-- Field mapping: map our data to Mailchimp audience columns (same as Import All) -->
                    <div class="mb-4 p-4 bg-purple-50 border border-purple-200 rounded-lg">
                        <button
                            type="button"
                            @click="showMappingSection = !showMappingSection"
                            class="flex items-center gap-2 w-full text-left text-sm font-medium text-gray-800"
                        >
                            <i :class="showMappingSection ? 'fa-solid fa-chevron-down' : 'fa-solid fa-chevron-right'" class="text-purple-600"></i>
                            Field mapping: map our data to Mailchimp audience columns
                        </button>
                        <p v-if="!showMappingSection" class="text-xs text-gray-600 mt-1 ml-6">Select which column in our data maps to each Mailchimp field. Use <strong>Address (concatenated)</strong> for full address.</p>
                        <div v-else class="mt-4">
                            <p class="text-xs text-gray-600 mb-3">Map each Mailchimp audience column to our sign-up/ticket data. <strong>Address (concatenated)</strong> combines street, city, state, zip, country.</p>
                            <div v-if="!selectedList" class="text-sm text-gray-500 py-2">Select an audience above to load columns.</div>
                            <div v-else class="overflow-x-auto max-h-64 overflow-y-auto border rounded">
                                <table class="w-full text-sm border-collapse">
                                    <thead class="bg-purple-100 sticky top-0">
                                        <tr>
                                            <th class="border border-purple-200 p-2 text-left">Mailchimp field (label)</th>
                                            <th class="border border-purple-200 p-2 text-left">Map from our column</th>
                                            <th class="border border-purple-200 p-2 text-left">Validation</th>
                                            <th class="border border-purple-200 p-2 text-left">Value we'll import (1st row)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td class="border border-purple-200 p-2 font-medium">Email Address</td>
                                            <td class="border border-purple-200 p-2 text-gray-600">email_address (built-in)</td>
                                            <td class="border border-purple-200 p-2 text-xs text-gray-600">Required, valid email</td>
                                            <td class="border border-purple-200 p-2 text-xs text-gray-700 font-mono max-w-[200px] truncate" :title="getMappingPreviewValue('EMAIL')">
                                                {{ getMappingPreviewValue('EMAIL') }}
                                            </td>
                                        </tr>
                                        <tr v-for="mf in mergeFieldsWithValidation" :key="mf.tag" class="bg-white">
                                            <td class="border border-purple-200 p-2 font-medium">{{ mf.name || mf.tag }}</td>
                                            <td class="border border-purple-200 p-2">
                                                <select
                                                    :value="fieldMapping[mf.tag]"
                                                    @change="fieldMapping = { ...fieldMapping, [mf.tag]: $event.target.value }"
                                                    class="w-full border rounded px-2 py-1 text-sm"
                                                >
                                                    <option value="">— Don't map</option>
                                                    <option v-for="sc in sourceColumns" :key="sc.key" :value="sc.key">{{ sc.label }}</option>
                                                </select>
                                            </td>
                                            <td class="border border-purple-200 p-2 text-xs text-gray-600">
                                                <span v-if="mf.validation">{{ mf.validation.type }}{{ mf.validation.required ? ', required' : '' }}</span>
                                                <span v-if="mf.validation?.choices" class="block mt-1">Allowed: {{ mf.validation.choices.slice(0, 5).join(', ') }}{{ mf.validation.choices.length > 5 ? '…' : '' }}</span>
                                            </td>
                                            <td class="border border-purple-200 p-2 text-xs text-gray-700 font-mono max-w-[200px] truncate" :title="getMappingPreviewValue(mf.tag, fieldMapping[mf.tag])">
                                                {{ getMappingPreviewValue(mf.tag, fieldMapping[mf.tag]) }}
                                            </td>
                                        </tr>
                                        <tr v-if="mergeFieldsWithValidation.length === 0 && selectedList">
                                            <td colspan="4" class="border border-purple-200 p-4 text-gray-500 text-center">Loading audience fields...</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button 
                            @click="closeMailchimpModal"
                            class="px-4 py-2 border rounded text-gray-600 hover:bg-gray-50"
                            :disabled="isImporting"
                        >
                            Cancel
                        </button>
                        <button 
                            @click="handleMailchimpImport"
                            class="px-4 py-2 bg-orange-500 text-white rounded hover:bg-orange-600"
                            :disabled="isImporting || !selectedList"
                        >
                            <span v-if="isImporting">
                                <i class="fa-solid fa-spinner fa-spin mr-2"></i>
                                Importing...
                            </span>
                            <span v-else>
                                <i class="fa-solid fa-envelope mr-2"></i>
                                Import {{ mailchimpAttendeeCount }} Attendees
                                <span v-if="customTags.trim()" class="text-sm opacity-90">
                                    ({{ parseTagsFromInput(customTags).length }} tags)
                                </span>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Import Ticket Data Modal (Eventbrite link OR manual CSV / Event Groove) -->
        <div v-if="showEventbriteModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">Import Ticket Data</h3>
                    <button @click="closeEventbriteModal" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                <p class="text-gray-600 mb-4">
                    Import ticket data for <strong>{{ location.name }}</strong>
                </p>

                <!-- Tabs: Eventbrite | Import manually (CSV / Event Groove) -->
                <div class="flex border-b border-gray-200 mb-4">
                    <button
                        type="button"
                        @click="ticketImportTab = 'eventbrite'"
                        :class="ticketImportTab === 'eventbrite' ? 'border-b-2 border-blue-500 text-blue-600 font-medium' : 'text-gray-500 hover:text-gray-700'"
                        class="px-4 py-2"
                    >
                        <i class="fa-solid fa-ticket mr-2"></i> Eventbrite link
                    </button>
                    <button
                        type="button"
                        @click="switchToCsvTab"
                        :class="ticketImportTab === 'csv' ? 'border-b-2 border-blue-500 text-blue-600 font-medium' : 'text-gray-500 hover:text-gray-700'"
                        class="px-4 py-2"
                    >
                        <i class="fa-solid fa-file-csv mr-2"></i> Import manually (CSV / Event Groove)
                    </button>
                </div>

                <!-- Tab: Eventbrite link -->
                <div v-if="ticketImportTab === 'eventbrite'" class="space-y-4">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Eventbrite Event Link
                        </label>
                        <input 
                            type="text" 
                            v-model="eventbriteLink"
                            class="w-full border rounded px-3 py-2"
                            placeholder="https://www.eventbrite.com/e/event-name-tickets-1234567890"
                            :disabled="isFetchingEventbrite"
                        />
                        <p class="text-sm text-gray-500 mt-1">
                            Paste the Eventbrite event URL. The system will fetch attendees, save them to this location, then open <strong>Import to Mailchimp</strong> so you can send them to Mailchimp right away.
                        </p>
                    </div>
                    <div class="flex justify-end space-x-3">
                        <button 
                            @click="closeEventbriteModal"
                            class="px-4 py-2 border rounded text-gray-600 hover:bg-gray-50"
                            :disabled="isFetchingEventbrite"
                        >
                            Cancel
                        </button>
                        <button 
                            @click="fetchEventbriteAttendees"
                            class="px-4 py-2 bg-orange-500 text-white rounded hover:bg-orange-600"
                            :disabled="isFetchingEventbrite || !eventbriteLink.trim()"
                        >
                            <span v-if="isFetchingEventbrite">
                                <i class="fa-solid fa-spinner fa-spin mr-2"></i>
                                Fetching...
                            </span>
                            <span v-else>
                                <i class="fa-solid fa-download mr-2"></i>
                                Fetch Attendees
                            </span>
                        </button>
                    </div>
                </div>

                <!-- Tab: Import manually (CSV / Event Groove) -->
                <div v-if="ticketImportTab === 'csv'" class="space-y-4">
                    <!-- Step 1: Choose file -->
                    <div v-if="csvImportStep === 1">
                        <p class="text-gray-600 mb-3">
                            Upload a CSV (e.g. from Event Groove). You will map columns to Email, First name, Last name, etc. Other columns will be ignored.
                        </p>
                        <label class="block">
                            <span class="sr-only">Choose CSV file</span>
                            <input
                                type="file"
                                accept=".csv,.txt"
                                class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                                @change="onCsvFileSelected"
                            />
                        </label>
                    </div>

                    <!-- Step 2: Map columns -->
                    <div v-if="csvImportStep === 2" class="space-y-4">
                        <p class="text-gray-600">
                            Map each CSV column to a field. <strong>Email</strong> is required. Unmapped columns are ignored.
                        </p>
                        <div class="overflow-x-auto">
                            <table class="w-full border border-gray-300 text-sm">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th class="border border-gray-300 p-2 text-left">Our field</th>
                                        <th class="border border-gray-300 p-2 text-left">CSV column</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="field in CSV_FIELDS" :key="field.key" class="border border-gray-300">
                                        <td class="border border-gray-300 p-2">
                                            {{ field.label }}
                                            <span v-if="field.required" class="text-red-500">*</span>
                                        </td>
                                        <td class="border border-gray-300 p-2">
                                            <select
                                                v-model="csvColumnMapping[field.key]"
                                                class="w-full border rounded px-2 py-1"
                                            >
                                                <option value="">Don't import</option>
                                                <option v-for="h in csvHeaders" :key="h" :value="h">{{ h }}</option>
                                            </select>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-xs text-gray-500">Preview (first 5 rows):</p>
                        <div class="overflow-x-auto max-h-32 border border-gray-200 rounded">
                            <table class="w-full text-xs border-collapse">
                                <thead class="bg-gray-50 sticky top-0">
                                    <tr>
                                        <th v-for="h in csvHeaders" :key="h" class="border p-1 text-left whitespace-nowrap">{{ h }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, ri) in csvPreviewRows" :key="ri">
                                        <td v-for="(cell, ci) in row" :key="ci" class="border p-1 truncate max-w-[120px]">{{ cell }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="flex justify-end gap-2">
                            <button @click="csvImportStep = 1" class="px-4 py-2 border rounded text-gray-600 hover:bg-gray-50">Back</button>
                            <button
                                @click="submitCsvImport"
                                :disabled="isImportingCsv || !csvColumnMapping.email"
                                class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50"
                            >
                                <span v-if="isImportingCsv"><i class="fa-solid fa-spinner fa-spin mr-1"></i> Importing...</span>
                                <span v-else>Import CSV</span>
                            </button>
                        </div>
                    </div>

                    <!-- Step 3: Success + Import to Mailchimp -->
                    <div v-if="csvImportStep === 3" class="space-y-4">
                        <p class="text-gray-600">
                            <strong>{{ importedCsvCount }}</strong> attendees were imported. You can now import them to Mailchimp or close.
                        </p>
                        <div class="flex justify-end gap-2">
                            <button @click="closeEventbriteModal" class="px-4 py-2 border rounded text-gray-600 hover:bg-gray-50">Close</button>
                            <button
                                @click="openMailchimpWithTicketAttendees"
                                class="px-4 py-2 bg-orange-500 text-white rounded hover:bg-orange-600"
                            >
                                <i class="fa-solid fa-envelope mr-2"></i> Import to Mailchimp
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
