<script setup>
/**
 * The landing page: what is on, what needs someone, and how sign-ups compare.
 *
 * Top to bottom it answers three questions in the order they get asked —
 * "is anything waiting on me?", "what is on tonight and this week?", and
 * "which films and locations are pulling people in?".
 */
import { ref, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    events: { type: Array, default: () => [] },
    locations: { type: Array, default: () => [] },
    selectedEventId: [String, Number],
    allDataAttendees: Number,
    eventCount: Number,
    selectedLocationId: String,
    attendeesSelectedLocation: Number,
    attendeesChartData: { type: Array, default: () => [] },
    todayEvents: { type: Object, default: () => ({ locations: [], total_attendees: 0, date: '' }) },
    tomorrowEvents: { type: Object, default: () => ({ locations: [], total_attendees: 0, date: '' }) },
    thisWeekEvents: { type: Object, default: () => ({ locations: [], total_attendees: 0, week_range: '' }) },
});

const page = usePage();

/* ----------------------------------------------------------------- filter -- */
// What is applied, and what the two dropdowns currently say. They differ until
// Apply is pressed, so the page never describes a selection it has not loaded.

const selectedEvent = ref(props.selectedEventId || 'all');
const selectedLocation = ref(props.selectedLocationId || null);
const tempSelectedEvent = ref(props.selectedEventId || 'all');
const tempSelectedLocation = ref(props.selectedLocationId || null);

const isAll = computed(() => selectedEvent.value === 'all');

const filteredLocations = computed(() => {
    if (!Array.isArray(props.locations)) return [];
    if (!tempSelectedEvent.value || tempSelectedEvent.value === 'all') return [];

    return props.locations.filter((location) => location.event_id === tempSelectedEvent.value);
});

/** Locations for the chosen film, grouped under the night they run. */
const groupedLocations = computed(() => filteredLocations.value.reduce((groups, location) => {
    const dateKey = location.date;

    if (!groups[dateKey]) {
        groups[dateKey] = { formattedDate: formatDate(location.date), locations: [] };
    }
    groups[dateKey].locations.push({ ...location, formattedTime: formatTime(location.time) });

    return groups;
}, {}));

const handleEventChange = () => {
    if (tempSelectedEvent.value === 'all') {
        tempSelectedLocation.value = null;
    } else if (!filteredLocations.value.some((location) => location.id === tempSelectedLocation.value)) {
        tempSelectedLocation.value = null;
    }
};

const filterData = () => {
    if (!tempSelectedEvent.value) return;

    selectedEvent.value = tempSelectedEvent.value;
    selectedLocation.value = tempSelectedLocation.value;

    router.get(route('dashboard.filter', { event: tempSelectedEvent.value, location: tempSelectedLocation.value }));
};

const clearFilter = () => {
    tempSelectedEvent.value = 'all';
    tempSelectedLocation.value = null;
    filterData();
};

const selectedEventName = computed(() => {
    if (isAll.value) return 'All films';

    return props.events.find((e) => e.id === selectedEvent.value)?.event_name ?? 'One film';
});

const selectedLocationName = computed(() =>
    props.locations?.find((l) => String(l.id) === String(selectedLocation.value))?.name ?? 'Selected location');

const runningCount = computed(() => props.events.filter((e) => e.is_enabled).length);

/* ------------------------------------------------------------- needs you -- */
// Shared with every page by the server, and the two things on this list are the
// ones that sit unnoticed otherwise: nothing else tells you they are waiting.

const sheetReview = computed(() => page.props.sheetReview ?? null);
const filmSiteAlerts = computed(() => page.props.filmSiteAlerts ?? 0);
const canManageMasterSheet = computed(() => page.props.canManageMasterSheet === true);

const needsYou = computed(() => {
    const items = [];
    const pending = sheetReview.value?.count ?? 0;
    const missing = sheetReview.value?.missing ?? 0;

    if (canManageMasterSheet.value && pending > 0) {
        items.push({
            key: 'sheet',
            tone: 'warn',
            title: `${pending} master-sheet change${pending === 1 ? '' : 's'} waiting`,
            detail: missing > 0
                ? `${missing} of them ${missing === 1 ? 'is a location' : 'are locations'} no longer on the sheet.`
                : 'New and edited locations, ready to review.',
            action: 'Review',
            href: route('sheetSync.index'),
        });
    }

    if (filmSiteAlerts.value > 0) {
        items.push({
            key: 'sites',
            tone: 'bad',
            title: `${filmSiteAlerts.value} film site check${filmSiteAlerts.value === 1 ? '' : 's'} failing`,
            detail: 'A film website is showing different dates from ours, or could not be read.',
            action: 'Open',
            href: route('filmSiteChecks.index'),
        });
    }

    return items;
});

/* ----------------------------------------------------------------- tiles -- */
// One neutral style for all of them. The colours they used to wear said nothing
// about the numbers, so they are gone; the value is the loud part.

const tiles = computed(() => {
    const list = [
        {
            key: 'total',
            label: 'Sign-ups',
            value: props.allDataAttendees || 0,
            sub: isAll.value
                ? `${props.eventCount || 0} films, ${runningCount.value} running`
                : selectedEventName.value,
        },
        {
            key: 'today',
            label: 'Today',
            value: props.todayEvents.total_attendees || 0,
            sub: `${props.todayEvents.locations.length} location${props.todayEvents.locations.length === 1 ? '' : 's'} on`,
        },
        {
            key: 'tomorrow',
            label: 'Tomorrow',
            value: props.tomorrowEvents.total_attendees || 0,
            sub: `${props.tomorrowEvents.locations.length} location${props.tomorrowEvents.locations.length === 1 ? '' : 's'} on`,
        },
        {
            key: 'week',
            label: 'This week',
            value: props.thisWeekEvents.total_attendees || 0,
            sub: props.thisWeekEvents.week_range || `${props.thisWeekEvents.locations.length} locations on`,
        },
    ];

    if (selectedLocation.value) {
        list.push({
            key: 'location',
            label: 'Selected location',
            value: props.attendeesSelectedLocation || 0,
            sub: selectedLocationName.value,
        });
    }

    return list;
});

/* ------------------------------------------------------------ what is on -- */

const sections = computed(() => [
    { key: 'today', title: 'Today', subtitle: props.todayEvents.date, empty: 'Nothing on today', showDate: false, data: props.todayEvents },
    { key: 'tomorrow', title: 'Tomorrow', subtitle: props.tomorrowEvents.date, empty: 'Nothing on tomorrow', showDate: false, data: props.tomorrowEvents },
    { key: 'week', title: 'This week', subtitle: props.thisWeekEvents.week_range, empty: 'Nothing on this week', showDate: true, data: props.thisWeekEvents },
]);

// A row opens that location's sign-up list — the same place the Locations page
// sends you, so the dashboard is a shortcut into it rather than a dead end.
const openLocation = (location) => {
    if (!location.location_id || !location.event_id) return;

    router.get(route('attendees.location', {
        eventId: location.event_id,
        locationId: location.location_id,
    }));
};

/* --------------------------------------------------------------- ranking -- */
// Sign-ups per film, or per location once a film is chosen. Drawn as ranked
// horizontal bars with the figure printed on every row: the names are long, and
// the old upright bars had to turn them on their side to fit.

const sortBy = ref('largest');   // largest | order
const showAllRows = ref(false);
const ROW_LIMIT = 12;

const ranked = computed(() => {
    const rows = props.attendeesChartData.map((item, index) => ({
        key: `${item.location}-${index}`,
        name: item.location,
        count: Number(item.count) || 0,
        meta: isAll.value
            ? [item.location_count != null ? `${item.location_count} location${item.location_count === 1 ? '' : 's'}` : null, item.date]
                .filter(Boolean).join(' · ')
            : [item.date, item.time || 'time TBA'].filter(Boolean).join(' · '),
        noForm: item.has_signup_form === false,
        starts: Date.parse(item.date) || 0,
    }));

    if (sortBy.value === 'largest') return [...rows].sort((a, b) => b.count - a.count);

    // Locations already arrive in date order. Films arrive in the order they
    // were added, so "newest first" sorts them by their start date here.
    return isAll.value ? [...rows].sort((a, b) => b.starts - a.starts) : rows;
});

const largest = computed(() => Math.max(1, ...ranked.value.map((row) => row.count)));
const collapsible = computed(() => ranked.value.length > ROW_LIMIT + 4);
const visibleRows = computed(() => (collapsible.value && !showAllRows.value ? ranked.value.slice(0, ROW_LIMIT) : ranked.value));

/** Width of a bar as a share of the largest, so the biggest fills the track. */
const barWidth = (count) => `${Math.max(count > 0 ? 1.5 : 0, (count / largest.value) * 100)}%`;

/* ------------------------------------------------------------ formatting -- */

function formatDate(dateString) {
    if (!dateString) return '';
    if (String(dateString).includes(',')) return dateString;

    const date = new Date(dateString);
    if (isNaN(date.getTime())) return dateString;

    return date.toLocaleDateString('en-US', { month: 'long', day: '2-digit', year: 'numeric' });
}

function formatTime(timeString) {
    if (!timeString) return '';
    if (timeString.includes('AM') || timeString.includes('PM')) return timeString.replace(' ', '');
    if (!timeString.includes(':')) return timeString;

    const [hours, minutes] = timeString.split(':');
    const date = new Date();
    date.setHours(parseInt(hours));
    date.setMinutes(parseInt(minutes));

    return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true }).replace(' ', '');
}
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1 basis-full sm:basis-0">
                    <p class="truncate text-xs font-semibold text-gray-500">
                        {{ eventCount || events.length }} films · {{ runningCount }} running
                        <span v-if="!isAll"> · showing {{ selectedEventName }}</span>
                    </p>
                    <h2 class="truncate text-2xl font-extrabold tracking-tight text-gray-900">Dashboard</h2>
                </div>
                <Link
                    :href="route('events.index')"
                    class="flex h-10 items-center rounded-lg border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                >All films</Link>
                <Link
                    :href="route('weekly-report')"
                    class="flex h-10 items-center rounded-lg bg-[#16C3D9] px-4 text-sm font-bold text-[#06242A] hover:brightness-105"
                >Weekly report</Link>
            </div>
        </template>

        <div class="w-full space-y-5 px-4 py-6 sm:px-6 lg:px-8">

            <!-- Waiting on someone. Absent when nothing is. -->
            <div v-if="needsYou.length" class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                <div
                    v-for="item in needsYou" :key="item.key"
                    class="flex flex-wrap items-center gap-3 rounded-xl px-4 py-3"
                    :class="item.tone === 'bad' ? 'bg-red-50' : 'bg-amber-50'"
                >
                    <Icon name="triangle-exclamation" :class="item.tone === 'bad' ? 'text-red-700' : 'text-amber-700'" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold" :class="item.tone === 'bad' ? 'text-red-900' : 'text-amber-900'">{{ item.title }}</p>
                        <p class="text-sm" :class="item.tone === 'bad' ? 'text-red-800' : 'text-amber-800'">{{ item.detail }}</p>
                    </div>
                    <Link
                        :href="item.href"
                        class="flex h-9 items-center rounded-lg bg-white px-4 text-sm font-bold shadow-sm"
                        :class="item.tone === 'bad' ? 'text-red-800' : 'text-amber-900'"
                    >{{ item.action }}</Link>
                </div>
            </div>

            <!-- One filter row, above everything it changes -->
            <div class="flex flex-wrap items-end gap-3 rounded-xl bg-white p-4 shadow-card">
                <label class="min-w-[200px] flex-1 sm:max-w-sm">
                    <span class="mb-1 block text-xs font-bold text-gray-600">Film</span>
                    <select
                        v-model="tempSelectedEvent" @change="handleEventChange"
                        class="h-10 w-full rounded-lg border-gray-200 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]"
                    >
                        <option value="all">All films</option>
                        <option v-for="event in events" :key="event.id" :value="event.id">{{ event.event_name }}</option>
                    </select>
                </label>

                <label v-if="locations.length > 0 && tempSelectedEvent !== 'all'" class="min-w-[200px] flex-1 sm:max-w-sm">
                    <span class="mb-1 block text-xs font-bold text-gray-600">Location</span>
                    <select
                        v-model="tempSelectedLocation" :disabled="filteredLocations.length === 0"
                        class="h-10 w-full rounded-lg border-gray-200 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]"
                    >
                        <option :value="null">Every location</option>
                        <template v-for="(dateGroup, dateKey) in groupedLocations" :key="dateKey">
                            <optgroup :label="dateGroup.formattedDate">
                                <option v-for="location in dateGroup.locations" :key="location.id" :value="location.id">
                                    {{ location.name }} - {{ location.formattedTime }}
                                </option>
                            </optgroup>
                        </template>
                    </select>
                </label>

                <button
                    type="button" @click="filterData"
                    class="h-10 rounded-lg bg-[#16C3D9] px-5 text-sm font-bold text-[#06242A] hover:brightness-105"
                >Apply</button>
                <button
                    v-if="!isAll" type="button" @click="clearFilter"
                    class="h-10 rounded-lg border border-gray-200 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                >Show all films</button>
            </div>

            <!-- The headline numbers -->
            <div class="grid grid-cols-2 gap-3 sm:gap-4" :class="tiles.length > 4 ? 'lg:grid-cols-5' : 'lg:grid-cols-4'">
                <div v-for="tile in tiles" :key="tile.key" class="min-w-0 rounded-xl bg-white p-4 shadow-card sm:p-5">
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-500">{{ tile.label }}</p>
                    <p class="mt-1 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">{{ Number(tile.value).toLocaleString() }}</p>
                    <p class="mt-1 truncate text-xs text-gray-500">{{ tile.sub }}</p>
                </div>
            </div>

            <!-- What is on -->
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <section v-for="section in sections" :key="section.key" class="flex min-w-0 flex-col rounded-xl bg-white shadow-card">
                    <div class="flex items-baseline justify-between gap-3 border-b border-gray-100 px-5 py-3.5">
                        <h3 class="text-sm font-extrabold tracking-tight text-gray-900">{{ section.title }}</h3>
                        <span class="truncate text-xs text-gray-500">{{ section.subtitle }}</span>
                    </div>

                    <p v-if="section.data.locations.length === 0" class="flex flex-1 items-center justify-center px-5 py-10 text-sm text-gray-400">
                        {{ section.empty }}
                    </p>

                    <template v-else>
                        <div class="max-h-[340px] flex-1 divide-y divide-gray-100 overflow-y-auto">
                            <button
                                v-for="(location, i) in section.data.locations" :key="`${section.key}-${i}`"
                                type="button" @click="openLocation(location)"
                                class="flex w-full items-center gap-3 px-5 py-3 text-left hover:bg-gray-50 focus:bg-gray-50 focus:outline-none"
                                :title="`View sign-ups for ${location.location_name}`"
                            >
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-gray-900">{{ location.location_name }}</span>
                                    <span class="block truncate text-xs text-gray-500">
                                        {{ location.event_name }} ·
                                        <span v-if="section.showDate">{{ location.date }}, </span>{{ location.time || 'time TBA' }}
                                    </span>
                                </span>
                                <span class="shrink-0 text-sm font-bold tabular-nums text-gray-900">{{ location.attendees }}</span>
                                <svg class="h-4 w-4 shrink-0 text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round"><path d="m9 6 6 6-6 6" /></svg>
                            </button>
                        </div>
                        <div class="flex items-center justify-between border-t border-gray-100 px-5 py-3">
                            <span class="text-xs text-gray-500">{{ section.data.locations.length }} location{{ section.data.locations.length === 1 ? '' : 's' }}</span>
                            <span class="text-sm font-bold tabular-nums text-gray-900">{{ section.data.total_attendees }} sign-ups</span>
                        </div>
                    </template>
                </section>
            </div>

            <!-- Sign-ups, ranked -->
            <section class="rounded-xl bg-white shadow-card">
                <div class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-5 py-3.5">
                    <div class="min-w-0 flex-1 basis-full sm:basis-0">
                        <h3 class="text-sm font-extrabold tracking-tight text-gray-900">
                            {{ isAll ? 'Sign-ups by film' : `Sign-ups by location — ${selectedEventName}` }}
                        </h3>
                        <p class="text-xs text-gray-500">
                            {{ ranked.length }} {{ isAll ? 'film' : 'location' }}{{ ranked.length === 1 ? '' : 's' }}
                        </p>
                    </div>
                    <div v-if="ranked.length > 1" class="flex rounded-lg border border-gray-200 p-0.5 text-xs font-semibold">
                        <button
                            type="button" @click="sortBy = 'largest'"
                            class="rounded-md px-3 py-1.5"
                            :class="sortBy === 'largest' ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-50'"
                        >Largest first</button>
                        <button
                            type="button" @click="sortBy = 'order'"
                            class="rounded-md px-3 py-1.5"
                            :class="sortBy === 'order' ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-50'"
                        >{{ isAll ? 'Newest first' : 'By date' }}</button>
                    </div>
                </div>

                <p v-if="!ranked.length" class="px-5 py-14 text-center text-sm text-gray-400">
                    {{ isAll ? 'No films yet.' : 'No sign-up data for this film yet.' }}
                </p>

                <ol v-else class="divide-y divide-gray-50 px-5 py-2">
                    <li
                        v-for="row in visibleRows" :key="row.key"
                        class="grid items-center gap-x-4 gap-y-1 py-2.5 sm:grid-cols-[minmax(0,280px)_minmax(0,1fr)_72px]"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-gray-900">{{ row.name }}</p>
                            <p class="truncate text-xs text-gray-500">
                                {{ row.meta }}
                                <span v-if="row.noForm" class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 font-semibold text-amber-800">no sign-up form</span>
                            </p>
                        </div>
                        <!-- Square at the baseline, rounded at the data end. -->
                        <div class="h-3 rounded-r bg-gray-100">
                            <div class="h-3 rounded-r bg-[#16C3D9]" :style="{ width: barWidth(row.count) }"></div>
                        </div>
                        <p class="text-sm font-bold tabular-nums text-gray-900 sm:text-right">{{ row.count.toLocaleString() }}</p>
                    </li>
                </ol>

                <div v-if="collapsible" class="border-t border-gray-100 px-5 py-3 text-center">
                    <button type="button" @click="showAllRows = !showAllRows" class="text-sm font-semibold text-[#0B7A8A] hover:underline">
                        {{ showAllRows ? `Show the top ${ROW_LIMIT}` : `Show all ${ranked.length}` }}
                    </button>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
