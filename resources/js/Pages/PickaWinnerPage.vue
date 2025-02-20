<script setup>
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';

// ✅ Define Props to Receive Locations from Backend
const props = defineProps({
    event: Object,
    locations: Array
});

// ✅ Reactive Data
const searchQuery = ref(''); // ✅ Search query input

// ✅ Computed Property to Filter Locations
const filteredLocations = computed(() => {
    if (!searchQuery.value) {
        return props.locations; // ✅ Use props.locations instead of just locations
    }
    return props.locations.filter(location =>
        location.name.toLowerCase().includes(searchQuery.value.toLowerCase())
    );
});

// ✅ Redirect to Winner Page for Selected Location
const goToLocationWinnerPage = (locationId, eventId) => {
    router.get(route('pickawinner.locationpage', { location: locationId, event: eventId }));
};
</script>

<template>
    <Head title="Pick a Winner Page" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Pick a Winner for {{ event.event_name }}
            </h2>
        </template>

        <div class="p-3">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 text-center">
                        <h3 class="text-lg font-semibold mb-4">Please select a Location</h3>

                        <!-- ✅ Search Bar -->
                        <input 
                            v-model="searchQuery" 
                            type="text" 
                            placeholder="Search location..."
                            class="w-50 border p-2 rounded mb-4"
                        />

                        <!-- ✅ Loop Through Filtered Locations (Each Location is Clickable) -->
                        <div v-if="filteredLocations.length > 0">
                            <button 
                                v-for="(location, index) in filteredLocations" 
                                :key="index"
                                @click="goToLocationWinnerPage(location.id, event.id)"
                                class="bg-blue-500 text-white px-4 py-2 rounded m-2 hover:bg-blue-700 w-full md:w-auto"
                            >
                                {{ location.name }}
                            </button>
                        </div>

                        <!-- ✅ If No Locations Match Search Query -->
                        <div v-else>
                            <p class="text-gray-600">No locations found.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
