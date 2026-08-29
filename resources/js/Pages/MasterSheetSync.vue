<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Swal from 'sweetalert2';
import axios from 'axios';

const props = defineProps({
    configured: { type: Boolean, default: false },
    connection: { type: Object, default: null },
    sources: { type: Array, default: () => [] },
    events: { type: Array, default: () => [] },
    regions: { type: Object, default: () => ({}) },
});

const page = usePage();

// --- Add a tab -------------------------------------------------------------

const spreadsheetInput = ref('');
const loadingTabs = ref(false);
const loadedSpreadsheetId = ref(null);
const loadedSpreadsheetTitle = ref('');
const availableTabs = ref([]);
const newSource = ref({ tab_name: '', event_id: '', region: '' });

const connected = computed(() => props.connection && props.connection.status === 'active');

async function loadTabs() {
    if (!spreadsheetInput.value.trim()) return;

    loadingTabs.value = true;
    availableTabs.value = [];
    loadedSpreadsheetId.value = null;

    try {
        const { data } = await axios.post(route('sheetSync.tabs'), { spreadsheet: spreadsheetInput.value });
        loadedSpreadsheetId.value = data.spreadsheet_id;
        loadedSpreadsheetTitle.value = data.title;
        availableTabs.value = data.tabs;

        if (!data.tabs.length) {
            Swal.fire({ icon: 'info', title: 'No tabs', text: 'That spreadsheet has no readable tabs.' });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Could not read that sheet', text: e.response?.data?.message ?? 'Something went wrong.' });
    } finally {
        loadingTabs.value = false;
    }
}

function addSource() {
    if (!newSource.value.tab_name || !newSource.value.event_id) return;

    router.post(route('sheetSync.store'), {
        spreadsheet: loadedSpreadsheetId.value,
        tab_name: newSource.value.tab_name,
        event_id: newSource.value.event_id,
        region: newSource.value.region || null,
        spreadsheet_title: loadedSpreadsheetTitle.value || null,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            newSource.value = { tab_name: '', event_id: '', region: '' };
            availableTabs.value = [];
            loadedSpreadsheetId.value = null;
            loadedSpreadsheetTitle.value = '';
            spreadsheetInput.value = '';
        },
    });
}

// --- Per-source actions ----------------------------------------------------

const busy = ref({});
const openSource = ref(null);
const changes = ref([]);
const changeFilter = ref('create');
const loadingChanges = ref(false);

function runNow(source) {
    busy.value = { ...busy.value, [source.id]: true };

    router.post(route('sheetSync.run', { sheetSource: source.id }), {}, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = { ...busy.value, [source.id]: false };
            if (openSource.value === source.id) loadChanges(source.id);
        },
    });
}

async function toggleReview(source) {
    if (openSource.value === source.id) {
        openSource.value = null;
        return;
    }

    openSource.value = source.id;
    changeFilter.value = source.counts.create > 0
        ? 'create'
        : source.counts.update > 0
            ? 'update'
            : source.counts.missing > 0
                ? 'missing'
                : 'skipped';
    await loadChanges(source.id);
}

async function loadChanges(sourceId) {
    loadingChanges.value = true;

    try {
        const { data } = await axios.get(route('sheetSync.changes', { sheetSource: sourceId }), {
            params: { action: changeFilter.value },
        });
        changes.value = data.changes;
    } catch {
        changes.value = [];
    } finally {
        loadingChanges.value = false;
    }
}

async function setFilter(sourceId, action) {
    changeFilter.value = action;
    await loadChanges(sourceId);
}

async function approve(source) {
    const result = await Swal.fire({
        icon: 'question',
        title: `Apply ${source.pending} change${source.pending === 1 ? '' : 's'}?`,
        html: `<b>${source.tab_name}</b> will write
               <b>${source.counts.create} new</b> and
               <b>${source.counts.update} changed</b> location${source.counts.update === 1 ? '' : 's'}.<br><br>
               ${source.duplicates > 0
                   ? `<div style="color:#b91c1c"><b>${source.duplicates} of the new ones look like locations this event
                      already has.</b> Approving creates a second copy of each. Check the
                      <b>New locations</b> group first.</div><br>`
                   : ''}
               This applies exactly what you reviewed.`,
        showCancelButton: true,
        confirmButtonText: 'Apply now',
        confirmButtonColor: '#0d9488',
    });

    if (!result.isConfirmed) return;

    router.post(route('sheetSync.approve', { sheetSource: source.id }), {}, {
        preserveScroll: true,
        onSuccess: () => { if (openSource.value === source.id) loadChanges(source.id); },
    });
}

async function discard(source) {
    const result = await Swal.fire({
        icon: 'warning',
        title: 'Dismiss these changes?',
        text: 'Nothing is written. The next run will find the same differences again.',
        showCancelButton: true,
        confirmButtonText: 'Dismiss',
        confirmButtonColor: '#b45309',
    });

    if (!result.isConfirmed) return;

    router.post(route('sheetSync.discard', { sheetSource: source.id }), {}, { preserveScroll: true });
}

/**
 * Delete a location whose row has gone from the sheet.
 *
 * The confirmation is deliberately heavier when the location carries attendees:
 * the delete cascades to them and there is no undo. The server re-counts and
 * re-checks the typed confirmation, so this dialog is a courtesy, not the guard.
 */
async function removeMissing(source, change) {
    const costs = [];
    if (change.attendees) costs.push(`${change.attendees} attendee record${change.attendees === 1 ? '' : 's'}`);
    if (change.prizes) costs.push(`${change.prizes} prize${change.prizes === 1 ? '' : 's'}`);

    const destructive = costs.length > 0;

    const result = await Swal.fire({
        icon: 'warning',
        title: `Remove "${change.label}"?`,
        html: destructive
            ? `This permanently deletes the location <b>and its ${costs.join(' and ')}</b>.
               There is no undo.<br><br>Type <b>DELETE</b> to confirm.`
            : 'This deletes the location. It has no attendees or prizes attached.',
        input: destructive ? 'text' : undefined,
        inputPlaceholder: destructive ? 'DELETE' : undefined,
        inputValidator: destructive
            ? (value) => (value === 'DELETE' ? undefined : 'Type DELETE to confirm.')
            : undefined,
        showCancelButton: true,
        confirmButtonText: 'Remove location',
        confirmButtonColor: '#dc2626',
    });

    if (!result.isConfirmed) return;

    router.delete(route('sheetSync.removeLocation', { sheetSource: source.id, sheetSourceChange: change.id }), {
        data: { confirmation: destructive ? 'DELETE' : null },
        preserveScroll: true,
        onSuccess: () => { if (openSource.value === source.id) loadChanges(source.id); },
    });
}

/**
 * Keep a location the sheet has stopped mentioning. It stops being managed by
 * the tab, so no future run asks about it again.
 */
async function keepMissing(source, change) {
    const result = await Swal.fire({
        icon: 'question',
        title: `Keep "${change.label}"?`,
        text: 'The location stays exactly as it is, and this tab stops managing it — future syncs will leave it alone.',
        showCancelButton: true,
        confirmButtonText: 'Keep it',
        confirmButtonColor: '#0d9488',
    });

    if (!result.isConfirmed) return;

    router.post(route('sheetSync.keepLocation', { sheetSource: source.id, sheetSourceChange: change.id }), {}, {
        preserveScroll: true,
        onSuccess: () => { if (openSource.value === source.id) loadChanges(source.id); },
    });
}

function toggleEnabled(source) {
    router.patch(route('sheetSync.update', { sheetSource: source.id }), { enabled: !source.enabled }, { preserveScroll: true });
}

function changeEvent(source, eventId) {
    router.patch(route('sheetSync.update', { sheetSource: source.id }), { event_id: eventId }, { preserveScroll: true });
}

async function disconnect(source) {
    const result = await Swal.fire({
        icon: 'warning',
        title: `Disconnect "${source.tab_name}"?`,
        text: 'The tab stops syncing. Locations it already created are kept.',
        showCancelButton: true,
        confirmButtonText: 'Disconnect',
        confirmButtonColor: '#dc2626',
    });

    if (!result.isConfirmed) return;

    router.delete(route('sheetSync.destroy', { sheetSource: source.id }), { preserveScroll: true });
}

async function disconnectGoogle() {
    const result = await Swal.fire({
        icon: 'warning',
        title: 'Disconnect Google?',
        text: 'Every tab stops syncing until an account is connected again.',
        showCancelButton: true,
        confirmButtonText: 'Disconnect',
        confirmButtonColor: '#dc2626',
    });

    if (!result.isConfirmed) return;

    router.delete(route('google.integration.destroy'), { preserveScroll: true });
}

// --- Display helpers -------------------------------------------------------

const FIELD_LABELS = {
    name: 'Name',
    date: 'Date',
    time: 'Time',
    state: 'State',
    country: 'Country',
    category: 'Show Type',
    status: 'Booking Status',
    ticketing_type: 'Ticketing Type',
    booked_by: 'Booked By',
    date_booking_confirmed: 'Date Booking Confirmed',
    film_format: 'Film Format',
    cinema_contact: 'Cinema Contact',
    number_of_screenings: 'No. Screenings',
    dcp_trailer_sent: 'DCP Trailer Sent',
    media_kit_sent: 'Media Kit Sent',
    dcp_sent: 'DCP Sent',
    specific_deliverable_requests: 'Deliverable Requests',
};

function fieldLabel(key) {
    return FIELD_LABELS[key] ?? key;
}

function cellText(value) {
    if (value === true) return 'Yes';
    if (value === false) return 'No';
    if (value === null || value === '') return '—';
    return String(value);
}

function whenText(iso) {
    if (!iso) return 'never';
    return new Date(iso).toLocaleString('en-AU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

function summaryOf(source) {
    if (source.last_status === 'failed') return null;

    const c = source.counts;

    if (!source.needs_review) {
        return `In sync — ${c.unchanged} screening${c.unchanged === 1 ? '' : 's'} here match the sheet exactly (${c.skipped} sheet rows ignored).`;
    }

    const parts = [];
    if (c.create) parts.push(`${c.create} new screening${c.create === 1 ? '' : 's'} to add`);
    if (c.update) parts.push(`${c.update} existing screening${c.update === 1 ? '' : 's'} changed in the sheet`);

    const edited = parts.length ? `Someone edited the sheet: ${parts.join(' and ')}.` : '';

    const gone = c.missing
        ? `${c.missing} screening${c.missing === 1 ? '' : 's'} this tab added ${c.missing === 1 ? 'is' : 'are'} no longer in the sheet.`
        : '';

    return [edited, gone].filter(Boolean).join(' ');
}

// --- The "how this works" strip --------------------------------------------
//
// The page used to open on a form and a list of tabs, which told an admin what
// the controls were but not what the thing does. These four numbers are the
// whole story: what we are connected to, when we last looked, what is waiting
// for them, and what is already live.

const showHelp = ref(false);

const activeSources = computed(() => props.sources.filter((s) => s.enabled));

const pendingTotal = computed(
    () => props.sources.reduce((total, s) => total + (s.enabled ? s.pending : 0), 0)
);

const inSyncTotal = computed(
    () => props.sources.reduce((total, s) => total + (s.counts?.unchanged ?? 0), 0)
);

/** The most recent run across every tab — ISO strings sort chronologically. */
const lastCheckedAt = computed(() => {
    const stamps = props.sources.map((s) => s.last_synced_at).filter(Boolean).sort();

    return stamps.length ? stamps[stamps.length - 1] : null;
});

const flash = computed(() => page.props.flash ?? {});
</script>

<template>
    <Head title="Master Sheet Sync" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="text-xl font-semibold text-gray-800 leading-tight">Master Sheet Sync</h2>
                <Link :href="route('mastersheet.index')" class="text-sm text-teal-700 hover:text-teal-900">
                    &larr; Back to master sheet
                </Link>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-6xl px-4 sm:px-6 space-y-6">

                <div v-if="flash.error" class="rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
                    {{ flash.error }}
                </div>
                <div v-if="flash.success" class="rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
                    {{ flash.success }}
                </div>

                <!-- What this page does, in the order it happens -->
                <section class="bg-white shadow-sm rounded-lg p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-gray-800">How this works</h3>
                            <p class="mt-0.5 text-sm text-gray-500">
                                The spreadsheet is the source of truth for screenings. We read it for you
                                automatically &mdash; you decide when it lands on the locations.
                            </p>
                        </div>
                        <button
                            @click="showHelp = !showHelp"
                            class="shrink-0 rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50"
                        >
                            {{ showHelp ? 'Hide' : 'How do I test a change?' }}
                        </button>
                    </div>

                    <ol class="mt-4 grid gap-3 sm:grid-cols-4">
                        <li class="rounded-md border border-gray-200 p-3">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Step 1 &middot; Connected</p>
                            <p class="mt-1 text-sm font-medium text-gray-800">We are reading your spreadsheet</p>
                            <p class="mt-1 text-xs" :class="connected ? 'text-green-700' : 'text-red-700'">
                                {{ connected ? (props.connection.email || 'Google account connected') : 'No Google account connected yet' }}
                            </p>
                            <p class="mt-0.5 text-xs text-gray-500">
                                {{ activeSources.length }} tab{{ activeSources.length === 1 ? '' : 's' }} being watched
                            </p>
                        </li>

                        <li class="rounded-md border border-gray-200 p-3">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Step 2 &middot; Automatic</p>
                            <p class="mt-1 text-sm font-medium text-gray-800">We check it every hour</p>
                            <p class="mt-1 text-xs text-gray-500">Last checked {{ whenText(lastCheckedAt) }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">Or press <strong>Run now</strong> on a tab to check immediately.</p>
                        </li>

                        <li class="rounded-md border p-3"
                            :class="pendingTotal > 0 ? 'border-amber-300 bg-amber-50' : 'border-gray-200'">
                            <p class="text-[11px] font-semibold uppercase tracking-wide"
                               :class="pendingTotal > 0 ? 'text-amber-600' : 'text-gray-400'">Step 3 &middot; Review</p>
                            <p class="mt-1 text-sm font-medium" :class="pendingTotal > 0 ? 'text-amber-900' : 'text-gray-800'">
                                You see what changed first
                            </p>
                            <p class="mt-1 text-xs" :class="pendingTotal > 0 ? 'text-amber-800' : 'text-gray-500'">
                                <template v-if="pendingTotal > 0">
                                    {{ pendingTotal }} screening{{ pendingTotal === 1 ? '' : 's' }} waiting for you below
                                </template>
                                <template v-else>Nothing waiting &mdash; the sheet and the locations agree</template>
                            </p>
                        </li>

                        <li class="rounded-md border border-gray-200 p-3">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Step 4 &middot; Approve</p>
                            <p class="mt-1 text-sm font-medium text-gray-800">The locations update</p>
                            <p class="mt-1 text-xs text-gray-500">
                                Every sign-up form for that event uses the new locations straight away.
                            </p>
                            <p class="mt-0.5 text-xs text-gray-500">
                                {{ inSyncTotal }} screening{{ inSyncTotal === 1 ? '' : 's' }} currently match the sheet.
                            </p>
                        </li>
                    </ol>

                    <p class="mt-3 text-xs text-teal-800 bg-teal-50 border border-teal-200 rounded-md px-3 py-2">
                        This sync only ever <strong>reads</strong> from Google. The connected account has read-only
                        access, so nothing here can change a cell, a tab, or a file in Drive.
                    </p>

                    <div v-if="showHelp" class="mt-4 rounded-md border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
                        <p class="font-medium text-gray-800">Testing a spreadsheet edit, end to end</p>
                        <ol class="mt-2 list-decimal space-y-1.5 pl-5">
                            <li>
                                Open the spreadsheet &mdash; the link is on each connected tab below &mdash; and edit
                                one screening. Change its <em>Cinema</em>, <em>Date</em> or <em>Time</em>. Google saves
                                on its own.
                            </li>
                            <li>
                                Come back here and press <strong>Run now</strong> on that tab. You do not have to wait
                                for the hourly check.
                            </li>
                            <li>
                                The tab flips to <strong>&ldquo;N to review&rdquo;</strong>. Press <strong>Review</strong>,
                                then the <strong>Changed</strong> group: the old value is struck through, the new value
                                sits beside it.
                            </li>
                            <li>
                                Press <strong>Approve &amp; apply</strong>, then open that event's location list &mdash;
                                the screening now reads exactly what the sheet says.
                            </li>
                            <li>Put the cell back the way it was in the sheet, run and approve again, and you are where you started.</li>
                        </ol>
                        <p class="mt-2 text-xs text-gray-500">
                            A brand-new row in the sheet turns up under <strong>New</strong> rather than
                            <strong>Changed</strong>. A row you delete from the sheet is deliberately ignored &mdash;
                            locations are never deleted here, because attendees and sign-ups hang off them.
                        </p>
                    </div>
                </section>

                <!-- Google connection -->
                <section class="bg-white shadow-sm rounded-lg p-5">
                    <h3 class="text-base font-semibold text-gray-800 mb-3">Google account</h3>

                    <div v-if="!props.configured" class="text-sm text-gray-600">
                        Google OAuth is not configured on this server. Set
                        <code class="bg-gray-100 px-1 rounded">GOOGLE_OAUTH_CLIENT_ID</code> and
                        <code class="bg-gray-100 px-1 rounded">GOOGLE_OAUTH_CLIENT_SECRET</code> in the environment.
                    </div>

                    <div v-else-if="!props.connection" class="flex items-center justify-between gap-4">
                        <p class="text-sm text-gray-600">
                            No account connected. Connect the Google account the master schedule is shared with.
                        </p>
                        <a :href="route('google.integration.connect')"
                           class="shrink-0 inline-flex items-center rounded-md bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700">
                            Connect Google
                        </a>
                    </div>

                    <div v-else class="flex items-start justify-between gap-4">
                        <div class="text-sm">
                            <p class="font-medium text-gray-800">{{ props.connection.email ?? 'Connected account' }}</p>
                            <p v-if="connected" class="text-gray-500">
                                Connected {{ whenText(props.connection.connected_at) }}
                                <span v-if="props.connection.connected_by">by {{ props.connection.connected_by }}</span>
                            </p>
                            <p v-else class="text-red-600 font-medium">
                                Google revoked this connection — reconnect to resume syncing.
                            </p>
                        </div>
                        <div class="shrink-0 flex gap-2">
                            <a v-if="!connected" :href="route('google.integration.connect')"
                               class="inline-flex items-center rounded-md bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700">
                                Reconnect
                            </a>
                            <button @click="disconnectGoogle"
                                    class="inline-flex items-center rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                Disconnect
                            </button>
                        </div>
                    </div>
                </section>

                <!-- Add a tab -->
                <section v-if="connected" class="bg-white shadow-sm rounded-lg p-5">
                    <h3 class="text-base font-semibold text-gray-800 mb-1">Connect a tab</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Paste the spreadsheet link, then pick the tab and the event its screenings belong to.
                    </p>

                    <div class="flex gap-2">
                        <input
                            v-model="spreadsheetInput"
                            type="text"
                            placeholder="https://docs.google.com/spreadsheets/d/..."
                            class="flex-1 rounded-md border-gray-300 text-sm"
                            @keyup.enter="loadTabs"
                        />
                        <button @click="loadTabs" :disabled="loadingTabs || !spreadsheetInput.trim()"
                                class="shrink-0 rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-900 disabled:opacity-40">
                            {{ loadingTabs ? 'Reading…' : 'Load tabs' }}
                        </button>
                    </div>

                    <p v-if="loadedSpreadsheetTitle" class="mt-3 text-sm text-gray-600">
                        Reading <strong>{{ loadedSpreadsheetTitle }}</strong>
                    </p>

                    <div v-if="availableTabs.length" class="mt-4 grid gap-3 sm:grid-cols-4">
                        <select v-model="newSource.tab_name" class="rounded-md border-gray-300 text-sm sm:col-span-2">
                            <option value="">Choose a tab…</option>
                            <option v-for="tab in availableTabs" :key="tab.name" :value="tab.name" :disabled="tab.taken">
                                {{ tab.name }}{{ tab.taken ? ' (already connected)' : '' }}
                            </option>
                        </select>

                        <select v-model="newSource.event_id" class="rounded-md border-gray-300 text-sm">
                            <option value="">Choose an event…</option>
                            <option v-for="evt in props.events" :key="evt.id" :value="evt.id">{{ evt.label }}</option>
                        </select>

                        <select v-model="newSource.region" class="rounded-md border-gray-300 text-sm">
                            <option value="">Region (optional)</option>
                            <option v-for="(label, key) in props.regions" :key="key" :value="key">{{ label }}</option>
                        </select>

                        <div class="sm:col-span-4">
                            <button @click="addSource" :disabled="!newSource.tab_name || !newSource.event_id"
                                    class="rounded-md bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700 disabled:opacity-40">
                                Connect tab &amp; preview
                            </button>
                            <span class="ml-3 text-xs text-gray-500">Nothing is written until you review and approve it.</span>
                        </div>
                    </div>
                </section>

                <!-- Configured sources -->
                <section class="bg-white shadow-sm rounded-lg">
                    <div class="px-5 py-4 border-b border-gray-200">
                        <h3 class="text-base font-semibold text-gray-800">Connected tabs</h3>
                    </div>

                    <div v-if="!props.sources.length" class="px-5 py-10 text-center text-sm text-gray-500">
                        No tabs connected yet.
                    </div>

                    <div v-else class="divide-y divide-gray-200">
                        <div v-for="source in props.sources" :key="source.id" class="px-5 py-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="font-medium text-gray-900">{{ source.tab_name }}</span>

                                        <span v-if="source.region"
                                              class="text-xs rounded-full bg-gray-100 text-gray-600 px-2 py-0.5">
                                            {{ props.regions[source.region] ?? source.region }}
                                        </span>

                                        <span v-if="!source.enabled"
                                              class="text-xs rounded-full bg-gray-200 text-gray-600 px-2 py-0.5">Paused</span>

                                        <span v-else-if="source.pending > 0"
                                              class="text-xs rounded-full bg-amber-100 text-amber-800 px-2 py-0.5">
                                            {{ source.pending }} to review
                                        </span>

                                        <span v-if="source.enabled && source.missing > 0"
                                              class="text-xs rounded-full bg-rose-100 text-rose-800 px-2 py-0.5">
                                            {{ source.missing }} removed from sheet
                                        </span>

                                        <span v-if="source.enabled && source.duplicates > 0"
                                              class="text-xs rounded-full bg-orange-100 text-orange-800 px-2 py-0.5">
                                            {{ source.duplicates }} possible duplicate{{ source.duplicates === 1 ? '' : 's' }}
                                        </span>

                                        <span v-else-if="!source.needs_review"
                                              class="text-xs rounded-full bg-green-100 text-green-700 px-2 py-0.5">Up to date</span>
                                    </div>

                                    <div class="mt-1 flex items-center gap-2 text-xs text-gray-500 flex-wrap">
                                        <select
                                            :value="source.event_id"
                                            @change="changeEvent(source, $event.target.value)"
                                            class="rounded border-gray-300 text-xs py-0.5 pr-7"
                                        >
                                            <option v-for="evt in props.events" :key="evt.id" :value="evt.id">{{ evt.label }}</option>
                                        </select>
                                        <span>·</span>
                                        <a :href="source.url" target="_blank" rel="noopener"
                                           class="text-teal-700 hover:underline">
                                            {{ source.spreadsheet_title || 'Open spreadsheet' }}
                                        </a>
                                        <span>·</span>
                                        <span>Last run {{ whenText(source.last_synced_at) }}</span>
                                    </div>

                                    <p v-if="source.last_status === 'failed'" class="mt-2 text-sm text-red-700">
                                        {{ source.last_error }}
                                    </p>
                                    <template v-else>
                                        <p v-if="summaryOf(source)" class="mt-2 text-sm text-gray-600">
                                            {{ summaryOf(source) }}
                                        </p>
                                        <p v-if="source.pending > 0" class="mt-1 text-sm text-amber-700">
                                            Nothing is written until you press <strong>Approve &amp; apply</strong>.
                                        </p>
                                        <p v-if="source.missing > 0" class="mt-1 text-sm text-rose-700">
                                            Removals are never automatic &mdash; open <strong>Review</strong> and decide
                                            each one.
                                        </p>
                                        <p v-if="source.duplicates > 0" class="mt-1 text-sm text-orange-700">
                                            {{ source.duplicates }} of the new screening{{ source.duplicates === 1 ? '' : 's' }}
                                            look like location{{ source.duplicates === 1 ? '' : 's' }} this event already has.
                                            Check <strong>New locations</strong> before approving.
                                        </p>
                                    </template>
                                </div>

                                <div class="shrink-0 flex flex-wrap items-center gap-2">
                                    <button @click="toggleReview(source)"
                                            class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                        {{ openSource === source.id ? 'Hide' : 'Review' }}
                                    </button>
                                    <button @click="runNow(source)" :disabled="busy[source.id]"
                                            class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40">
                                        {{ busy[source.id] ? 'Running…' : 'Run now' }}
                                    </button>
                                    <button v-if="source.pending > 0" @click="approve(source)"
                                            class="rounded-md bg-teal-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-teal-700">
                                        Approve &amp; apply
                                    </button>
                                    <button v-if="source.pending > 0" @click="discard(source)"
                                            class="rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-800 hover:bg-amber-100">
                                        Dismiss
                                    </button>
                                    <button @click="toggleEnabled(source)"
                                            class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                        {{ source.enabled ? 'Pause' : 'Resume' }}
                                    </button>
                                    <button @click="disconnect(source)"
                                            class="rounded-md border border-red-300 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50">
                                        Disconnect
                                    </button>
                                </div>
                            </div>

                            <!-- Review panel -->
                            <div v-if="openSource === source.id" class="mt-4 rounded-md border border-gray-200 bg-gray-50">
                                <div class="border-b border-gray-200 px-3 pt-3">
                                    <p class="text-xs text-gray-600">
                                        The screenings <strong>{{ source.tab_name }}</strong> holds for
                                        <strong>{{ source.event_name || 'this event' }}</strong>. Nothing below is live
                                        until you approve it.
                                    </p>
                                </div>

                                <div class="flex flex-wrap gap-1 border-b border-gray-200 px-3 py-2">
                                    <button
                                        v-for="tab in [
                                            { key: 'create', label: 'New locations', count: source.counts.create },
                                            { key: 'update', label: 'Changed', count: source.counts.update },
                                            { key: 'unchanged', label: 'Already in sync', count: source.counts.unchanged },
                                            { key: 'skipped', label: 'Ignored rows', count: source.counts.skipped },
                                            { key: 'missing', label: 'Gone from the sheet', count: source.counts.missing },
                                        ]"
                                        :key="tab.key"
                                        @click="setFilter(source.id, tab.key)"
                                        :class="[
                                            'rounded px-3 py-1 text-xs font-medium',
                                            changeFilter === tab.key ? 'bg-white text-teal-700 shadow-sm' : 'text-gray-500 hover:text-gray-700'
                                        ]"
                                    >
                                        {{ tab.label }} ({{ tab.count }})
                                    </button>
                                </div>

                                <p class="px-4 pt-2 text-xs text-gray-500">
                                    <template v-if="changeFilter === 'create'">
                                        In the sheet, but not a location yet. Approving creates them.
                                    </template>
                                    <template v-else-if="changeFilter === 'update'">
                                        Already a location here, but the sheet now says something different.
                                        Struck-through is what we hold, green is what the sheet says.
                                    </template>
                                    <template v-else-if="changeFilter === 'unchanged'">
                                        Locations that already match the sheet. Nothing to do.
                                    </template>
                                    <template v-else-if="changeFilter === 'skipped'">
                                        Sheet rows we did not treat as screenings — section headings, blank rows,
                                        rows with no cinema or date. Each says why.
                                    </template>
                                    <template v-else>
                                        Locations this tab created that the sheet no longer lists. Nothing is deleted
                                        automatically — a row can vanish because a screening was cancelled, or because
                                        someone is mid-edit in the spreadsheet. You decide each one.
                                    </template>
                                </p>

                                <div v-if="loadingChanges" class="px-4 py-6 text-center text-xs text-gray-500">Loading…</div>

                                <div v-else-if="!changes.length" class="px-4 py-6 text-center text-xs text-gray-500">
                                    Nothing in this group.
                                </div>

                                <ul v-else class="divide-y divide-gray-200 max-h-96 overflow-y-auto">
                                    <li v-for="change in changes" :key="change.id" class="px-4 py-2.5 text-xs">
                                        <div class="flex items-baseline gap-2">
                                            <span v-if="change.sheet_row" class="text-gray-400 tabular-nums shrink-0">row {{ change.sheet_row }}</span>
                                            <span v-else class="text-rose-500 shrink-0">removed</span>
                                            <span class="font-medium text-gray-800">{{ change.label || '(no label)' }}</span>
                                        </div>

                                        <p v-if="change.skip_reason" class="mt-0.5 text-gray-500">{{ change.skip_reason }}</p>

                                        <!-- A create that matches an existing location closely enough to be the
                                             same screening spelled differently. -->
                                        <div v-if="change.duplicate_of"
                                             class="mt-1 rounded border border-orange-200 bg-orange-50 px-2 py-1 text-[11px] text-orange-900">
                                            Looks like an existing location:
                                            <strong>{{ change.duplicate_of.name }}</strong>
                                            <span v-if="change.duplicate_of.date">({{ change.duplicate_of.date }})</span>.
                                            Approving creates a second copy. Make the two names identical &mdash; in the
                                            sheet or on the locations screen &mdash; and it will update that one instead.
                                        </div>

                                        <!-- A screening the sheet has stopped listing: what deleting it would cost,
                                             and the two ways out. -->
                                        <div v-if="change.action === 'missing'" class="mt-1 flex flex-wrap items-center gap-2">
                                            <span :class="change.attendees > 0 || change.prizes > 0 ? 'text-rose-700 font-medium' : 'text-gray-500'">
                                                {{ change.attendees }} attendee{{ change.attendees === 1 ? '' : 's' }}
                                                &middot;
                                                {{ change.prizes }} prize{{ change.prizes === 1 ? '' : 's' }}
                                            </span>
                                            <button @click="removeMissing(source, change)"
                                                    class="rounded border border-red-300 px-2 py-0.5 text-[11px] font-medium text-red-700 hover:bg-red-50">
                                                Remove location
                                            </button>
                                            <button @click="keepMissing(source, change)"
                                                    class="rounded border border-gray-300 px-2 py-0.5 text-[11px] font-medium text-gray-700 hover:bg-gray-50">
                                                Keep
                                            </button>
                                            <span v-if="change.attendees > 0" class="text-[11px] text-rose-600">
                                                Removing destroys those attendee records permanently.
                                            </span>
                                        </div>

                                        <table v-if="change.diff" class="mt-1.5 w-full">
                                            <tbody>
                                                <tr v-for="(pair, field) in change.diff" :key="field" class="align-top">
                                                    <td class="py-0.5 pr-3 text-gray-500 whitespace-nowrap w-44">{{ fieldLabel(field) }}</td>
                                                    <td class="py-0.5 pr-2 text-red-700 line-through">{{ cellText(pair[0]) }}</td>
                                                    <td class="py-0.5 text-green-700">{{ cellText(pair[1]) }}</td>
                                                </tr>
                                            </tbody>
                                        </table>

                                        <table v-else-if="change.action === 'create' && change.payload" class="mt-1.5 w-full">
                                            <tbody>
                                                <tr v-for="(value, field) in change.payload" :key="field" class="align-top">
                                                    <td class="py-0.5 pr-3 text-gray-500 whitespace-nowrap w-44">{{ fieldLabel(field) }}</td>
                                                    <td class="py-0.5 text-gray-800">{{ cellText(value) }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </section>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
