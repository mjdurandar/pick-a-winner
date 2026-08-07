<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import Swal from 'sweetalert2';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    maxUploadMb: { type: Number, default: 10 },
});

// One page, four states. Nothing here writes to Mailchimp — the dry run in the
// preview step is the last stop before the confirm button.
const STEPS = ['upload', 'configure', 'preview', 'running'];
const step = ref('upload');

const activeAccounts = computed(() => props.accounts.filter((a) => a.active));
const account = ref(activeAccounts.value[0]?.key ?? null);

const file = ref(null);
const dragging = ref(false);
const uploading = ref(false);
const uploadError = ref('');

const importRecord = ref(null);
const headers = ref([]);

const audiences = ref([]);
const audienceId = ref('');
const loadingAudiences = ref(false);

const mergeFields = ref([]);
const fieldMap = ref({});
const doubleOptin = ref(false);
const loadingFields = ref(false);

const tag = ref('');
const consentConfirmed = ref(false);
const consentSource = ref('');
const saving = ref(false);
const configErrors = ref({});

const emailMapped = computed(() => Object.values(fieldMap.value).includes('EMAIL'));

const canContinue = computed(
    () => audienceId.value && emailMapped.value && consentConfirmed.value && !saving.value
);

// A merge tag can only take one column, so tags already spoken for are hidden
// from the other dropdowns rather than silently overwriting each other.
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
    if (!account.value) {
        uploadError.value = 'Connect a Mailchimp account first.';
        return;
    }

    uploading.value = true;
    uploadError.value = '';

    const form = new FormData();
    form.append('account', account.value);
    form.append('file', file.value);

    try {
        const { data } = await axios.post(route('mailchimpImport.upload'), form);
        importRecord.value = data.import;
        headers.value = data.headers;
        step.value = 'configure';
        loadAudiences();
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

async function loadAudiences() {
    loadingAudiences.value = true;
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

async function onAudienceChange() {
    if (!audienceId.value) return;

    loadingFields.value = true;
    fieldMap.value = {};

    try {
        const { data } = await axios.get(
            route('mailchimpImport.mergeFields', { import: importRecord.value.id }),
            { params: { audience_id: audienceId.value } }
        );
        mergeFields.value = data.merge_fields;
        doubleOptin.value = data.double_optin;
        // Obvious matches are pre-filled; everything else stays blank for the
        // admin to set.
        fieldMap.value = { ...data.suggested_map };
    } catch (err) {
        reportApiError(err, 'Could not load merge fields from Mailchimp.');
    } finally {
        loadingFields.value = false;
    }
}

async function saveConfiguration() {
    saving.value = true;
    configErrors.value = {};

    try {
        await axios.post(route('mailchimpImport.configure', { import: importRecord.value.id }), {
            audience_id: audienceId.value,
            audience_name: audiences.value.find((a) => a.id === audienceId.value)?.name,
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

function reportApiError(err, fallback) {
    const message = err.response?.data?.message || fallback;
    Swal.fire({
        title: err.response?.data?.needs_reconnect ? 'Reconnect required' : 'Mailchimp error',
        text: message,
        icon: 'error',
    });
}

function startOver() {
    step.value = 'upload';
    file.value = null;
    importRecord.value = null;
    headers.value = [];
    audiences.value = [];
    audienceId.value = '';
    mergeFields.value = [];
    fieldMap.value = {};
    tag.value = '';
    consentConfirmed.value = false;
    consentSource.value = '';
    configErrors.value = {};
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
                <!-- Step rail -->
                <ol class="mb-6 flex flex-wrap items-center gap-2 text-sm">
                    <li
                        v-for="(name, index) in STEPS"
                        :key="name"
                        :class="[
                            'flex items-center gap-2 rounded-full px-3 py-1 capitalize',
                            STEPS.indexOf(step) === index
                                ? 'bg-gray-800 text-white'
                                : STEPS.indexOf(step) > index
                                    ? 'bg-green-100 text-green-800'
                                    : 'bg-gray-100 text-gray-500',
                        ]"
                    >
                        <span class="font-medium">{{ index + 1 }}.</span> {{ name }}
                    </li>
                </ol>

                <div v-if="!activeAccounts.length" class="rounded-md border border-amber-300 bg-amber-50 p-4">
                    <p class="text-sm font-semibold text-amber-900">No Mailchimp account is connected</p>
                    <p class="mt-1 text-sm text-amber-800">
                        Connect an account on the
                        <a :href="route('mailchimp.integration.index')" class="underline">Mailchimp Integration</a>
                        page before importing.
                    </p>
                </div>

                <div v-else class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <!-- ── Upload ─────────────────────────────────────────── -->
                        <div v-show="step === 'upload'">
                            <div v-if="activeAccounts.length > 1" class="mb-4">
                                <label class="block text-sm font-medium text-gray-700">Mailchimp account</label>
                                <select v-model="account" class="mt-1 w-full rounded border-gray-300 text-sm sm:w-72">
                                    <option v-for="a in activeAccounts" :key="a.key" :value="a.key">
                                        {{ a.label }}<span v-if="a.name"> — {{ a.name }}</span>
                                    </option>
                                </select>
                            </div>

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
                        </div>

                        <!-- ── Configure ──────────────────────────────────────── -->
                        <div v-show="step === 'configure'">
                            <div class="mb-6 rounded border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
                                <span class="font-medium">{{ importRecord?.filename }}</span>
                                · {{ importRecord?.row_count?.toLocaleString() }} rows
                                · {{ formatBytes(importRecord?.file_size) }}
                            </div>

                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700">Audience</label>
                                <select
                                    v-model="audienceId"
                                    :disabled="loadingAudiences"
                                    class="mt-1 w-full rounded border-gray-300 text-sm"
                                    @change="onAudienceChange"
                                >
                                    <option value="">
                                        {{ loadingAudiences ? 'Loading audiences…' : 'Select an audience' }}
                                    </option>
                                    <option v-for="a in audiences" :key="a.id" :value="a.id">
                                        {{ a.name }} ({{ a.member_count.toLocaleString() }} members)
                                    </option>
                                </select>
                            </div>

                            <div v-if="doubleOptin" class="mb-4 rounded-md bg-blue-50 p-3">
                                <p class="text-sm text-blue-800">
                                    This audience uses double opt-in. Contacts will be added as
                                    <strong>pending</strong> and only become subscribed once they confirm.
                                </p>
                            </div>

                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700">
                                    Tag <span class="font-normal text-gray-500">(optional, applied to every contact)</span>
                                </label>
                                <input v-model="tag" type="text" class="mt-1 w-full rounded border-gray-300 text-sm" />
                            </div>

                            <div v-if="audienceId" class="mb-6">
                                <h3 class="mb-2 text-sm font-medium text-gray-700">Column mapping</h3>
                                <p v-if="loadingFields" class="text-sm text-gray-500">Loading merge fields…</p>
                                <table v-else class="w-full text-sm">
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
                                                <select
                                                    v-model="fieldMap[header]"
                                                    class="w-full rounded border-gray-300 text-sm"
                                                >
                                                    <option :value="undefined">— Do not import —</option>
                                                    <option
                                                        v-for="f in availableFields(header)"
                                                        :key="f.tag"
                                                        :value="f.tag"
                                                    >
                                                        {{ f.name }} ({{ f.tag }})
                                                    </option>
                                                </select>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <p v-if="!loadingFields && !emailMapped" class="mt-2 text-sm text-amber-700">
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
                                        Where did they opt in? <span class="font-normal">(optional, recorded with the import)</span>
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
                                    :disabled="!canContinue"
                                    class="rounded bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:cursor-not-allowed disabled:bg-gray-300"
                                    @click="saveConfiguration"
                                >
                                    {{ saving ? 'Saving…' : 'Continue to preview' }}
                                </button>
                                <button
                                    type="button"
                                    class="text-sm text-gray-600 underline"
                                    @click="startOver"
                                >
                                    Start over
                                </button>
                            </div>
                        </div>

                        <!-- ── Preview ────────────────────────────────────────── -->
                        <div v-show="step === 'preview'">
                            <div class="rounded border border-dashed border-gray-300 bg-gray-50 p-6 text-center">
                                <p class="text-sm font-medium text-gray-700">
                                    Configuration saved. The dry run is not built yet.
                                </p>
                                <p class="mt-1 text-sm text-gray-500">
                                    Nothing has been sent to Mailchimp.
                                </p>
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
