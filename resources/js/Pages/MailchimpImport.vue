<script setup>
import { computed, ref, watch } from 'vue';
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
];
const step = ref('audience');
const stepIndex = computed(() => STEPS.findIndex((s) => s.key === step.value));

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

function startOver() {
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
                        Connect an account on the
                        <a :href="route('mailchimp.integration.index')" class="underline">Mailchimp Integration</a>
                        page, or configure an API key for it.
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
                            <div class="rounded border border-dashed border-gray-300 bg-gray-50 p-6 text-center">
                                <p class="text-sm font-medium text-gray-700">
                                    Configuration saved. The dry run is not built yet.
                                </p>
                                <p class="mt-1 text-sm text-gray-500">Nothing has been sent to Mailchimp.</p>
                            </div>
                            <button type="button" class="mt-4 text-sm text-gray-600 underline" @click="startOver">
                                Start over
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
