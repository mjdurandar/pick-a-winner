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

// Format locations to display date and time in the desired format
const formattedLocations = computed(() => {
    return locations.value.map(location => {
        return {
            ...location,
            formatted_date: formatDate(location.date),
            formatted_time: formatTime(location.time)
        };
    });
});

// Group locations by date
const groupedLocations = computed(() => {
    return formattedLocations.value.reduce((groups, location) => {
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
});

// Function to format date to "March 07, 2025" format
function formatDate(dateString) {
    if (!dateString) return '';
    
    try {
        // If date is already in a format like "March 7, 2025", no need to reformat
        if (dateString.includes(',')) {
            return dateString;
        }
        
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return dateString; // Return original if invalid
        
        return date.toLocaleDateString('en-US', {
            month: 'long',
            day: '2-digit',
            year: 'numeric'
        });
    } catch (e) {
        console.error("Error formatting date:", e);
        return dateString;
    }
}

// Function to format time to "7:00PM" format
function formatTime(timeString) {
    if (!timeString) return '';
    
    try {
        // If time is already in a format like "7:00 PM", no need to reformat
        if (timeString.includes('AM') || timeString.includes('PM')) {
            return timeString.replace(' ', ''); // Remove space between time and AM/PM
        }
        
        // For 24-hour format "HH:MM"
        if (timeString.includes(':')) {
            const [hours, minutes] = timeString.split(':');
            const date = new Date();
            date.setHours(parseInt(hours));
            date.setMinutes(parseInt(minutes));
            
            return date.toLocaleTimeString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            }).replace(' ', ''); // Remove space between time and AM/PM
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
        <!-- Selection Modal -->
        <div class="flex items-center justify-center min-h-screen p-3">
            <div class="bg-white p-8 rounded-lg shadow-lg w-full max-w-md">
                <div class="flex justify-center mb-4">
                    <img
                        src="https://s3-ap-southeast-2.amazonaws.com/yc.cldmlk.com/jrvyz6cav5m2kaq1mqmsb7ag7r/uploads/1710312954714_AdventureEntertainment_Logo_RGB_Black.png"
                        alt="Login"
                        class="w-48"
                    />
                </div>
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
                        class="w-full bg-blue-500 text-white py-2 px-4 rounded-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                        :disabled="form.processing || (!form.is_all_locations && !form.location_id) || !form.event_id || !form.password"
                    >
                        {{ form.processing ? 'Verifying...' : 'Continue' }}
                    </button>
                </form>
            </div>
        </div>
    </PickaWinnerLayout>
</template>