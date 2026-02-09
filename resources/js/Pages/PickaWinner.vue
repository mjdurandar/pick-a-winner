<script setup>
import Swal from 'sweetalert2';
import { ref, onMounted, computed, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import PickaWinnerLayout from '@/Layouts/PickaWinnerLayout.vue';
import { Head } from '@inertiajs/vue3';

// Props from Laravel
const props = defineProps({
    events: Array,
    locations: Array
});

const showSelectionModal = ref(true);
const locations = ref([]);

const form = useForm({
    event_id: '',
    location_id: '',
    password: '',
    is_all_locations: false
});

// Get the selected location object
const selectedLocation = computed(() => {
    return locations.value.find(loc => loc.id === form.location_id);
});

// Sort key: raw date + time string (no timezone – use values as stored for the location)
function dateTimeSortKey(dateStr, timeStr) {
    if (!dateStr || !timeStr) return '';
    const t = String(timeStr).trim();
    const parts = t.split(':');
    const h = parseInt(parts[0], 10) || 0;
    const m = parseInt(parts[1], 10) || 0;
    return `${dateStr}T${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

// Format locations to display date and time in the desired format (no timezone conversion)
const formattedLocations = computed(() => {
    return locations.value.map(location => {
        const dateSortKey = location.date || '';
        const dateTimeSortKeyVal = dateTimeSortKey(location.date, location.time);
        return {
            ...location,
            dateSortKey,
            dateTimeSortKeyVal,
            formatted_date: formatDate(location.date),
            formatted_time: formatTime(location.time)
        };
    }).sort((a, b) => (a.dateSortKey || '').localeCompare(b.dateSortKey || '')); // Sort by date string
});

// Group locations by date
const groupedLocations = computed(() => {
    const groups = formattedLocations.value.reduce((groups, location) => {
        const dateKey = location.date; // Use raw date for grouping key
        if (!groups[dateKey]) {
            groups[dateKey] = {
                formatted_date: location.formatted_date,
                locations: []
            };
        }
        groups[dateKey].locations.push(location);
        return groups;
    }, {});

    // Sort locations within each date group alphabetically by name, then by time (no timezone)
    Object.keys(groups).forEach(dateKey => {
        groups[dateKey].locations.sort((a, b) => {
            const nameComparison = a.name.localeCompare(b.name);
            if (nameComparison !== 0) return nameComparison;
            return (a.dateTimeSortKeyVal || '').localeCompare(b.dateTimeSortKeyVal || '');
        });
    });

    return groups;
});

// Month names for formatting (no timezone – we only use the date parts as stored)
const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

// Format date to "March 07, 2025" using only the date string (no timezone conversion)
function formatDate(dateString) {
    if (!dateString) return '';
    try {
        if (dateString.includes(',')) return dateString;
        // Parse YYYY-MM-DD as plain numbers (no Date so no UTC/local shift)
        const match = String(dateString).trim().match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
        if (!match) return dateString;
        const [, y, m, d] = match;
        const monthIdx = parseInt(m, 10) - 1;
        if (monthIdx < 0 || monthIdx > 11) return dateString;
        const day = parseInt(d, 10);
        const month = MONTH_NAMES[monthIdx];
        return `${month} ${String(day).padStart(2, '0')}, ${y}`;
    } catch (e) {
        console.error("Error formatting date:", e);
        return dateString;
    }
}

// Format time to "7:00PM" using only the time string (no timezone conversion)
function formatTime(timeString) {
    if (!timeString) return '';
    try {
        if (timeString.includes('AM') || timeString.includes('PM')) {
            return timeString.replace(/\s+/g, ''); // Remove spaces
        }
        // Parse HH:MM or HH:MM:SS as plain numbers and convert to 12h AM/PM
        if (timeString.includes(':')) {
            const parts = timeString.split(':');
            let hours = parseInt(parts[0], 10) || 0;
            const minutes = parseInt(parts[1], 10) || 0;
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            return `${hours}:${String(minutes).padStart(2, '0')}${ampm}`;
        }
        return timeString;
    } catch (e) {
        console.error("Error formatting time:", e);
        return timeString;
    }
}

// Get the password hint based on selection
const getPasswordHint = computed(() => {
    if (form.is_all_locations) {
        const selectedEvent = props.events.find(e => e.id === form.event_id);
        return selectedEvent ? selectedEvent.event_name.toUpperCase() : '';
    }
    if (!selectedLocation.value) return '';
    const firstWord = selectedLocation.value.name.split('-')[0];
    return firstWord.trim().toUpperCase();
});

const loadLocations = async () => {
    if (!form.event_id) {
        locations.value = [];
        form.location_id = '';
        return;
    }
    
    if (form.is_all_locations) {
        locations.value = [];
        form.location_id = '';
        return;
    }
    
    try {
        const response = await axios.get(`/api/events/${form.event_id}/locations`);
        locations.value = response.data;
    } catch (error) {
        console.error('Error loading locations:', error);
        locations.value = [];
    }
};

const handleSubmit = () => {
    // Handle all locations case
    if (form.is_all_locations) {
        const selectedEvent = props.events.find(e => e.id === form.event_id);
        if (!selectedEvent) return;

        // Check if password matches event's stored password
        if (form.password.toUpperCase() === selectedEvent.password.toUpperCase()) {
            // Redirect to all locations page for the selected event
            router.visit(route('pickawinner.alllocation', selectedEvent.id));
            return;
        }
        
        // Show error if password doesn't match
        Swal.fire({
            icon: 'error',
            title: 'Invalid Password',
            text: 'The password you entered is incorrect.'
        });
        return;
    }

    // Handle single location case
    form.post(route('picka-winner.verify'), {
        preserveScroll: true,
        onError: () => {
            Swal.fire({
                icon: 'error',
                title: 'Invalid Password',
                text: 'The password you entered is incorrect.',
            });
        }
    });
};

// Watch for event changes
watch(() => form.event_id, () => {
    form.is_all_locations = false;
    form.location_id = '';
    form.password = '';
    loadLocations();
});
</script>

<template>
    <Head title="Pick a Winner" />

    <PickaWinnerLayout>
        <!-- Selection Modal with Black Background -->
        <div class="flex flex-col items-center justify-center min-h-screen p-3" style="background-color: #151515;">
            <!-- Logo outside the box -->
            <div class="flex justify-center mb-10">
                <img
                    src="https://adventureentertainment.com/wp-content/themes/adv001corp/resources/artwork/site-header__logo.svg?v=1712329405"
                    alt="Login"
                    class="w-100"
                />
            </div>
            <!-- <div class="flex justify-center mb-10">
                <h1 class="text-white text-4xl font-bold">Pick a Winner</h1>
            </div> -->
            
            <!-- White Form Box -->
            <div class="bg-white p-8 shadow-lg w-full max-w-md">
                <form @submit.prevent="handleSubmit">
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="event">
                            Select Event
                        </label>
                        <select
                            id="event"
                            v-model="form.event_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                            required
                        >
                            <option value="">Select an event</option>
                            <option v-for="event in events" :key="event.id" :value="event.id">
                                {{ event.event_name }}
                            </option>
                        </select>
                        <div v-if="form.errors.event_id" class="text-red-500 text-sm mt-1">
                            {{ form.errors.event_id }}
                        </div>
                    </div>

                    <div v-if="!form.is_all_locations" class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="location">
                            Select Location
                        </label>
                        <select
                            id="location"
                            v-model="form.location_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                            :disabled="!form.event_id"
                            :required="!form.is_all_locations"
                        >
                            <option value="">Select a location</option>
                            <template v-for="(group, dateKey) in groupedLocations" :key="dateKey">
                                <optgroup :label="group.formatted_date">
                                    <option v-for="location in group.locations" :key="location.id" :value="location.id">
                                        {{ location.name }} - {{ location.formatted_time }}
                                    </option>
                                </optgroup>
                            </template>
                        </select>
                        <div v-if="form.errors.location_id" class="text-red-500 text-sm mt-1">
                            {{ form.errors.location_id }}
                        </div>
                    </div>

                    <div class="mb-6">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="password">
                            Password
                        </label>
                        <input
                            id="password"
                            type="password"
                            v-model="form.password"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                            :placeholder="form.is_all_locations ? 'Enter event name as password' : 'Enter location password'"
                            @input="form.password = form.password.toUpperCase()"
                            required
                        />
                        <div v-if="form.errors.password" class="text-red-500 text-sm mt-1">
                            {{ form.errors.password }}
                        </div>
                    </div>

                    <button
                        type="submit"
                        class="w-full bg-cyan-500 text-white py-2 px-4 hover:bg-cyan-600 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:ring-offset-2"
                        :disabled="form.processing || (!form.is_all_locations && !form.location_id) || !form.event_id || !form.password"
                    >
                        {{ form.processing ? 'Verifying...' : 'Enter' }}
                    </button>
                </form>
            </div>
        </div>
    </PickaWinnerLayout>
</template>