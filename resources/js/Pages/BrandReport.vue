<script setup>
import { ref, onMounted, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    film: { type: Object, required: true } // { id, name }
});

const reportData = ref(null);
const loading = ref(true);
const error = ref(null);

onMounted(async () => {
    try {
        const { data } = await axios.get(route('film.ticketReport', props.film.id));
        reportData.value = data;
    } catch (e) {
        error.value = e.response?.data?.error || e.message || 'Failed to load report.';
    } finally {
        loading.value = false;
    }
});

const summary = computed(() => reportData.value?.summary || null);
const demographics = computed(() => reportData.value?.demographics || { by_country: [], by_state: [] });
const locationStats = computed(() => reportData.value?.location_stats || []);
</script>

<template>
    <Head :title="`Brand Report – ${film.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center flex-wrap gap-4">
                <div class="flex items-center gap-3">
                    <Link
                        :href="route('films.index')"
                        class="text-gray-500 hover:text-gray-700"
                    >
                        <i class="fa-solid fa-arrow-left"></i>
                    </Link>
                    <h2 class="text-xl font-semibold text-gray-800 leading-tight">
                        Brand Report – {{ film.name }}
                    </h2>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div v-if="loading" class="bg-white rounded-lg shadow p-12 text-center">
                    <i class="fa-solid fa-spinner fa-spin text-4xl text-teal-600"></i>
                    <p class="mt-4 text-gray-600">Loading analytics...</p>
                </div>

                <div v-else-if="error" class="bg-white rounded-lg shadow p-6">
                    <p class="text-red-600">{{ error }}</p>
                    <Link :href="route('films.index')" class="inline-block mt-4 text-teal-600 hover:text-teal-800">
                        ← Back to Brands
                    </Link>
                </div>

                <div v-else-if="reportData" class="space-y-6">
                    <!-- Summary cards -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
                            <p class="text-sm font-medium text-gray-500 uppercase">Ticket Emails</p>
                            <p class="text-2xl font-bold text-gray-900">{{ summary.ticket_emails_count ?? 0 }}</p>
                        </div>
                        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-purple-500">
                            <p class="text-sm font-medium text-gray-500 uppercase">Sign-up Emails</p>
                            <p class="text-2xl font-bold text-gray-900">{{ summary.signup_emails_count ?? 0 }}</p>
                        </div>
                        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-orange-500">
                            <p class="text-sm font-medium text-gray-500 uppercase">Duplicate (Both)</p>
                            <p class="text-2xl font-bold text-gray-900">{{ summary.duplicate_emails_count ?? 0 }}</p>
                        </div>
                        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-teal-500">
                            <p class="text-sm font-medium text-gray-500 uppercase">Total Unique</p>
                            <p class="text-2xl font-bold text-gray-900">{{ summary.total_unique_emails ?? 0 }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-gray-400">
                            <p class="text-sm font-medium text-gray-500 uppercase">Events</p>
                            <p class="text-2xl font-bold text-gray-900">{{ summary.total_events ?? 0 }}</p>
                        </div>
                        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-gray-400">
                            <p class="text-sm font-medium text-gray-500 uppercase">Locations</p>
                            <p class="text-2xl font-bold text-gray-900">{{ summary.total_locations ?? 0 }}</p>
                        </div>
                    </div>

                    <!-- Demographics by country -->
                    <div v-if="demographics.by_country && demographics.by_country.length" class="bg-white rounded-lg shadow overflow-hidden">
                        <div class="px-4 py-3 bg-gray-50 border-b border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-800">Demographics by country</h3>
                            <p class="text-sm text-gray-500">Unique attendees (ticket data) by country</p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Country</th>
                                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Count</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="row in demographics.by_country" :key="row.country" class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-gray-900">{{ row.country }}</td>
                                        <td class="px-4 py-3 text-right font-medium text-gray-900">{{ row.count }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- By state (if any) -->
                    <div v-if="demographics.by_state && demographics.by_state.length" class="bg-white rounded-lg shadow overflow-hidden">
                        <div class="px-4 py-3 bg-gray-50 border-b border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-800">By state / region</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Country</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">State</th>
                                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Count</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="(row, i) in demographics.by_state" :key="i" class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-gray-900">{{ row.country }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ row.state }}</td>
                                        <td class="px-4 py-3 text-right font-medium text-gray-900">{{ row.count }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Per-location stats -->
                    <div v-if="locationStats.length" class="bg-white rounded-lg shadow overflow-hidden">
                        <div class="px-4 py-3 bg-gray-50 border-b border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-800">By location</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Location</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Event</th>
                                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Ticket</th>
                                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Sign-up</th>
                                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Both</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="loc in locationStats" :key="loc.location_id" class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-gray-900">{{ loc.location_name }}</td>
                                        <td class="px-4 py-3 text-gray-600">{{ loc.event_name }}</td>
                                        <td class="px-4 py-3 text-right">{{ loc.ticket_emails_count }}</td>
                                        <td class="px-4 py-3 text-right">{{ loc.signup_emails_count }}</td>
                                        <td class="px-4 py-3 text-right">{{ loc.duplicate_emails_count }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="!locationStats.length && (!demographics.by_country || !demographics.by_country.length)" class="bg-white rounded-lg shadow p-8 text-center text-gray-500">
                        <p>No event or ticket data is linked to this brand yet.</p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
