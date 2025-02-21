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
const copiedIndex = ref(null); // ✅ Index of copied location link

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

const copyLocationLink = (locationId, eventId, index) => {
    // ✅ Copy location link to clipboard
    const locationLink = `${window.location.origin}/pickawinner/show/${locationId}/${eventId}`;
    navigator.clipboard.writeText(locationLink);

    // ✅ Show checkmark icon for 3 seconds
    copiedIndex.value = index;
    setTimeout(() => {
        copiedIndex.value = null;
    }, 3000);
};

const allLocationsPage = () => {
    router.get(route('pickawinner.alllocation', { event: props.event.id }));
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

        <div class="p-4">
            <div class="mx-auto max-w-3xl">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 text-center">
                        <div class="d-flex justify-content-between items-center mb-4">
                            <h3 class="text-lg font-semibold mb-4">Please select a Location</h3>
                            <button class="btn btn-primary" @click="allLocationsPage()">All Locations</button>
                        </div>
                        <!-- ✅ Search Bar -->
                        <input 
                            v-model="searchQuery" 
                            type="text" 
                            placeholder="Search location..."
                            class="w-full border p-2 rounded mb-4 focus:ring focus:ring-blue-300"
                        />

                        <!-- ✅ Locations List -->
                        <div v-if="filteredLocations.length > 0" class="space-y-2">
                            <div 
                                v-for="(location, index) in filteredLocations" 
                                :key="index" 
                                class="flex items-center space-x-2 w-full"
                            >
                                <!-- Location Button (Full Width) -->
                                <button 
                                    @click="goToLocationWinnerPage(location.id, event.id)"
                                    class="bg-blue-500 text-white px-4 py-2 rounded w-full text-left truncate hover:bg-blue-700"
                                >
                                    {{ location.name }}
                                </button>

                                <!-- Copy Link Button -->
                                <button 
                                    @click="copyLocationLink(location.id, event.id, index)"
                                    class="bg-gray-300 text-gray-600 px-3 py-2 rounded hover:bg-gray-400 transition"
                                    title="Copy link"
                                >   
                                <i :class="copiedIndex === index ? 'fa-solid fa-check text-green-600' : 'fa-solid fa-copy'"></i>
                                </button>
                            </div>
                        </div>

                        <!-- ✅ If No Locations Found -->
                        <div v-else>
                            <p class="text-gray-600 mt-3">No locations found.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
