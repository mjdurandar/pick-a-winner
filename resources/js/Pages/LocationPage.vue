<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';
import Swal from 'sweetalert2';
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';

// ✅ Define Props to Receive Locations from Backend
const props = defineProps({
    event: Object,
    locations: Array,
    total_participants: { type: Number, default: 0 },
    flash: Object
});

// ✅ Reactive Data
const searchQuery = ref(''); // ✅ Search query input
const copiedIndex = ref(null); // ✅ Index of copied location link
const showPasswordModal = ref(false);
const showEventPasswordModal = ref(false);
const showLocationModal = ref(false);
const showAllPasswordsModal = ref(false);
const isEditing = ref(false);
const selectedLocation = ref(null);
const selectedLocationsForExport = ref([]);
const newPassword = ref('');
const confirmPassword = ref('');
const eventNewPassword = ref('');
const eventConfirmPassword = ref('');
const allLocationsNewPassword = ref('');
const allLocationsConfirmPassword = ref('');
const showMailchimpModal = ref(false);
const mailchimpLists = ref([]);
const selectedList = ref('');
const isImporting = ref(false);
const tags = ref(''); // Add new ref for tags
const showMailchimpSettingsModal = ref(false);
const mailchimpSettings = ref({
    auto_sync: false,
    default_list_id: '',
    default_tags: '',
    enabled_locations: [],
    film_tour: '',  // Default to WM
    mailchimp_account: 'anz', // Default to ANZ
    event_id: props.event.id  // Add event_id
});
const availableLists = ref([]);
const availableAccounts = ref([]);
const isSettingsLoading = ref(false);
const showEventbriteModal = ref(false);
const eventbriteLink = ref('');
const isFetchingEventbrite = ref(false);
const eventbriteAttendees = ref([]);
const eventbriteEventId = ref('');
const isImportingEventbrite = ref(false);
const showEventbriteMailchimpModal = ref(false);
const eventbriteMailchimpAccount = ref('');
const eventbriteMailchimpLists = ref([]);
const eventbriteSelectedList = ref('');
const eventbriteAvailableAccounts = ref([]);
const isLoadingEventbriteLists = ref(false);
const eventbriteTags = ref('');
const eventbriteDefaultTags = ref([]);
const selectedCountry = ref(null);
const selectedCategory = ref('');

// Import All (event-level): one modal to stage ticket data for all locations, then import in one click
const showImportAllModal = ref(false);
const isOpeningImportAllModal = ref(false); // true while loading before showing modal
const stagedByLocation = ref({}); // { [locationId]: { attendees: [...] } }
const importAllDefaultTags = ref(''); // Default tags applied to ALL locations (separate with ;)
const importAllTagsByLocation = ref({}); // { [locationId]: 'SHOW - X; SOURCE - ...' } – tags for ticket data for this location (; separator)
const importAllFormTagsByLocation = ref({}); // { [locationId]: '...' } – tags for form data, for review (; separator)
const importAllListId = ref('');
// Year used in SOURCE tag (defaults to event's year from events table if set)
const importAllSourceYear = ref(null);

// Parse tag string: separate by ; so tags like "SHOW - Mammoth, LA" stay as one tag
const parseTagStr = (s) => (s || '').split(';').map(t => t.trim()).filter(Boolean);

// Build location tag for SOURCE only: part before " - ", state excluded (SOURCE tag never includes state)
const locationTagForSource = (name, stateOptional) => {
    const part = (name || '').split(' - ')[0].trim();
    if (!part) return 'LOC';
    let out = part;
    if (stateOptional && String(stateOptional).trim()) {
        const state = String(stateOptional).trim();
        out = part.replace(new RegExp(`,?\\s*${state.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`, 'i'), '').trim();
    } else {
        out = part.replace(/,?\s+[A-Z]{2,3}$/i, '').trim();
    }
    return (out || part).toUpperCase();
};

// Build location tag for SHOW only: USA = include state (e.g. "DENVER, CO"); Australia/NZ/other = location only, no state (strip state abbrev or full state name)
const locationTagForShow = (name, stateOptional, countryOptional) => {
    const part = (name || '').split(' - ')[0].trim();
    if (!part) return 'LOC';
    const country = (countryOptional || '').trim().toUpperCase();
    const isUSA = country === 'USA' || country === 'USA & CANADA' || country === 'USA AND CANADA';
    const state = (stateOptional || '').trim();
    if (isUSA && state) {
        const base = part.replace(/,?\s+[A-Z]{2,3}$/i, '').trim();
        return (base + ', ' + state).toUpperCase();
    }
    if (isUSA) return part.toUpperCase();
    // Non-USA: strip 2–3 letter abbrev (NSW, VIC) and/or full state name (Victoria, New South Wales) from end
    let out = part.replace(/,?\s+[A-Z]{2,3}$/i, '').trim() || part;
    if (state) {
        const stateEsc = state.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        out = out.replace(new RegExp(`,?\\s*${stateEsc}$`, 'i'), '').trim() || out;
    }
    return (out || part).toUpperCase();
};
const importAllAccount = ref('');
const importAllLists = ref([]);
const importAllAccounts = ref([]);
const isImportAllLoading = ref(false);
const importAllEventbriteLinkByLocation = ref({}); // { [locationId]: '' }
const isFetchingPreviewByLocation = ref({}); // { [locationId]: true/false }
const importAllCsvFileInputByLocation = ref({}); // keep file input ref per location if needed
const importAllSkipAlreadyImported = ref(false); // when true, skip locations already imported to this audience
/** '' = choose type first; 'ticket' = import ticket only; 'form' = import win form only */
const importAllDataMode = ref('');
const importAllMergeFieldsWithValidation = ref([]); // audience columns + validation
const importAllSourceColumns = ref([]); // our data columns for mapping
const importAllFieldMapping = ref({}); // { FNAME: 'first_name', ADDRESS: 'address_full', ... }
const showMappingSection = ref(false);
const isLoadingMergeFields = ref(false);

// First row of staged data (ticket) or win form preview row for field-mapping preview in Import All modal
const importAllFormPreviewRow = ref(null); // set from event.mailchimpImportPreview preview_row when modal opens
const importAllPreviewFirstRow = computed(() => {
    if (importAllDataMode.value === 'form' && importAllFormPreviewRow.value) {
        return importAllFormPreviewRow.value;
    }
    const entries = Object.values(stagedByLocation.value);
    for (const v of entries) {
        if (v?.attendees?.length) return v.attendees[0];
    }
    return null;
});

// Build concatenated address from attendee (same logic as backend) for preview when mapping to "Address (concatenated)"
function buildAddressFullForPreview(attendee) {
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

// Preview value for Import All field mapping: what will be sent for the first staged attendee
function getImportAllMappingPreviewValue(mailchimpTag, mappedOurKey) {
    const first = importAllPreviewFirstRow.value;
    if (!first) return '—';
    let val;
    if (mailchimpTag === 'EMAIL') {
        val = first.email_address ?? first.email ?? '';
    } else if (!mappedOurKey) {
        return '—';
    } else if (mappedOurKey === 'address_full') {
        val = (first.address_full && String(first.address_full).trim()) || buildAddressFullForPreview(first);
    } else {
        val = first[mappedOurKey];
    }
    if (val == null || val === '') return '—';
    const s = String(val).trim();
    return s.length > 50 ? s.slice(0, 50) + '…' : s;
}

// Add computed property for tag preview
const tagPreview = computed(() => {
    if (!mailchimpSettings.value.film_tour) return [];
    
    const filmTour = mailchimpSettings.value.film_tour;
    const year = props.event?.event_year ?? new Date().getFullYear();
    
    // Sample location names for preview
    const sampleLocations = [
        'Melbourne South East - Classic Cinema',
        'Sydney - Opera House',
        'Brisbane Central - Convention Centre',
        'Adelaide - Entertainment Centre'
    ];
    
    const previewTags = [];
    
    sampleLocations.forEach(locationName => {
        const sourceLoc = locationTagForSource(locationName);
        const showLoc = locationTagForShow(locationName, '', props.event?.event_country || '');
        const showTag = `SHOW - ${showLoc}`;
        const sourceTag = `SOURCE - ${filmTour.toUpperCase()} ${sourceLoc} COMP ${year}`;
        
        previewTags.push({
            location: locationName,
            showTag: showTag,
            sourceTag: sourceTag
        });
    });
    
    return previewTags;
});

// Add new form for location creation
const locationForm = useForm({
    id: null,
    name: '',
    event_id: '',
    date: '',
    time: '',
    country: '',
    state: '',
    category: ''
});

// Add reactive refs for TBA checkboxes
const noDateYet = ref(false);
const noTimeYet = ref(false);

const formatLocationDateTime = (date, time) => {
    if (!date || !time) return '';
    
    // Handle TBA values
    if (date === 'TBA' || time === 'TBA') {
        let result = '';
        if (date === 'TBA' && time === 'TBA') {
            result = 'Date & Time TBA';
        } else if (date === 'TBA') {
            result = `Date TBA, ${time}`;
        } else if (time === 'TBA') {
            try {
                // Try to parse date - handle YYYY-MM-DD format or other formats
                let dateObj;
                if (date.match(/^\d{4}-\d{2}-\d{2}$/)) {
                    // Already in YYYY-MM-DD format
                    dateObj = new Date(date);
                } else {
                    // Try to parse other formats
                    dateObj = new Date(date);
                }
                
                if (isNaN(dateObj.getTime())) {
                    return `${date} - Time TBA`;
                }
                
            result = `${dateObj.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            })} - Time TBA`;
            } catch (e) {
                return `${date} - Time TBA`;
            }
        }
        return result;
    }

    try {
        // Parse date - handle YYYY-MM-DD format or other formats
        let dateObj;
        if (date.match(/^\d{4}-\d{2}-\d{2}$/)) {
            // Already in YYYY-MM-DD format - combine with time
            dateObj = new Date(`${date}T${time}`);
        } else {
            // Try to parse other formats
            // First try combining date and time
            dateObj = new Date(`${date}T${time}`);
            
            // If that fails, try parsing date alone
            if (isNaN(dateObj.getTime())) {
                dateObj = new Date(date);
                if (!isNaN(dateObj.getTime()) && time) {
                    // If date parsed successfully, try to add time
                    const [hours, minutes] = time.split(':').map(Number);
                    if (!isNaN(hours) && !isNaN(minutes)) {
                        dateObj.setHours(hours, minutes || 0);
                    }
                }
            }
        }
        
        // Check if date is valid
        if (isNaN(dateObj.getTime())) {
            // If date parsing failed, return a fallback format
            return `${date} ${time}`;
        }
        
        return dateObj.toLocaleString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
    } catch (e) {
        // Fallback to simple display if parsing fails
        return `${date} ${time}`;
    }
};

const generateLocationTags = (locationName, stateOptional, countryOptional) => {
    const tags = [];
    const filmTour = mailchimpSettings.value.film_tour;
    const year = props.event?.event_year ?? new Date().getFullYear();
    const sourceLoc = locationTagForSource(locationName, stateOptional);
    const showLoc = locationTagForShow(locationName, stateOptional || '', countryOptional || '');
    tags.push(`SHOW - ${showLoc}`);
    tags.push(`SOURCE - ${filmTour.toUpperCase()} ${sourceLoc} COMP ${year}`);
    return tags;
};

const handleMailchimpImport = async () => {
    console.log('handleMailchimpImport called');
    if (!selectedList.value) {
        Swal.fire('Error!', 'Please select a Mailchimp audience.', 'error');
        return;
    }

    isImporting.value = true;

    try {
        // First, get all subscribers
        console.log('Getting subscribers');
        const subscribersResponse = await axios.get(route('location.getSubscribers'), {
            params: {
                location_id: selectedLocation.value.id
            }
        });
        console.log('Subscribers response', subscribersResponse);   
        const allSubscribers = subscribersResponse.data.subscribers;
        const totalSubscribers = subscribersResponse.data.total;
        console.log('Total subscribers', totalSubscribers);
        if (totalSubscribers === 0) {
            Swal.fire('Error!', 'No subscribers found for this location.', 'error');
            isImporting.value = false;
            return;
        }
        console.log('Processing tags');

        // Generate location-specific tags: SHOW includes state for USA; SOURCE never includes state
        const filmTour = mailchimpSettings.value.film_tour;
        const year = props.event?.event_year ?? new Date().getFullYear();
        const sourceLoc = locationTagForSource(selectedLocation.value.name, selectedLocation.value.state);
        const showLoc = locationTagForShow(selectedLocation.value.name, selectedLocation.value.state, props.event?.event_country || selectedLocation.value.country);
        const sourceTag = `SOURCE - ${filmTour.toUpperCase()} ${sourceLoc} COMP ${year}`;
        const showTag = `SHOW - ${showLoc}`;
        let allTags = [sourceTag, showTag];
        
        // Add any manual tags if they exist
        if (tags.value) {
            const manualTags = tags.value
                .split(',')
                .map(tag => tag.trim())
                .filter(tag => tag);
            allTags = [...allTags, ...manualTags];
        }
        
        console.log('Final tags for import:', allTags);

        // Adjust chunk size based on total subscribers
        const chunkSize = totalSubscribers <= 10 ? totalSubscribers : 10;
        const totalChunks = Math.ceil(allSubscribers.length / chunkSize);
        let successCount = 0;
        let failureCount = 0;
        let errors = [];
        let importedSubscribers = [];

        // Create and show progress modal
        Swal.fire({
            title: 'Importing Subscribers',
            html: `Processing 0 of ${totalSubscribers} subscribers...`,
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        // Process each chunk
        for (let i = 0; i < totalChunks; i++) {
            const start = i * chunkSize;
            const end = Math.min(start + chunkSize, allSubscribers.length);
            const chunk = allSubscribers.slice(start, end);
            
            try {
                console.log('Posting to Mailchimp with tags:', allTags);
                const response = await axios.post('/location/import-data-to-mailchimp', {
                    subscribers: chunk,
                    list_id: selectedList.value,
                    tags: allTags,
                    location_id: selectedLocation.value.id
                });
                console.log('Mailchimp response', response);
                // Add successfully imported subscribers to the log
                if (response.data.details.success > 0) {
                    importedSubscribers = importedSubscribers.concat(chunk);
                }
                successCount += response.data.details.success;
                failureCount += response.data.details.failed;
                errors = errors.concat(response.data.details.errors);

                // Update progress
                const processed = Math.min((i + 1) * chunkSize, totalSubscribers);
                await Swal.update({
                    html: `Processing ${processed} of ${totalSubscribers} subscribers...<br>Success: ${successCount}, Failed: ${failureCount}`
                });
                console.log('Processed', processed);
            } catch (error) {
                console.error('Chunk import error:', error);
                console.error('Error response:', error.response);
                
                // Get detailed error message
                let errorMessage = error.message;
                if (error.response?.data?.message) {
                    errorMessage = error.response.data.message;
                } else if (error.response?.data?.errors) {
                    // Laravel validation errors
                    const validationErrors = Object.entries(error.response.data.errors)
                        .map(([field, messages]) => `${field}: ${Array.isArray(messages) ? messages.join(', ') : messages}`)
                        .join('; ');
                    errorMessage = `Validation failed: ${validationErrors}`;
                } else if (error.response?.data?.error) {
                    errorMessage = error.response.data.error;
                }
                
                errors.push(`Chunk ${i + 1} failed: ${errorMessage}`);
                failureCount += chunk.length;
            }
        }

        // Close progress modal
        await Swal.close();
        console.log('Progress modal closed');
        // Show final results with optimized error display
        let errorHtml = '';
        if (errors.length > 0) {
            errorHtml = '<br><br>Errors:<ul style="text-align:left;">' +
                errors.slice(0, 5).map(e => `<li>${e}</li>`).join('') +
                (errors.length > 5 ? '<li>...and more</li>' : '') +
                '</ul>';
        }
        Swal.fire({
            title: 'Import Completed',
            html: `Successfully imported ${successCount} out of ${totalSubscribers} subscribers.<br>Failed: ${failureCount}${errorHtml}<br><br>Import logs have been saved to the database.`,
            icon: errors.length > 0 ? 'warning' : 'success'
        });
        console.log('Final results shown'); 
        // Close the Mailchimp modal and reset form
        showMailchimpModal.value = false;
        selectedList.value = '';
        tags.value = '';
        isImporting.value = false;
    } catch (error) {
        console.error('Import error:', error);
        await Swal.fire('Error!', error.response?.data?.error || 'Failed to import data to Mailchimp.', 'error');
        isImporting.value = false;
    }
};

// Helper function to sort locations by date and time
const sortLocationsByDateTime = (locations) => {
    return locations.sort((a, b) => {
        // Handle TBA dates - put them at the end
        if (a.date === 'TBA' && b.date !== 'TBA') return 1;
        if (a.date !== 'TBA' && b.date === 'TBA') return -1;
        if (a.date === 'TBA' && b.date === 'TBA') return 0;
        
        // Handle TBA times - put them at the end
        if (a.time === 'TBA' && b.time !== 'TBA') return 1;
        if (a.time !== 'TBA' && b.time === 'TBA') return -1;
        if (a.time === 'TBA' && b.time === 'TBA') return 0;
        
        // Try to create Date objects for comparison
        try {
            const dateA = new Date(`${a.date}T${a.time}`);
            const dateB = new Date(`${b.date}T${b.time}`);
            
            // Check if dates are valid
            if (isNaN(dateA.getTime()) && isNaN(dateB.getTime())) return 0;
            if (isNaN(dateA.getTime())) return 1;
            if (isNaN(dateB.getTime())) return -1;
            
            return dateA - dateB;
        } catch (e) {
            // If date parsing fails, compare as strings
            return `${a.date} ${a.time}`.localeCompare(`${b.date} ${b.time}`);
        }
    });
};

// ✅ Computed Property to Filter and Group Locations by Country
const locationsByCountry = computed(() => {
    let locations = props.locations;
    
    // Filter by search query if exists
    if (searchQuery.value) {
        locations = locations.filter(location =>
            location.name.toLowerCase().includes(searchQuery.value.toLowerCase())
        );
    }
    
    // Group by country
    const grouped = {};
    locations.forEach(location => {
        const country = location.country || 'Other';
        if (!grouped[country]) {
            grouped[country] = [];
        }
        grouped[country].push(location);
    });
    
    // Sort locations within each country by date and time
    Object.keys(grouped).forEach(country => {
        grouped[country] = sortLocationsByDateTime(grouped[country]);
    });
    
    // Sort countries alphabetically
    const sortedCountries = Object.keys(grouped).sort();
    const result = {};
    sortedCountries.forEach(country => {
        result[country] = grouped[country];
    });
    
    return result;
});

// ✅ Computed Property for Country List (for tabs)
const countryList = computed(() => {
    return Object.keys(locationsByCountry.value).sort();
});

// ✅ Computed Property for Available Categories
const availableCategories = computed(() => {
    const categories = new Set();
    if (selectedCountry.value && locationsByCountry.value[selectedCountry.value]) {
        locationsByCountry.value[selectedCountry.value].forEach(location => {
            if (location.category) {
                categories.add(location.category);
            }
        });
    }
    return Array.from(categories).sort();
});

// ✅ Computed Property for Selected Country Locations (filtered by category)
const selectedCountryLocations = computed(() => {
    if (!selectedCountry.value) {
        return [];
    }
    let locations = locationsByCountry.value[selectedCountry.value] || [];
    
    // Filter by category if selected
    if (selectedCategory.value) {
        locations = locations.filter(location => location.category === selectedCategory.value);
    }
    
    return locations;
});

// ✅ Initialize selected country on mount or when locations change
watch(
    () => locationsByCountry.value,
    (newValue) => {
        if (!selectedCountry.value && Object.keys(newValue).length > 0) {
            // Set first country as default
            selectedCountry.value = Object.keys(newValue).sort()[0];
        }
    },
    { immediate: true }
);
        
// ✅ Reset category filter when country changes
watch(
    () => selectedCountry.value,
    () => {
        selectedCategory.value = '';
    }
);

// ✅ Computed Property to Filter Locations (for backward compatibility)
const filteredLocations = computed(() => {
    let locations = props.locations;
    
    // Filter by search query if exists
    if (searchQuery.value) {
        locations = locations.filter(location =>
            location.name.toLowerCase().includes(searchQuery.value.toLowerCase())
        );
    }
    
    // Sort by date and time
    return sortLocationsByDateTime(locations);
});

const openPasswordModal = (location) => {
    selectedLocation.value = location;
    newPassword.value = '';
    confirmPassword.value = '';
    showPasswordModal.value = true;
    let modalElement = new bootstrap.Modal(document.getElementById('passwordModal'));
    modalElement.show();
};

const openEventPasswordModal = () => {
    eventNewPassword.value = '';
    eventConfirmPassword.value = '';
    showEventPasswordModal.value = true;
};

const generatePassword = (isEvent = false) => {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let password = '';
    for (let i = 0; i < 8; i++) {
        password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    if (isEvent) {
        eventNewPassword.value = password;
        eventConfirmPassword.value = password;
    } else {
        newPassword.value = password;
        confirmPassword.value = password;
        allLocationsNewPassword.value = password;
        allLocationsConfirmPassword.value = password;
    }
};

const updatePassword = async () => {
    if (!newPassword.value || !confirmPassword.value) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Please fill in all fields'
        });
        return;
    }

    if (newPassword.value !== confirmPassword.value) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Passwords do not match'
        });
        return;
    }

    try {
        await router.put(route('location.updatePassword', selectedLocation.value.id), {
            password: newPassword.value
        });

        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: 'Password updated successfully',
            timer: 1500,
            showConfirmButton: false
        });
        showPasswordModal.value = false;
    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Failed to update password. Please try again.'
        });
    }
};

const copyPassword = (location, index) => {
    navigator.clipboard.writeText(location.password);
    copiedIndex.value = index;
    // Swal.fire({
    //     icon: 'success',
    //     title: 'Copied!',
    //     text: 'Password copied to clipboard',
    //     timer: 1500,
    //     showConfirmButton: false
    // });
    // setTimeout(() => {
    //     copiedIndex.value = null;
    // }, 2000);
};

const copyEventPassword = () => {
    navigator.clipboard.writeText(props.event.password);
    Swal.fire({
        icon: 'success',
        title: 'Copied!',
        text: 'Event password copied to clipboard',
        timer: 1500,
        showConfirmButton: false
    });
};

const allLocationsPage = () => {
    const url = route('pickawinner.alllocation', { event: props.event.id });
    window.open(url, '_blank', 'noopener');
};

const goToAttendeesPage = () => {
    router.get(route('attendees.index', { eventId: props.event.id }));
};

const viewLocationAttendees = (location) => {
    router.get(route('attendees.location', { eventId: props.event.id, locationId: location.id }));
};

const openEventbriteModal = (location) => {
    selectedLocation.value = location;
    eventbriteLink.value = '';
    eventbriteAttendees.value = [];
    eventbriteEventId.value = '';
    showEventbriteModal.value = true;
};


const closeEventbriteModal = () => {
    showEventbriteModal.value = false;
    eventbriteLink.value = '';
    eventbriteAttendees.value = [];
    eventbriteEventId.value = '';
    selectedLocation.value = null;
};

const extractEventIdFromLink = (link) => {
    // Eventbrite links can be in various formats:
    // https://www.eventbrite.com/e/event-name-tickets-1234567890
    // https://eventbrite.com/e/event-name-tickets-1234567890
    // https://www.eventbrite.com/event/1234567890
    // https://eventbrite.com/event/1234567890
    
    try {
        // Try to extract from URL path
        const url = new URL(link);
        const pathParts = url.pathname.split('/');
        
        // Look for event ID in path (usually the last numeric part)
        for (let i = pathParts.length - 1; i >= 0; i--) {
            const part = pathParts[i];
            // Check if it's a numeric ID
            if (/^\d+$/.test(part)) {
                return part;
            }
            // Check if it ends with a numeric ID (e.g., "tickets-1234567890")
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
            html: 'Please wait while we fetch attendee data from Eventbrite...',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        const response = await axios.post(route('location.fetchEventbriteAttendees'), {
            event_id: eventId,
            location_id: selectedLocation.value.id
        });

        eventbriteAttendees.value = response.data.attendees;
        
        await Swal.close();
        
        if (eventbriteAttendees.value.length === 0) {
            Swal.fire('Info', 'No attendees found for this event.', 'info');
        } else {
            Swal.fire('Success', `Found ${eventbriteAttendees.value.length} unique attendees`, 'success');
        }
    } catch (error) {
        await Swal.close();
        console.error('Error fetching Eventbrite attendees:', error);
        Swal.fire('Error', error.response?.data?.error || 'Failed to fetch attendees from Eventbrite', 'error');
    } finally {
        isFetchingEventbrite.value = false;
    }
};

const exportEventbriteAttendees = () => {
    if (eventbriteAttendees.value.length === 0) {
        Swal.fire('Error', 'No attendees to export', 'error');
        return;
    }

    // Create CSV content
    const headers = ['Email', 'First Name', 'Last Name', 'Phone', 'City', 'State', 'Country'];
    const rows = eventbriteAttendees.value.map(attendee => [
        attendee.email || '',
        attendee.first_name || '',
        attendee.last_name || '',
        attendee.phone || '',
        attendee.city || '',
        attendee.state || '',
        attendee.country || ''
    ]);

    const csvContent = [
        headers.join(','),
        ...rows.map(row => row.map(cell => `"${cell}"`).join(','))
    ].join('\n');

    // Create and download file
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', `eventbrite_attendees_${selectedLocation.value.name}_${new Date().toISOString().split('T')[0]}.csv`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    Swal.fire('Success', 'Attendees exported successfully', 'success');
};

const openEventbriteMailchimpModal = async () => {
    if (eventbriteAttendees.value.length === 0) {
        Swal.fire('Error', 'No attendees to import', 'error');
        return;
    }

    // Show loading spinner
    Swal.fire({
            title: 'Loading...',
            html: 'Preparing Mailchimp import...',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

    try {
        // Get available accounts and settings
        const settingsResponse = await axios.get(route('mailchimp.autosync.settings'), {
            params: {
                event_id: props.event.id
            }
        });

        const { available_accounts, settings } = settingsResponse.data;
        eventbriteAvailableAccounts.value = available_accounts;
        
        // Set default account from settings
        eventbriteMailchimpAccount.value = settings.mailchimp_account || 'anz';
        
        // Generate default tags
        generateEventbriteDefaultTags(settings);
        
        // Load lists for default account
        await loadEventbriteLists(eventbriteMailchimpAccount.value);
        
        // Close loading spinner
        await Swal.close();
        
        showEventbriteMailchimpModal.value = true;
    } catch (error) {
        await Swal.close();
        console.error('Error opening Mailchimp modal:', error);
        Swal.fire('Error', 'Failed to load Mailchimp settings', 'error');
    }
};

const generateEventbriteDefaultTags = (settings) => {
    const tags = [];
    const filmTour = settings.film_tour || 'WM';
    const year = props.event?.event_year ?? new Date().getFullYear();
    const showLoc = locationTagForShow(selectedLocation.value.name, selectedLocation.value.state, props.event?.event_country || selectedLocation.value.country);
    const sourceLoc = locationTagForSource(selectedLocation.value.name, selectedLocation.value.state);
    tags.push(`SHOW - ${showLoc}`);
    tags.push(`SOURCE - ${filmTour.toUpperCase()} ${sourceLoc} TIX ${year}`);
    
    // Add default tags from settings
    if (settings.default_tags && Array.isArray(settings.default_tags) && settings.default_tags.length > 0) {
        tags.push(...settings.default_tags);
    } else if (settings.default_tags && typeof settings.default_tags === 'string') {
        const defaultTagsArray = settings.default_tags
            .split(',')
            .map(tag => tag.trim())
            .filter(tag => tag);
        if (defaultTagsArray.length > 0) {
            tags.push(...defaultTagsArray);
        }
    }
    
    eventbriteDefaultTags.value = tags;
    eventbriteTags.value = tags.join(', ');
};

const loadEventbriteLists = async (account) => {
    if (!account) return;
    
    isLoadingEventbriteLists.value = true;
    eventbriteSelectedList.value = '';
    
    try {
        const response = await axios.get(route('mailchimp.autosync.lists'), {
            params: { account }
        });
        eventbriteMailchimpLists.value = response.data.lists;
    } catch (error) {
        console.error('Failed to load lists:', error);
        eventbriteMailchimpLists.value = [];
        Swal.fire('Error', 'Failed to load Mailchimp lists for this account', 'error');
    } finally {
        isLoadingEventbriteLists.value = false;
    }
};

const closeEventbriteMailchimpModal = () => {
    showEventbriteMailchimpModal.value = false;
    eventbriteMailchimpAccount.value = '';
    eventbriteSelectedList.value = '';
    eventbriteMailchimpLists.value = [];
    eventbriteTags.value = '';
    eventbriteDefaultTags.value = [];
};

const importEventbriteToMailchimp = async () => {
    // Validate before starting
    if (!eventbriteSelectedList.value || eventbriteSelectedList.value.trim() === '') {
        Swal.fire('Error', 'Please select a Mailchimp audience', 'error');
        return;
    }

    if (!eventbriteMailchimpAccount.value || eventbriteMailchimpAccount.value.trim() === '') {
        Swal.fire('Error', 'Please select a Mailchimp account', 'error');
        return;
    }

    isImportingEventbrite.value = true;

    try {
        // Capture values BEFORE closing modal (important! - closeEventbriteMailchimpModal resets them)
        const selectedListId = String(eventbriteSelectedList.value).trim();
        const selectedAccount = String(eventbriteMailchimpAccount.value).trim();
        
        // Double-check we have valid values
        if (!selectedListId || selectedListId === '' || !selectedAccount || selectedAccount === '') {
            Swal.fire('Error', 'Please select both Mailchimp account and audience', 'error');
            isImportingEventbrite.value = false;
            return;
        }
        
        console.log('Captured values for import:', {
            selectedListId,
            selectedAccount,
            location_id: selectedLocation.value.id,
            event_id: props.event.id
        });

        // Parse tags from the input field
        let tags = eventbriteTags.value
            .split(',')
            .map(tag => tag.trim())
            .filter(tag => tag);
        
        // Ensure tags is always an array (even if empty)
        if (!Array.isArray(tags) || tags.length === 0) {
            tags = [];
        }

        // Prepare attendees data (only first name, last name, email, phone)
        const subscribers = eventbriteAttendees.value.map(attendee => ({
            email_address: attendee.email,
            first_name: attendee.first_name || '',
            last_name: attendee.last_name || '',
            mobile_number: attendee.phone || ''
        }));

        const totalAttendees = subscribers.length;
        const chunkSize = totalAttendees <= 10 ? totalAttendees : 10;
        const totalChunks = Math.ceil(subscribers.length / chunkSize);
        let successCount = 0;
        let failureCount = 0;
        let updateCount = 0;
        let newCount = 0;
        let errors = [];
        let importedAttendees = [];
        let updatedAttendees = [];
        let newAttendees = [];
        let errorDetails = [];

        // Close the selection modal AFTER capturing values
        closeEventbriteMailchimpModal();

        // Show progress modal
        Swal.fire({
            title: 'Importing Attendees to Mailchimp',
            html: `
                <div class="text-left">
                    <div class="mb-3">
                        <div class="flex justify-between mb-1">
                            <span>Processing:</span>
                            <span class="font-semibold">0 / ${totalAttendees}</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div id="progress-bar" class="bg-blue-600 h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-sm mt-3">
                        <div>✅ Success: <span id="success-count" class="font-semibold text-green-600">0</span></div>
                        <div>❌ Failed: <span id="failed-count" class="font-semibold text-red-600">0</span></div>
                        <div>🆕 New: <span id="new-count" class="font-semibold text-blue-600">0</span></div>
                        <div>🔄 Updated: <span id="update-count" class="font-semibold text-orange-600">0</span></div>
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
        for (let i = 0; i < totalChunks; i++) {
            const start = i * chunkSize;
            const end = Math.min(start + chunkSize, subscribers.length);
            const chunk = subscribers.slice(start, end);
            
            try {
                console.log('Sending chunk to Eventbrite import:', {
                    chunkSize: chunk.length,
                    location_id: selectedLocation.value.id,
                    event_id: props.event.id,
                    list_id: selectedListId,
                    mailchimp_account: selectedAccount,
                    tags: tags,
                    firstSubscriber: chunk[0]
                });
                
                // Validate we have required values
                if (!selectedListId || !selectedAccount) {
                    throw new Error(`Missing required values: list_id=${selectedListId}, account=${selectedAccount}`);
                }
                
                const response = await axios.post(route('location.importEventbriteToMailchimp'), {
                    subscribers: chunk,
                    location_id: parseInt(selectedLocation.value.id),
                    event_id: parseInt(props.event.id),
                    list_id: selectedListId,
                    mailchimp_account: selectedAccount,
                    tags: tags
                });

                // Process response details
                if (response.data.details.success > 0) {
                    importedAttendees = importedAttendees.concat(chunk);
                }

                if (response.data.details.updated !== undefined) {
                    updateCount += response.data.details.updated || 0;
                    // Track which attendees were updated
                    if (response.data.details.updated > 0) {
                        updatedAttendees = updatedAttendees.concat(chunk.slice(0, response.data.details.updated));
                    }
                }

                if (response.data.details.new !== undefined) {
                    newCount += response.data.details.new || 0;
                }

                successCount += response.data.details.success || 0;
                failureCount += response.data.details.failed || 0;
                errors = errors.concat(response.data.details.errors || []);

                // Update progress
                const processed = Math.min((i + 1) * chunkSize, totalAttendees);
                const progressPercent = (processed / totalAttendees) * 100;
                
                await Swal.update({
                    html: `
                        <div class="text-left">
                            <div class="mb-3">
                                <div class="flex justify-between mb-1">
                                    <span>Processing:</span>
                                    <span class="font-semibold">${processed} / ${totalAttendees}</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div id="progress-bar" class="bg-blue-600 h-2 rounded-full transition-all duration-300" style="width: ${progressPercent}%"></div>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-2 text-sm mt-3">
                                <div>✅ Success: <span id="success-count" class="font-semibold text-green-600">${successCount}</span></div>
                                <div>❌ Failed: <span id="failed-count" class="font-semibold text-red-600">${failureCount}</span></div>
                                <div>🆕 New: <span id="new-count" class="font-semibold text-blue-600">${newCount}</span></div>
                                <div>🔄 Updated: <span id="update-count" class="font-semibold text-orange-600">${updateCount}</span></div>
                            </div>
                        </div>
                    `
                });
            } catch (error) {
                console.error('Chunk import error:', error);
                errors.push(`Chunk ${i + 1} failed: ${error.message}`);
                failureCount += chunk.length;
            }
        }

        // Close progress modal
        await Swal.close();

        // Generate and download CSV file instead of log file
        if (importedAttendees.length > 0) {
            await downloadEventbriteImportCSV(importedAttendees, selectedLocation.value.name);
        }

        // Generate copy-paste data using the same service as regular import
        let copyPasteData = null;
        try {
            const importDataForSpreadsheet = {
                totalSubscribers: totalAttendees,
                successCount: successCount,
                failureCount: failureCount,
                updateCount: updateCount,
                newCount: newCount,
                errors: errors,
                errorDetails: errorDetails
            };
            
            const response = await axios.post(route('location.generateSpreadsheetData'), {
                location_id: selectedLocation.value.id,
                import_data: importDataForSpreadsheet,
                tags: tags
            });
            
            if (response.data.copy_paste_data) {
                copyPasteData = response.data.copy_paste_data;
            }
        } catch (error) {
            console.error('Failed to generate copy-paste data:', error);
        }

        // Show detailed results
        await showEventbriteDetailedResults({
            totalAttendees,
            successCount,
            failureCount,
            updateCount,
            newCount,
            errors,
            errorDetails,
            importedAttendees,
            updatedAttendees,
            copyPasteData,
            tags
        });

        // Close modal and reload page to update button color
        closeEventbriteModal();
        router.reload();

    } catch (error) {
        await Swal.close();
        console.error('Error importing to Mailchimp:', error);
        Swal.fire('Error', error.response?.data?.error || 'Failed to import attendees to Mailchimp', 'error');
    } finally {
        isImportingEventbrite.value = false;
    }
};

// Show detailed results modal for Eventbrite import
const showEventbriteDetailedResults = async (results) => {
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
        copyPasteData,
        tags
    } = results;

    // Create tabs content - matching LocationAttendees.vue exactly
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
                            <span class="font-medium">${totalAttendees > 0 ? ((successCount / totalAttendees) * 100).toFixed(1) : 0}%</span>
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
                            <span class="font-medium">${selectedLocation.value.name}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>📋 Tags Applied:</span>
                            <span class="font-medium">${tags.length}</span>
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
                                ${attendee.mobile_number ? `<div class="text-gray-500">${attendee.mobile_number}</div>` : ''}
                            </div>
                        `).join('')}
                        ${importedAttendees.length > 50 ? `<div class="text-center text-gray-500 text-sm mt-2">... and ${importedAttendees.length - 50} more</div>` : ''}
                    </div>
                ` : '<div class="text-gray-500 text-center py-4">No successful imports</div>'}
            </div>
        </div>
    `;

    const updatesTab = `
        <div class="text-left">
            <h4 class="font-semibold text-orange-800 mb-3">🔄 Updated Subscribers (${updateCount})</h4>
            <div class="max-h-60 overflow-y-auto">
                ${updatedAttendees && updatedAttendees.length > 0 ? `
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

    // Show the comprehensive results modal - matching LocationAttendees.vue exactly
    await Swal.fire({
        title: 'Import Results',
        html: `
            <div class="text-left">
                <div class="border-b border-gray-200 mb-4">
                    <nav class="-mb-px flex space-x-8">
                        <button onclick="showEventbriteTab('summary')" id="tab-summary" class="tab-button active border-b-2 border-blue-500 py-2 px-1 text-sm font-medium text-blue-600">
                            📊 Summary
                        </button>
                        <button onclick="showEventbriteTab('success')" id="tab-success" class="tab-button border-b-2 border-transparent py-2 px-1 text-sm font-medium text-gray-500 hover:text-gray-700">
                            ✅ Success (${successCount})
                        </button>
                        <button onclick="showEventbriteTab('updates')" id="tab-updates" class="tab-button border-b-2 border-transparent py-2 px-1 text-sm font-medium text-gray-500 hover:text-gray-700">
                            🔄 Updates (${updateCount})
                        </button>
                        <button onclick="showEventbriteTab('errors')" id="tab-errors" class="tab-button border-b-2 border-transparent py-2 px-1 text-sm font-medium text-gray-500 hover:text-gray-700">
                            ❌ Errors (${failureCount})
                        </button>
                    </nav>
                </div>
                <div id="tab-content-summary" class="tab-content">${summaryTab}</div>
                <div id="tab-content-success" class="tab-content hidden">${successTab}</div>
                <div id="tab-content-updates" class="tab-content hidden">${updatesTab}</div>
                <div id="tab-content-errors" class="tab-content hidden">${errorsTab}</div>
                <div class="mt-4 text-center">
                    <div class="text-sm text-gray-600">
                        📥 A CSV file has been downloaded with the imported attendees.
                    </div>
                </div>
                
                <!-- Copy-Paste Data Section -->
                <div class="mt-6 p-4 bg-gray-50 rounded-lg">
                    <h4 class="font-semibold text-gray-800 mb-3">📋 Spreadsheet Data</h4>
                    <div class="mb-4 p-3 bg-white border rounded-lg">
                        <div class="text-sm text-gray-600 mb-2">Copy-paste data for spreadsheet:</div>
                        <div class="select-all cursor-pointer hover:bg-gray-100 transition-colors p-2 bg-gray-50 rounded font-mono text-xs whitespace-pre-wrap" id="spreadsheet-data">${copyPasteData || 'No data available'}</div>
                    </div>
                    <div class="flex justify-center">
                        <button onclick="copyEventbriteTabSeparated()" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg text-sm font-medium transition-colors shadow-md">
                            📋 Copy Separated Data
                        </button>
                    </div>
                </div>
            </div>
        `,
        width: '800px',
        confirmButtonText: 'Close',
        confirmButtonColor: '#059669',
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: () => {
            // Add tab switching functionality
            window.showEventbriteTab = (tabName) => {
                // Hide all tab content
                document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
                document.querySelectorAll('.tab-button').forEach(el => {
                    el.classList.remove('active', 'border-blue-500', 'text-blue-600');
                    el.classList.add('border-transparent', 'text-gray-500');
                });
                
                // Show selected tab
                const contentEl = document.getElementById('tab-content-' + tabName);
                if (contentEl) {
                    contentEl.classList.remove('hidden');
                }
                const button = document.getElementById('tab-' + tabName);
                if (button) {
                    button.classList.add('active', 'border-blue-500', 'text-blue-600');
                    button.classList.remove('border-transparent', 'text-gray-500');
                }
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
            
            // Add copy function - matching LocationAttendees.vue logic
            window.copyEventbriteTabSeparated = function() {
                const dataElement = document.getElementById('spreadsheet-data');
                const copyButton = document.querySelector('button[onclick="copyEventbriteTabSeparated()"]');
                
                if (dataElement) {
                    const text = dataElement.textContent;
                    const lines = text.split('\n');
                    
                    // Find the line that contains "COPY THIS LINE"
                    const copyLineIndex = lines.findIndex(line => line.includes('COPY THIS LINE'));
                    if (copyLineIndex !== -1 && copyLineIndex + 1 < lines.length) {
                        // Get the line after "COPY THIS LINE" which contains the tab-separated data
                        const tabLine = lines[copyLineIndex + 1].trim();
                        const hasTabs = tabLine.includes('\t');
                        const hasCommas = tabLine.includes(',');
                        const tabSplit = tabLine.split('\t');
                        const commaSplit = tabLine.split(',');
                        
                        let dataToCopy = tabLine;
                        
                        if (hasTabs && tabSplit.length > 1) {
                            dataToCopy = tabLine;
                        } else if (hasCommas && commaSplit.length > 1) {
                            // Convert comma-separated to tab-separated
                            dataToCopy = commaSplit.join('\t');
                        }
                        
                        navigator.clipboard.writeText(dataToCopy).then(() => {
                            // Change button to show checkmark
                            if (copyButton) {
                                const originalHTML = copyButton.innerHTML;
                                copyButton.innerHTML = '✅ Copied!';
                                copyButton.classList.remove('bg-blue-500', 'hover:bg-blue-600');
                                copyButton.classList.add('bg-green-500', 'hover:bg-green-600');
                                copyButton.disabled = true;
                                
                                // Reset after 2 seconds
                                setTimeout(() => {
                                    copyButton.innerHTML = originalHTML;
                                    copyButton.classList.remove('bg-green-500', 'hover:bg-green-600');
                                    copyButton.classList.add('bg-blue-500', 'hover:bg-blue-600');
                                    copyButton.disabled = false;
                                }, 2000);
                            }
                        }).catch(err => {
                            console.error('Failed to copy to clipboard:', err);
                            Swal.fire({
                                title: 'Copy Failed',
                                html: `
                                    <div class="text-left">
                                        <p class="mb-3">Please manually copy this data:</p>
                                        <div class="bg-gray-100 p-3 rounded text-sm font-mono break-all">
                                            ${dataToCopy}
                                        </div>
                                    </div>
                                `,
                                confirmButtonText: 'OK',
                                allowOutsideClick: false,
                                allowEscapeKey: false
                            });
                        });
                    } else {
                        // Fallback: copy the entire content
                        navigator.clipboard.writeText(text).then(() => {
                            // Change button to show checkmark
                            if (copyButton) {
                                const originalHTML = copyButton.innerHTML;
                                copyButton.innerHTML = '✅ Copied!';
                                copyButton.classList.remove('bg-blue-500', 'hover:bg-blue-600');
                                copyButton.classList.add('bg-green-500', 'hover:bg-green-600');
                                copyButton.disabled = true;
                                
                                // Reset after 2 seconds
                                setTimeout(() => {
                                    copyButton.innerHTML = originalHTML;
                                    copyButton.classList.remove('bg-green-500', 'hover:bg-green-600');
                                    copyButton.classList.add('bg-blue-500', 'hover:bg-blue-600');
                                    copyButton.disabled = false;
                                }, 2000);
                            }
                        }).catch(err => {
                            console.error('Failed to copy:', err);
                        });
                    }
                }
            };
            
        }
    });
};


// Function to extract state from location name (same logic as backend)
const extractStateFromName = (name) => {
    if (!name) return '';
    
    // Check if name contains " - " (separator for cinema)
    let locationPart = name;
    if (name.includes(' - ')) {
        const parts = name.split(' - ', 2);
        locationPart = parts[0].trim();
    }
    
    // Check if location part contains state (2-3 letter abbreviation at the end)
    // Pattern: "Location ST" where ST is 2-3 uppercase letters
    const stateMatch = locationPart.match(/^(.+?)\s+([A-Z]{2,3})$/);
    if (stateMatch) {
        return stateMatch[2].trim();
    }
    
    return '';
};

// Function to extract location name without state
const extractLocationName = (name) => {
    if (!name) return '';
    
    // Check if name contains " - " (separator for cinema)
    let locationPart = name;
    let cinema = '';
    if (name.includes(' - ')) {
        const parts = name.split(' - ', 2);
        locationPart = parts[0].trim();
        cinema = parts[1]?.trim() || '';
    }
    
    // Remove state if present (2-3 letter abbreviation at the end)
    const stateMatch = locationPart.match(/^(.+?)\s+([A-Z]{2,3})$/);
    if (stateMatch) {
        locationPart = stateMatch[1].trim();
    }
    
    // Reconstruct name without state
    if (cinema) {
        return locationPart + ' - ' + cinema;
    }
    return locationPart;
};

// Function to open location creation modal
const openLocationModal = (location = null) => {
    locationForm.reset();
    noDateYet.value = false;
    noTimeYet.value = false;
    
    if (location) {
        isEditing.value = true;
        locationForm.id = location.id;
        // Extract state and location name separately
        locationForm.name = extractLocationName(location.name);
        locationForm.state = extractStateFromName(location.name);
        locationForm.date = location.date;
        locationForm.time = location.time;
        locationForm.country = location.country || '';
        locationForm.category = location.category || '';
        locationForm.event_id = props.event.id;
        
        // Set checkboxes based on existing values
        noDateYet.value = location.date === 'TBA';
        noTimeYet.value = location.time === 'TBA';
    } else {
        isEditing.value = false;
        locationForm.event_id = props.event.id;
        locationForm.country = '';
        locationForm.state = '';
        locationForm.category = '';
    }
    showLocationModal.value = true;
    let modalElement = new bootstrap.Modal(document.getElementById('createLocationModal'));
    modalElement.show();
};

// Function to save location
const saveLocation = () => {
    if (!locationForm.name) {
        Swal.fire('Error', 'Location name is required!', 'error');
        return;
    }

    // Build the name: "Location State (if any) - Cinema" (same logic as master sheet)
    let locationName = locationForm.name.trim();
    const state = locationForm.state ? locationForm.state.trim().toUpperCase() : '';
    
    // Check if name already contains " - " (cinema separator)
    let hasCinema = false;
    let cinema = '';
    if (locationName.includes(' - ')) {
        const parts = locationName.split(' - ', 2);
        locationName = parts[0].trim();
        cinema = parts[1].trim();
        hasCinema = true;
    }
    
    // Build final name: add state if provided, then add cinema if exists
    let finalName = locationName;
    if (state) {
        finalName += ' ' + state;
    }
    if (hasCinema && cinema) {
        finalName += ' - ' + cinema;
    }

    // Prepare form data with TBA values if checkboxes are checked
    const formData = {
        ...locationForm,
        name: finalName, // Use the built name with state
        date: noDateYet.value ? 'TBA' : locationForm.date,
        time: noTimeYet.value ? 'TBA' : locationForm.time
    };

    // Validate that if checkboxes are not checked, date and time are provided
    if (!noDateYet.value && !formData.date) {
        Swal.fire('Error', 'Date is required or check "No Date yet"!', 'error');
        return;
    }
    if (!noTimeYet.value && !formData.time) {
        Swal.fire('Error', 'Time is required or check "No Time yet"!', 'error');
        return;
    }

    if (isEditing.value) {
        router.put(route('location.update', locationForm.id), formData, {
            onSuccess: () => {
                closeLocationModal();
                Swal.fire('Success!', 'Location has been updated.', 'success');
                locationForm.reset();
                router.reload();
            },
            onError: (errors) => {
                Swal.fire('Error!', 'There was an issue updating the location.', 'error');
            }
        });
    } else {
        router.post(route('location.store'), formData, {
            onSuccess: () => {
                closeLocationModal();
                Swal.fire('Success!', 'Location has been created.', 'success');
                locationForm.reset();
                router.reload();
            },
            onError: (errors) => {
                Swal.fire('Error!', 'There was an issue creating the location.', 'error');
            }
        });
    }
};



// Add function to handle modal close
const closeLocationModal = () => {
    showLocationModal.value = false;
    noDateYet.value = false;
    noTimeYet.value = false;
    let modalElement = bootstrap.Modal.getInstance(document.getElementById('createLocationModal'));
    if (modalElement) {
        modalElement.hide();
        // Remove the backdrop
        const backdrop = document.querySelector('.modal-backdrop');
        if (backdrop) {
            backdrop.remove();
        }
        // Remove the modal-open class from body
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    }
};

// Add function to handle password modal close
const closePasswordModal = () => {
    showPasswordModal.value = false;
    let modalElement = bootstrap.Modal.getInstance(document.getElementById('passwordModal'));
    if (modalElement) {
        modalElement.hide();
        // Remove the backdrop
        const backdrop = document.querySelector('.modal-backdrop');
        if (backdrop) {
            backdrop.remove();
        }
        // Remove the modal-open class from body
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    }
};

const updateAllPasswords = async () => {
    if (!allLocationsNewPassword.value || !allLocationsConfirmPassword.value) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Please fill in all fields'
        });
        return;
    }

    if (allLocationsNewPassword.value.length < 8) {
        Swal.fire({
            icon: 'error',
            title: 'Password Too Short',
            text: 'Password must be at least 8 characters long'
        });
        return;
    }

    if (allLocationsNewPassword.value !== allLocationsConfirmPassword.value) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Passwords do not match'
        });
        return;
    }

    try {
        await router.put(route('location.updateAllPasswords', props.event.id), {
            password: allLocationsNewPassword.value
        });

        // Close the modal first
        const modalElement = bootstrap.Modal.getInstance(document.getElementById('allPasswordsModal'));
        if (modalElement) {
            modalElement.hide();
        }
        
        // Show success message
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: 'All location passwords updated successfully',
            timer: 1500,
            showConfirmButton: false
        }).then(() => {
            // Reload the page after the success message
            router.reload();
        });
    } catch (error) {
        console.error('Update error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Failed to update passwords. Please try again.'
        });
    }
};

const openAllPasswordsModal = () => {
    allLocationsNewPassword.value = '';
    allLocationsConfirmPassword.value = '';
    showAllPasswordsModal.value = true;
    let modalElement = new bootstrap.Modal(document.getElementById('allPasswordsModal'));
    modalElement.show();
};

const closeAllPasswordsModal = () => {
    showAllPasswordsModal.value = false;
    allLocationsNewPassword.value = '';
    allLocationsConfirmPassword.value = '';
    let modalElement = bootstrap.Modal.getInstance(document.getElementById('allPasswordsModal'));
    if (modalElement) {
        modalElement.hide();
        // Remove the backdrop
        const backdrop = document.querySelector('.modal-backdrop');
        if (backdrop) {
            backdrop.remove();
        }
        // Remove the modal-open class from body
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    }
};

const openMailchimpSettingsModal = async () => {
    isSettingsLoading.value = true;
    try {
        const response = await axios.get(route('mailchimp.autosync.settings'), {
            params: {
                event_id: props.event.id
            }
        });
        const { settings, available_lists, available_accounts } = response.data;
        
        mailchimpSettings.value = {
            ...settings,
            film_tour: settings.film_tour,
            default_tags: Array.isArray(settings.default_tags) ? settings.default_tags.join(', ') : '',
            mailchimp_account: settings.mailchimp_account || 'anz',
            event_id: props.event.id
        };
        availableLists.value = available_lists;
        availableAccounts.value = available_accounts;
        showMailchimpSettingsModal.value = true;
    } catch (error) {
        console.error('Failed to fetch Mailchimp settings:', error);
        Swal.fire('Error', 'Failed to load Mailchimp settings', 'error');
    } finally {
        isSettingsLoading.value = false;
    }
};

const loadListsForAccount = async (account) => {
    try {
        const response = await axios.get(route('mailchimp.autosync.lists'), {
            params: { account }
        });
        availableLists.value = response.data.lists;
        // Reset selected list when account changes
        mailchimpSettings.value.default_list_id = '';
    } catch (error) {
        console.error('Failed to load lists for account:', error);
        // Don't show error modal for account changes, just log it
        console.warn('Account not configured or API error:', error.message);
        availableLists.value = [];
    }
};

const saveMailchimpSettings = async () => {
    try {
        const settings = {
            ...mailchimpSettings.value,
            film_tour: mailchimpSettings.value.film_tour,
            default_tags: mailchimpSettings.value.default_tags.split(',').map(tag => tag.trim()).filter(tag => tag),
            enabled_locations: Array.isArray(mailchimpSettings.value.enabled_locations) 
                ? mailchimpSettings.value.enabled_locations 
                : [],
            mailchimp_account: mailchimpSettings.value.mailchimp_account,
            event_id: props.event.id
        };

        console.log('Saving settings:', settings);

        await axios.post(route('mailchimp.autosync.update'), settings);
        
        showMailchimpSettingsModal.value = false;
        
        Swal.fire('Success', 'Mailchimp auto-sync settings updated successfully', 'success');
    } catch (error) {
        console.error('Failed to save Mailchimp settings:', error);
        Swal.fire('Error', 'Failed to save Mailchimp settings', 'error');
    }
};

// Download CSV file for Eventbrite import
const downloadEventbriteImportCSV = (attendees, locationName) => {
    try {
        // Create CSV content
        const headers = ['Email', 'First Name', 'Last Name', 'Phone'];
        const rows = attendees.map(attendee => [
            attendee.email_address || '',
            attendee.first_name || '',
            attendee.last_name || '',
            attendee.mobile_number || ''
        ]);

        const csvContent = [
            headers.join(','),
            ...rows.map(row => row.map(cell => `"${cell}"`).join(','))
        ].join('\n');

        // Create and download file
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', `eventbrite-import-${locationName.replace(/[^a-z0-9]/gi, '_')}-${new Date().toISOString().split('T')[0]}.csv`);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    } catch (error) {
        console.error('Failed to generate CSV file:', error);
    }
};



// Toggle location selection for export
const toggleLocationSelection = (location) => {
    const index = selectedLocationsForExport.value.findIndex(loc => loc.id === location.id);
    if (index > -1) {
        selectedLocationsForExport.value.splice(index, 1);
    } else {
        selectedLocationsForExport.value.push(location);
    }
};

// Check if location is selected
const isLocationSelected = (location) => {
    return selectedLocationsForExport.value.some(loc => loc.id === location.id);
};

// Check if all locations are selected
const areAllLocationsSelected = computed(() => {
    if (!selectedCountry.value || selectedCountryLocations.value.length === 0) {
        return false;
    }
    return selectedCountryLocations.value.every(location => 
        selectedLocationsForExport.value.some(loc => loc.id === location.id)
    );
});

// Toggle select all locations
const toggleSelectAll = () => {
    if (!selectedCountry.value) return;
    
    if (areAllLocationsSelected.value) {
        // Deselect all locations in current country
        selectedLocationsForExport.value = selectedLocationsForExport.value.filter(loc => 
            !selectedCountryLocations.value.some(countryLoc => countryLoc.id === loc.id)
        );
    } else {
        // Select all locations in current country
        selectedCountryLocations.value.forEach(location => {
            if (!selectedLocationsForExport.value.some(loc => loc.id === location.id)) {
                selectedLocationsForExport.value.push(location);
            }
        });
    }
};

// Export selected locations
const exportSelectedLocations = async () => {
    if (selectedLocationsForExport.value.length === 0) {
        Swal.fire('Error', 'Please select at least one location to export', 'error');
        return;
    }

    try {
        Swal.fire({
            title: 'Exporting Locations',
            html: `Exporting ${selectedLocationsForExport.value.length} location(s)...`,
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        // Export each location as a separate file
        for (let i = 0; i < selectedLocationsForExport.value.length; i++) {
            const location = selectedLocationsForExport.value[i];
            const exportUrl = route('location.export', { locationId: location.id });
            
            // Open in new window to trigger download
            window.open(exportUrl, '_blank');
            
            // Small delay between downloads to avoid browser blocking
            if (i < selectedLocationsForExport.value.length - 1) {
                await new Promise(resolve => setTimeout(resolve, 500));
            }
        }

        await Swal.close();
        
        Swal.fire({
            title: 'Export Complete',
            text: `Successfully exported ${selectedLocationsForExport.value.length} location(s). Check your downloads folder.`,
            icon: 'success',
            timer: 3000,
            showConfirmButton: false
        });

        // Clear selection after export
        selectedLocationsForExport.value = [];
    } catch (error) {
        await Swal.close();
        console.error('Export error:', error);
        Swal.fire('Error', 'Failed to export locations. Please try again.', 'error');
    }
};

// Add watch for flash messages
watch(
    () => props.flash,
    (flash) => {
        if (flash?.success) {
            Swal.fire({
                title: 'Success!',
                text: flash.success,
                icon: 'success',
                timer: 1500,
                showConfirmButton: false
            });
        }
        if (flash?.error) {
            Swal.fire({
                title: 'Error!',
                text: flash.error,
                icon: 'error'
            });
        }
    },
    { immediate: true, deep: true }
);

// Watch for account changes to load lists
watch(
    () => mailchimpSettings.value.mailchimp_account,
    (newAccount) => {
        if (newAccount && showMailchimpSettingsModal.value) {
            loadListsForAccount(newAccount);
        }
    }
);

// Watch for Eventbrite Mailchimp account changes
watch(
    () => eventbriteMailchimpAccount.value,
    (newAccount) => {
        if (newAccount && showEventbriteMailchimpModal.value) {
            loadEventbriteLists(newAccount);
        }
    }
);

// ---- Import All (event-level) ----
const openImportAllModal = async () => {
    isOpeningImportAllModal.value = true;
    // Reset state first; do NOT show modal yet so the opening click cannot hit the overlay
    stagedByLocation.value = {};
    importAllEventbriteLinkByLocation.value = {};
    importAllDefaultTags.value = '';
    importAllTagsByLocation.value = {};
    importAllFormTagsByLocation.value = {};
    importAllDataMode.value = '';
    isFetchingPreviewByLocation.value = {};
    importAllListId.value = '';
    importAllAccount.value = '';
    importAllLists.value = [];
    importAllMergeFieldsWithValidation.value = [];
    importAllSourceColumns.value = [];
    importAllFieldMapping.value = {};
    showMappingSection.value = false;
    try {
        const settingsRes = await axios.get(route('mailchimp.autosync.settings'), { params: { event_id: props.event.id } });
        const settings = settingsRes.data.settings || {};
        importAllAccount.value = settings.mailchimp_account || 'anz';
        const filmTour = settings.film_tour || 'WM';
        const eventYear = props.event?.event_year != null && props.event.event_year >= 2020 && props.event.event_year <= 2035 ? props.event.event_year : null;
        const year = typeof importAllSourceYear.value === 'number' && importAllSourceYear.value >= 2020 && importAllSourceYear.value <= 2035
            ? importAllSourceYear.value
            : (eventYear ?? new Date().getFullYear());
        importAllSourceYear.value = year;
        if (settings.default_tags) {
            const arr = Array.isArray(settings.default_tags)
                ? settings.default_tags
                : (typeof settings.default_tags === 'string' ? settings.default_tags.split(',').map(t => t.trim()).filter(Boolean) : []);
            importAllDefaultTags.value = arr.join('; ');
        }
        const ticketTagsByLoc = {};
        const formTagsByLoc = {};
        const eventCountry = props.event?.event_country || '';
        (props.locations || []).forEach(loc => {
            const showLoc = locationTagForShow(loc.name, loc.state, eventCountry || loc.country);
            const sourceLoc = locationTagForSource(loc.name, loc.state);
            ticketTagsByLoc[loc.id] = [`SHOW - ${showLoc}`, `SOURCE - ${filmTour.toUpperCase()} ${sourceLoc} TIX ${year}`].join('; ');
            formTagsByLoc[loc.id] = [`SHOW - ${showLoc}`, `SOURCE - ${filmTour.toUpperCase()} ${sourceLoc} COMP ${year}`].join('; ');
        });
        importAllTagsByLocation.value = ticketTagsByLoc;
        importAllFormTagsByLocation.value = formTagsByLoc;
        importAllDataMode.value = '';
        importAllAccounts.value = settingsRes.data.available_accounts || { anz: { name: 'ANZ', enabled: true }, usa: { name: 'USA', enabled: false } };
        await loadImportAllLists(importAllAccount.value);
        try {
            const previewRes = await axios.get(route('event.mailchimpImportPreview', props.event.id));
            importPreviewFormTotal.value = previewRes.data.total_form ?? 0;
            importPreviewByLocation.value = previewRes.data.by_location ?? [];
            importAllFormPreviewRow.value = previewRes.data.preview_row ?? null;
        } catch (e) {
            importPreviewFormTotal.value = 0;
            importPreviewByLocation.value = [];
            importAllFormPreviewRow.value = null;
        }
    } catch (e) {
        console.error(e);
        importAllAccounts.value = { anz: { name: 'ANZ', enabled: true }, usa: { name: 'USA', enabled: false } };
    } finally {
        isOpeningImportAllModal.value = false;
        showImportAllModal.value = true;
    }
};

// Replace the 4-digit year in SOURCE tags (e.g. SOURCE - WM MELB COMP 2025 → 2026) when user changes Import All year
const applyImportAllSourceYear = (newYear) => {
    const y = String(newYear).replace(/\D/g, '');
    if (y.length !== 4) return;
    const repl = (str) => (str || '').replace(/(SOURCE\s*-\s*[^;]+?)\s+\d{4}(\s*;|\s*$|$)/gi, (m, prefix, suffix) => prefix + ' ' + y + (suffix || ''));
    const ticket = {};
    Object.keys(importAllTagsByLocation.value || {}).forEach((id) => {
        ticket[id] = repl(importAllTagsByLocation.value[id]);
    });
    const form = {};
    Object.keys(importAllFormTagsByLocation.value || {}).forEach((id) => {
        form[id] = repl(importAllFormTagsByLocation.value[id]);
    });
    importAllTagsByLocation.value = ticket;
    importAllFormTagsByLocation.value = form;
};

const closeImportAllModal = () => {
    showImportAllModal.value = false;
    stagedByLocation.value = {};
    importAllEventbriteLinkByLocation.value = {};
    importAllDefaultTags.value = '';
    importAllTagsByLocation.value = {};
    importAllFormTagsByLocation.value = {};
    importAllDataMode.value = '';
    importPreviewFormTotal.value = 0;
    importPreviewByLocation.value = [];
    importAllFormPreviewRow.value = null;
};

const loadImportAllLists = async (account) => {
    if (!account) return;
    try {
        const res = await axios.get(route('mailchimp.autosync.lists'), { params: { account } });
        importAllLists.value = res.data.lists || [];
    } catch (e) {
        importAllLists.value = [];
    }
};

watch(
    () => importAllAccount.value,
    (acc) => { if (acc && showImportAllModal.value) loadImportAllLists(acc); }
);

watch(
    () => importAllListId.value,
    async (listId) => {
        if (!listId || !importAllAccount.value || !showImportAllModal.value) {
            importAllMergeFieldsWithValidation.value = [];
            importAllSourceColumns.value = [];
            importAllFieldMapping.value = {};
            return;
        }
        isLoadingMergeFields.value = true;
        try {
            const [mergeRes, sourceRes] = await Promise.all([
                axios.get(route('location.mailchimpMergeFields'), { params: { list_id: listId, account: importAllAccount.value } }),
                axios.get(route('event.importSourceColumns', props.event.id))
            ]);
            const mf = mergeRes.data?.merge_fields_with_validation ?? mergeRes.data?.merge_fields ?? [];
            importAllMergeFieldsWithValidation.value = mf;
            importAllSourceColumns.value = sourceRes.data?.source_columns ?? [];
            const mapping = {};
            // Adventure Entertainment Newsletter (ANZ) - strict field mapping defaults
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
            (mergeRes.data?.merge_fields ?? []).forEach((f) => {
                const tag = f.tag || f;
                if (tag === 'EMAIL') return;
                mapping[tag] = tagToDefault[tag] ?? '';
            });
            importAllFieldMapping.value = mapping;
        } catch (e) {
            importAllMergeFieldsWithValidation.value = [];
            importAllSourceColumns.value = [];
        } finally {
            isLoadingMergeFields.value = false;
        }
    }
);

watch(
    () => importAllSourceYear.value,
    (newYear) => {
        if (!showImportAllModal.value) return;
        const y = typeof newYear === 'number' ? newYear : parseInt(String(newYear).replace(/\D/g, ''), 10);
        if (y >= 2020 && y <= 2035) applyImportAllSourceYear(y);
    }
);

const extractEventIdFromLinkForImportAll = (link) => {
    try {
        const url = new URL(link);
        const pathParts = url.pathname.split('/');
        for (let i = pathParts.length - 1; i >= 0; i--) {
            const part = pathParts[i];
            if (/^\d+$/.test(part)) return part;
            const match = part.match(/-(\d+)$/);
            if (match) return match[1];
        }
        return null;
    } catch (_) { return null; }
};

const fetchPreviewForLocation = async (locationId, link) => {
    const eventId = extractEventIdFromLinkForImportAll(link);
    if (!eventId) {
        Swal.fire('Error', 'Could not extract Eventbrite event ID from the link.', 'error');
        return;
    }
    isFetchingPreviewByLocation.value = { ...isFetchingPreviewByLocation.value, [locationId]: true };
    try {
        const res = await axios.post(route('location.fetchEventbriteAttendeesPreview'), {
            event_id: eventId,
            location_id: locationId
        });
        const attendees = (res.data.attendees || []).map(a => ({
            email: a.email,
            first_name: a.first_name || '',
            last_name: a.last_name || '',
            phone: a.phone || '',
            city: a.city || '',
            state: a.state || '',
            country: a.country || ''
        }));
        stagedByLocation.value = { ...stagedByLocation.value, [locationId]: { attendees } };
        importAllEventbriteLinkByLocation.value = { ...importAllEventbriteLinkByLocation.value, [locationId]: link };
        Swal.fire('Success', `${attendees.length} attendees staged for this location.`, 'success');
    } catch (err) {
        Swal.fire('Error', err.response?.data?.error || 'Failed to fetch attendees.', 'error');
    } finally {
        isFetchingPreviewByLocation.value = { ...isFetchingPreviewByLocation.value, [locationId]: false };
    }
};

const parseCsvLine = (line) => {
    const result = []; let current = ''; let inQuotes = false;
    for (let i = 0; i < line.length; i++) {
        const c = line[i];
        if (c === '"') { inQuotes = !inQuotes; continue; }
        if (!inQuotes && (c === ',' || c === '\t')) { result.push(current.trim()); current = ''; continue; }
        current += c;
    }
    result.push(current.trim());
    return result;
};

const parseCsvForLocation = (locationId, file) => {
    const reader = new FileReader();
    reader.onload = (e) => {
        const text = (e.target?.result || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
        const lines = text.split('\n').filter(l => l.trim());
        if (lines.length < 2) {
            Swal.fire('Error', 'CSV must have a header row and at least one data row.', 'error');
            return;
        }
        const headers = parseCsvLine(lines[0].replace(/^\uFEFF/, ''));
        const lower = headers.map(h => (h || '').toLowerCase());
        let emailIdx = -1, firstIdx = -1, lastIdx = -1, phoneIdx = -1, cityIdx = -1, stateIdx = -1, countryIdx = -1;
        lower.forEach((h, i) => {
            if (/email|e-mail|e_mail/.test(h)) emailIdx = i;
            else if (/first|fname|firstname|given/.test(h)) firstIdx = i;
            else if (/last|lname|lastname|surname|family/.test(h)) lastIdx = i;
            else if (/phone|mobile|cell|tel/.test(h)) phoneIdx = i;
            else if (/^city$|town/.test(h)) cityIdx = i;
            else if (/state|region|province/.test(h)) stateIdx = i;
            else if (/country/.test(h)) countryIdx = i;
        });
        if (emailIdx === -1) {
            Swal.fire('Error', 'CSV must have an email column.', 'error');
            return;
        }
        const seen = new Set();
        const attendees = [];
        for (let r = 1; r < lines.length; r++) {
            const row = parseCsvLine(lines[r]);
            const email = (row[emailIdx] || '').toLowerCase().trim();
            if (!email || seen.has(email)) continue;
            seen.add(email);
            attendees.push({
                email,
                first_name: firstIdx >= 0 ? (row[firstIdx] || '').trim() : '',
                last_name: lastIdx >= 0 ? (row[lastIdx] || '').trim() : '',
                phone: phoneIdx >= 0 ? (row[phoneIdx] || '').trim() : '',
                city: cityIdx >= 0 ? (row[cityIdx] || '').trim() : '',
                state: stateIdx >= 0 ? (row[stateIdx] || '').trim() : '',
                country: countryIdx >= 0 ? (row[countryIdx] || '').trim() : ''
            });
        }
        stagedByLocation.value = { ...stagedByLocation.value, [locationId]: { attendees } };
        Swal.fire('Success', `${attendees.length} attendees staged from CSV for this location.`, 'success');
    };
    reader.readAsText(file, 'UTF-8');
};

const removeStagedForLocation = (locationId) => {
    const next = { ...stagedByLocation.value };
    delete next[locationId];
    stagedByLocation.value = next;
    const nextLink = { ...importAllEventbriteLinkByLocation.value };
    delete nextLink[locationId];
    importAllEventbriteLinkByLocation.value = nextLink;
};

const stagedLocationIds = computed(() => Object.keys(stagedByLocation.value));
const hasStagedData = computed(() => stagedLocationIds.value.length > 0);
const totalStagedCount = computed(() => {
    let n = 0;
    Object.values(stagedByLocation.value).forEach(v => { if (v && v.attendees) n += v.attendees.length; });
    return n;
});

// Import preview: win form counts per location (from backend); ticket count comes from staged data
const importPreviewFormTotal = ref(0);
const importPreviewByLocation = ref([]);
const totalToImport = computed(() => (importPreviewFormTotal.value || 0) + (totalStagedCount.value || 0));

const runImportAll = async () => {
    const listId = (importAllListId.value || '').trim();
    const account = (importAllAccount.value || '').trim();
    if (!listId || !account) {
        Swal.fire('Error', 'Please select Mailchimp account and audience.', 'error');
        return;
    }
    const defaultTagsArray = parseTagStr(importAllDefaultTags.value);
    const getLocationCountryTag = (locationId) => {
        const loc = props.locations.find(l => l.id === parseInt(locationId, 10));
        const country = (loc?.country || '').trim();
        return country ? `COUNTRY - ${country.toUpperCase()}` : null;
    };
    const mode = importAllDataMode.value;
    if (mode !== 'ticket' && mode !== 'form') {
        Swal.fire('Error', 'Please choose what to import (Ticket data or Win form data) first.', 'error');
        return;
    }
    // Build payload: one type per run (ticket only or win form only). Locations with no data are skipped by the job and not logged.
    const isTicket = mode === 'ticket';
    const locationsPayload = (isTicket ? props.locations.filter((loc) => {
        const staged = stagedByLocation.value[loc.id];
        return staged && Array.isArray(staged.attendees) && staged.attendees.length > 0;
    }) : props.locations)
        .map((loc) => {
            const locationId = loc.id;
            const countryTag = getLocationCountryTag(locationId);
            if (isTicket) {
                const staged = stagedByLocation.value[locationId];
                const attendees = (staged && Array.isArray(staged.attendees) && staged.attendees.length > 0) ? staged.attendees : [];
                const ticketTags = attendees.length > 0
                    ? [...defaultTagsArray, ...(countryTag ? [countryTag] : []), ...parseTagStr(importAllTagsByLocation.value[locationId] || '')]
                    : [];
                return {
                    location_id: locationId,
                    import_ticket: true,
                    import_form: false,
                    attendees,
                    tags: ticketTags,
                    form_tags: []
                };
            }
            const formTags = [...defaultTagsArray, ...(countryTag ? [countryTag] : []), ...parseTagStr(importAllFormTagsByLocation.value[locationId] || '')];
            return {
                location_id: locationId,
                import_ticket: false,
                import_form: true,
                attendees: [],
                tags: [],
                form_tags: formTags
            };
        });
    if (locationsPayload.length === 0) {
        Swal.fire('Error', isTicket
            ? 'No locations have ticket data staged. Add Eventbrite link or CSV per location first, then Fetch.'
            : 'No locations to import. Add tags for form data per location.', 'error');
        return;
    }
    if (isTicket) {
        const missingTicketTags = locationsPayload.filter(loc => loc.attendees.length > 0 && loc.tags.length === 0);
        if (missingTicketTags.length > 0) {
            Swal.fire('Error', 'Each location with ticket data must have at least one tag in "Tags for ticket data".', 'error');
            return;
        }
    } else {
        const missingFormTags = locationsPayload.filter(loc => loc.form_tags.length === 0);
        if (missingFormTags.length > 0) {
            Swal.fire('Error', 'Each location must have at least one tag in "Tags for form data".', 'error');
            return;
        }
    }
    const totalLocations = locationsPayload.length;
    const totalContacts = mode === 'ticket'
        ? (totalStagedCount.value || 0)
        : (importPreviewFormTotal.value || 0);
    isImportAllLoading.value = true;
    const listName = (importAllLists.value || []).find(l => l.id === listId)?.name || '';
    try {
        const fieldMapping = { ...importAllFieldMapping.value };
        Object.keys(fieldMapping).forEach((k) => { if (fieldMapping[k] === '') delete fieldMapping[k]; });
        const res = await axios.post(route('event.importAll'), {
            event_id: props.event.id,
            list_id: listId,
            list_name: listName,
            mailchimp_account: account,
            locations: locationsPayload,
            skip_already_imported: !!importAllSkipAlreadyImported.value,
            field_mapping: Object.keys(fieldMapping).length ? fieldMapping : null
        }, { timeout: 30000 });

        const d = res.data;
        if (d.queued === true) {
            await Swal.fire({
                title: 'Import started',
                html: `
                    <p class="text-left">${d.message || 'Import is running in the background.'}</p>
                    <p class="text-left mt-2 text-sm text-gray-600">Up to <strong>${totalContacts}</strong> contacts across <strong>${totalLocations}</strong> locations. You can close this and keep using the app.</p>
                    <p class="text-left mt-2 text-sm">Check <strong>Mailchimp Import Logs</strong> in the menu for results when it finishes.</p>
                `,
                icon: 'success'
            });
            closeImportAllModal();
            router.reload();
        } else {
            // Fallback if backend ever returns sync results
            let msg = d.message || 'Import completed.';
            if (d.locations && d.locations.length) {
                msg += '\n\n' + d.locations.map(l => `${l.location_name}${l.source ? ` (${l.source === 'signup_form' ? 'win form' : 'ticket'})` : ''}: ${l.success} success, ${l.failed} failed`).join('\n');
            }
            await Swal.fire('Done', msg, 'success');
            closeImportAllModal();
            router.reload();
        }
    } catch (err) {
        await Swal.close();
        const isTimeout = err.code === 'ECONNABORTED' || (err.message && /timeout|timed out/i.test(err.message));
        const isNetwork = !err.response && (err.message === 'Network Error' || err.code === 'ERR_NETWORK');
        let msg = err.response?.data?.error || err.response?.data?.message || err.response?.data?.errors?.message;
        if (!msg && (isTimeout || isNetwork)) {
            msg = 'The request timed out or was interrupted. Try importing fewer locations at once, or run Import All again.';
        }
        if (!msg) msg = 'Import failed.';
        Swal.fire('Error', msg, 'error');
    } finally {
        isImportAllLoading.value = false;
    }
};

</script>

<template>
    <Head title="Pick a Winner Page" />

    <AuthenticatedLayout>
        <div class="p-4">
            <div class="mx-auto max-w-5xl">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 text-center">
                        <div class="flex flex-col sm:flex-row justify-between items-center gap-4 mb-4">
                            <div class="text-center sm:text-left flex items-center gap-2">
                                <!-- All Locations Page Button -->
                                <button 
                                    target="_blank"
                                    @click="allLocationsPage"
                                    style="background-color: #16C3D9; color: white; border-radius: 5px; padding: 10px 20px; cursor: pointer;"
                                    title="View All Locations Attendees"
                                >
                                    National Tour Wide Prizes
                                </button>
                                </div>

                            <h3 class="text-lg font-semibold">{{ event.event_name }}</h3>
                            <div class="flex gap-2">
                                <!-- Add Location Button -->
                                <button 
                                    class="bg-black text-white px-4 py-2 rounded hover:bg-black-700"
                                    @click="openLocationModal()"
                                >
                                    <i class="fa-solid fa-plus"></i> Add Location
                                </button>
                                <!-- Mailchimp Settings Button -->
                                <button 
                                    style="background-color: #16C3D9; color: white; border-radius: 5px; padding: 10px 20px; cursor: pointer;"
                                    @click="openMailchimpSettingsModal"
                                    title="Mailchimp Auto-Sync Settings"
                                    :disabled="isSettingsLoading"
                                >
                                    <i v-if="!isSettingsLoading" class="fa-solid fa-gear"></i>
                                    <i v-else class="fa-solid fa-spinner fa-spin"></i>
                                </button>
                                <!-- Update All Passwords Button -->
                                <button 
                                    style="background-color: #16C3D9; color: white; border-radius: 5px; padding: 10px 20px; cursor: pointer;"
                                    @click="openAllPasswordsModal"
                                >
                                    <i class="fa-solid fa-key"></i> 
                                </button>
                            </div>
                        </div>
                        <!-- <div class="d-flex justify-content-between items-center mb-4">
                            <button class="btn btn-primary" @click="openEventPasswordModal">
                                <i class="fa-solid fa-key"></i> Event Password
                            </button>
                        </div> -->
                        <!-- ✅ Search Bar and Category Filter -->
                        <div class="mb-4 space-y-3">
                        <input 
                            v-model="searchQuery" 
                            type="text" 
                            placeholder="Search location..."
                                class="w-full border p-2 rounded focus:ring focus:ring-blue-300"
                        />

                            <!-- Category Filter and Database Button -->
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-3 flex-wrap">
                                    <!-- Total participants - at start on the left -->
                                    <span class="text-sm font-medium text-gray-700 whitespace-nowrap" title="Total participants (win form) across all locations">
                                        <i class="fa-solid fa-users mr-1"></i> Total: <strong>{{ total_participants ?? 0 }}</strong> participants
                                    </span>
                                    <div v-if="selectedCountry && availableCategories.length > 0" class="flex items-center gap-2">
                                        <label class="text-sm font-medium text-gray-700 whitespace-nowrap">Filter by Category:</label>
                                        <select 
                                            v-model="selectedCategory"
                                            class="border rounded px-3 py-2 text-sm focus:ring focus:ring-blue-300"
                                            style="min-width: 200px;"
                                        >
                                            <option value="">All Categories</option>
                                            <option 
                                                v-for="category in availableCategories" 
                                                :key="category" 
                                                :value="category"
                                            >
                                                {{ category }}
                                            </option>
                                        </select>
                                        <button 
                                            v-if="selectedCategory"
                                            @click="selectedCategory = ''"
                                            class="px-3 py-2 text-sm text-gray-600 hover:text-gray-800 underline"
                                        >
                                            Clear Filter
                                        </button>
                                        <!-- Export Selected Locations Button -->
                                        <button 
                                            v-if="selectedLocationsForExport.length > 0"
                                            style="background-color: #17a2b8; color: white; border-radius: 5px; padding: 10px 20px; cursor: pointer;"
                                            @click="exportSelectedLocations"
                                            :title="`Export ${selectedLocationsForExport.length} selected location(s)`"
                                        >
                                            <i class="fa-solid fa-file-export"></i> Export Selected ({{ selectedLocationsForExport.length }})
                                        </button>
                                    </div>
                                </div>
                                <!-- Import All + Database - Right -->
                                <div class="ml-auto flex items-center gap-3">
                                    <button 
                                        type="button"
                                        @click="openImportAllModal"
                                        :disabled="isOpeningImportAllModal"
                                        :style="{
                                            backgroundColor: '#0d9488',
                                            color: 'white',
                                            borderRadius: '5px',
                                            padding: '10px 20px',
                                            cursor: isOpeningImportAllModal ? 'wait' : 'pointer',
                                            opacity: isOpeningImportAllModal ? 0.9 : 1
                                        }"
                                        title="Import all ticket data for this event in one place (Eventbrite or CSV per location), then import to Mailchimp in one click"
                                    >
                                        <i v-if="isOpeningImportAllModal" class="fa-solid fa-spinner fa-spin mr-2"></i>
                                        <i v-else class="fa-solid fa-upload"></i> Import All Data
                                    </button>
                                    <button 
                                        @click="goToAttendeesPage"
                                        style="background-color: #16C3D9; color: white; border-radius: 5px; padding: 10px 20px; cursor: pointer;"
                                        title="View Attendees Database"
                                    >
                                        <i class="fa-solid fa-database"></i> Database
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- ✅ Country Tabs -->
                        <div v-if="countryList.length > 0" class="mb-4">
                            <div class="border-b border-gray-200">
                                <nav class="-mb-px flex space-x-4 overflow-x-auto" style="flex-wrap: wrap;">
                                <button 
                                        v-for="country in countryList"
                                        :key="country"
                                        @click="selectedCountry = country"
                                        :class="[
                                            'px-4 py-2 text-sm font-medium border-b-2 transition-colors whitespace-nowrap',
                                            selectedCountry === country
                                                ? 'border-blue-500 text-blue-600'
                                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                        ]"
                                        :style="selectedCountry === country ? 'background-color: #EFF6FF;' : ''"
                                    >
                                        {{ country }}
                                        <span class="ml-2 px-2 py-0.5 rounded-full text-xs"
                                            :style="selectedCountry === country 
                                                ? 'background-color: #3B82F6; color: white;' 
                                                : 'background-color: #E5E7EB; color: #6B7280;'"
                                        >
                                            {{ locationsByCountry[country].length }}
                                        </span>
                                    </button>
                                </nav>
                            </div>
                        </div>

                        <!-- ✅ Filter Info -->
                        <div v-if="selectedCountry" class="mb-3 text-sm text-gray-600">
                            <span v-if="selectedCategory">
                                Showing {{ selectedCountryLocations.length }} location(s) in <strong>{{ selectedCountry }}</strong> with category <strong>{{ selectedCategory }}</strong>
                            </span>
                            <span v-else>
                                Showing {{ selectedCountryLocations.length }} location(s) in <strong>{{ selectedCountry }}</strong>
                            </span>
                        </div>

                        <!-- ✅ Locations List for Selected Country -->
                        <div v-if="selectedCountry && selectedCountryLocations.length > 0" class="space-y-2">
                            <!-- Select All Checkbox -->
                            <div class="flex items-center space-x-2 w-full pb-2 border-b border-gray-200">
                                <input 
                                    type="checkbox"
                                    :checked="areAllLocationsSelected"
                                    @change="toggleSelectAll"
                                    class="w-5 h-5 text-blue-600 border-gray-300 rounded focus:ring-blue-500 cursor-pointer"
                                    style="min-width: 20px;"
                                />
                                <label class="text-sm font-medium text-gray-700 cursor-pointer" @click="toggleSelectAll">
                                    Select All ({{ selectedCountryLocations.length }})
                                </label>
                            </div>
                            <div 
                                v-for="(location, index) in selectedCountryLocations" 
                                :key="`${selectedCountry}-${index}`" 
                                class="flex items-center space-x-2 w-full"
                            >
                                <!-- Checkbox for selection -->
                                <input 
                                    type="checkbox"
                                    :checked="isLocationSelected(location)"
                                    @change="toggleLocationSelection(location)"
                                    class="w-5 h-5 text-blue-600 border-gray-300 rounded focus:ring-blue-500 cursor-pointer"
                                    style="min-width: 20px;"
                                />
                                <div 
                                    @click="viewLocationAttendees(location)"
                                    class="px-4 py-2 rounded w-full text-left" 
                                    style="background-color: white; border: 2px solid black; font-weight: bold; color: black; border-radius: 5px; padding: 10px 20px; cursor: pointer; transition: background-color 0.2s;"
                                    @mouseenter="$event.target.style.backgroundColor = '#f3f4f6'"
                                    @mouseleave="$event.target.style.backgroundColor = 'white'"
                                    title="View Attendees"
                                >
                                    <div class="flex items-center justify-between flex-wrap gap-2">
                                        <div>
                                            <div class="font-semibold">{{ location.name }}</div>
                                            <div class="text-sm font-normal text-gray-600 mt-1">
                                                {{ formatLocationDateTime(location.date, location.time) }}
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2 flex-shrink-0 flex-wrap">
                                            <!-- Win data imported to Mailchimp -->
                                            <span 
                                                v-if="location.imported_win_to_mailchimp"
                                                class="px-2 py-1 rounded text-xs font-medium bg-teal-100 text-teal-800"
                                                title="Win data imported to Mailchimp"
                                            >
                                                <i class="fa-solid fa-circle-check mr-1"></i>Win data
                                            </span>
                                            <!-- Ticket data imported to Mailchimp -->
                                            <span 
                                                v-if="location.imported_ticket_to_mailchimp"
                                                class="px-2 py-1 rounded text-xs font-medium bg-amber-100 text-amber-800"
                                                title="Ticket data imported to Mailchimp"
                                            >
                                                <i class="fa-solid fa-circle-check mr-1"></i>Ticket data
                                            </span>
                                            <!-- Category + Participant count always together -->
                                            <span class="inline-flex items-center gap-2">
                                                <span 
                                                    class="px-2 py-1 rounded text-xs font-medium bg-gray-200 text-gray-700"
                                                    title="Participants (win form) for this location"
                                                >
                                                    <i class="fa-solid fa-user-group mr-1"></i>{{ location.participants_count ?? 0 }}
                                                </span>
                                                <span 
                                                    v-if="location.category"
                                                    class="px-3 py-1 rounded-full text-xs font-semibold"
                                                    style="background-color: #16C3D9; color: white;"
                                                >
                                                    {{ location.category }}
                                                </span>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <!-- Edit Button -->
                                <button 
                                    @click="openLocationModal(location)"
                                    class="text-white px-3 py-2 rounded"
                                    style="background-color: #16C3D9; color: white; border-radius: 5px; padding: 10px 20px; cursor: pointer;"
                                    title="Edit Location"
                                >
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>
                                <!-- Password Button -->
                                <button 
                                    @click="openPasswordModal(location)"
                                    class="text-white px-3 py-2 rounded"
                                    style="background-color: #16C3D9; color: white; border-radius: 5px; padding: 10px 20px; cursor: pointer;"
                                    title="View Password"
                                >
                                    <i class="fa-solid fa-key"></i>
                                </button>
                            </div>
                        </div>

                        <!-- ✅ If No Locations Found -->
                        <div v-else-if="selectedCountry && selectedCountryLocations.length === 0">
                            <p class="text-gray-600 mt-3">
                                <span v-if="selectedCategory">
                                    No locations found in <strong>{{ selectedCountry }}</strong> with category <strong>{{ selectedCategory }}</strong>.
                                </span>
                                <span v-else>
                                    No locations found in <strong>{{ selectedCountry }}</strong>.
                                </span>
                            </p>
                        </div>
                        <div v-else-if="!selectedCountry">
                            <p class="text-gray-600 mt-3">Please select a country to view locations.</p>
                        </div>
                        <div v-else>
                            <p class="text-gray-600 mt-3">No locations found.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Location Password Modal -->
        <div class="modal fade" id="passwordModal" tabindex="-1" aria-labelledby="passwordModalLabel" @hidden.bs.modal="closePasswordModal">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="passwordModalLabel">Location Password - {{ selectedLocation?.name }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <div class="input-group">
                                <input type="text" :value="selectedLocation?.password" class="form-control" readonly>
                                <button class="btn btn-outline-secondary" @click="copyPassword(selectedLocation, -1)">
                                    <i class="fa-solid fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="text" v-model="newPassword" class="form-control" placeholder="Enter new password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <input type="text" v-model="confirmPassword" class="form-control" placeholder="Confirm new password">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" @click="generatePassword(false)">
                            <i class="fa-solid fa-random"></i> Generate Password
                        </button>
                        <button type="button" class="btn btn-primary" @click="updatePassword">Save Changes</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Event Password Modal -->
        <!-- <div v-if="showEventPasswordModal" class="modal fade show" style="display: block;">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Event Password - {{ event.event_name }}</h5>
                        <button type="button" class="btn-close" @click="showEventPasswordModal = false"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <div class="input-group">
                                <input type="text" :value="event.password" class="form-control" readonly>
                                <button class="btn btn-outline-secondary" @click="copyEventPassword">
                                    <i class="fa-solid fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="text" v-model="eventNewPassword" class="form-control" placeholder="Enter new password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <input type="text" v-model="eventConfirmPassword" class="form-control" placeholder="Confirm new password">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" @click="generatePassword(true)">
                            <i class="fa-solid fa-random"></i> Generate Password
                        </button>
                        <button type="button" class="btn btn-primary" @click="updateEventPassword">Save Changes</button>
                    </div>
                </div>
            </div>
        </div> -->

        <!-- Add/Edit Location Modal -->
        <div class="modal fade" id="createLocationModal" tabindex="-1" aria-labelledby="createLocationModalLabel" @hidden.bs.modal="closeLocationModal">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="createLocationModalLabel">{{ isEditing ? 'Edit Location' : 'Create New Location' }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form @submit.prevent="saveLocation">
                            <div class="mb-3">
                                <label class="form-label">Location Name</label>
                                <input 
                                    v-model="locationForm.name" 
                                    type="text" 
                                    class="form-control" 
                                    required 
                                />
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Country</label>
                                <select 
                                    v-model="locationForm.country" 
                                    class="form-control" 
                                    required
                                >
                                    <option value="">Select Country</option>
                                    <option value="Australia">Australia</option>
                                    <option value="New Zealand">New Zealand</option>
                                    <option value="Canada">Canada</option>
                                    <option value="USA">USA</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">State (Optional)</label>
                                <input 
                                    v-model="locationForm.state" 
                                    type="text" 
                                    class="form-control" 
                                    placeholder="e.g., MT, VIC"
                                    maxlength="3"
                                    @input="locationForm.state = $event.target.value.toUpperCase()"
                                    style="text-transform: uppercase;"
                                />
                                <small class="text-muted">2-3 letter state abbreviation (e.g., MT, VIC, NSW)</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Category</label>
                                <select 
                                    v-model="locationForm.category" 
                                    class="form-control" 
                                    required
                                >
                                    <option value="">Select Category</option>
                                    <option value="Theatrical">Theatrical</option>
                                    <option value="AE Tour Stop">AE Tour Stop</option>
                                    <option value="Host a Show">Host a Show</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Date</label>
                                <input 
                                    v-model="locationForm.date" 
                                    type="date" 
                                    class="form-control" 
                                    :disabled="noDateYet"
                                    :required="!noDateYet"
                                />
                                <div class="form-check mt-2">
                                    <input 
                                        type="checkbox" 
                                        v-model="noDateYet" 
                                        class="form-check-input" 
                                        id="noDateYet"
                                    />
                                    <label class="form-check-label" for="noDateYet">
                                        No Date yet
                                    </label>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Time</label>
                                <input 
                                    v-model="locationForm.time" 
                                    type="time" 
                                    class="form-control" 
                                    :disabled="noTimeYet"
                                    :required="!noTimeYet"
                                />
                                <div class="form-check mt-2">
                                    <input 
                                        type="checkbox" 
                                        v-model="noTimeYet" 
                                        class="form-check-input" 
                                        id="noTimeYet"
                                    />
                                    <label class="form-check-label" for="noTimeYet">
                                        No Time yet
                                    </label>
                                </div>
                            </div>

                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary">{{ isEditing ? 'Update Location' : 'Save Location' }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Update All Passwords Modal -->
        <div class="modal fade" id="allPasswordsModal" tabindex="-1" aria-labelledby="allPasswordsModalLabel" @hidden.bs.modal="closeAllPasswordsModal">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="allPasswordsModalLabel">Update All Location Passwords</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="text" v-model="allLocationsNewPassword" class="form-control" placeholder="Enter new password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <input type="text" v-model="allLocationsConfirmPassword" class="form-control" placeholder="Confirm new password">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" @click="generatePassword(false)">
                            <i class="fa-solid fa-random"></i> Generate Password
                        </button>
                        <button type="button" class="btn btn-primary" @click="updateAllPasswords">Update All Passwords</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mailchimp Import Modal -->
        <div v-if="showMailchimpModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">Import to Mailchimp</h3>
                    <button @click="showMailchimpModal = false" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                
                <div class="space-y-4">
                    <p class="text-gray-600">
                        Import subscribers from <strong>{{ selectedLocation?.name }}</strong> to Mailchimp
                    </p>

                    <!-- Field Mapping Information -->
                    <div class="mb-4 p-4 bg-gray-50 rounded-lg">
                        <h4 class="font-semibold mb-2">Field Mappings:</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
                            <div><strong>First Name:</strong> FNAME | MERGE1</div>
                            <div><strong>Last Name:</strong> LNAME | MERGE2</div>
                            <div><strong>Email Address:</strong> EMAIL | MERGE0</div>
                            <div><strong>Street Address:</strong> MMERGE10 | MERGE10</div>
                            <div><strong>City:</strong> CITY | MERGE3</div>
                            <div><strong>State:</strong> STATE | MERGE6</div>
                            <div><strong>Zip Code:</strong> ZIPCODE | MERGE7</div>
                            <div><strong>Country:</strong> COUNTRY | MERGE8</div>
                            <div><strong>Mobile Number:</strong> PHONE | MERGE4</div>
                            <div><strong>SMS Phone:</strong> SMSPHONE | MERGE30</div>
                            <div><strong>Age:</strong> MMERGE14 | MERGE14</div>
                            <div><strong>Gender:</strong> GENDER | MERGE17</div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Select Mailchimp Audience
                        </label>
                        <select 
                            v-model="selectedList"
                            class="w-full border rounded px-3 py-2"
                            :disabled="isImporting"
                        >
                            <option value="">Select an audience...</option>
                            <option 
                                v-for="list in mailchimpLists" 
                                :key="list.id" 
                                :value="list.id"
                            >
                                {{ list.name }} ({{ list.stats.member_count }} members)
                            </option>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Add Tags (comma-separated)
                        </label>
                        <input 
                            type="text" 
                            v-model="tags"
                            class="w-full border rounded px-3 py-2"
                            placeholder="e.g., 2025, FILM TOUR - WARREN MILLER, SHOW - MELBOURNE"
                            :disabled="isImporting"
                        />
                        <p class="text-sm text-gray-500 mt-1">
                            Enter tags separated by commas. Each tag will be added to the subscribers.
                        </p>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button 
                            @click="showMailchimpModal = false"
                            class="px-4 py-2 border rounded text-gray-600 hover:bg-gray-50"
                            :disabled="isImporting"
                        >
                            Cancel
                        </button>
                        <button 
                            @click="handleMailchimpImport"
                            class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
                            :disabled="isImporting || !selectedList"
                        >
                            <span v-if="isImporting">
                                <i class="fa-solid fa-spinner fa-spin mr-2"></i>
                                Importing...
                            </span>
                            <span v-else>
                                Import to Mailchimp
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mailchimp Settings Modal -->
        <div class="modal fade" :class="{ 'show': showMailchimpSettingsModal }" :style="{ display: showMailchimpSettingsModal ? 'block' : 'none' }" id="mailchimpSettingsModal" tabindex="-1" aria-labelledby="mailchimpSettingsModalLabel" aria-modal="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="mailchimpSettingsModalLabel">Mailchimp Auto-Sync Settings</h5>
                        <button type="button" class="btn-close" @click="showMailchimpSettingsModal = false" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-4">
                            <label class="form-label">Mailchimp Account</label>
                            <select 
                                v-model="mailchimpSettings.mailchimp_account"
                                class="form-select"
                                @change="loadListsForAccount(mailchimpSettings.mailchimp_account)"
                            >
                                <option 
                                    v-for="(account, key) in availableAccounts" 
                                    :key="key" 
                                    :value="key"
                                    :disabled="!account.enabled"
                                >
                                    {{ account.name }} {{ !account.enabled ? '(Not Configured)' : '' }}
                                </option>
                            </select>
                            <div class="form-text">
                                Select which Mailchimp account to use for auto-sync.
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Auto-Sync</label>
                            <div class="form-check">
                                <input 
                                    type="checkbox" 
                                    v-model="mailchimpSettings.auto_sync"
                                    class="form-check-input"
                                    id="autoSyncCheck"
                                >
                                <label class="form-check-label" for="autoSyncCheck">Enable automatic synchronization</label>
                            </div>
                        </div>

                        <div class="mb-4" v-if="mailchimpSettings.auto_sync">
                            <label class="form-label">Default Mailchimp Audience</label>
                            <select 
                                v-model="mailchimpSettings.default_list_id"
                                class="form-select"
                                :class="{ 'is-invalid': !mailchimpSettings.default_list_id && mailchimpSettings.auto_sync }"
                            >
                                <option value="">Select an audience...</option>
                                <option 
                                    v-for="list in availableLists" 
                                    :key="list.id" 
                                    :value="list.id"
                                >
                                    {{ list.name }} ({{ list.stats.member_count }} members)
                                </option>
                            </select>
                            <div v-if="availableLists.length === 0" class="form-text text-warning">
                                <i class="fa-solid fa-exclamation-triangle me-1"></i>
                                No audiences found for this account. Please check your API configuration or select a different account.
                            </div>
                            <div v-if="!mailchimpSettings.default_list_id && mailchimpSettings.auto_sync" class="invalid-feedback">
                                Please select a Mailchimp audience when auto-sync is enabled.
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Film Tour Code</label>
                            <input 
                                type="text" 
                                v-model="mailchimpSettings.film_tour"
                                class="form-control"
                                placeholder="e.g., WM, RUNNATION, etc."
                                required
                            >
                            <div class="form-text">
                                Enter the film tour code (e.g., WM for Warren Miller, RUNNATION). This will be used in the SOURCE tag.
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Default Tags</label>
                            <input 
                                type="text" 
                                v-model="mailchimpSettings.default_tags"
                                class="form-control"
                                placeholder="e.g., 2025, FILM TOUR - WARREN MILLER, SHOW - MELBOURNE"
                            >
                            <div class="form-text">
                                Enter tags separated by commas. Each tag will be added to the subscribers.
                            </div>
                        </div>

                        <!-- Tag Preview Section -->
                        <!-- <div class="mb-4" v-if="mailchimpSettings.film_tour">
                            <label class="form-label">Tag Preview</label>
                            <div class="p-3 bg-light rounded">
                                <div class="mb-2">
                                    <strong>Sample tags that will be automatically generated:</strong>
                                </div>
                                <div class="row">
                                    <div class="col-md-6" v-for="(preview, index) in tagPreview" :key="index">
                                        <div class="mb-3 p-2 border rounded">
                                            <div class="fw-bold text-primary mb-1">{{ preview.location }}</div>
                                            <div class="mb-1">
                                                <span class="badge bg-success me-1">{{ preview.showTag }}</span>
                                            </div>
                                            <div class="mb-1">
                                                <span class="badge bg-info">{{ preview.sourceTag }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-muted small">
                                    <i class="fa-solid fa-info-circle me-1"></i>
                                    These tags will be automatically added to subscribers based on their selected location.
                                </div>
                            </div>
                        </div> -->
                    </div>
                    <div class="modal-footer">
                        <button 
                            type="button"
                            class="btn btn-secondary" 
                            @click="showMailchimpSettingsModal = false"
                        >
                            Cancel
                        </button>
                        <button 
                            type="button"
                            class="btn btn-primary"
                            @click="saveMailchimpSettings"
                        >
                            Save Settings
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div v-if="showMailchimpSettingsModal" class="modal-backdrop fade show"></div>

        <!-- Eventbrite Import Modal -->
        <div v-if="showEventbriteModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">Import Ticket Data</h3>
                    <button @click="closeEventbriteModal" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                
                <div class="space-y-4">
                    <p class="text-gray-600">
                        Import Ticket Data for <strong>{{ selectedLocation?.name }}</strong>
                    </p>

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
                            Paste the Eventbrite event URL. The system will extract the event ID and fetch all attendees.
                        </p>
                    </div>

                    <div class="flex justify-end space-x-3 mb-4">
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

                    <!-- Attendees List -->
                    <div v-if="eventbriteAttendees.length > 0" class="mt-6">
                        <div class="flex justify-between items-center mb-4">
                            <h4 class="text-md font-semibold">
                                Found {{ eventbriteAttendees.length }} unique attendee(s)
                            </h4>
                            <div class="flex gap-2">
                                <button 
                                    @click="exportEventbriteAttendees"
                                    class="px-4 py-2 bg-green-500 text-white rounded hover:bg-green-600"
                                    :disabled="isImportingEventbrite"
                                >
                                    <i class="fa-solid fa-file-export mr-2"></i>
                                    Export CSV
                                </button>
                                <button 
                                    @click="openEventbriteMailchimpModal"
                                    class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
                                    :disabled="isImportingEventbrite"
                                >
                                    <i class="fa-solid fa-upload mr-2"></i>
                                    Import to Mailchimp
                                </button>
                            </div>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">First Name</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Last Name</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">City</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">State</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Country</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="(attendee, index) in eventbriteAttendees" :key="index" class="hover:bg-gray-50">
                                        <td class="px-4 py-2 text-sm">{{ attendee.email || '-' }}</td>
                                        <td class="px-4 py-2 text-sm">{{ attendee.first_name || '-' }}</td>
                                        <td class="px-4 py-2 text-sm">{{ attendee.last_name || '-' }}</td>
                                        <td class="px-4 py-2 text-sm">{{ attendee.phone || '-' }}</td>
                                        <td class="px-4 py-2 text-sm">{{ attendee.city || '-' }}</td>
                                        <td class="px-4 py-2 text-sm">{{ attendee.state || '-' }}</td>
                                        <td class="px-4 py-2 text-sm">{{ attendee.country || '-' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Eventbrite Mailchimp Import Modal -->
        <div v-if="showEventbriteMailchimpModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">Import Eventbrite Attendees to Mailchimp</h3>
                    <button @click="closeEventbriteMailchimpModal" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                
                <div class="space-y-4">
                    <p class="text-gray-600">
                        Import <strong>{{ eventbriteAttendees.length }}</strong> attendees from Eventbrite for <strong>{{ selectedLocation?.name }}</strong>
                    </p>

                    <!-- Mailchimp Account Selection -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Mailchimp Account
                        </label>
                        <select 
                            v-model="eventbriteMailchimpAccount"
                            @change="loadEventbriteLists(eventbriteMailchimpAccount)"
                            class="w-full border rounded px-3 py-2"
                            :disabled="isLoadingEventbriteLists"
                        >
                            <option value="">Select an account...</option>
                            <option 
                                v-for="(account, key) in eventbriteAvailableAccounts" 
                                :key="key" 
                                :value="key"
                                :disabled="!account.enabled"
                            >
                                {{ account.name }} {{ !account.enabled ? '(Not Configured)' : '' }}
                            </option>
                        </select>
                    </div>

                    <!-- Mailchimp Audience Selection -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Mailchimp Audience
                        </label>
                        <select 
                            v-model="eventbriteSelectedList"
                            class="w-full border rounded px-3 py-2"
                            :disabled="isLoadingEventbriteLists || !eventbriteMailchimpAccount"
                        >
                            <option value="">Select an audience...</option>
                            <option 
                                v-for="list in eventbriteMailchimpLists" 
                                :key="list.id" 
                                :value="list.id"
                            >
                                {{ list.name }} ({{ list.stats.member_count }} members)
                            </option>
                        </select>
                        <div v-if="isLoadingEventbriteLists" class="mt-2 text-sm text-gray-500">
                            <i class="fa-solid fa-spinner fa-spin mr-1"></i>
                            Loading audiences...
                        </div>
                    </div>

                    <!-- Preview of Columns to Import -->
                    <div v-if="eventbriteAttendees.length > 0" class="mb-4">
                        <h4 class="text-md font-semibold mb-2">Preview - Columns to Import</h4>
                        <div class="bg-gray-50 p-4 rounded-lg mb-3">
                            <p class="text-sm text-gray-600 mb-2">The following columns will be imported:</p>
                            <div class="flex flex-wrap gap-2">
                                <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded text-sm font-medium">Email</span>
                                <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded text-sm font-medium">First Name</span>
                                <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded text-sm font-medium">Last Name</span>
                                <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded text-sm font-medium">Phone Number</span>
                            </div>
                        </div>
                        
                        <div class="bg-white border rounded-lg overflow-hidden">
                            <div class="max-h-64 overflow-y-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50 sticky top-0">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">First Name</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Last Name</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <tr v-for="(attendee, index) in eventbriteAttendees.slice(0, 10)" :key="index" class="hover:bg-gray-50">
                                            <td class="px-4 py-2 text-sm">{{ attendee.email || '-' }}</td>
                                            <td class="px-4 py-2 text-sm">{{ attendee.first_name || '-' }}</td>
                                            <td class="px-4 py-2 text-sm">{{ attendee.last_name || '-' }}</td>
                                            <td class="px-4 py-2 text-sm">{{ attendee.phone || '-' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-if="eventbriteAttendees.length > 10" class="px-4 py-2 bg-gray-50 text-sm text-gray-600 border-t">
                                Showing first 10 of {{ eventbriteAttendees.length }} attendees
                            </div>
                        </div>
                    </div>

                    <!-- Tags Section -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Tags to Apply
                        </label>
                        <div class="bg-gray-50 p-4 rounded-lg mb-3">
                            <p class="text-sm text-gray-600 mb-2">Default tags (editable):</p>
                            <div class="flex flex-wrap gap-2 mb-3">
                                <span 
                                    v-for="(tag, index) in eventbriteDefaultTags" 
                                    :key="index"
                                    class="px-3 py-1 bg-blue-100 text-blue-800 rounded text-sm font-medium"
                                >
                                    {{ tag }}
                                </span>
                            </div>
                        </div>
                        <input 
                            type="text" 
                            v-model="eventbriteTags"
                            class="w-full border rounded px-3 py-2"
                            placeholder="SHOW - LOCATION, SOURCE - WM LOCATION COMP 2025, EVENTBRITE, ..."
                        />
                        <p class="text-sm text-gray-500 mt-1">
                            Edit tags separated by commas. Default tags are pre-filled but you can add or modify them.
                        </p>
                    </div>

                    <div class="flex justify-end space-x-3 mt-6">
                        <button 
                            @click="closeEventbriteMailchimpModal"
                            class="px-4 py-2 border rounded text-gray-600 hover:bg-gray-50"
                            :disabled="isImportingEventbrite"
                        >
                            Cancel
                        </button>
                        <button 
                            @click="importEventbriteToMailchimp"
                            class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
                            :disabled="isImportingEventbrite || !eventbriteSelectedList || !eventbriteMailchimpAccount"
                        >
                            <span v-if="isImportingEventbrite">
                                <i class="fa-solid fa-spinner fa-spin mr-2"></i>
                                Importing...
                            </span>
                            <span v-else>
                                <i class="fa-solid fa-upload mr-2"></i>
                                Import to Mailchimp
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Import All Data Modal: step 1 = choose type (ticket or win form), step 2 = type-specific form -->
        <div v-if="showImportAllModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto" @click.stop role="dialog" aria-modal="true">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">{{ importAllDataMode ? (importAllDataMode === 'ticket' ? 'Import All – Ticket data' : 'Import All – Win form data') : 'Import All Data' }}</h3>
                    <button type="button" @click="importAllDataMode ? (importAllDataMode = '') : closeImportAllModal()" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>

                <!-- Step 1: Choose what to import (ticket or win form only – not both in one run) -->
                <div v-if="!importAllDataMode" class="py-6">
                    <p class="text-gray-600 text-sm mb-6">Choose what data to import. Each run imports <strong>one type only</strong> so the flow stays simple. Locations with no data are skipped and not logged in MC Logs.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <button
                            type="button"
                            @click="importAllDataMode = 'ticket'"
                            class="p-6 border-2 border-teal-200 rounded-lg text-left hover:border-teal-500 hover:bg-teal-50 transition-colors"
                        >
                            <div class="font-semibold text-gray-800 mb-2"><i class="fa-solid fa-ticket mr-2 text-teal-600"></i> Ticket data</div>
                            <p class="text-sm text-gray-600">Import Eventbrite/CSV ticket attendees per location. Add links or CSV, then set tags and run.</p>
                        </button>
                        <button
                            type="button"
                            @click="importAllDataMode = 'form'"
                            class="p-6 border-2 border-amber-200 rounded-lg text-left hover:border-amber-500 hover:bg-amber-50 transition-colors"
                        >
                            <div class="font-semibold text-gray-800 mb-2"><i class="fa-solid fa-clipboard-list mr-2 text-amber-600"></i> Win form data</div>
                            <p class="text-sm text-gray-600">Import sign-up (win) form data per location. Set tags per location and run.</p>
                        </button>
                    </div>
                </div>

                <!-- Step 2: Type-specific form (ticket only or win form only) -->
                <template v-else>
                <div class="flex items-center gap-2 mb-4">
                    <button type="button" @click="importAllDataMode = ''" class="text-sm text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-arrow-left mr-1"></i> Back
                    </button>
                </div>
                <p class="text-gray-600 text-sm mb-4">
                    <template v-if="importAllDataMode === 'ticket'">Import <strong>ticket data only</strong>. Add Eventbrite link or CSV per location, set tags, then run. Locations with no ticket data are skipped (not logged).</template>
                    <template v-else>Import <strong>win form data only</strong>. Set tags per location, then run. Locations with no form data are skipped (not logged).</template>
                </p>

                <!-- Year used in SOURCE tag -->
                <div class="mb-4 p-4 bg-amber-50 border border-amber-200 rounded-lg">
                    <label class="block text-sm font-medium text-gray-800 mb-2">Year (for SOURCE tag)</label>
                    <input
                        v-model.number="importAllSourceYear"
                        type="number"
                        min="2020"
                        max="2035"
                        step="1"
                        class="w-24 border border-gray-300 rounded px-3 py-2"
                    />
                    <p class="text-xs text-gray-600 mt-1">This year is used in SOURCE tags (e.g. <code>SOURCE - WM MELBOURNE COMP 2025</code>). Defaults to this event's year (from event settings). Changing it updates the year in all per-location tags below.</p>
                </div>

                <!-- Default tags (applied to all locations) -->
                <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                    <label class="block text-sm font-medium text-gray-800 mb-2">Default tags (applied to every location)</label>
                    <input
                        v-model="importAllDefaultTags"
                        type="text"
                        class="w-full border rounded px-3 py-2"
                        placeholder="e.g. 2025 Tour; Film Name; SHOW - Mammoth, LA"
                    />
                    <p class="text-xs text-gray-600 mt-1">Separate with semicolon (;). These tags are added to every location when you import.</p>
                </div>

                <!-- Per-location: ticket-only fields (when ticket mode) or form-only fields (when form mode) -->
                <div class="space-y-4 mb-6">
                    <div v-for="loc in locations" :key="loc.id" class="border rounded-lg p-4 bg-gray-50 space-y-4">
                        <div class="flex flex-wrap items-center gap-4">
                            <span class="font-medium">{{ loc.name }}</span>
                            <span v-if="importAllDataMode === 'ticket' && stagedByLocation[loc.id]?.attendees?.length" class="text-sm text-green-600 font-medium">
                                {{ stagedByLocation[loc.id].attendees.length }} ticket attendees staged
                            </span>
                            <span v-else-if="importAllDataMode === 'ticket'" class="text-xs text-gray-500">No ticket data (add Eventbrite/CSV below)</span>
                        </div>

                        <!-- Ticket data: Eventbrite or CSV (only when ticket mode) -->
                        <div v-if="importAllDataMode === 'ticket'">
                            <label class="block text-xs font-medium text-gray-600 mb-2">Ticket data (optional)</label>
                            <div class="flex flex-wrap gap-3 items-end">
                                <div class="flex-1 min-w-[200px]">
                                    <input
                                        type="text"
                                        :value="importAllEventbriteLinkByLocation[loc.id] || ''"
                                        @input="importAllEventbriteLinkByLocation = { ...importAllEventbriteLinkByLocation, [loc.id]: $event.target.value }"
                                        class="w-full border rounded px-2 py-1.5 text-sm"
                                        placeholder="Eventbrite link or upload CSV"
                                    />
                                </div>
                                <button
                                    @click="fetchPreviewForLocation(loc.id, importAllEventbriteLinkByLocation[loc.id] || '')"
                                    :disabled="!importAllEventbriteLinkByLocation[loc.id]?.trim() || isFetchingPreviewByLocation[loc.id]"
                                    class="px-3 py-1.5 bg-orange-500 text-white rounded text-sm hover:bg-orange-600 disabled:opacity-50"
                                >
                                    <span v-if="isFetchingPreviewByLocation[loc.id]"><i class="fa-solid fa-spinner fa-spin mr-1"></i> Fetch</span>
                                    <span v-else>Fetch</span>
                                </button>
                                <div class="flex items-center gap-2">
                                    <label class="text-xs text-gray-500">or CSV</label>
                                    <input
                                        type="file"
                                        accept=".csv,.txt"
                                        class="text-sm"
                                        @change="(e) => { const f = e.target.files?.[0]; if (f) parseCsvForLocation(loc.id, f); e.target.value = ''; }"
                                    />
                                </div>
                                <button
                                    v-if="stagedByLocation[loc.id]?.attendees?.length"
                                    @click="removeStagedForLocation(loc.id)"
                                    class="text-red-600 text-sm hover:underline"
                                >
                                    Clear ticket data
                                </button>
                            </div>
                        </div>

                        <!-- Tags for ticket data (this location) – only when ticket mode -->
                        <div v-if="importAllDataMode === 'ticket'">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Tags for ticket data (this location)</label>
                            <div class="mb-2 px-2.5 py-1.5 rounded bg-teal-50 border border-teal-200 text-sm">
                                <span class="text-gray-600">Country tag (auto-applied):</span>
                                <span v-if="(loc.country || '').trim()" class="ml-1 font-semibold text-teal-800">COUNTRY - {{ (loc.country || '').trim().toUpperCase() }}</span>
                                <span v-else class="ml-1 text-amber-600">Not set—edit this location to add a country for COUNTRY - USA, etc.</span>
                            </div>
                            <input
                                type="text"
                                :value="importAllTagsByLocation[loc.id] || ''"
                                @input="importAllTagsByLocation = { ...importAllTagsByLocation, [loc.id]: $event.target.value }"
                                class="w-full border rounded px-2 py-1.5 text-sm"
                                placeholder="SHOW - LOC; SOURCE - WM LOC TIX 2025"
                            />
                            <p class="text-xs text-gray-500 mt-1">Separate with ; . These are added on top of the default tags above.</p>
                        </div>

                        <!-- Tags for form data (this location) – only when form mode -->
                        <div v-if="importAllDataMode === 'form'">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Tags for form data (this location)</label>
                            <input
                                type="text"
                                :value="importAllFormTagsByLocation[loc.id] || ''"
                                @input="importAllFormTagsByLocation = { ...importAllFormTagsByLocation, [loc.id]: $event.target.value }"
                                class="w-full border rounded px-2 py-1.5 text-sm bg-white"
                                placeholder="SHOW - LOC; SOURCE - WM LOC COMP 2025"
                            />
                            <p class="text-xs text-gray-500 mt-1">Separate with ; . These are added on top of the default tags above.</p>
                        </div>
                    </div>
                </div>

                <!-- Mailchimp account & list -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Mailchimp account</label>
                        <select v-model="importAllAccount" class="w-full border rounded px-3 py-2">
                            <option value="">Select...</option>
                            <option v-for="(acc, key) in importAllAccounts" :key="key" :value="key" :disabled="!acc?.enabled">
                                {{ acc?.name || key }} {{ !acc?.enabled ? '(Not configured)' : '' }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Mailchimp audience</label>
                        <select v-model="importAllListId" class="w-full border rounded px-3 py-2">
                            <option value="">Select...</option>
                            <option v-for="list in importAllLists" :key="list.id" :value="list.id">
                                {{ list.name }} ({{ list.stats?.member_count ?? 0 }} members)
                            </option>
                        </select>
                    </div>
                </div>

                <!-- Field mapping: audience columns -> our data -->
                <div class="mb-4 p-4 bg-purple-50 border border-purple-200 rounded-lg">
                    <button
                        type="button"
                        @click="showMappingSection = !showMappingSection"
                        class="flex items-center gap-2 w-full text-left text-sm font-medium text-gray-800"
                    >
                        <i :class="showMappingSection ? 'fa-solid fa-chevron-down' : 'fa-solid fa-chevron-right'" class="text-purple-600"></i>
                        Field mapping: map our data to Mailchimp audience columns
                    </button>
                    <p v-if="!showMappingSection" class="text-xs text-gray-600 mt-1 ml-6">Map our sign-up/ticket columns to each Mailchimp field. For <strong>Adventure Entertainment Newsletter (ANZ)</strong>, use <strong>Address (concatenated)</strong> for ADDRESSWIN or MMERGE10.</p>
                    <div v-else class="mt-4">
                        <p class="text-xs text-gray-600 mb-3">Map each Mailchimp audience column to our sign-up/ticket data. <strong>Address (concatenated)</strong> combines street, city, state, zip, country. Validation info shows type and allowed values.</p>
                        <div v-if="isLoadingMergeFields" class="text-sm text-gray-500 py-4"><i class="fa-solid fa-spinner fa-spin mr-2"></i> Loading audience fields...</div>
                        <div v-else-if="!importAllListId" class="text-sm text-gray-500 py-2">Select a Mailchimp audience above to load columns.</div>
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
                                        <td class="border border-purple-200 p-2 text-xs text-gray-700 font-mono max-w-[200px] truncate" :title="getImportAllMappingPreviewValue('EMAIL')">
                                            {{ getImportAllMappingPreviewValue('EMAIL') }}
                                        </td>
                                    </tr>
                                    <tr v-for="mf in importAllMergeFieldsWithValidation" :key="mf.tag" class="bg-white">
                                        <td class="border border-purple-200 p-2 font-medium">{{ mf.name || mf.tag }}</td>
                                        <td class="border border-purple-200 p-2">
                                            <select
                                                :value="importAllFieldMapping[mf.tag]"
                                                @change="importAllFieldMapping = { ...importAllFieldMapping, [mf.tag]: $event.target.value }"
                                                class="w-full border rounded px-2 py-1 text-sm"
                                            >
                                                <option value="">— Don't map</option>
                                                <option v-for="sc in importAllSourceColumns" :key="sc.key" :value="sc.key">{{ sc.label }}</option>
                                            </select>
                                        </td>
                                        <td class="border border-purple-200 p-2 text-xs text-gray-600">
                                            <span v-if="mf.validation">{{ mf.validation.type }}{{ mf.validation.required ? ', required' : '' }}</span>
                                            <span v-if="mf.validation?.choices" class="block mt-1">Allowed: {{ mf.validation.choices.slice(0, 5).join(', ') }}{{ mf.validation.choices.length > 5 ? '…' : '' }}</span>
                                        </td>
                                        <td class="border border-purple-200 p-2 text-xs text-gray-700 font-mono max-w-[200px] truncate" :title="getImportAllMappingPreviewValue(mf.tag, importAllFieldMapping[mf.tag])">
                                            {{ getImportAllMappingPreviewValue(mf.tag, importAllFieldMapping[mf.tag]) }}
                                        </td>
                                    </tr>
                                    <tr v-if="importAllMergeFieldsWithValidation.length === 0">
                                        <td colspan="4" class="border border-purple-200 p-4 text-gray-500 text-center">No merge fields loaded. Select an audience and try again.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Skip already imported -->
                <div class="mb-4 p-3 bg-gray-50 border border-gray-200 rounded-lg">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            v-model="importAllSkipAlreadyImported"
                            class="w-4 h-4 text-teal-600 border-gray-300 rounded focus:ring-teal-500"
                        />
                        <span class="text-sm font-medium text-gray-800">Skip locations already imported to this audience</span>
                    </label>
                    <p class="text-xs text-gray-600 mt-1 ml-6">When checked, only locations that have not been imported to this Mailchimp audience will be included. When unchecked, all locations are re-imported.</p>
                </div>

                <!-- Total contacts to be imported (ticket or form only) -->
                <div class="mb-4 p-4 bg-gray-100 border border-gray-200 rounded-lg">
                    <div class="text-sm font-medium text-gray-800 mb-1">Total contacts to be imported</div>
                    <div class="text-lg font-semibold text-gray-900">{{ importAllDataMode === 'ticket' ? totalStagedCount : importPreviewFormTotal }}</div>
                    <div class="text-xs text-gray-600 mt-1">
                        <template v-if="importAllDataMode === 'ticket'">{{ totalStagedCount }} ticket (add Eventbrite/CSV per location)</template>
                        <template v-else>{{ importPreviewFormTotal }} win form</template>
                    </div>
                </div>

                <div class="flex justify-between items-center pt-4 border-t">
                    <div class="text-sm text-gray-600">
                        <span>{{ importAllDataMode === 'ticket' ? 'Ticket data only. Locations with no staged ticket data are skipped (not logged).' : 'Win form data only. Locations with no form data are skipped (not logged).' }}</span>
                        <span v-if="!importAllListId || !importAllAccount" class="block mt-1 text-amber-600">
                            To enable Import All: {{ !importAllAccount ? 'select Mailchimp account' : '' }}{{ !importAllAccount && !importAllListId ? ' and ' : '' }}{{ !importAllListId ? 'select Mailchimp audience' : '' }}.
                        </span>
                    </div>
                    <div class="flex gap-3">
                        <button type="button" @click="closeImportAllModal" class="px-4 py-2 border rounded text-gray-600 hover:bg-gray-50">Cancel</button>
                        <button
                            type="button"
                            @click="runImportAll"
                            :disabled="!importAllListId || !importAllAccount || isImportAllLoading"
                            class="px-4 py-2 bg-teal-600 text-white rounded hover:bg-teal-700 disabled:opacity-50"
                            :title="!importAllAccount ? 'Select Mailchimp account' : !importAllListId ? 'Select Mailchimp audience' : 'Import staged ticket data to Mailchimp (ticket tags only where there is ticket data)'"
                        >
                            <span v-if="isImportAllLoading"><i class="fa-solid fa-spinner fa-spin mr-2"></i> Importing...</span>
                            <span v-else><i class="fa-solid fa-upload mr-2"></i> Import All</span>
                        </button>
                    </div>
                </div>
                </template>
            </div>
        </div>

    </AuthenticatedLayout>
</template>
