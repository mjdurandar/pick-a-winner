<script setup>
import { computed, ref } from 'vue';
import { router, Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    events: Array,
});

const search = ref('');

const visible = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.events ?? [];

    return (props.events ?? []).filter((e) =>
        `${e.event_name ?? ''} ${e.event_country ?? ''} ${e.event_coordinator ?? ''}`
            .toLowerCase().includes(q));
});

const goToLocationPage = (eventId) => {
    router.get(route('location.locationpage', { eventId: eventId }));
};
</script>

<template>
    <Head title="Locations" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-end gap-4">
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-semibold text-gray-500">
                        Pick a film to see its screenings
                    </p>
                    <h2 class="truncate text-2xl font-extrabold tracking-tight text-gray-900">Locations</h2>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-full px-4 py-6 sm:px-6 lg:px-8">
            <div class="overflow-hidden rounded-xl bg-white shadow-card">
                <div class="flex flex-wrap items-center gap-3 border-b border-gray-100 p-4">
                    <label class="flex h-10 min-w-[220px] flex-1 items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 sm:max-w-sm">
                        <svg class="h-4 w-4 shrink-0 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                        <span class="sr-only">Search films</span>
                        <input v-model="search" type="search" placeholder="Film, country or coordinator" class="w-full border-0 bg-transparent p-0 text-sm focus:ring-0" />
                    </label>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="bg-gray-50">
                                <th scope="col" class="whitespace-nowrap border-b border-gray-200 px-4 py-2.5 text-left text-[10.5px] font-bold uppercase tracking-wider text-gray-500">Film</th>
                                <th scope="col" class="whitespace-nowrap border-b border-gray-200 px-4 py-2.5 text-left text-[10.5px] font-bold uppercase tracking-wider text-gray-500">Country</th>
                                <th scope="col" class="whitespace-nowrap border-b border-gray-200 px-4 py-2.5 text-left text-[10.5px] font-bold uppercase tracking-wider text-gray-500">First show</th>
                                <th scope="col" class="whitespace-nowrap border-b border-gray-200 px-4 py-2.5 text-left text-[10.5px] font-bold uppercase tracking-wider text-gray-500">Coordinator</th>
                                <th scope="col" class="border-b border-gray-200 px-4 py-2.5"><span class="sr-only">Open</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="event in visible" :key="event.id"
                                class="border-b border-gray-100 last:border-b-0 hover:bg-gray-50"
                            >
                                <td class="px-4 py-3">
                                    <button
                                        type="button" @click="goToLocationPage(event.id)"
                                        class="text-left text-sm font-bold text-gray-900 hover:text-[#0B7A8A]"
                                    >{{ event.event_name }}</button>
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ event.event_country }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ event.event_date }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ event.event_coordinator || '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <button
                                        type="button" @click="goToLocationPage(event.id)"
                                        class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                                    >Screenings</button>
                                </td>
                            </tr>
                            <tr v-if="!visible.length">
                                <td colspan="5" class="px-4 py-14 text-center text-sm text-gray-400">
                                    {{ search ? 'No film matches that.' : 'No films yet.' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
