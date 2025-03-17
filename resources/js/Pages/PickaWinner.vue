<script setup>
import Swal from 'sweetalert2';
import { ref, onMounted, computed, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import PickaWinnerLayout from '@/Layouts/PickaWinnerLayout.vue';
import { Head } from '@inertiajs/vue3';

// Props from Laravel
const props = defineProps({
    events: Array
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

        // Check if password matches event name
        if (form.password.toUpperCase() === selectedEvent.event_name.toUpperCase()) {
            // Redirect to all locations page for the selected event
            router.visit(route('pickawinner.alllocation', selectedEvent.id));
            return;
        }
        
        // Show error if password doesn't match
        Swal.fire({
            icon: 'error',
            title: 'Invalid Password',
            text: 'The password must match the event name.',
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

const toggleAllLocations = () => {
    // Set the form state first
    if (!form.is_all_locations) {
        // If we're checking the box
        const selectedEvent = props.events.find(e => e.id === form.event_id);
        if (selectedEvent) {
            form.password = selectedEvent.event_name.toUpperCase();
        }
    } else {
        // If we're unchecking the box
        form.password = '';
    }
    
    form.location_id = '';
    loadLocations();
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
        <div class="flex items-center justify-center min-h-screen">
            <div class="bg-white p-8 rounded-lg shadow-lg w-full max-w-md">
                <h2 class="text-2xl font-bold text-center mb-6">Pick a Winner Selection</h2>
                
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

                    <!-- All Locations Checkbox -->
                    <div v-if="form.event_id" class="mb-4">
                        <label class="flex items-center cursor-pointer">
                            <input
                                type="checkbox"
                                :checked="form.is_all_locations"
                                @change="form.is_all_locations = !form.is_all_locations; toggleAllLocations()"
                                class="form-checkbox h-4 w-4 text-blue-500 cursor-pointer"
                            >
                            <span class="ml-2 text-gray-700">Show all locations</span>
                        </label>
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
                            <option v-for="location in locations" :key="location.id" :value="location.id">
                                {{ location.name }}
                            </option>
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
                        <p class="text-gray-600 text-sm mt-1" v-if="form.event_id">
                            <template v-if="form.is_all_locations">
                                Password is "{{ props.events.find(e => e.id === form.event_id)?.event_name.toUpperCase() }}"
                            </template>
                            <template v-else>
                                Password is the location name before the hyphen in UPPERCASE
                            </template>
                        </p>
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


