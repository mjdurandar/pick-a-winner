<script setup>
import { ref, computed, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { stateInName, stripState } from '@/locationTag';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';
import Swal from 'sweetalert2';
import { useForm } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';

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
const showLocationModal = ref(false);
const showAllPasswordsModal = ref(false);
const isEditing = ref(false);
const selectedLocation = ref(null);
const selectedLocationsForExport = ref([]);
const newPassword = ref('');
const confirmPassword = ref('');
const allLocationsNewPassword = ref('');
const allLocationsConfirmPassword = ref('');
const selectedCountry = ref(null);
const selectedCategory = ref('');

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

/**
 * Screenings nobody has given a start time.
 *
 * The Time column is often left blank on the master sheet, and locations.time
 * is NOT NULL, so those are stored as 'TBA'. Hosts cannot see a start time and
 * the film sites will not list them, which is worth saying on the page rather
 * than leaving to be noticed while scrolling.
 */
const missingTime = computed(() => (props.locations ?? [])
    .filter((l) => !l.time || String(l.time).trim().toUpperCase() === 'TBA'));

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
};

const generatePassword = () => {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let password = '';
    for (let i = 0; i < 8; i++) {
        password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    newPassword.value = password;
    confirmPassword.value = password;
    allLocationsNewPassword.value = password;
    allLocationsConfirmPassword.value = password;
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
    
    // Only a real abbreviation counts. Matching any trailing 2-3 letter word
    // pulled "Bay" out of "Byron Bay" as the state, and saving the form then
    // wrote the truncated name back to the location.
    return stateInName(locationPart);
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
    
    // Same rule as extractStateFromName: a real abbreviation, not any short word.
    locationPart = stripState(locationPart);
    
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
};

// Add function to handle password modal close
const closePasswordModal = () => {
    showPasswordModal.value = false;
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

        // Close the panel first
        showAllPasswordsModal.value = false;
        
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
};

const closeAllPasswordsModal = () => {
    showAllPasswordsModal.value = false;
    allLocationsNewPassword.value = '';
    allLocationsConfirmPassword.value = '';
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


</script>

<template>
    <Head title="Pick a Winner Page" />

    <AuthenticatedLayout>
        <!-- The page header carries the name and the actions, so the body can
             get on with the list. Every button says what it does: the gear and
             the key used to be icons with nothing but a tooltip. -->
        <template #header>
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-semibold text-gray-500">
                        {{ locations?.length ?? 0 }} screening{{ (locations?.length ?? 0) === 1 ? '' : 's' }} ·
                        {{ total_participants }} sign-up{{ total_participants === 1 ? '' : 's' }}
                    </p>
                    <h2 class="truncate text-2xl font-extrabold tracking-tight text-gray-900">{{ event.event_name }}</h2>
                </div>

                <Link
                    :href="route('location.mailchimpSync', { eventId: event.id })"
                    class="flex h-10 items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                >
                    <Icon name="gear" /> Mailchimp sync
                </Link>
                <button
                    type="button" @click="openAllPasswordsModal"
                    class="flex h-10 items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                >
                    <Icon name="key" /> Set all passwords
                </button>
                <!-- National Tour Wide draw — account-only, so it lives here
                     rather than on the public login. -->
                <button
                    type="button" @click="allLocationsPage"
                    class="flex h-10 items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                    title="Draw a winner from every location on the tour"
                >
                    <Icon name="trophy" /> Tour-wide draw
                </button>
                <button
                    type="button" @click="openLocationModal()"
                    class="flex h-10 items-center gap-2 rounded-lg bg-[#16C3D9] px-4 text-sm font-bold text-[#06242A] hover:brightness-105"
                >
                    <Icon name="plus" /> Add screening
                </button>
            </div>
        </template>

        <div class="p-4">
            <div class="mx-auto max-w-full">
                <!-- A screening with no start time is invisible to its host and
                     to the film site, so it gets said out loud. -->
                <div
                    v-if="missingTime.length"
                    class="mb-4 flex flex-wrap items-center gap-3 rounded-xl bg-amber-50 px-4 py-3 text-left"
                >
                    <Icon name="triangle-exclamation" class="text-amber-700" />
                    <p class="min-w-0 flex-1 text-sm text-amber-900">
                        <strong>{{ missingTime.length }} screening{{ missingTime.length === 1 ? ' has' : 's have' }} no start time.</strong>
                        {{ missingTime.slice(0, 3).map((l) => l.name).join(', ') }}<span v-if="missingTime.length > 3"> and {{ missingTime.length - 3 }} more</span>.
                        Hosts cannot see a start time, and the film sites will not list them.
                    </p>
                </div>

                <div class="overflow-hidden bg-white shadow-card sm:rounded-lg">
                    <div class="p-6 text-gray-900 text-center">
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
                                        <Icon name="users" class="mr-1" /> Total: <strong>{{ total_participants ?? 0 }}</strong> participants
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
                                            <Icon name="file-export" /> Export ({{ selectedLocationsForExport.length }})
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
                                        <Icon name="database" /> Database
                                    </button>
                                    <button
                                        v-if="isAdmin"
                                        @click="goToResubscribeReport"
                                        style="background-color: #7C3AED; color: white; border-radius: 5px; padding: 6px 14px; font-size: 14px; cursor: pointer;"
                                        title="Newsletter resubscribes: who came back and who Mailchimp blocked"
                                    >
                                        <Icon name="rotate" /> Resubscribes
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
                                        :class="[ 'px-4 py-2 text-sm font-medium border-b-2 transition-colors whitespace-nowrap', selectedCountry === country ? 'border-[#16C3D9] text-[#0B7A8A]' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ]"
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
                                    class="w-5 h-5 text-blue-600 border-gray-300 rounded focus:ring-[#16C3D9] cursor-pointer"
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
                                    class="w-5 h-5 text-blue-600 border-gray-300 rounded focus:ring-[#16C3D9] cursor-pointer"
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
                                                <Icon name="circle-check" class="mr-1" />Win data
                                            </span>
                                            <!-- Ticket data imported to Mailchimp -->
                                            <span 
                                                v-if="location.imported_ticket_to_mailchimp"
                                                class="px-2 py-1 rounded text-xs font-medium bg-amber-100 text-amber-800"
                                                title="Ticket data imported to Mailchimp"
                                            >
                                                <Icon name="circle-check" class="mr-1" />Ticket data
                                            </span>
                                            <!-- Category + Participant count always together -->
                                            <span class="inline-flex items-center gap-2">
                                                <span 
                                                    class="px-2 py-1 rounded text-xs font-medium bg-gray-200 text-gray-700"
                                                    title="Participants (win form) for this location"
                                                >
                                                    <Icon name="user-group" class="mr-1" />{{ location.participants_count ?? 0 }}
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
                                    type="button" @click="openLocationModal(location)"
                                    class="flex h-10 items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                                    :aria-label="`Edit ${location.name}`"
                                >
                                    <Icon name="pen-to-square" /> Edit
                                </button>
                                <!-- Password Button -->
                                <button
                                    type="button" @click="openPasswordModal(location)"
                                    class="flex h-10 items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                                    :aria-label="`Password for ${location.name}`"
                                >
                                    <Icon name="key" /> Password
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
        <div v-if="showPasswordModal" class="fixed inset-0 z-[60] flex items-center justify-center bg-gray-900/50 p-4 sm:p-8">
            <div class="flex max-h-full w-full max-w-lg flex-col">
                <div class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl bg-white shadow-xl">
                    <div class="flex shrink-0 items-center justify-between gap-3 border-b border-gray-200 px-5 py-4">
                        <h2 class="text-lg font-extrabold tracking-tight text-gray-900">Location Password - {{ selectedLocation?.name }}</h2>
                        <button type="button" class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700" @click="closePasswordModal" aria-label="Close"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg></button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                        <div class="mb-3">
                            <label class="mb-1 block text-sm font-semibold text-gray-700">Current Password</label>
                            <div class="flex items-stretch gap-2">
                                <input type="text" :value="selectedLocation?.password" class="w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" readonly>
                                <button class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold border border-gray-300 bg-white text-gray-700 hover:bg-gray-50" @click="copyPassword(selectedLocation, -1)">
                                    <Icon name="copy" />
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="mb-1 block text-sm font-semibold text-gray-700">New Password</label>
                            <input type="text" v-model="newPassword" class="w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" placeholder="Enter new password">
                        </div>
                        <div class="mb-3">
                            <label class="mb-1 block text-sm font-semibold text-gray-700">Confirm New Password</label>
                            <input type="text" v-model="confirmPassword" class="w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" placeholder="Confirm new password">
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap justify-end gap-2 border-t border-gray-200 bg-white px-5 py-4">
                        <button type="button" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold border border-gray-200 bg-white text-gray-700 hover:bg-gray-50" @click="generatePassword">
                            <Icon name="random" /> Generate Password
                        </button>
                        <button type="button" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold bg-[#16C3D9] text-[#06242A] hover:brightness-105" @click="updatePassword">Save Changes</button>
                    </div>
                </div>
            </div>
        </div>


        <!-- Add/Edit Location Modal -->
        <div v-if="showLocationModal" class="fixed inset-0 z-[60] flex items-center justify-center bg-gray-900/50 p-4 sm:p-8">
            <div class="flex max-h-full w-full max-w-lg flex-col">
                <div class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl bg-white shadow-xl">
                    <div class="flex shrink-0 items-center justify-between gap-3 border-b border-gray-200 px-5 py-4">
                        <h2 class="text-lg font-extrabold tracking-tight text-gray-900">{{ isEditing ? 'Edit Location' : 'Create New Location' }}</h2>
                        <button type="button" class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700" @click="closeLocationModal" aria-label="Close"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg></button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                        <form id="location-form" @submit.prevent="saveLocation">
                            <div class="mb-3">
                                <label class="mb-1 block text-sm font-semibold text-gray-700">Location Name</label>
                                <input 
                                    v-model="locationForm.name" 
                                    type="text" 
                                    class="w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" 
                                    required 
                                />
                            </div>
                            <div class="mb-3">
                                <label class="mb-1 block text-sm font-semibold text-gray-700">Country</label>
                                <select 
                                    v-model="locationForm.country" 
                                    class="w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" 
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
                                <label class="mb-1 block text-sm font-semibold text-gray-700">State (Optional)</label>
                                <input 
                                    v-model="locationForm.state" 
                                    type="text" 
                                    class="w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" 
                                    placeholder="e.g., MT, VIC"
                                    maxlength="3"
                                    @input="locationForm.state = $event.target.value.toUpperCase()"
                                    style="text-transform: uppercase;"
                                />
                                <small class="text-gray-500">2-3 letter state abbreviation (e.g., MT, VIC, NSW)</small>
                            </div>
                            <div class="mb-3">
                                <label class="mb-1 block text-sm font-semibold text-gray-700">Category</label>
                                <select 
                                    v-model="locationForm.category" 
                                    class="w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" 
                                    required
                                >
                                    <option value="">Select Category</option>
                                    <option value="Theatrical">Theatrical</option>
                                    <option value="AE Tour Stop">AE Tour Stop</option>
                                    <option value="Host a Show">Host a Show</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="mb-1 block text-sm font-semibold text-gray-700">Date</label>
                                <input 
                                    v-model="locationForm.date" 
                                    type="date" 
                                    class="w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" 
                                    :disabled="noDateYet"
                                    :required="!noDateYet"
                                />
                                <div class="flex items-center gap-2 mt-2">
                                    <input 
                                        type="checkbox" 
                                        v-model="noDateYet" 
                                        class="h-4 w-4 shrink-0 rounded border-gray-300 text-[#16C3D9] focus:ring-[#16C3D9]" 
                                        id="noDateYet"
                                    />
                                    <label class="text-sm text-gray-700" for="noDateYet">
                                        No Date yet
                                    </label>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="mb-1 block text-sm font-semibold text-gray-700">Time</label>
                                <input 
                                    v-model="locationForm.time" 
                                    type="time" 
                                    class="w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" 
                                    :disabled="noTimeYet"
                                    :required="!noTimeYet"
                                />
                                <div class="flex items-center gap-2 mt-2">
                                    <input 
                                        type="checkbox" 
                                        v-model="noTimeYet" 
                                        class="h-4 w-4 shrink-0 rounded border-gray-300 text-[#16C3D9] focus:ring-[#16C3D9]" 
                                        id="noTimeYet"
                                    />
                                    <label class="text-sm text-gray-700" for="noTimeYet">
                                        No Time yet
                                    </label>
                                </div>
                            </div>
                        </form>
                    </div>
                    <!-- Outside the form so it can stay put while the fields
                         scroll; the submit button reaches the form by its id. -->
                    <div class="flex shrink-0 flex-wrap justify-end gap-2 border-t border-gray-200 bg-white px-5 py-4">
                        <button type="button" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold border border-gray-200 bg-white text-gray-700 hover:bg-gray-50" @click="closeLocationModal">Cancel</button>
                        <button type="submit" form="location-form" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold bg-[#16C3D9] text-[#06242A] hover:brightness-105">{{ isEditing ? 'Update Location' : 'Save Location' }}</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Update All Passwords Modal -->
        <div v-if="showAllPasswordsModal" class="fixed inset-0 z-[60] flex items-center justify-center bg-gray-900/50 p-4 sm:p-8">
            <div class="flex max-h-full w-full max-w-lg flex-col">
                <div class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl bg-white shadow-xl">
                    <div class="flex shrink-0 items-center justify-between gap-3 border-b border-gray-200 px-5 py-4">
                        <h2 class="text-lg font-extrabold tracking-tight text-gray-900">Update All Location Passwords</h2>
                        <button type="button" class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700" @click="closeAllPasswordsModal" aria-label="Close"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg></button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                        <div class="mb-3">
                            <label class="mb-1 block text-sm font-semibold text-gray-700">New Password</label>
                            <input type="text" v-model="allLocationsNewPassword" class="w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" placeholder="Enter new password">
                        </div>
                        <div class="mb-3">
                            <label class="mb-1 block text-sm font-semibold text-gray-700">Confirm New Password</label>
                            <input type="text" v-model="allLocationsConfirmPassword" class="w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" placeholder="Confirm new password">
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap justify-end gap-2 border-t border-gray-200 bg-white px-5 py-4">
                        <button type="button" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold border border-gray-200 bg-white text-gray-700 hover:bg-gray-50" @click="generatePassword">
                            <Icon name="random" /> Generate Password
                        </button>
                        <button type="button" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold bg-[#16C3D9] text-[#06242A] hover:brightness-105" @click="updateAllPasswords">Update All Passwords</button>
                    </div>
                </div>
            </div>
        </div>

    </AuthenticatedLayout>
</template>
