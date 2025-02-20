<script setup>
import Swal from 'sweetalert2';
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

// Props from Laravel
defineProps({
    events: Array
});

//Go to Pick a Winner Page
const goToPickaWinnerPage = (eventId) => {
    router.get(route('pickawinner.page', { eventId }));
};

</script>

<template>
    <Head title="Pick a Winner" />

    <AuthenticatedLayout>
        <template #header>
            <h2
                class="text-xl font-semibold leading-tight text-gray-800"
            >
                Pick a Winner
            </h2>
        </template>

        <div class="p-3 mt-5">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="row">
                    <div v-for="event in events" :key="event.id" class="col-md-4 mb-4">
                        <div class="card">
                            <img style="height: 200px;" :src="'/storage/' + event.event_banner" class="card-img-top" alt="Event Banner" />
                            <div class="card-body">
                                <h5 class="card-title">{{ event.event_name }}</h5>
                                <p class="card-text">{{ event.event_description }}</p>
                                <p class="text-muted">📅 {{ event.event_date }}</p>
                                <p class="text-muted">👤 {{ event.event_coordinator }}</p>
                                <div class="d-flex justify-content-between mt-3">
                                    <button class="btn btn-primary btn-sm me-2">
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


