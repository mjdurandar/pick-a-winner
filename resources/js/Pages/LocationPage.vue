<script setup>
import { ref, computed, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';
import Swal from 'sweetalert2';
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';

// ✅ Define Props to Receive Locations from Backend
const props = defineProps({
    event: Object,
    locations: Array,
    total_participants: { type: Number, default: 0 },
    fave_sport_options: { type: Array, default: () => [] },
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
const showMailchimpSettingsModal = ref(false);
const mailchimpSettings = ref({
    auto_sync: false,
    default_list_id: '',
    default_tags: '',
    enabled_locations: [],
    film_tour: '',  // Default to WM
    mailchimp_account: 'anz', // Default to ANZ
    interest_tag_map: {},
    event_id: props.event.id  // Add event_id
});
const availableLists = ref([]);
const availableAccounts = ref([]);
const isSettingsLoading = ref(false);
const selectedCountry = ref(null);
const selectedCategory = ref('');

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
// Helper function to sort locations by date, then time
// Date is the primary key: a TBA/unparseable time only affects ordering
// within the same day, it never pushes a location past a later date.
const dateKey = (location) => {
    if (!location.date || location.date === 'TBA') return null;
    const parsed = Date.parse(`${location.date}T00:00:00`);
    return isNaN(parsed) ? null : parsed;
};

const timeKey = (location) => {
    if (!location.time || location.time === 'TBA') return null;
    const match = /^(\d{1,2}):(\d{2})/.exec(location.time);
    return match ? Number(match[1]) * 60 + Number(match[2]) : null;
};

const sortLocationsByDateTime = (locations) => {
    // Copy first so we never sort props.locations in place
    return [...locations].sort((a, b) => {
        const dateA = dateKey(a);
        const dateB = dateKey(b);

        // Locations with no usable date (TBA) go at the end
        if (dateA === null && dateB === null) return 0;
        if (dateA === null) return 1;
        if (dateB === null) return -1;
        if (dateA !== dateB) return dateA - dateB;

        // Same date - order by time, TBA times last within that day
        const timeA = timeKey(a);
        const timeB = timeKey(b);
        if (timeA === null && timeB === null) return 0;
        if (timeA === null) return 1;
        if (timeB === null) return -1;
        return timeA - timeB;
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

// The resubscribe report exposes contact emails across every location, so it is
// admin-only — the route rejects hosts and the button is hidden from them.
const isAdmin = computed(() => usePage().props.auth?.user?.role === 'admin');

const goToResubscribeReport = () => {
    router.get(route('newsletterResubscribes.index', { event: props.event.id }));
};

const viewLocationAttendees = (location) => {
    router.get(route('attendees.location', { eventId: props.event.id, locationId: location.id }));
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
        // 'TBA' is not a value a date/time input can hold - it would be silently
        // dropped and reappear as a blank field. Leave the input empty and let the
        // checkbox below carry the TBA state instead.
        locationForm.date = location.date === 'TBA' ? '' : location.date;
        locationForm.time = location.time === 'TBA' ? '' : location.time;
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

// Default Mailchimp tag for a given "Favorite adventure sport?" answer.
// Matched by lowercase prefix so option labels like
// "Snow Sports (Skiing, Snowboarding)" still map to INT - SNOWSPORTS.
// Kept in sync with the PHP fallback in MailchimpService::mapFaveSportToInterestTags.
const DEFAULT_INTEREST_TAG_MAP = [
    { prefix: 'snow sports', tag: 'INT - SNOWSPORTS' },
    { prefix: 'climbing', tag: 'INT - CLIMBING' },
    { prefix: 'running', tag: 'INT - RUNNING' },
    { prefix: 'trail sports', tag: 'INT - TRAILSPORTS, INT - RUNNING' },
    { prefix: 'skate sports', tag: 'INT - SKATEBOARDING' },
    { prefix: 'cycling', tag: 'INT - MTB' },
    { prefix: 'water sports', tag: 'INT - WATERSPORTS' },
    { prefix: 'outdoor', tag: 'INT - OUTDOOR' },
    { prefix: 'aerial', tag: 'INT - OUTDOOR, INT - ENVIRONMENT' },
    { prefix: 'extreme', tag: 'INT - OUTDOOR, INT - ENVIRONMENT' },
    { prefix: 'other', tag: 'INT - ALL' },
];

const defaultInterestTagFor = (option) => {
    const v = typeof option === 'string' ? option.trim().toLowerCase() : '';
    if (!v) return '';
    for (const { prefix, tag } of DEFAULT_INTEREST_TAG_MAP) {
        if (v.startsWith(prefix)) return tag;
    }
    return '';
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

        const savedMap = (settings && typeof settings.interest_tag_map === 'object' && settings.interest_tag_map !== null)
            ? settings.interest_tag_map
            : {};
        const seededMap = {};
        (props.fave_sport_options || []).forEach((opt) => {
            const saved = typeof savedMap[opt] === 'string' ? savedMap[opt].trim() : '';
            seededMap[opt] = saved !== '' ? saved : defaultInterestTagFor(opt);
        });

        mailchimpSettings.value = {
            ...settings,
            film_tour: settings.film_tour,
            default_tags: Array.isArray(settings.default_tags) ? settings.default_tags.join(', ') : '',
            mailchimp_account: settings.mailchimp_account || 'anz',
            interest_tag_map: seededMap,
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
        const rawMap = mailchimpSettings.value.interest_tag_map || {};
        const cleanedMap = {};
        Object.keys(rawMap).forEach((key) => {
            const val = typeof rawMap[key] === 'string' ? rawMap[key].trim() : '';
            if (val !== '') {
                cleanedMap[key] = val;
            }
        });

        const settings = {
            ...mailchimpSettings.value,
            film_tour: mailchimpSettings.value.film_tour,
            default_tags: mailchimpSettings.value.default_tags.split(',').map(tag => tag.trim()).filter(tag => tag),
            enabled_locations: Array.isArray(mailchimpSettings.value.enabled_locations)
                ? mailchimpSettings.value.enabled_locations
                : [],
            mailchimp_account: mailchimpSettings.value.mailchimp_account,
            interest_tag_map: cleanedMap,
            event_id: props.event.id,
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
                                            style="background-color: #17a2b8; color: white; border-radius: 5px; padding: 6px 14px; font-size: 14px; cursor: pointer;"
                                            @click="exportSelectedLocations"
                                            :title="`Export ${selectedLocationsForExport.length} selected location(s)`"
                                        >
                                            <i class="fa-solid fa-file-export"></i> Export ({{ selectedLocationsForExport.length }})
                                        </button>
                                    </div>
                                </div>
                                <!-- Database + Resubscribes - Right -->
                                <div class="ml-auto flex items-center gap-2">
                                    <button
                                        @click="goToAttendeesPage"
                                        style="background-color: #16C3D9; color: white; border-radius: 5px; padding: 6px 14px; font-size: 14px; cursor: pointer;"
                                        title="View Attendees Database"
                                    >
                                        <i class="fa-solid fa-database"></i> Database
                                    </button>
                                    <button
                                        v-if="isAdmin"
                                        @click="goToResubscribeReport"
                                        style="background-color: #7C3AED; color: white; border-radius: 5px; padding: 6px 14px; font-size: 14px; cursor: pointer;"
                                        title="Newsletter resubscribes: who came back and who Mailchimp blocked"
                                    >
                                        <i class="fa-solid fa-rotate"></i> Resubscribes
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

                        <div class="mb-4">
                            <label class="form-label">Interest Tags (by adventure sport)</label>
                            <div class="form-text mb-2">
                                Choose the tag to apply for each answer to the "Favorite adventure sport?" question.
                                Leave a field empty to skip tagging for that answer.
                            </div>
                            <div v-if="!fave_sport_options || fave_sport_options.length === 0" class="text-muted small">
                                The sign-up form for this event has no "Favorite adventure sport?" question, so there are no answers to map.
                            </div>
                            <div v-else class="border rounded p-2">
                                <div
                                    v-for="option in fave_sport_options"
                                    :key="option"
                                    class="d-flex align-items-center gap-2 mb-2"
                                >
                                    <div class="flex-grow-1 small text-break">{{ option }}</div>
                                    <input
                                        type="text"
                                        class="form-control form-control-sm"
                                        style="max-width: 260px;"
                                        :placeholder="'e.g., INT - SNOWSPORTS'"
                                        v-model="mailchimpSettings.interest_tag_map[option]"
                                    />
                                </div>
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

    </AuthenticatedLayout>
</template>
