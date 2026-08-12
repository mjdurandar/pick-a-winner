<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import axios from 'axios';
import Swal from 'sweetalert2';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    maxUploadMb: { type: Number, default: 10 },
});

// Destination first. The audience decides which merge fields the mapping step can
// offer and whether contacts are subscribed or invited, so it is fixed before a
// file is ever involved.
const STEPS = [
    { key: 'audience', label: 'Audience' },
    { key: 'upload', label: 'Upload CSV' },
    { key: 'map', label: 'Map & confirm' },
    { key: 'preview', label: 'Preview' },
    { key: 'run', label: 'Import' },
];
const step = ref('audience');

// The last two steps share one screen — the preview becomes the report once the
// send starts — so the chip is driven by the run's state, not by `step`.
const stepIndex = computed(() =>
    step.value === 'preview' && (running.value || finished.value)
        ? STEPS.length - 1
        : STEPS.findIndex((s) => s.key === step.value)
);

const usableAccounts = computed(() => props.accounts.filter((a) => a.active));
const account = ref(usableAccounts.value[0]?.key ?? null);

const audiences = ref([]);
const audienceId = ref('');
const loadingAudiences = ref(false);
const starting = ref(false);

const importRecord = ref(null);
const mergeFields = ref([]);
const doubleOptin = ref(false);

const file = ref(null);
const dragging = ref(false);
const uploading = ref(false);
const uploadError = ref('');

const headers = ref([]);
const fieldMap = ref({});
const tag = ref('');
const consentConfirmed = ref(false);
const consentSource = ref('');
const saving = ref(false);
const configErrors = ref({});

// Preview. The dry run reads the audience and classifies the file on the queue,
// so the page starts it and then polls until the row log is written.
const POLL_MS = 2000;
// Roughly two minutes. A dry run that has not been picked up by then almost
// always means no queue worker is running, which no amount of waiting fixes.
const MAX_POLLS = 60;
const BLOCKED_OUTCOMES = ['blocked_invalid', 'blocked_duplicate', 'blocked_missing'];

const preview = ref(null);
const previewError = ref('');
const outcomeFilter = ref(null);
const loadingRows = ref(false);
let pollTimer = null;
let polls = 0;
// The idle counter resets whenever a poll shows movement, so a long import is
// never mistaken for a stalled one — only genuine silence trips MAX_POLLS.
let lastProgress = -1;

const previewReady = computed(() => preview.value?.ready === true);
const running = computed(() => preview.value?.running === true);
const finished = computed(() => preview.value?.finished === true);
const counts = computed(() => preview.value?.counts ?? {});
const labels = computed(() => preview.value?.labels ?? {});
const blockedTotal = computed(() =>
    BLOCKED_OUTCOMES.reduce((total, key) => total + (counts.value[key] ?? 0), 0)
);

const sending = ref(false);

// The poll gave up while a send was in flight, rather than while the preview was
// being built. The two need opposite recovery actions.
const stalledSend = computed(() => !!previewError.value && preview.value?.status === 'running');

// Once the run starts, the same rows carry post-run outcomes, so the report reads
// from these instead of the will_* counts.
const sent = computed(
    () => (counts.value.subscribed ?? 0) + (counts.value.resubscribed ?? 0)
);
const complianceBlocked = computed(() => counts.value.blocked_unsubscribed ?? 0);
const runProgress = computed(() => {
    // `actionable` is what is still outstanding — it shrinks as rows are written
    // back — so the total is whatever is done plus whatever is left.
    const remaining = preview.value?.actionable ?? 0;
    const done = sent.value + complianceBlocked.value + (counts.value.failed ?? 0);
    if (!remaining) return done ? 100 : 0;
    return Math.min(100, Math.round((done / (done + remaining)) * 100));
});

// Ordered for reading: what happened (or will) first, what did not last. Post-run
// outcomes sit alongside the dry-run ones — a finished import has both, because
// rows that were never actionable keep their original classification.
const OUTCOME_ORDER = [
    'will_subscribe',
    'will_resubscribe',
    'subscribed',
    'resubscribed',
    'already_member',
    'blocked_unsubscribed',
    'blocked_invalid',
    'blocked_duplicate',
    'blocked_missing',
    'failed',
];

const breakdown = computed(() =>
    OUTCOME_ORDER.map((key) => ({
        key,
        label: labels.value[key] ?? key,
        total: counts.value[key] ?? 0,
    })).filter((row) => row.total > 0)
);

const selectedAudience = computed(() => audiences.value.find((a) => a.id === audienceId.value));
const emailMapped = computed(() => Object.values(fieldMap.value).includes('EMAIL'));
const canConfirm = computed(() => emailMapped.value && consentConfirmed.value && !saving.value);

// A merge tag takes at most one column, so tags already spoken for drop out of the
// other dropdowns rather than silently overwriting each other.
function availableFields(header) {
    const taken = Object.entries(fieldMap.value)
        .filter(([key, value]) => key !== header && value)
        .map(([, value]) => value);
    return mergeFields.value.filter((f) => !taken.includes(f.tag));
}

function formatBytes(bytes) {
    if (!bytes) return '—';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(2)} MB`;
}

function reportApiError(err, fallback) {
    Swal.fire({
        title: err.response?.data?.needs_reconnect ? 'Reconnect required' : 'Mailchimp error',
        text: err.response?.data?.message || fallback,
        icon: 'error',
    });
}

async function loadAudiences() {
    if (!account.value) return;
    loadingAudiences.value = true;
    audienceId.value = '';
    audiences.value = [];
    try {
        const { data } = await axios.get(route('mailchimpImport.audiences'), {
            params: { account: account.value },
        });
        audiences.value = data.audiences;
    } catch (err) {
        reportApiError(err, 'Could not load audiences from Mailchimp.');
    } finally {
        loadingAudiences.value = false;
    }
}

watch(account, loadAudiences, { immediate: true });

async function chooseAudience() {
    starting.value = true;
    try {
        const { data } = await axios.post(route('mailchimpImport.start'), {
            account: account.value,
            audience_id: audienceId.value,
        });
        importRecord.value = data.import;
        mergeFields.value = data.merge_fields;
        doubleOptin.value = data.import.double_optin;
        step.value = 'upload';
    } catch (err) {
        reportApiError(err, 'Could not open the import.');
    } finally {
        starting.value = false;
    }
}

function onDrop(event) {
    dragging.value = false;
    const dropped = event.dataTransfer?.files?.[0];
    if (dropped) selectFile(dropped);
}

function onPick(event) {
    const picked = event.target.files?.[0];
    if (picked) selectFile(picked);
}

function selectFile(picked) {
    uploadError.value = '';
    if (!picked.name.toLowerCase().endsWith('.csv')) {
        uploadError.value = 'Only .csv files can be imported.';
        return;
    }
    if (picked.size > props.maxUploadMb * 1024 * 1024) {
        uploadError.value = `The file must be ${props.maxUploadMb} MB or smaller.`;
        return;
    }
    file.value = picked;
    upload();
}

async function upload() {
    uploading.value = true;
    uploadError.value = '';

    const form = new FormData();
    form.append('file', file.value);

    try {
        const { data } = await axios.post(
            route('mailchimpImport.upload', { import: importRecord.value.id }),
            form
        );
        importRecord.value = { ...importRecord.value, ...data.import };
        headers.value = data.headers;
        fieldMap.value = { ...data.suggested_map };
        step.value = 'map';
    } catch (err) {
        uploadError.value =
            err.response?.data?.errors?.file?.[0] ||
            err.response?.data?.message ||
            'The file could not be uploaded.';
        file.value = null;
    } finally {
        uploading.value = false;
    }
}

async function saveConfiguration() {
    saving.value = true;
    configErrors.value = {};
    try {
        await axios.post(route('mailchimpImport.configure', { import: importRecord.value.id }), {
            tag: tag.value,
            field_map: fieldMap.value,
            consent_confirmed: consentConfirmed.value,
            consent_source: consentSource.value,
        });
        step.value = 'preview';
        startDryRun();
    } catch (err) {
        if (err.response?.status === 422) {
            configErrors.value = err.response.data.errors || {};
        } else {
            reportApiError(err, 'The configuration could not be saved.');
        }
    } finally {
        saving.value = false;
    }
}

async function startDryRun() {
    stopPolling();
    preview.value = null;
    previewError.value = '';
    outcomeFilter.value = null;
    polls = 0;

    try {
        const { data } = await axios.post(
            route('mailchimpImport.dryRun', { import: importRecord.value.id })
        );
        preview.value = data;
        if (!data.ready) schedulePoll();
    } catch (err) {
        previewError.value =
            err.response?.data?.errors?.field_map?.[0] ||
            err.response?.data?.errors?.file?.[0] ||
            err.response?.data?.message ||
            'The preview could not be started.';
    }
}

function schedulePoll() {
    pollTimer = setTimeout(pollPreview, POLL_MS);
}

function stopPolling() {
    if (pollTimer) clearTimeout(pollTimer);
    pollTimer = null;
}

async function pollPreview() {
    pollTimer = null;

    // The admin navigated away from the preview while the queue was still working.
    if (step.value !== 'preview' || !importRecord.value) return;

    try {
        const { data } = await axios.get(
            route('mailchimpImport.preview', { import: importRecord.value.id }),
            { params: outcomeFilter.value ? { outcome: outcomeFilter.value } : {} }
        );
        preview.value = data;

        // Done in both senses: the send finished, or the dry run landed and is now
        // waiting on the admin to press the button.
        if (data.finished) return;
        if (data.ready && !data.running) return;

        // A dry run that fails puts the import back to pending and leaves a reason
        // behind, which is the only way a run that never finishes is
        // distinguishable from one still going.
        if (data.failure_reason && !data.running) {
            previewError.value = data.failure_reason;
            return;
        }

        const progress = sent.value + complianceBlocked.value + (counts.value.failed ?? 0);
        if (progress !== lastProgress) {
            lastProgress = progress;
            polls = 0;
        }

        if (++polls >= MAX_POLLS) {
            previewError.value = running.value
                ? 'The import stopped reporting progress. Check the queue worker, then reload this page — whatever was already sent is recorded.'
                : 'The preview did not finish. Check that the queue worker is running (php artisan queue:work).';
            return;
        }

        schedulePoll();
    } catch (err) {
        previewError.value = err.response?.data?.message || 'The preview could not be loaded.';
    }
}

async function filterRows(outcome) {
    outcomeFilter.value = outcome;
    loadingRows.value = true;
    try {
        const { data } = await axios.get(
            route('mailchimpImport.preview', { import: importRecord.value.id }),
            { params: outcome ? { outcome } : {} }
        );
        preview.value = data;
    } catch (err) {
        previewError.value = err.response?.data?.message || 'Those rows could not be loaded.';
    } finally {
        loadingRows.value = false;
    }
}

async function startRun() {
    const target = preview.value.actionable.toLocaleString();
    const verb = preview.value.import.double_optin ? 'invited' : 'subscribed';

    const confirmed = await Swal.fire({
        title: 'Send this import?',
        html:
            `<p><strong>${target}</strong> contacts will be ${verb} in ` +
            `<strong>${preview.value.import.audience_name}</strong>.</p>` +
            '<p class="mt-2">This writes to Mailchimp and cannot be undone from here.</p>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Send to Mailchimp',
        confirmButtonColor: '#166534',
        cancelButtonText: 'Cancel',
    });

    if (!confirmed.isConfirmed) return;

    sending.value = true;
    previewError.value = '';
    polls = 0;
    lastProgress = -1;

    try {
        const { data } = await axios.post(
            route('mailchimpImport.run', { import: importRecord.value.id })
        );
        preview.value = data;
        if (!data.finished) schedulePoll();
    } catch (err) {
        previewError.value =
            err.response?.data?.errors?.field_map?.[0] ||
            err.response?.data?.errors?.consent_confirmed?.[0] ||
            err.response?.data?.errors?.file?.[0] ||
            err.response?.data?.message ||
            'The import could not be started.';
    } finally {
        sending.value = false;
    }
}

function backToMapping() {
    stopPolling();
    preview.value = null;
    previewError.value = '';
    step.value = 'map';
}

onBeforeUnmount(stopPolling);

function startOver() {
    stopPolling();
    step.value = 'audience';
    audienceId.value = '';
    importRecord.value = null;
    mergeFields.value = [];
    doubleOptin.value = false;
    file.value = null;
    headers.value = [];
    fieldMap.value = {};
    tag.value = '';
    consentConfirmed.value = false;
    consentSource.value = '';
    configErrors.value = {};
    uploadError.value = '';
    preview.value = null;
    previewError.value = '';
    outcomeFilter.value = null;
}
</script>

<template>
    <Head title="Mailchimp Import" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Import Contacts to Mailchimp
            </h2>
        </template>

        <div class="p-2 pb-5 pt-4">
            <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
                <ol class="mb-6 flex flex-wrap items-center gap-2 text-sm">
                    <li
                        v-for="(s, index) in STEPS"
                        :key="s.key"
                        :class="[
                            'flex items-center gap-2 rounded-full px-3 py-1',
                            stepIndex === index
                                ? 'bg-gray-800 text-white'
                                : stepIndex > index
                                    ? 'bg-green-100 text-green-800'
                                    : 'bg-gray-100 text-gray-500',
                        ]"
                    >
                        <span class="font-medium">{{ index + 1 }}.</span> {{ s.label }}
                    </li>
                </ol>

                <div v-if="!usableAccounts.length" class="rounded-md border border-amber-300 bg-amber-50 p-4">
                    <p class="text-sm font-semibold text-amber-900">No Mailchimp account is available</p>
                    <p class="mt-1 text-sm text-amber-800">
                        Configure a Mailchimp API key for it in the server environment.
                    </p>
                </div>

                <div v-else class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <!-- Destination banner, once chosen -->
                    <div
                        v-if="importRecord"
                        class="border-b border-gray-200 bg-gray-50 px-6 py-3 text-sm text-gray-700"
                    >
                        Importing into
                        <span class="font-semibold">{{ importRecord.audience_name }}</span>
                        <span class="text-gray-500">
                            · {{ usableAccounts.find((a) => a.key === account)?.label }}</span
                        >
                        <span v-if="doubleOptin" class="ml-2 rounded bg-blue-100 px-2 py-0.5 text-xs text-blue-800">
                            double opt-in
                        </span>
                    </div>

                    <div class="p-6 text-gray-900">
                        <!-- ── 1. Audience ────────────────────────────────────── -->
                        <div v-show="step === 'audience'">
                            <p class="mb-4 text-sm text-gray-600">
                                Choose the account and the audience these contacts belong in.
                            </p>

                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700">Mailchimp account</label>
                                <select v-model="account" class="mt-1 w-full rounded border-gray-300 text-sm sm:w-96">
                                    <option v-for="a in usableAccounts" :key="a.key" :value="a.key">
                                        {{ a.label }}<span v-if="a.name"> — {{ a.name }}</span>
                                    </option>
                                </select>
                            </div>

                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700">Audience</label>
                                <select
                                    v-model="audienceId"
                                    :disabled="loadingAudiences"
                                    class="mt-1 w-full rounded border-gray-300 text-sm"
                                >
                                    <option value="">
                                        {{ loadingAudiences ? 'Loading audiences…' : 'Select an audience' }}
                                    </option>
                                    <option v-for="a in audiences" :key="a.id" :value="a.id">
                                        {{ a.name }} ({{ a.member_count.toLocaleString() }} members)
                                    </option>
                                </select>
                                <p v-if="selectedAudience" class="mt-2 text-sm text-gray-500">
                                    Contacts already in this audience as unsubscribed, cleaned or archived will be
                                    resubscribed. Anyone Mailchimp refuses is reported, never silently dropped.
                                </p>
                            </div>

                            <button
                                type="button"
                                :disabled="!audienceId || starting"
                                class="rounded bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:cursor-not-allowed disabled:bg-gray-300"
                                @click="chooseAudience"
                            >
                                {{ starting ? 'Opening…' : 'Continue' }}
                            </button>
                        </div>

                        <!-- ── 2. Upload ──────────────────────────────────────── -->
                        <div v-show="step === 'upload'">
                            <div
                                :class="[
                                    'rounded-lg border-2 border-dashed p-10 text-center transition',
                                    dragging ? 'border-gray-800 bg-gray-50' : 'border-gray-300',
                                ]"
                                @dragover.prevent="dragging = true"
                                @dragleave.prevent="dragging = false"
                                @drop.prevent="onDrop"
                            >
                                <p class="text-sm text-gray-600">
                                    Drag a CSV here, or
                                    <label class="cursor-pointer font-medium text-gray-900 underline">
                                        choose a file
                                        <input type="file" accept=".csv" class="hidden" @change="onPick" />
                                    </label>
                                </p>
                                <p class="mt-2 text-xs text-gray-500">
                                    .csv only, up to {{ maxUploadMb }} MB. The first row must name the columns.
                                </p>
                                <p v-if="uploading" class="mt-4 text-sm font-medium text-gray-700">Parsing…</p>
                            </div>

                            <p v-if="uploadError" class="mt-3 text-sm text-red-700">{{ uploadError }}</p>

                            <button type="button" class="mt-4 text-sm text-gray-600 underline" @click="startOver">
                                Change audience
                            </button>
                        </div>

                        <!-- ── 3. Map & confirm ───────────────────────────────── -->
                        <div v-show="step === 'map'">
                            <div class="mb-6 rounded border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
                                <span class="font-medium">{{ importRecord?.filename }}</span>
                                · {{ importRecord?.row_count?.toLocaleString() }} rows
                                · {{ formatBytes(importRecord?.file_size) }}
                            </div>

                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700">
                                    Tag <span class="font-normal text-gray-500">(optional, applied to every contact)</span>
                                </label>
                                <input v-model="tag" type="text" class="mt-1 w-full rounded border-gray-300 text-sm" />
                            </div>

                            <div class="mb-6">
                                <h3 class="mb-2 text-sm font-medium text-gray-700">Column mapping</h3>
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b text-left text-xs uppercase text-gray-500">
                                            <th class="py-2">CSV column</th>
                                            <th class="py-2">Mailchimp field</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr v-for="header in headers" :key="header">
                                            <td class="py-2 pr-4 font-mono text-xs">{{ header }}</td>
                                            <td class="py-2">
                                                <select v-model="fieldMap[header]" class="w-full rounded border-gray-300 text-sm">
                                                    <option :value="undefined">— Do not import —</option>
                                                    <option v-for="f in availableFields(header)" :key="f.tag" :value="f.tag">
                                                        {{ f.name }} ({{ f.tag }})
                                                    </option>
                                                </select>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <p v-if="!emailMapped" class="mt-2 text-sm text-amber-700">
                                    Map one column to the email address to continue.
                                </p>
                            </div>

                            <div class="mb-6 rounded border border-gray-200 p-4">
                                <label class="flex items-start gap-2">
                                    <input v-model="consentConfirmed" type="checkbox" class="mt-1 rounded border-gray-300" />
                                    <span class="text-sm text-gray-800">
                                        I confirm these contacts opted in to receive email.
                                    </span>
                                </label>
                                <div class="mt-3">
                                    <label class="block text-xs font-medium text-gray-600">
                                        Where did they opt in?
                                        <span class="font-normal">(optional, recorded with the import)</span>
                                    </label>
                                    <input
                                        v-model="consentSource"
                                        type="text"
                                        placeholder="e.g. Partner signup form, Jan 2026"
                                        class="mt-1 w-full rounded border-gray-300 text-sm"
                                    />
                                </div>
                                <p v-if="configErrors.consent_confirmed" class="mt-2 text-sm text-red-700">
                                    {{ configErrors.consent_confirmed[0] }}
                                </p>
                            </div>

                            <p v-if="configErrors.field_map" class="mb-3 text-sm text-red-700">
                                {{ configErrors.field_map[0] }}
                            </p>

                            <div class="flex items-center gap-3">
                                <button
                                    type="button"
                                    :disabled="!canConfirm"
                                    class="rounded bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:cursor-not-allowed disabled:bg-gray-300"
                                    @click="saveConfiguration"
                                >
                                    {{ saving ? 'Saving…' : 'Continue to preview' }}
                                </button>
                                <button type="button" class="text-sm text-gray-600 underline" @click="step = 'upload'">
                                    Choose a different file
                                </button>
                            </div>
                        </div>

                        <!-- ── 4. Preview ─────────────────────────────────────── -->
                        <div v-show="step === 'preview'">
                            <!-- Failed to start, or the run gave up. -->
                            <div v-if="previewError" class="rounded border border-red-300 bg-red-50 p-4">
                                <p class="text-sm font-semibold text-red-900">The preview could not be built</p>
                                <p class="mt-1 text-sm text-red-800">{{ previewError }}</p>
                                <p class="mt-2 text-sm text-red-800">Nothing has been sent to Mailchimp.</p>
                                <div class="mt-3 flex flex-wrap items-center gap-3">
                                    <!-- A send that went quiet must never be answered by
                                         re-running the preview: that would throw away the
                                         record of what already reached Mailchimp. -->
                                    <button
                                        type="button"
                                        class="rounded bg-red-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-600"
                                        @click="stalledSend ? startRun() : startDryRun()"
                                    >
                                        {{ stalledSend ? 'Resume the import' : 'Try again' }}
                                    </button>
                                    <button
                                        v-if="!stalledSend"
                                        type="button"
                                        class="text-sm text-red-800 underline"
                                        @click="backToMapping"
                                    >
                                        Back to mapping
                                    </button>
                                    <a
                                        v-if="stalledSend"
                                        :href="route('mailchimpImport.download', { import: importRecord.id })"
                                        class="text-sm text-red-800 underline"
                                    >
                                        Download what was sent so far
                                    </a>
                                </div>
                            </div>

                            <!-- Queued or working. -->
                            <div
                                v-else-if="!previewReady"
                                class="rounded border border-dashed border-gray-300 bg-gray-50 p-6 text-center"
                            >
                                <svg
                                    class="mx-auto h-6 w-6 animate-spin text-gray-500"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                >
                                    <circle
                                        class="opacity-25"
                                        cx="12"
                                        cy="12"
                                        r="10"
                                        stroke="currentColor"
                                        stroke-width="4"
                                    />
                                    <path
                                        class="opacity-75"
                                        fill="currentColor"
                                        d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"
                                    />
                                </svg>
                                <p class="mt-3 text-sm font-medium text-gray-700">
                                    Checking {{ importRecord?.row_count?.toLocaleString() }} rows against
                                    {{ importRecord?.audience_name }}…
                                </p>
                                <p class="mt-1 text-sm text-gray-500">
                                    Reading the audience and matching every address. Nothing is being sent to
                                    Mailchimp.
                                </p>
                            </div>

                            <!-- The dry run, the run in progress, and the report. -->
                            <div v-else>
                                <!-- Finished. -->
                                <div
                                    v-if="finished"
                                    :class="[
                                        'rounded border p-4',
                                        preview.status === 'failed'
                                            ? 'border-red-300 bg-red-50'
                                            : 'border-green-300 bg-green-50',
                                    ]"
                                >
                                    <p
                                        :class="[
                                            'text-sm font-semibold',
                                            preview.status === 'failed' ? 'text-red-900' : 'text-green-900',
                                        ]"
                                    >
                                        {{
                                            preview.status === 'failed'
                                                ? 'The import stopped before it finished'
                                                : 'Import complete'
                                        }}
                                    </p>
                                    <p
                                        :class="[
                                            'mt-1 text-sm',
                                            preview.status === 'failed' ? 'text-red-800' : 'text-green-800',
                                        ]"
                                    >
                                        <span v-if="preview.failure_reason">{{ preview.failure_reason }}</span>
                                        <span v-else>
                                            {{ sent.toLocaleString() }} contacts reached
                                            {{ preview.import.audience_name }}.
                                        </span>
                                    </p>
                                    <p v-if="preview.status === 'failed'" class="mt-2 text-sm text-red-800">
                                        Everything below was recorded as Mailchimp answered for it, so it reflects
                                        what actually went through.
                                    </p>
                                </div>

                                <!-- Sending. -->
                                <div v-else-if="running" class="rounded border border-blue-300 bg-blue-50 p-4">
                                    <p class="text-sm font-semibold text-blue-900">
                                        Sending to {{ preview.import.audience_name }}…
                                    </p>
                                    <div class="mt-3 h-2 overflow-hidden rounded bg-blue-100">
                                        <div
                                            class="h-full bg-blue-600 transition-all duration-500"
                                            :style="{ width: runProgress + '%' }"
                                        />
                                    </div>
                                    <p class="mt-2 text-sm text-blue-800">
                                        {{ sent.toLocaleString() }} done,
                                        {{ preview.actionable.toLocaleString() }} to go. Safe to leave this page —
                                        the run continues on the server.
                                    </p>
                                </div>

                                <!-- Previewed, waiting on the admin. -->
                                <div v-else class="rounded border border-green-300 bg-green-50 p-4">
                                    <p class="text-sm font-semibold text-green-900">
                                        Preview only — nothing has been sent to Mailchimp
                                    </p>
                                    <p class="mt-1 text-sm text-green-800">
                                        {{ preview.import.filename }} was checked against
                                        {{ preview.import.audience_name }}.
                                    </p>
                                </div>

                                <dl
                                    :class="[
                                        'mt-4 grid grid-cols-2 gap-3',
                                        finished ? 'sm:grid-cols-5' : 'sm:grid-cols-4',
                                    ]"
                                >
                                    <div class="rounded border border-gray-200 p-3">
                                        <dt class="text-xs font-medium uppercase text-gray-500">Rows in file</dt>
                                        <dd class="mt-1 text-2xl font-semibold text-gray-900">
                                            {{ preview.import.row_count.toLocaleString() }}
                                        </dd>
                                    </div>
                                    <div class="rounded border border-green-200 bg-green-50 p-3">
                                        <dt class="text-xs font-medium uppercase text-green-700">
                                            {{ finished ? 'Subscribed' : labels.will_subscribe }}
                                        </dt>
                                        <dd class="mt-1 text-2xl font-semibold text-green-900">
                                            {{
                                                (finished
                                                    ? (counts.subscribed ?? 0)
                                                    : (counts.will_subscribe ?? 0)
                                                ).toLocaleString()
                                            }}
                                        </dd>
                                    </div>
                                    <div class="rounded border border-blue-200 bg-blue-50 p-3">
                                        <dt class="text-xs font-medium uppercase text-blue-700">
                                            {{ finished ? 'Resubscribed' : 'Will resubscribe' }}
                                        </dt>
                                        <dd class="mt-1 text-2xl font-semibold text-blue-900">
                                            {{
                                                (finished
                                                    ? (counts.resubscribed ?? 0)
                                                    : (counts.will_resubscribe ?? 0)
                                                ).toLocaleString()
                                            }}
                                        </dd>
                                    </div>
                                    <!-- Refused by Mailchimp, which is not the same as
                                         failing — nothing went wrong, the contact simply
                                         cannot be brought back by anyone but themselves. -->
                                    <div
                                        v-if="finished"
                                        :class="[
                                            'rounded border p-3',
                                            complianceBlocked ? 'border-amber-200 bg-amber-50' : 'border-gray-200',
                                        ]"
                                    >
                                        <dt
                                            :class="[
                                                'text-xs font-medium uppercase',
                                                complianceBlocked ? 'text-amber-700' : 'text-gray-500',
                                            ]"
                                        >
                                            Can't resubscribe
                                        </dt>
                                        <dd
                                            :class="[
                                                'mt-1 text-2xl font-semibold',
                                                complianceBlocked ? 'text-amber-900' : 'text-gray-900',
                                            ]"
                                        >
                                            {{ complianceBlocked.toLocaleString() }}
                                        </dd>
                                    </div>

                                    <div
                                        :class="[
                                            'rounded border p-3',
                                            finished && counts.failed
                                                ? 'border-red-200 bg-red-50'
                                                : 'border-gray-200',
                                        ]"
                                    >
                                        <dt
                                            :class="[
                                                'text-xs font-medium uppercase',
                                                finished && counts.failed ? 'text-red-700' : 'text-gray-500',
                                            ]"
                                        >
                                            {{ finished ? 'Failed' : 'Skipped' }}
                                        </dt>
                                        <dd
                                            :class="[
                                                'mt-1 text-2xl font-semibold',
                                                finished && counts.failed ? 'text-red-900' : 'text-gray-900',
                                            ]"
                                        >
                                            {{
                                                (finished
                                                    ? (counts.failed ?? 0)
                                                    : (counts.already_member ?? 0) + blockedTotal
                                                ).toLocaleString()
                                            }}
                                        </dd>
                                    </div>
                                </dl>

                                <p v-if="!finished && !running" class="mt-3 text-sm text-gray-600">
                                    <span class="font-semibold">{{ preview.actionable.toLocaleString() }}</span>
                                    of {{ preview.import.row_count.toLocaleString() }} rows would be sent.
                                    <span v-if="preview.import.tag">
                                        Each one tagged <span class="font-semibold">{{ preview.import.tag }}</span
                                        >.
                                    </span>
                                    <span v-if="preview.import.double_optin">
                                        This audience is double opt-in, so contacts are invited and only subscribe
                                        once they confirm.
                                    </span>
                                </p>

                                <!-- Compliance refusals. Only the contact can undo this, so the
                                     admin gets the audience's own signup link to pass on. -->
                                <div
                                    v-if="finished && complianceBlocked"
                                    class="mt-4 rounded border border-amber-300 bg-amber-50 p-4"
                                >
                                    <p class="text-sm font-semibold text-amber-900">
                                        {{ complianceBlocked.toLocaleString() }} contacts could not be resubscribed
                                    </p>
                                    <p class="mt-1 text-sm text-amber-800">
                                        Mailchimp holds these addresses in a compliance state. No API call can bring
                                        them back — they have to opt in themselves. Filter the table below by
                                        “{{ labels.blocked_unsubscribed }}” to see who.
                                    </p>
                                    <p v-if="preview.signup_url" class="mt-2 text-sm text-amber-800">
                                        Send them this form:
                                        <a
                                            :href="preview.signup_url"
                                            target="_blank"
                                            rel="noopener"
                                            class="break-all underline"
                                            >{{ preview.signup_url }}</a
                                        >
                                    </p>
                                </div>

                                <!-- Breakdown. Doubles as the filter for the row list. -->
                                <div class="mt-5 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        :class="[
                                            'rounded-full border px-3 py-1 text-sm',
                                            outcomeFilter === null
                                                ? 'border-gray-800 bg-gray-800 text-white'
                                                : 'border-gray-300 text-gray-700 hover:bg-gray-50',
                                        ]"
                                        @click="filterRows(null)"
                                    >
                                        All rows
                                    </button>
                                    <button
                                        v-for="item in breakdown"
                                        :key="item.key"
                                        type="button"
                                        :class="[
                                            'rounded-full border px-3 py-1 text-sm',
                                            outcomeFilter === item.key
                                                ? 'border-gray-800 bg-gray-800 text-white'
                                                : 'border-gray-300 text-gray-700 hover:bg-gray-50',
                                        ]"
                                        @click="filterRows(item.key)"
                                    >
                                        {{ item.label }}
                                        <span class="font-semibold">{{ item.total.toLocaleString() }}</span>
                                    </button>
                                </div>

                                <div class="mt-3 overflow-x-auto rounded border border-gray-200">
                                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-3 py-2 text-left font-medium text-gray-600">Row</th>
                                                <th class="px-3 py-2 text-left font-medium text-gray-600">Email</th>
                                                <th class="px-3 py-2 text-left font-medium text-gray-600">Outcome</th>
                                                <th class="px-3 py-2 text-left font-medium text-gray-600">Detail</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 bg-white">
                                            <tr v-if="loadingRows">
                                                <td colspan="4" class="px-3 py-4 text-center text-gray-500">
                                                    Loading…
                                                </td>
                                            </tr>
                                            <tr v-else-if="!preview.rows.length">
                                                <td colspan="4" class="px-3 py-4 text-center text-gray-500">
                                                    No rows with this outcome.
                                                </td>
                                            </tr>
                                            <tr v-for="row in loadingRows ? [] : preview.rows" :key="row.row_number">
                                                <td class="px-3 py-2 text-gray-500">{{ row.row_number }}</td>
                                                <td class="px-3 py-2 text-gray-900">{{ row.email || '—' }}</td>
                                                <td class="px-3 py-2">
                                                    <span
                                                        :class="[
                                                            'rounded px-2 py-0.5 text-xs',
                                                            row.outcome === 'will_subscribe'
                                                                ? 'bg-green-100 text-green-800'
                                                                : row.outcome === 'will_resubscribe'
                                                                    ? 'bg-blue-100 text-blue-800'
                                                                    : row.outcome === 'already_member'
                                                                        ? 'bg-gray-100 text-gray-700'
                                                                        : 'bg-amber-100 text-amber-800',
                                                        ]"
                                                    >
                                                        {{ labels[row.outcome] ?? row.outcome }}
                                                    </span>
                                                </td>
                                                <td class="px-3 py-2 text-gray-600">{{ row.detail || '—' }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <p
                                    v-if="preview.rows.length >= preview.row_limit"
                                    class="mt-2 text-xs text-gray-500"
                                >
                                    Showing the first {{ preview.row_limit }} rows of this outcome.
                                </p>

                                <div class="mt-5 flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4">
                                    <button
                                        v-if="!finished && !running"
                                        type="button"
                                        :disabled="sending || !preview.actionable"
                                        class="rounded bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-600 disabled:cursor-not-allowed disabled:bg-gray-300"
                                        @click="startRun"
                                    >
                                        {{ sending ? 'Starting…' : `Import ${preview.actionable.toLocaleString()} contacts` }}
                                    </button>

                                    <!-- A stopped run left rows untouched; sending again picks
                                         up only those, never what already went through. -->
                                    <button
                                        v-if="finished && preview.status === 'failed' && preview.actionable"
                                        type="button"
                                        :disabled="sending"
                                        class="rounded bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:cursor-not-allowed disabled:bg-gray-300"
                                        @click="startRun"
                                    >
                                        {{
                                            sending
                                                ? 'Starting…'
                                                : `Resume — ${preview.actionable.toLocaleString()} left to send`
                                        }}
                                    </button>

                                    <button
                                        v-if="!running && !finished"
                                        type="button"
                                        class="text-sm text-gray-600 underline"
                                        @click="backToMapping"
                                    >
                                        Change the mapping
                                    </button>
                                    <!-- Every row with its outcome and Mailchimp's own reason. -->
                                    <a
                                        v-if="previewReady"
                                        :href="route('mailchimpImport.download', { import: importRecord.id })"
                                        class="text-sm text-gray-600 underline"
                                    >
                                        Download full report (CSV)
                                    </a>

                                    <button
                                        v-if="!running"
                                        type="button"
                                        class="text-sm text-gray-600 underline"
                                        @click="startOver"
                                    >
                                        {{ finished ? 'Import another file' : 'Start over' }}
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
