<script setup>
import Swal from 'sweetalert2';
import { ref, onMounted, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

// Props from Laravel
const props = defineProps({
    events: Array
});

const showSelectionModal = ref(true);
const locations = ref([]);
const isVerified = ref(false);

const form = useForm({
    event_id: '',
    location_id: '',
    password: ''
});

// Get the selected location object
const selectedLocation = computed(() => {
    return locations.value.find(loc => loc.id === form.location_id);
});

// Get the password hint (first word before hyphen)
const getLocationPassword = computed(() => {
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
    
    try {
        const response = await axios.get(`/api/events/${form.event_id}/locations`);
        locations.value = response.data;
    } catch (error) {
        console.error('Error loading locations:', error);
        locations.value = [];
    }
};

const handleSubmit = () => {
    form.post(route('picka-winner.verify'), {
        preserveScroll: true,
        onSuccess: () => {
            // The redirect will be handled automatically by Inertia
        },
        onError: () => {
            Swal.fire({
                icon: 'error',
                title: 'Invalid Password',
                text: 'The password you entered is incorrect.',
            });
        }
    });
};

//Go to Pick a Winner Page
const goToPickaWinnerPage = (eventId) => {
    router.get(route('pickawinner.page', { eventId }));
};

const goToAttendeesPage = (eventId) => {
    router.get(route('attendees.index', { eventId }));
};
</script>

<template>
    <Head title="Pick a Winner" />

    <!-- Selection Modal -->
    <div v-if="showSelectionModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
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
                        @change="loadLocations"
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

                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="location">
                        Select Location
                    </label>
                    <select
                        id="location"
                        v-model="form.location_id"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        :disabled="!form.event_id"
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
                        placeholder="Enter location password"
                        @input="form.password = form.password.toUpperCase()"
                    />
                    <p class="text-gray-600 text-sm mt-1" v-if="!selectedLocation">
                        Password is the location name before the hyphen in UPPERCASE
                    </p>
                    <p class="text-gray-600 text-sm mt-1" v-else>
                        For "{{ selectedLocation.name }}", the password would be "{{ getLocationPassword }}"
                    </p>
                    <div v-if="form.errors.password" class="text-red-500 text-sm mt-1">
                        {{ form.errors.password }}
                    </div>
                </div>

                <button
                    type="submit"
                    class="w-full bg-blue-500 text-white py-2 px-4 rounded-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Verifying...' : 'Continue' }}
                </button>
            </form>
        </div>
    </div>

    <!-- Main Content (Only shown after verification) -->
    <AuthenticatedLayout v-if="isVerified">
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Pick a Winner
            </h2>
        </template>

        <div class="p-3 mt-1">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="row">
                    <div v-for="event in events" :key="event.id" class="col-md-4 mb-4">
                        <div class="card">
                            <img style="height: 200px;" :src="'/storage/' + event.event_banner" class="card-img-top" alt="Event Banner" />
                            <div class="card-body">
                                <h5 class="card-title">{{ event.event_name }}</h5>
                                <p class="card-text mb-1" style="font-size: 14px; font-weight: 500;">{{ event.event_country }}</p>
                                <p class="text-muted">📅 {{ event.event_date }}</p>
                                <div class="d-flex justify-content-between mt-3">
                                    <button class="btn btn-primary btn-sm me-2" @click="goToAttendeesPage(event.id)">
                                        Attendees
                                    </button>
                                    <button @click="goToPickaWinnerPage(event.id)" class="btn btn-success btn-sm me-2">
                                        Pick a Winner
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>


