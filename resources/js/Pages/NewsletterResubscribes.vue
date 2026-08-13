<script setup>
import { ref, computed, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { debounce } from 'lodash';

const props = defineProps({
    event: Object,
    scope: { type: String, default: 'event' },
    filters: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
    breakdown: { type: Array, default: () => [] },
    breakdownBy: { type: String, default: 'location' },
    locations: { type: Array, default: () => [] },
    siblingEventCount: { type: Number, default: 1 },
    attempts: { type: Object, default: () => ({ data: [], links: [] }) },
});

// Local mirror of the server-side filters. Every change re-requests the page so
// the totals, the breakdown and the table can never disagree with each other.
const scope = ref(props.scope);
const report = ref(props.filters.report || '');
const locationId = ref(props.filters.location_id || '');
const search = ref(props.filters.search || '');
const from = ref(props.filters.from || '');
const to = ref(props.filters.to || '');

const query = computed(() => {
    const params = { scope: scope.value };
    if (report.value) params.report = report.value;
    if (locationId.value) params.location_id = locationId.value;
    if (search.value) params.search = search.value;
    if (from.value) params.from = from.value;
    if (to.value) params.to = to.value;
    return params;
});

const reload = () => {
    router.get(route('newsletterResubscribes.index', { event: props.event.id }), query.value, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

// Debounced so typing an email does not fire a request per keystroke, and so a
// change that clears a second filter still only reloads once.
const debouncedReload = debounce(reload, 350);

watch(query, debouncedReload);

// The location list belongs to this event, so a location filter is meaningless
// once the report widens to the whole film — drop it rather than silently
// filtering every other event down to nothing.
watch(scope, () => {
    if (scope.value === 'film') locationId.value = '';
});

const resetFilters = () => {
    report.value = '';
    locationId.value = '';
    search.value = '';
    from.value = '';
    to.value = '';
};

const hasFilters = computed(
    () => !!(report.value || locationId.value || search.value || from.value || to.value)
);

// The CSV takes the same query string as the page, so what downloads is what is
// on screen — filters included.
const exportCsv = () => {
    const url = route('newsletterResubscribes.download', {
        event_id: props.event.id,
        ...query.value,
    });
    window.location.href = url;
};

const goBack = () => {
    router.get(route('location.locationpage', { eventId: props.event.id }));
};

const tiles = computed(() => [
    {
        key: '',
        label: 'All attempts',
        value: props.summary.total?.attempts ?? 0,
        contacts: props.summary.total?.contacts ?? 0,
        classes: 'border-gray-300 bg-gray-50',
        text: 'text-gray-800',
        icon: 'fa-list',
    },
    {
        key: 'resubscribed',
        label: 'Resubscribed',
        value: props.summary.resubscribed?.attempts ?? 0,
        contacts: props.summary.resubscribed?.contacts ?? 0,
        classes: 'border-green-300 bg-green-50',
        text: 'text-green-700',
        icon: 'fa-rotate',
    },
    {
        key: 'confirmation_sent',
        label: 'Confirmation sent',
        value: props.summary.confirmation_sent?.attempts ?? 0,
        contacts: props.summary.confirmation_sent?.contacts ?? 0,
        classes: 'border-blue-300 bg-blue-50',
        text: 'text-blue-700',
        icon: 'fa-envelope-circle-check',
    },
    {
        key: 'deferred',
        label: 'Waiting to retry',
        value: props.summary.deferred?.attempts ?? 0,
        contacts: props.summary.deferred?.contacts ?? 0,
        classes: 'border-purple-300 bg-purple-50',
        text: 'text-purple-700',
        icon: 'fa-clock-rotate-left',
    },
    {
        key: 'blocked',
        label: 'Blocked by Mailchimp',
        value: props.summary.blocked_compliance?.attempts ?? 0,
        contacts: props.summary.blocked_compliance?.contacts ?? 0,
        classes: 'border-amber-300 bg-amber-50',
        text: 'text-amber-700',
        icon: 'fa-ban',
    },
    {
        key: 'failed',
        label: 'Failed',
        value: props.summary.failed?.attempts ?? 0,
        contacts: props.summary.failed?.contacts ?? 0,
        classes: 'border-red-300 bg-red-50',
        text: 'text-red-700',
        icon: 'fa-triangle-exclamation',
    },
]);

// A tour has dozens of locations and most see no resubscribes at all, so the
// quiet ones are folded away rather than burying the rows that matter.
const showEmptyRows = ref(false);

const visibleBreakdown = computed(() =>
    showEmptyRows.value ? props.breakdown : props.breakdown.filter((row) => row.total > 0)
);

const emptyRowCount = computed(() => props.breakdown.filter((row) => row.total === 0).length);

const outcomeClass = (outcome) => {
    if (outcome === 'resubscribed') return 'bg-green-100 text-green-800';
    if (outcome === 'confirmation_sent') return 'bg-blue-100 text-blue-800';
    if (outcome === 'deferred') return 'bg-purple-100 text-purple-800';
    if (outcome === 'blocked_compliance') return 'bg-amber-100 text-amber-800';
    return 'bg-red-100 text-red-800';
};

const goToPage = (url) => {
    if (!url) return;
    router.get(url, {}, { preserveState: true, preserveScroll: true, replace: true });
};
</script>

<template>
    <Head title="Newsletter resubscribes" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Newsletter resubscribes — {{ event.film_name || event.event_name }}
            </h2>
        </template>

        <div class="p-2 pb-5 pt-5">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <!-- Header row: scope, back, export -->
                        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                            <div>
                                <p class="text-sm text-gray-600">
                                    Everyone the sign-up form tried to bring back to the newsletter —
                                    the ones who returned, and the ones Mailchimp refused.
                                </p>
                                <p class="text-xs text-gray-500 mt-1">
                                    {{ scope === 'film' ? 'All events of this film' : event.event_name }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button
                                    @click="goBack"
                                    class="px-4 py-2 text-sm bg-gray-200 text-gray-700 rounded hover:bg-gray-300"
                                >
                                    <i class="fa-solid fa-arrow-left mr-1"></i> Locations
                                </button>
                                <button
                                    @click="exportCsv"
                                    class="px-4 py-2 text-sm bg-green-600 text-white rounded hover:bg-green-700"
                                    title="Download the rows below as CSV"
                                >
                                    <i class="fa-solid fa-file-csv mr-1"></i> Export CSV
                                </button>
                            </div>
                        </div>

                        <!-- Event vs whole film -->
                        <div v-if="event.film_id && siblingEventCount > 1" class="mb-4">
                            <div class="inline-flex rounded-md border border-gray-300 overflow-hidden text-sm">
                                <button
                                    @click="scope = 'event'"
                                    :class="scope === 'event' ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                                    class="px-4 py-2"
                                >
                                    This event
                                </button>
                                <button
                                    @click="scope = 'film'"
                                    :class="scope === 'film' ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                                    class="px-4 py-2 border-l border-gray-300"
                                >
                                    Whole film ({{ siblingEventCount }} events)
                                </button>
                            </div>
                        </div>

                        <!-- Totals; click one to filter the table below -->
                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
                            <button
                                v-for="tile in tiles"
                                :key="tile.label"
                                @click="report = tile.key"
                                :class="[
                                    tile.classes,
                                    report === tile.key ? 'ring-2 ring-blue-400' : '',
                                ]"
                                class="border rounded-lg p-4 text-left transition hover:shadow"
                            >
                                <div class="text-xs uppercase tracking-wide text-gray-600">
                                    <i :class="`fa-solid ${tile.icon} mr-1`"></i> {{ tile.label }}
                                </div>
                                <div :class="tile.text" class="text-2xl font-bold mt-1">
                                    {{ tile.value.toLocaleString() }}
                                </div>
                                <div class="text-xs text-gray-500 mt-1">
                                    {{ tile.contacts.toLocaleString() }} unique
                                    {{ tile.contacts === 1 ? 'contact' : 'contacts' }}
                                </div>
                            </button>
                        </div>

                        <p class="text-xs text-gray-500 -mt-4 mb-6">
                            A contact is checked twice — once when they leave the email field, once on
                            submit — so attempts can exceed unique contacts. The unique figure is the
                            people count.
                        </p>

                        <!-- Filters -->
                        <div class="flex flex-wrap items-end gap-3 mb-6 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Outcome</label>
                                <select v-model="report" class="border rounded px-3 py-2 text-sm min-w-[180px]">
                                    <option value="">All outcomes</option>
                                    <option value="resubscribed">Resubscribed</option>
                                    <option value="confirmation_sent">Confirmation sent</option>
                                    <option value="deferred">Waiting to retry</option>
                                    <option value="blocked">Blocked by Mailchimp</option>
                                    <option value="failed">Failed</option>
                                </select>
                            </div>
                            <div v-if="scope === 'event' && locations.length > 0">
                                <label class="block text-xs font-medium text-gray-600 mb-1">Location</label>
                                <select v-model="locationId" class="border rounded px-3 py-2 text-sm min-w-[200px]">
                                    <option value="">All locations</option>
                                    <option v-for="location in locations" :key="location.id" :value="location.id">
                                        {{ location.name }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Email contains</label>
                                <input
                                    v-model="search"
                                    type="text"
                                    placeholder="Search email..."
                                    class="border rounded px-3 py-2 text-sm min-w-[220px]"
                                />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">From</label>
                                <input v-model="from" type="date" class="border rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">To</label>
                                <input v-model="to" type="date" class="border rounded px-3 py-2 text-sm" />
                            </div>
                            <button
                                v-if="hasFilters"
                                @click="resetFilters"
                                class="px-3 py-2 text-sm text-gray-600 underline hover:text-gray-800"
                            >
                                Clear filters
                            </button>
                        </div>

                        <!-- Breakdown -->
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                            <h3 class="text-lg font-semibold text-gray-800">
                                <i class="fa-solid fa-chart-simple mr-2"></i>
                                By {{ breakdownBy === 'event' ? 'event' : 'location' }}
                            </h3>
                            <button
                                v-if="emptyRowCount > 0"
                                @click="showEmptyRows = !showEmptyRows"
                                class="text-sm text-blue-600 underline hover:text-blue-800"
                            >
                                {{ showEmptyRows ? 'Hide' : 'Show' }} {{ emptyRowCount }} with no attempts
                            </button>
                        </div>
                        <div class="overflow-x-auto mb-8">
                            <table class="w-full border-collapse border border-gray-300 text-sm">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th class="border border-gray-300 p-2 text-left">
                                            {{ breakdownBy === 'event' ? 'Event' : 'Location' }}
                                        </th>
                                        <th class="border border-gray-300 p-2 text-right whitespace-nowrap">Resubscribed</th>
                                        <th class="border border-gray-300 p-2 text-right whitespace-nowrap" title="Relayed to Mailchimp's hosted form — back on the list once they click the confirmation">Confirming</th>
                                        <th class="border border-gray-300 p-2 text-right whitespace-nowrap" title="The signup form was busy — the drip retries these">Retrying</th>
                                        <th class="border border-gray-300 p-2 text-right whitespace-nowrap">Blocked</th>
                                        <th class="border border-gray-300 p-2 text-right whitespace-nowrap">Failed</th>
                                        <th class="border border-gray-300 p-2 text-right whitespace-nowrap">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in visibleBreakdown" :key="`${row.id}-${row.name}`" class="even:bg-gray-50">
                                        <td class="border border-gray-300 p-2">{{ row.name }}</td>
                                        <td class="border border-gray-300 p-2 text-right text-green-700">{{ row.resubscribed }}</td>
                                        <td class="border border-gray-300 p-2 text-right text-blue-700">{{ row.confirmation_sent }}</td>
                                        <td class="border border-gray-300 p-2 text-right text-purple-700">{{ row.deferred }}</td>
                                        <td class="border border-gray-300 p-2 text-right text-amber-700">{{ row.blocked_compliance }}</td>
                                        <td class="border border-gray-300 p-2 text-right text-red-700">{{ row.failed }}</td>
                                        <td class="border border-gray-300 p-2 text-right font-semibold">{{ row.total }}</td>
                                    </tr>
                                    <tr v-if="visibleBreakdown.length === 0">
                                        <td colspan="7" class="border border-gray-300 p-4 text-center text-gray-500">
                                            Nothing recorded yet.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Detail -->
                        <h3 class="text-lg font-semibold text-gray-800 mb-3">
                            <i class="fa-solid fa-list-ul mr-2"></i> Every attempt
                            <span class="text-sm font-normal text-gray-500">
                                ({{ (attempts.total ?? 0).toLocaleString() }})
                            </span>
                        </h3>
                        <div class="overflow-x-auto">
                            <table class="w-full border-collapse border border-gray-300 text-sm">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th class="border border-gray-300 p-2 text-left">Email</th>
                                        <th class="border border-gray-300 p-2 text-left">Outcome</th>
                                        <th class="border border-gray-300 p-2 text-left">Why</th>
                                        <th v-if="scope === 'film'" class="border border-gray-300 p-2 text-left">Event</th>
                                        <th class="border border-gray-300 p-2 text-left">Location</th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Audience</th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Attempted</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="attempt in attempts.data" :key="attempt.id" class="even:bg-gray-50">
                                        <td class="border border-gray-300 p-2">{{ attempt.email }}</td>
                                        <td class="border border-gray-300 p-2 whitespace-nowrap">
                                            <span :class="outcomeClass(attempt.outcome)" class="px-2 py-1 rounded text-xs font-medium">
                                                {{ attempt.outcome_label }}
                                            </span>
                                        </td>
                                        <td class="border border-gray-300 p-2 text-gray-600 max-w-md">
                                            {{ attempt.detail || '—' }}
                                        </td>
                                        <td v-if="scope === 'film'" class="border border-gray-300 p-2">
                                            {{ attempt.event_name || '—' }}
                                        </td>
                                        <td class="border border-gray-300 p-2">{{ attempt.location_name || '—' }}</td>
                                        <td class="border border-gray-300 p-2 whitespace-nowrap">
                                            {{ attempt.list_name || '—' }}
                                            <span v-if="attempt.mailchimp_account" class="text-xs text-gray-500">
                                                ({{ attempt.mailchimp_account.toUpperCase() }})
                                            </span>
                                        </td>
                                        <td class="border border-gray-300 p-2 whitespace-nowrap">{{ attempt.created_at }}</td>
                                    </tr>
                                    <tr v-if="attempts.data.length === 0">
                                        <td :colspan="scope === 'film' ? 7 : 6" class="border border-gray-300 p-4 text-center text-gray-500">
                                            No resubscribe attempts match these filters.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div v-if="(attempts.last_page ?? 1) > 1" class="flex justify-between items-center mt-4">
                            <button
                                @click="goToPage(attempts.prev_page_url)"
                                :disabled="!attempts.prev_page_url"
                                class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400 disabled:opacity-50"
                            >
                                Previous
                            </button>
                            <span class="text-gray-700">
                                Page {{ attempts.current_page }} of {{ attempts.last_page }}
                            </span>
                            <button
                                @click="goToPage(attempts.next_page_url)"
                                :disabled="!attempts.next_page_url"
                                class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400 disabled:opacity-50"
                            >
                                Next
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
