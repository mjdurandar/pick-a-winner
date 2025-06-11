<script setup>
import Swal from 'sweetalert2';
import { ref, computed } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

// Props from Laravel
defineProps({
    events: Array
});

const goToLocationPage = (eventId) => {
    router.get(route('location.locationpage', { eventId: eventId }));
}

</script>

<template>
    <Head title="Locations" />
    <AuthenticatedLayout>
        <template #header>
            <div class="d-flex justify-content-between align-items-center">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Locations</h2>
            </div>
        </template>

        <div class="p-4">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="row">
                    <div v-for="event in events" :key="event.id" class="col-md-4 mb-4">
                        <div class="card">
                            <img style="height: 200px;" :src="'/storage/' + event.event_banner" class="card-img-top" alt="Event Banner" />
                            <div class="card-body">
                                <h5 class="card-title">{{ event.event_name }}</h5>
                                <p class="card-text mb-1" style="font-size: 14px; font-weight: 500;">{{ event.event_country }}</p>
                                <p class="text-muted">📅 {{ event.event_date }}</p>
                                <p class="text-muted">👤 {{ event.event_coordinator }}</p>
                                <div class="d-flex justify-content-start mt-3">
                                    <button @click="goToLocationPage(event.id)" class="btn btn-sm me-2" style="background-color: #16C3D9; color: white; cursor: pointer;">
                                        Locations
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


