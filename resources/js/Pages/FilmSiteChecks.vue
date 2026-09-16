<script setup>
import { ref, computed, nextTick } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Swal from 'sweetalert2';
import axios from 'axios';

const props = defineProps({
    checks: { type: Array, default: () => [] },
    runs: { type: Array, default: () => [] },
    events: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

// --- Summary ---------------------------------------------------------------

const activeChecks = computed(() => props.checks.filter((c) => c.enabled));

const errorTotal = computed(() =>
    activeChecks.value.reduce((t, c) => t + (c.latest_run?.errors ?? 0), 0)
);

const warningTotal = computed(() =>
    activeChecks.value.reduce((t, c) => t + (c.latest_run?.warnings ?? 0), 0)
);

const failedCount = computed(() => activeChecks.value.filter((c) => c.last_status === 'failed').length);

const lastCheckedAt = computed(() => {
    const stamps = props.checks.map((c) => c.last_checked_at).filter(Boolean).sort();
    return stamps.length ? stamps[stamps.length - 1] : null;
});

// --- Add a check -----------------------------------------------------------

const siteInput = ref('');
const loadingSite = ref(false);
const loadedSite = ref(null);
const regions = ref([]);
const seasons = ref([]);
// Several regions: one Win App event can span regions the site files
// separately (WAFT holds Australia and New Zealand together).
const newCheck = ref({ event_id: '', regions: [], season: '' });
const adding = ref(false);

async function loadSite() {
    if (!siteInput.value.trim()) return;

    loadingSite.value = true;
    loadedSite.value = null;

    try {
        const { data } = await axios.post(route('filmSiteChecks.taxonomies'), { site_url: siteInput.value });
        loadedSite.value = data.site_url;
        regions.value = data.regions;
        seasons.value = data.seasons;
        newCheck.value.season = data.seasons[0]?.slug ?? '';
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Could not read that site', text: e.response?.data?.message ?? 'Something went wrong.' });
    } finally {
        loadingSite.value = false;
    }
}

function addCheck() {
    if (!loadedSite.value || !newCheck.value.event_id) return;

    adding.value = true;

    router.post(route('filmSiteChecks.store'), {
        site_url: loadedSite.value,
        event_id: newCheck.value.event_id,
        regions: newCheck.value.regions,
        season: newCheck.value.season || null,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            siteInput.value = '';
            loadedSite.value = null;
            regions.value = [];
            seasons.value = [];
            newCheck.value = { event_id: '', regions: [], season: '' };
        },
        onFinish: () => { adding.value = false; },
    });
}

// --- Per-check actions -----------------------------------------------------

const busy = ref({});

function runNow(check) {
    busy.value = { ...busy.value, [check.id]: true };

    router.post(route('filmSiteChecks.run', { filmSiteCheck: check.id }), {}, {
        preserveScroll: true,
        onSuccess: () => {
            // Follow the check to its new run if its results are open.
            if (selectedRun.value?.check_id === check.id) {
                const latest = props.checks.find((c) => c.id === check.id)?.latest_run;
                if (latest) openRun(latest.id, false);
            }
        },
        onFinish: () => { busy.value = { ...busy.value, [check.id]: false }; },
    });
}

function toggleEnabled(check) {
    router.patch(route('filmSiteChecks.update', { filmSiteCheck: check.id }), { enabled: !check.enabled }, { preserveScroll: true });
}

function changeEvent(check, eventId) {
    router.patch(route('filmSiteChecks.update', { filmSiteCheck: check.id }), { event_id: eventId }, { preserveScroll: true });
}

async function remove(check) {
    const result = await Swal.fire({
        icon: 'warning',
        title: `Remove "${check.label}"?`,
        text: 'The check and its run log are deleted. Locations and the film site are not touched.',
        showCancelButton: true,
        confirmButtonText: 'Remove',
        confirmButtonColor: '#dc2626',
    });

    if (!result.isConfirmed) return;

    router.delete(route('filmSiteChecks.destroy', { filmSiteCheck: check.id }), {
        preserveScroll: true,
        onSuccess: () => {
            if (selectedRun.value?.check_id === check.id) selectedRun.value = null;
        },
    });
}

// --- Run detail ------------------------------------------------------------

const selectedRun = ref(null);
const loadingRun = ref(false);
const severityFilter = ref('issues');
const search = ref('');
const onlyNew = ref(false);
const detailEl = ref(null);

async function openRun(runId, scroll = true) {
    loadingRun.value = true;

    try {
        const { data } = await axios.get(route('filmSiteChecks.showRun', { filmSiteCheckRun: runId }));
        selectedRun.value = data;
        severityFilter.value = data.errors + data.warnings > 0 ? 'issues' : 'all';
        onlyNew.value = false;
        search.value = '';

        if (scroll) {
            await nextTick();
            detailEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    } catch {
        Swal.fire({ icon: 'error', title: 'Could not load that run' });
    } finally {
        loadingRun.value = false;
    }
}

const SEVERITY_ORDER = { error: 0, warning: 1, notice: 2, ok: 3 };

const severityTabs = computed(() => {
    const items = selectedRun.value?.items ?? [];
    const count = (s) => items.filter((i) => i.severity === s).length;

    return [
        { key: 'issues', label: 'Errors & warnings', count: count('error') + count('warning') },
        { key: 'error', label: 'Errors', count: count('error') },
        { key: 'warning', label: 'Warnings', count: count('warning') },
        { key: 'notice', label: 'Notices', count: count('notice') },
        { key: 'ok', label: 'Matched', count: count('ok') },
        { key: 'all', label: 'All', count: items.length },
    ];
});

const visibleItems = computed(() => {
    const q = search.value.trim().toLowerCase();

    return (selectedRun.value?.items ?? [])
        .filter((i) => {
            if (severityFilter.value === 'issues') return i.severity === 'error' || i.severity === 'warning';
            if (severityFilter.value === 'all') return true;
            return i.severity === severityFilter.value;
        })
        .filter((i) => !onlyNew.value || i.new)
        .filter((i) => !q || [i.place, i.app?.name, i.site?.title, i.site?.venue].some((v) => (v ?? '').toLowerCase().includes(q)))
        .sort((a, b) => SEVERITY_ORDER[a.severity] - SEVERITY_ORDER[b.severity] || a.place.localeCompare(b.place));
});

// --- Run log ---------------------------------------------------------------

const logCheck = ref('');
const logStatus = ref('');

const visibleRuns = computed(() =>
    props.runs.filter((r) =>
        (!logCheck.value || r.check_id === Number(logCheck.value))
        && (!logStatus.value || r.status === logStatus.value)
    )
);

// --- Display helpers -------------------------------------------------------

const STATUS = {
    clean: { label: 'All good', badge: 'bg-green-100 text-green-700' },
    warnings: { label: 'Warnings', badge: 'bg-amber-100 text-amber-800' },
    errors: { label: 'Errors', badge: 'bg-red-100 text-red-800' },
    failed: { label: 'Check failed', badge: 'bg-gray-800 text-white' },
};

const SEVERITY = {
    error: { label: 'Error', dot: 'bg-red-500', text: 'text-red-700' },
    warning: { label: 'Warning', dot: 'bg-amber-500', text: 'text-amber-700' },
    notice: { label: 'Notice', dot: 'bg-sky-500', text: 'text-sky-700' },
    ok: { label: 'Matched', dot: 'bg-green-500', text: 'text-green-700' },
};

const TYPE_LABELS = {
    date_mismatch: 'Date differs',
    not_on_site: 'Not on the site',
    not_in_app: 'Not in the Win App',
    no_site_date: 'No date on the site',
    matched: 'Matched',
};

function statusOf(status) {
    return STATUS[status] ?? { label: 'Never run', badge: 'bg-gray-100 text-gray-600' };
}

function whenText(iso) {
    if (!iso) return 'never';
    return new Date(iso).toLocaleString('en-AU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

function dayText(ymd) {
    if (!ymd) return '—';
    const [y, m, d] = ymd.split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString('en-AU', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
}

function triggerText(run) {
    if (run.trigger === 'manual') return run.triggered_by ? `Run by ${run.triggered_by}` : 'Run now';
    if (run.trigger === 'cli') return 'Command line';
    return 'Scheduled';
}
</script>

<template>
    <Head title="Film Site Check" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-xl font-semibold text-gray-800 leading-tight">Film Site Check</h2>
                <p class="text-sm text-gray-500">Our locations compared with the shows each film website publishes</p>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 space-y-6">

                <div v-if="flash.error" class="rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
                    {{ flash.error }}
                </div>
                <div v-if="flash.success" class="rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
                    {{ flash.success }}
                </div>

                <!-- Summary -->
                <section class="grid gap-3 grid-cols-2 lg:grid-cols-4">
                    <div class="bg-white shadow-sm rounded-lg p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Sites checked</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">{{ activeChecks.length }}</p>
                        <p class="mt-0.5 text-xs text-gray-500">
                            Every hour &middot; last {{ whenText(lastCheckedAt) }}
                        </p>
                    </div>
                    <div class="bg-white shadow-sm rounded-lg p-4" :class="errorTotal > 0 ? 'ring-1 ring-red-200' : ''">
                        <p class="text-[11px] font-semibold uppercase tracking-wide" :class="errorTotal > 0 ? 'text-red-600' : 'text-gray-400'">Errors</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums" :class="errorTotal > 0 ? 'text-red-700' : 'text-gray-900'">{{ errorTotal }}</p>
                        <p class="mt-0.5 text-xs text-gray-500">Shows on a different date</p>
                    </div>
                    <div class="bg-white shadow-sm rounded-lg p-4" :class="warningTotal > 0 ? 'ring-1 ring-amber-200' : ''">
                        <p class="text-[11px] font-semibold uppercase tracking-wide" :class="warningTotal > 0 ? 'text-amber-600' : 'text-gray-400'">Warnings</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums" :class="warningTotal > 0 ? 'text-amber-700' : 'text-gray-900'">{{ warningTotal }}</p>
                        <p class="mt-0.5 text-xs text-gray-500">Upcoming shows listed on only one side</p>
                    </div>
                    <div class="bg-white shadow-sm rounded-lg p-4" :class="failedCount > 0 ? 'ring-1 ring-gray-400' : ''">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Failed checks</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">{{ failedCount }}</p>
                        <p class="mt-0.5 text-xs text-gray-500">Site unreachable or misconfigured</p>
                    </div>
                </section>

                <p class="text-xs text-teal-800 bg-teal-50 border border-teal-200 rounded-md px-3 py-2">
                    Read-only. Checks only <strong>read</strong> the film site's public listings and our locations &mdash;
                    nothing is changed on the website or in the Win App. Fix a difference on whichever side is wrong,
                    then press <strong>Run now</strong>. The site stops publishing a show's date once it has happened,
                    so past shows can only be checked for being listed, and show up as notices rather than warnings.
                </p>

                <!-- Checks -->
                <section class="bg-white shadow-sm rounded-lg">
                    <div class="px-5 py-4 border-b border-gray-200">
                        <h3 class="text-base font-semibold text-gray-800">Sites</h3>
                    </div>

                    <div v-if="!props.checks.length" class="px-5 py-10 text-center text-sm text-gray-500">
                        No sites checked yet. Add one below.
                    </div>

                    <div v-else class="divide-y divide-gray-200">
                        <div v-for="check in props.checks" :key="check.id" class="px-5 py-4"
                             :class="selectedRun?.check_id === check.id ? 'bg-teal-50/40' : ''">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <a :href="check.site_url" target="_blank" rel="noopener" class="font-medium text-gray-900 hover:underline">
                                            {{ check.label }}
                                        </a>
                                        <span v-if="!check.enabled" class="text-xs rounded-full bg-gray-200 text-gray-600 px-2 py-0.5">Paused</span>
                                        <span class="text-xs rounded-full px-2 py-0.5" :class="statusOf(check.last_status).badge">
                                            {{ statusOf(check.last_status).label }}
                                        </span>
                                        <span v-if="check.latest_run?.new_issues" class="text-xs rounded-full bg-rose-100 text-rose-800 px-2 py-0.5">
                                            {{ check.latest_run.new_issues }} new
                                        </span>
                                        <span v-if="check.latest_run?.resolved_issues" class="text-xs rounded-full bg-green-100 text-green-700 px-2 py-0.5">
                                            {{ check.latest_run.resolved_issues }} fixed
                                        </span>
                                    </div>

                                    <div class="mt-1 flex items-center gap-2 text-xs text-gray-500 flex-wrap">
                                        <span>Compared with</span>
                                        <select :value="check.event_id" @change="changeEvent(check, $event.target.value)"
                                                class="rounded border-gray-300 text-xs py-0.5 pr-7">
                                            <option v-for="evt in props.events" :key="evt.id" :value="evt.id">{{ evt.label }}</option>
                                        </select>
                                        <span>·</span>
                                        <span>Last run {{ whenText(check.last_checked_at) }}</span>
                                    </div>

                                    <p v-if="check.last_status === 'failed'" class="mt-2 text-sm text-red-700">{{ check.last_error }}</p>
                                    <p v-else-if="check.latest_run" class="mt-2 text-sm text-gray-600 tabular-nums">
                                        <span class="text-red-700 font-medium">{{ check.latest_run.errors }} error{{ check.latest_run.errors === 1 ? '' : 's' }}</span>
                                        &middot;
                                        <span class="text-amber-700 font-medium">{{ check.latest_run.warnings }} warning{{ check.latest_run.warnings === 1 ? '' : 's' }}</span>
                                        &middot;
                                        <span class="text-sky-700">{{ check.latest_run.notices }} notice{{ check.latest_run.notices === 1 ? '' : 's' }}</span>
                                        &middot;
                                        <span class="text-green-700">{{ check.latest_run.matched }} matched</span>
                                    </p>
                                </div>

                                <div class="shrink-0 flex flex-wrap items-center gap-2">
                                    <button v-if="check.latest_run" @click="openRun(check.latest_run.id)"
                                            class="rounded-md bg-gray-800 px-3 py-1.5 text-xs font-medium text-white hover:bg-gray-900">
                                        View results
                                    </button>
                                    <button @click="runNow(check)" :disabled="busy[check.id]"
                                            class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40">
                                        {{ busy[check.id] ? 'Checking…' : 'Run now' }}
                                    </button>
                                    <button @click="toggleEnabled(check)"
                                            class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                        {{ check.enabled ? 'Pause' : 'Resume' }}
                                    </button>
                                    <button @click="remove(check)"
                                            class="rounded-md border border-red-300 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50">
                                        Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Add a site -->
                <section class="bg-white shadow-sm rounded-lg p-5">
                    <h3 class="text-base font-semibold text-gray-800 mb-1">Add a site</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Paste the film website, then pick the region and season and the Win App event to compare it with.
                    </p>

                    <div class="flex flex-col sm:flex-row gap-2">
                        <input v-model="siteInput" type="text" placeholder="https://womensadventurefilmtour.com"
                               class="flex-1 rounded-md border-gray-300 text-sm" @keyup.enter="loadSite" />
                        <button @click="loadSite" :disabled="loadingSite || !siteInput.trim()"
                                class="shrink-0 rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-900 disabled:opacity-40">
                            {{ loadingSite ? 'Reading…' : 'Load site' }}
                        </button>
                    </div>

                    <div v-if="loadedSite" class="mt-4 grid gap-3 sm:grid-cols-2">
                        <fieldset v-if="regions.length" class="sm:col-span-2">
                            <legend class="text-xs font-medium text-gray-600">
                                Regions <span class="font-normal text-gray-400">&mdash; tick every region this event's locations cover; none ticked compares all</span>
                            </legend>
                            <div class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1.5">
                                <label v-for="r in regions" :key="r.slug" class="flex items-center gap-1.5 text-sm text-gray-700">
                                    <input v-model="newCheck.regions" :value="r.slug" type="checkbox" class="rounded border-gray-300 text-teal-600" />
                                    {{ r.name }}
                                </label>
                            </div>
                        </fieldset>
                        <select v-model="newCheck.season" class="rounded-md border-gray-300 text-sm">
                            <option value="">All seasons</option>
                            <option v-for="s in seasons" :key="s.slug" :value="s.slug">Season {{ s.name }}</option>
                        </select>
                        <select v-model="newCheck.event_id" class="rounded-md border-gray-300 text-sm">
                            <option value="">Choose a Win App event…</option>
                            <option v-for="evt in props.events" :key="evt.id" :value="evt.id">{{ evt.label }}</option>
                        </select>
                        <div class="sm:col-span-2">
                            <button @click="addCheck" :disabled="adding || !newCheck.event_id"
                                    class="rounded-md bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700 disabled:opacity-40">
                                {{ adding ? 'Checking…' : 'Add & run first check' }}
                            </button>
                        </div>
                    </div>
                </section>

                <!-- Run detail -->
                <section v-if="selectedRun || loadingRun" ref="detailEl" class="bg-white shadow-sm rounded-lg scroll-mt-4">
                    <div v-if="loadingRun && !selectedRun" class="px-5 py-10 text-center text-sm text-gray-500">Loading…</div>

                    <template v-else-if="selectedRun">
                        <div class="px-5 py-4 border-b border-gray-200 flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-gray-800">
                                    {{ selectedRun.check_label }}
                                    <span class="font-normal text-gray-500">vs {{ selectedRun.event_name }}</span>
                                </h3>
                                <p class="mt-0.5 text-xs text-gray-500">
                                    Run #{{ selectedRun.id }} &middot; {{ whenText(selectedRun.created_at) }} &middot;
                                    {{ triggerText(selectedRun) }} &middot;
                                    {{ selectedRun.meta?.site_shows ?? 0 }} shows on the site,
                                    {{ selectedRun.meta?.app_locations ?? 0 }} Win App locations
                                </p>
                            </div>
                            <button @click="selectedRun = null"
                                    class="shrink-0 rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                Close
                            </button>
                        </div>

                        <div v-if="selectedRun.status === 'failed'" class="px-5 py-6 text-sm text-red-700">
                            This run could not compare anything: {{ selectedRun.error }}
                        </div>

                        <template v-else>
                            <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 px-5 py-2">
                                <div class="flex flex-wrap gap-1">
                                    <button v-for="tab in severityTabs" :key="tab.key" @click="severityFilter = tab.key"
                                            :class="['rounded px-3 py-1 text-xs font-medium',
                                                     severityFilter === tab.key ? 'bg-gray-100 text-teal-700' : 'text-gray-500 hover:text-gray-700']">
                                        {{ tab.label }} ({{ tab.count }})
                                    </button>
                                </div>
                                <div class="ms-auto flex items-center gap-3">
                                    <label v-if="selectedRun.new_issues" class="flex items-center gap-1.5 text-xs text-gray-600">
                                        <input v-model="onlyNew" type="checkbox" class="rounded border-gray-300 text-teal-600" />
                                        Only new ({{ selectedRun.new_issues }})
                                    </label>
                                    <input v-model="search" type="search" placeholder="Search place or venue"
                                           class="rounded-md border-gray-300 text-xs py-1 w-48" />
                                </div>
                            </div>

                            <div v-if="!visibleItems.length" class="px-5 py-8 text-center text-sm text-gray-500">
                                Nothing in this group.
                            </div>

                            <div v-else class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-gray-50 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                        <tr>
                                            <th class="px-5 py-2">Issue</th>
                                            <th class="px-3 py-2">Place</th>
                                            <th class="px-3 py-2">Win App</th>
                                            <th class="px-3 py-2">Film site</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr v-for="item in visibleItems" :key="item.key" class="align-top">
                                            <td class="px-5 py-2.5 whitespace-nowrap">
                                                <div class="flex items-center gap-2">
                                                    <span class="h-2 w-2 rounded-full shrink-0" :class="SEVERITY[item.severity].dot"></span>
                                                    <span class="font-medium" :class="SEVERITY[item.severity].text">{{ TYPE_LABELS[item.type] }}</span>
                                                    <span v-if="item.new" class="rounded bg-rose-100 px-1.5 text-[10px] font-semibold uppercase text-rose-700">New</span>
                                                </div>
                                                <p class="mt-0.5 text-xs text-gray-500 whitespace-normal max-w-xs">{{ item.message }}</p>
                                            </td>
                                            <td class="px-3 py-2.5 capitalize text-gray-800">{{ item.place }}</td>
                                            <td class="px-3 py-2.5">
                                                <template v-if="item.app">
                                                    <p class="text-gray-800">{{ item.app.name }}</p>
                                                    <p class="text-xs tabular-nums"
                                                       :class="item.type === 'date_mismatch' ? 'text-red-700 font-medium' : 'text-gray-500'">
                                                        {{ dayText(item.app.date) }}<span v-if="item.app.time"> &middot; {{ item.app.time }}</span>
                                                    </p>
                                                    <p v-if="item.app.status" class="text-xs text-gray-400">{{ item.app.status }}</p>
                                                </template>
                                                <span v-else class="text-xs text-gray-400 italic">No location</span>
                                            </td>
                                            <td class="px-3 py-2.5">
                                                <template v-if="item.site">
                                                    <p class="text-gray-800">
                                                        <a v-if="item.site.link" :href="item.site.link" target="_blank" rel="noopener" class="hover:underline">{{ item.site.title }}</a>
                                                        <template v-else>{{ item.site.title }}</template>
                                                    </p>
                                                    <p class="text-xs tabular-nums"
                                                       :class="item.type === 'date_mismatch' ? 'text-green-700 font-medium' : 'text-gray-500'">
                                                        <template v-if="item.site.tbc">TBC</template>
                                                        <template v-else>{{ dayText(item.site.date) }}<span v-if="item.site.time"> &middot; {{ item.site.time }}</span></template>
                                                    </p>
                                                    <p v-if="item.site.venue || item.site.status" class="text-xs text-gray-400">
                                                        {{ [item.site.venue, item.site.status].filter(Boolean).join(' · ') }}
                                                    </p>
                                                </template>
                                                <span v-else class="text-xs text-gray-400 italic">Not listed</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                    </template>
                </section>

                <!-- Run log -->
                <section class="bg-white shadow-sm rounded-lg">
                    <div class="px-5 py-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-gray-800">Run log</h3>
                            <p class="text-xs text-gray-500">The last 100 runs. Kept for 30 days.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <select v-model="logCheck" class="rounded-md border-gray-300 text-xs py-1 pr-7">
                                <option value="">All sites</option>
                                <option v-for="c in props.checks" :key="c.id" :value="c.id">{{ c.label }}</option>
                            </select>
                            <select v-model="logStatus" class="rounded-md border-gray-300 text-xs py-1 pr-7">
                                <option value="">Any result</option>
                                <option v-for="(s, key) in STATUS" :key="key" :value="key">{{ s.label }}</option>
                            </select>
                        </div>
                    </div>

                    <div v-if="!visibleRuns.length" class="px-5 py-8 text-center text-sm text-gray-500">No runs yet.</div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-5 py-2">When</th>
                                    <th class="px-3 py-2">Site</th>
                                    <th class="px-3 py-2">Result</th>
                                    <th class="px-3 py-2 text-right">Errors</th>
                                    <th class="px-3 py-2 text-right">Warnings</th>
                                    <th class="px-3 py-2">Changes</th>
                                    <th class="px-3 py-2">Trigger</th>
                                    <th class="px-3 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="run in visibleRuns" :key="run.id"
                                    :class="selectedRun?.id === run.id ? 'bg-teal-50' : 'hover:bg-gray-50'">
                                    <td class="px-5 py-2 whitespace-nowrap text-gray-700">{{ whenText(run.created_at) }}</td>
                                    <td class="px-3 py-2">
                                        <p class="text-gray-800">{{ run.check_label }}</p>
                                        <p class="text-xs text-gray-400">{{ run.event_name }}</p>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="text-xs rounded-full px-2 py-0.5 whitespace-nowrap" :class="statusOf(run.status).badge">
                                            {{ statusOf(run.status).label }}
                                        </span>
                                        <p v-if="run.error" class="mt-0.5 text-xs text-red-700 max-w-xs">{{ run.error }}</p>
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums" :class="run.errors ? 'text-red-700 font-medium' : 'text-gray-400'">{{ run.errors }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums" :class="run.warnings ? 'text-amber-700 font-medium' : 'text-gray-400'">{{ run.warnings }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap text-xs">
                                        <span v-if="run.new_issues" class="text-rose-700">+{{ run.new_issues }} new</span>
                                        <span v-if="run.new_issues && run.resolved_issues" class="text-gray-300"> / </span>
                                        <span v-if="run.resolved_issues" class="text-green-700">{{ run.resolved_issues }} fixed</span>
                                        <span v-if="!run.new_issues && !run.resolved_issues" class="text-gray-400">—</span>
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-500">{{ triggerText(run) }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <button v-if="run.status !== 'failed'" @click="openRun(run.id)"
                                                class="text-xs font-medium text-teal-700 hover:text-teal-900">View</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
