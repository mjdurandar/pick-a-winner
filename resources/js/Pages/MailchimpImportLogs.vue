<script setup>
import { ref, computed } from 'vue';
import Swal from 'sweetalert2';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';

const page = usePage();
const showErrorsModal = ref(false);
const errorsModalLog = ref(null);
const editableFailedRows = ref([]);
const isReimporting = ref(false);
const showQueuedImportsModal = ref(false);
const queuedImports = ref([]);
const queueConnection = ref(null);
const loadingQueuedImports = ref(false);
const cancellingJobId = ref(null);
let queuedRefreshInterval = null;
const canDeleteLogs = computed(() => (page.props.auth?.user?.email || '').toLowerCase() === 'mj@adventureentertainment.com');
const selectedLogIds = ref([]);
const allFilteredIds = computed(() => filteredLogs.value.map((log) => log.id));
const allSelected = computed({
    get() {
        return allFilteredIds.value.length > 0 && selectedLogIds.value.length === allFilteredIds.value.length;
    },
    set(checked) {
        if (checked) {
            selectedLogIds.value = [...allFilteredIds.value];
        } else {
            selectedLogIds.value = [];
        }
    },
});
function toggleLogSelection(id) {
    const idx = selectedLogIds.value.indexOf(id);
    if (idx === -1) selectedLogIds.value = [...selectedLogIds.value, id];
    else selectedLogIds.value = selectedLogIds.value.filter((x) => x !== id);
}
function isLogSelected(id) {
    return selectedLogIds.value.includes(id);
}

const props = defineProps({
    mailchimpImportLogs: { type: Array, default: () => [] },
    events: { type: Array, default: () => [] },
    filterEventId: { type: Number, default: null },
    filterSource: { type: String, default: null } // 'signup_form' | 'ticket_data' | null
});

const formatImportDate = (dateStr) => {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    return isNaN(d.getTime()) ? String(dateStr) : d.toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });
};

const formatTags = (tags) => {
    if (!tags) return '—';
    if (Array.isArray(tags)) return tags.filter(Boolean).join(', ') || '—';
    return String(tags);
};

const formatErrors = (errors) => {
    if (!errors || !Array.isArray(errors) || errors.length === 0) return '—';
    return errors.filter(Boolean).join('; ');
};

const hasErrors = (errors) => {
    return errors && Array.isArray(errors) && errors.filter(Boolean).length > 0;
};

// Normalize failed_rows (backend may send array or JSON string)
const getFailedRows = (log) => {
    let rows = log.failed_rows;
    if (typeof rows === 'string') {
        try {
            rows = JSON.parse(rows) || [];
        } catch {
            rows = [];
        }
    }
    return Array.isArray(rows) ? rows : [];
};

const hasFailedRows = (log) => {
    const rows = getFailedRows(log);
    return rows.length > 0;
};

const statusLabel = (status) => {
    if (!status) return '—';
    return status === 'reimport' ? 'Reimport' : 'Import';
};

// Parse error strings like "email@x.com: Invalid email" to get email + message (for logs without failed_rows)
const parseErrorsToRows = (errors) => {
    if (!errors || !Array.isArray(errors)) return [];
    return errors.filter(Boolean).map((err) => {
        const s = String(err).trim();
        const colonIdx = s.indexOf(': ');
        const dashIdx = s.indexOf(' - ');
        const splitAt = colonIdx >= 0 ? colonIdx : (dashIdx >= 0 ? dashIdx : -1);
        const email = splitAt >= 0 ? s.slice(0, splitAt).trim() : '';
        const msg = splitAt >= 0 ? s.slice(splitAt + (colonIdx >= 0 ? 2 : 3)).trim() : s;
        return {
            email_address: email,
            first_name: '',
            last_name: '',
            mobile_number: '',
            street_address: '',
            street_address_2: '',
            city: '',
            state: '',
            zip_code: '',
            country: '',
            gender: '',
            age: '',
            error_message: msg,
        };
    }).filter((row) => row.email_address || row.error_message);
};

const showFullErrors = (log) => {
    if (hasFailedRows(log)) {
        errorsModalLog.value = log;
        const rows = getFailedRows(log);
        // Pre-fill every field from the failed row data so you can fix and re-import
        editableFailedRows.value = rows.map((row) => ({
            email_address: row.email_address ?? '',
            first_name: row.first_name ?? '',
            last_name: row.last_name ?? '',
            mobile_number: row.mobile_number ?? '',
            street_address: row.street_address ?? '',
            street_address_2: row.street_address_2 ?? '',
            city: row.city ?? '',
            state: row.state ?? '',
            zip_code: row.zip_code ?? '',
            country: row.country ?? '',
            gender: row.gender ?? '',
            age: row.age ?? '',
            error_message: row.error_message ?? '',
        }));
        showErrorsModal.value = true;
    } else if (hasErrors(log.errors)) {
        // Fallback: no failed_rows (e.g. old log) – parse error strings so user can still fix & re-import
        errorsModalLog.value = log;
        editableFailedRows.value = parseErrorsToRows(log.errors);
        if (editableFailedRows.value.length > 0) {
            showErrorsModal.value = true;
        } else {
            const text = formatErrors(log.errors);
            Swal.fire({
                title: 'Import errors',
                html: `<div class="text-left text-sm text-gray-700 whitespace-pre-wrap break-words max-h-96 overflow-y-auto p-2 bg-gray-50 rounded border border-gray-200">${escapeHtml(text)}</div><p class="text-xs text-gray-500 mt-2">To fix and re-import, the log needs stored failed rows. New imports will support that.</p>`,
                width: '32rem',
                showCloseButton: true,
                confirmButtonText: 'Close',
            });
        }
    } else {
        const text = formatErrors(log.errors);
        if (!text || text === '—') return;
        Swal.fire({
            title: 'Import errors',
            html: `<div class="text-left text-sm text-gray-700 whitespace-pre-wrap break-words max-h-96 overflow-y-auto p-2 bg-gray-50 rounded border border-gray-200">${escapeHtml(text)}</div>`,
            width: '32rem',
            showCloseButton: true,
            showConfirmButton: true,
            confirmButtonText: 'Close',
        });
    }
};

const closeErrorsModal = () => {
    showErrorsModal.value = false;
    errorsModalLog.value = null;
    editableFailedRows.value = [];
};

const reimportCorrectedData = async () => {
    if (!errorsModalLog.value || !editableFailedRows.value.length) return;
    isReimporting.value = true;
    try {
        const res = await axios.post(route('mailchimpImportLogs.reimportFailed'), {
            log_id: errorsModalLog.value.id,
            subscribers: editableFailedRows.value.map((row) => ({
                email_address: row.email_address,
                first_name: row.first_name,
                last_name: row.last_name,
                mobile_number: row.mobile_number,
                street_address: row.street_address,
                street_address_2: row.street_address_2,
                city: row.city,
                state: row.state,
                zip_code: row.zip_code,
                country: row.country,
                gender: row.gender,
                age: row.age,
            })),
        });
        const d = res.data;
        closeErrorsModal();
        Swal.fire('Done', d.message || 'Re-import completed. Check the log for results.', d.data_with_error > 0 ? 'warning' : 'success').then(() => {
            router.reload();
        });
    } catch (err) {
        const msg = err.response?.data?.message || err.response?.data?.error || err.message || 'Re-import failed.';
        Swal.fire('Error', msg, 'error');
    } finally {
        isReimporting.value = false;
    }
};

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

const fetchQueuedImports = async () => {
    loadingQueuedImports.value = true;
    try {
        const res = await axios.get(route('mailchimpImportLogs.queuedImports'));
        queuedImports.value = res.data.queued_imports || [];
        queueConnection.value = res.data.queue_connection ?? null;
    } catch {
        queuedImports.value = [];
        queueConnection.value = null;
    } finally {
        loadingQueuedImports.value = false;
    }
};

const openQueuedImportsModal = async () => {
    showQueuedImportsModal.value = true;
    await fetchQueuedImports();
    queuedRefreshInterval = setInterval(fetchQueuedImports, 5000);
};

const closeQueuedImportsModal = () => {
    showQueuedImportsModal.value = false;
    if (queuedRefreshInterval) {
        clearInterval(queuedRefreshInterval);
        queuedRefreshInterval = null;
    }
};

// Flatten queued imports to one row per location (for table with per-location cancel)
const queuedImportRows = computed(() => {
    const rows = [];
    for (const job of queuedImports.value) {
        const locations = job.locations || [];
        for (const loc of locations) {
            rows.push({
                job_id: job.job_id,
                location_id: loc.location_id,
                location_name: loc.location_name || '—',
                event_name: job.event_name || '—',
                status: job.status,
                created_at: job.created_at,
            });
        }
        if (locations.length === 0 && (job.job_id || job.event_name)) {
            rows.push({
                job_id: job.job_id,
                location_id: null,
                location_name: '—',
                event_name: job.event_name || '—',
                status: job.status,
                created_at: job.created_at,
            });
        }
    }
    return rows;
});

const cancelQueuedImportInProgress = async (row) => {
    if (row.status !== 'in_progress' || !row.job_id) return;
    const ok = await Swal.fire({
        title: 'Stop this import?',
        text: 'The import will finish the current location, then stop. Remaining locations will not be imported.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, stop it',
        cancelButtonText: 'Let it run',
        confirmButtonColor: '#dc2626',
    }).then((r) => r.isConfirmed);
    if (!ok) return;
    cancellingJobId.value = 'inprogress-' + row.job_id;
    try {
        await axios.delete(route('mailchimpImportLogs.cancelQueuedImport', row.job_id));
        await fetchQueuedImports();
        Swal.fire('Stopping', 'The import will stop after the current location finishes.', 'success');
    } catch (err) {
        const msg = err.response?.data?.error || err.response?.data?.message || err.message || 'Failed to stop.';
        Swal.fire('Error', msg, 'error');
    } finally {
        cancellingJobId.value = null;
    }
};

const cancelQueuedLocation = async (row) => {
    if (row.status !== 'queued' || !row.job_id || row.location_id == null) return;
    const ok = await Swal.fire({
        title: 'Cancel this location?',
        text: `"${row.location_name}" will be removed from the import. Other locations in this import will still run.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, cancel this location',
        cancelButtonText: 'Keep',
        confirmButtonColor: '#dc2626',
    }).then((r) => r.isConfirmed);
    if (!ok) return;
    cancellingJobId.value = `${row.job_id}-${row.location_id}`;
    try {
        await axios.post(route('mailchimpImportLogs.cancelQueuedLocation'), { job_id: row.job_id, location_id: row.location_id });
        await fetchQueuedImports();
        Swal.fire('Cancelled', 'That location was removed from the import. Remaining locations are still queued.', 'success');
    } catch (err) {
        const msg = err.response?.data?.error || err.response?.data?.message || err.message || 'Failed to cancel.';
        Swal.fire('Error', msg, 'error');
    } finally {
        cancellingJobId.value = null;
    }
};

const filteredLogs = computed(() => props.mailchimpImportLogs);

// Max number of tag columns to show (one column per tag)
const maxTagColumns = computed(() => {
    const logs = filteredLogs.value;
    if (!logs.length) return 0;
    const max = Math.max(...logs.map((log) => (log.tags && Array.isArray(log.tags) ? log.tags.length : 0)));
    return Math.min(max, 20);
});

const tagColumnIndices = computed(() => Array.from({ length: maxTagColumns.value }, (_, i) => i));

const buildLogsUrl = (eventId, source) => {
    const params = new URLSearchParams();
    if (eventId) params.set('event_id', eventId);
    if (source) params.set('source', source);
    const q = params.toString();
    return q ? `/mailchimp-import-logs?${q}` : '/mailchimp-import-logs';
};

const applyEventFilter = (value) => {
    const eventId = value === '' || value == null ? null : Number(value);
    router.visit(buildLogsUrl(eventId, props.filterSource || ''));
};

const applySourceFilter = (value) => {
    const source = value === '' || value == null ? null : value;
    router.visit(buildLogsUrl(props.filterEventId || '', source));
};

const sourceLabel = (source) => {
    if (!source) return '—';
    return source === 'ticket_data' ? 'Ticket data' : 'Signup form';
};

// Totals for filtered logs (breakdown at bottom)
const totalsBreakdown = computed(() => {
    const logs = filteredLogs.value;
    return {
        totalImports: logs.length,
        totalData: logs.reduce((s, l) => s + (l.total_data ?? 0), 0),
        newContacts: logs.reduce((s, l) => s + (l.new_contacts ?? 0), 0),
        updatedData: logs.reduce((s, l) => s + (l.updated_data ?? 0), 0),
        dataWithError: logs.reduce((s, l) => s + (l.data_with_error ?? 0), 0),
    };
});

const deleteLog = (log) => {
    Swal.fire({
        title: 'Delete this import log?',
        text: 'This will remove the log and any stored import file. This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete it',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('mailchimpImportLogs.destroy', log.id), {
                onSuccess: () => {
                    Swal.fire('Deleted', 'The import log has been removed.', 'success');
                },
                onError: () => {
                    Swal.fire('Error', 'Failed to delete the log.', 'error');
                },
            });
        }
    });
};

const deletingAllLogs = ref(false);
const deletingSelectedLogs = ref(false);
const deleteSelectedLogs = () => {
    if (selectedLogIds.value.length === 0) return;
    Swal.fire({
        title: 'Delete selected logs?',
        html: `This will permanently delete <strong>${selectedLogIds.value.length}</strong> import log(s) and their stored CSV files. This cannot be undone.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete selected',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626',
    }).then((result) => {
        if (!result.isConfirmed) return;
        deletingSelectedLogs.value = true;
        axios.post(route('mailchimpImportLogs.destroyMultiple'), { ids: selectedLogIds.value })
            .then(() => {
                selectedLogIds.value = [];
                router.reload();
                Swal.fire('Deleted', 'Selected import logs have been removed.', 'success');
            })
            .catch((err) => {
                const msg = err.response?.data?.message || err.response?.data?.error || err.message || 'Failed to delete.';
                Swal.fire('Error', msg, 'error');
            })
            .finally(() => {
                deletingSelectedLogs.value = false;
            });
    });
};
const deleteAllLogs = () => {
    Swal.fire({
        title: 'Delete all MC logs?',
        html: 'This will permanently delete <strong>all</strong> Mailchimp import logs and their stored CSV files. This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete all logs',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626',
    }).then((result) => {
        if (!result.isConfirmed) return;
        deletingAllLogs.value = true;
        router.delete(route('mailchimpImportLogs.destroyAll'), {
            onSuccess: () => {
                deletingAllLogs.value = false;
                Swal.fire('Deleted', 'All import logs have been removed.', 'success');
            },
            onError: () => {
                deletingAllLogs.value = false;
                Swal.fire('Error', 'Failed to delete all logs.', 'error');
            },
            onFinish: () => {
                deletingAllLogs.value = false;
            },
        });
    });
};

const exportToCsv = () => {
    const n = maxTagColumns.value;
    const headers = [
        'Event', 'Location', 'Imported Date', 'Imported By', 'Source', 'Status', 'Total Data', 'New Contacts', 'Updated Data', 'Data with Error', 'Errors', 'Imported data file',
        ...Array.from({ length: n }, (_, i) => `Tag ${i + 1}`)
    ];
    const escape = (v) => {
        const s = v == null ? '' : String(v);
        return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
    };
    const rows = filteredLogs.value.map((log) => {
        const tags = log.tags && Array.isArray(log.tags) ? log.tags : [];
        const base = [
            log.event_name ?? '—',
            log.location_name ?? '—',
            formatImportDate(log.created_at),
            log.imported_by_name ?? '—',
            sourceLabel(log.source),
            statusLabel(log.status),
            log.total_data ?? 0,
            log.new_contacts ?? 0,
            log.updated_data ?? 0,
            log.data_with_error ?? 0,
            formatErrors(log.errors),
            log.has_import_file ? 'Yes' : 'No'
        ];
        const tagCells = Array.from({ length: n }, (_, i) => tags[i] ?? '');
        return [...base, ...tagCells];
    });
    const csv = [headers.map(escape).join(','), ...rows.map((r) => r.map(escape).join(','))].join('\r\n');
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `mailchimp-import-logs-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
};
</script>

<template>
    <Head title="MC Logs" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                MC Logs
            </h2>
        </template>

        <div class="p-2 pb-5 pt-4">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <p class="text-gray-600 mb-4">
                            All Mailchimp imports recorded when you import attendees to Mailchimp from a location page.
                        </p>

                        <div class="flex flex-wrap items-center gap-4 mb-4">
                            <div class="flex items-center gap-2">
                                <label for="event-filter" class="text-sm font-medium text-gray-700">Filter by event</label>
                                <select
                                    id="event-filter"
                                    :value="filterEventId ?? ''"
                                    class="border rounded px-3 py-2 text-sm"
                                    @change="applyEventFilter($event.target.value)"
                                >
                                    <option value="">All events</option>
                                    <option v-for="e in events" :key="e.id" :value="e.id">{{ e.event_name }}</option>
                                </select>
                            </div>
                            <div class="flex items-center gap-2">
                                <label for="source-filter" class="text-sm font-medium text-gray-700">Filter by source</label>
                                <select
                                    id="source-filter"
                                    :value="filterSource ?? ''"
                                    class="border rounded px-3 py-2 text-sm"
                                    @change="applySourceFilter($event.target.value)"
                                >
                                    <option value="">All sources</option>
                                    <option value="signup_form">Signup form</option>
                                    <option value="ticket_data">Ticket data</option>
                                </select>
                            </div>
                            <button
                                type="button"
                                @click="openQueuedImportsModal"
                                class="bg-amber-600 text-white px-4 py-2 rounded hover:bg-amber-700 text-sm font-medium"
                                title="See locations still importing (queued or in progress)"
                            >
                                <i class="fa-solid fa-clock-rotate-left mr-2"></i> View queued imports
                            </button>
                            <button
                                type="button"
                                @click="exportToCsv"
                                class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 text-sm font-medium"
                            >
                                <i class="fa-solid fa-file-csv mr-2"></i> Export to CSV
                            </button>
                            <button
                                v-if="canDeleteLogs && selectedLogIds.length > 0"
                                type="button"
                                @click="deleteSelectedLogs"
                                :disabled="deletingSelectedLogs"
                                class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600 disabled:opacity-50 text-sm font-medium"
                                title="Delete selected logs"
                            >
                                <span v-if="deletingSelectedLogs"><i class="fa-solid fa-spinner fa-spin mr-2"></i></span>
                                <i v-else class="fa-solid fa-trash mr-2"></i> Delete selected ({{ selectedLogIds.length }})
                            </button>
                            <button
                                v-if="canDeleteLogs"
                                type="button"
                                @click="deleteAllLogs"
                                :disabled="deletingAllLogs || filteredLogs.length === 0"
                                class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed text-sm font-medium"
                                title="Delete all MC logs and their stored CSV files (mj only)"
                            >
                                <span v-if="deletingAllLogs"><i class="fa-solid fa-spinner fa-spin mr-2"></i></span>
                                <i v-else class="fa-solid fa-trash-can mr-2"></i> Delete all logs
                            </button>
                        </div>

                        <div v-if="filteredLogs.length === 0" class="text-gray-600 py-8 text-center">
                            No MC logs found.
                            <span v-if="filterEventId || filterSource">Try changing the event or source filter.</span>
                        </div>

                        <div v-else class="overflow-x-auto">
                            <table class="w-full border-collapse border border-gray-300 text-sm">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Event</th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Location</th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Imported Date</th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Imported By</th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Mailchimp Account</th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Audience</th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Source</th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Status</th>
                                        <th class="border border-gray-300 p-2 text-right whitespace-nowrap">Total Data</th>
                                        <th class="border border-gray-300 p-2 text-right whitespace-nowrap">New Contacts</th>
                                        <th class="border border-gray-300 p-2 text-right whitespace-nowrap">Updated Data</th>
                                        <th class="border border-gray-300 p-2 text-right whitespace-nowrap">Data with Error</th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Errors</th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Imported data</th>
                                        <th v-for="i in tagColumnIndices" :key="i" class="border border-gray-300 p-2 text-left whitespace-nowrap">Tag {{ i + 1 }}</th>
                                        <th class="border border-gray-300 p-2 text-center whitespace-nowrap">Actions</th>
                                        <th v-if="canDeleteLogs" class="border border-gray-300 p-2 text-center whitespace-nowrap w-12">
                                            <input
                                                type="checkbox"
                                                :checked="allSelected"
                                                :indeterminate="selectedLogIds.length > 0 && selectedLogIds.length < allFilteredIds.length"
                                                class="rounded border-gray-300"
                                                title="Select all on this page"
                                                @change="allSelected = $event.target.checked"
                                            >
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="log in filteredLogs"
                                        :key="log.id"
                                        class="text-left even:bg-gray-50"
                                    >
                                        <td class="border border-gray-300 p-2 whitespace-nowrap">{{ log.event_name || '—' }}</td>
                                        <td class="border border-gray-300 p-2 whitespace-nowrap">{{ log.location_name || '—' }}</td>
                                        <td class="border border-gray-300 p-2 whitespace-nowrap">{{ formatImportDate(log.created_at) }}</td>
                                        <td class="border border-gray-300 p-2 whitespace-nowrap">{{ log.imported_by_name || '—' }}</td>
                                        <td class="border border-gray-300 p-2 whitespace-nowrap">{{ log.mailchimp_account ? (log.mailchimp_account === 'usa' ? 'USA' : log.mailchimp_account === 'anz' ? 'ANZ' : log.mailchimp_account) : '—' }}</td>
                                        <td class="border border-gray-300 p-2 whitespace-nowrap max-w-xs truncate" :title="log.list_name || log.list_id">{{ log.list_name || log.list_id || '—' }}</td>
                                        <td class="border border-gray-300 p-2 whitespace-nowrap">{{ sourceLabel(log.source) }}</td>
                                        <td class="border border-gray-300 p-2 whitespace-nowrap">
                                            <span :class="log.status === 'reimport' ? 'text-amber-600 font-medium' : 'text-gray-700'">{{ statusLabel(log.status) }}</span>
                                        </td>
                                        <td class="border border-gray-300 p-2 text-right whitespace-nowrap">{{ log.total_data ?? 0 }}</td>
                                        <td class="border border-gray-300 p-2 text-right whitespace-nowrap">{{ log.new_contacts ?? 0 }}</td>
                                        <td class="border border-gray-300 p-2 text-right whitespace-nowrap">{{ log.updated_data ?? 0 }}</td>
                                        <td class="border border-gray-300 p-2 text-right whitespace-nowrap">{{ log.data_with_error ?? 0 }}</td>
                                        <td class="border border-gray-300 p-2 text-gray-600 max-w-xs whitespace-nowrap overflow-hidden">
                                            <span v-if="!hasErrors(log.errors)">—</span>
                                            <span v-else class="inline-flex items-baseline gap-1 max-w-full">
                                                <span class="truncate min-w-0">{{ formatErrors(log.errors) }}</span>
                                                <button
                                                    type="button"
                                                    @click="showFullErrors(log)"
                                                    class="flex-shrink-0 text-blue-600 hover:underline text-xs"
                                                >
                                                    See more
                                                </button>
                                            </span>
                                        </td>
                                        <td class="border border-gray-300 p-2 whitespace-nowrap">
                                            <a
                                                v-if="log.has_import_file"
                                                :href="route('mailchimpImportLogs.download', log.id)"
                                                class="text-blue-600 hover:underline"
                                                target="_blank"
                                                rel="noopener"
                                            >
                                                <i class="fa-solid fa-download mr-1"></i> Download
                                            </a>
                                            <span v-else class="text-gray-400">—</span>
                                        </td>
                                        <td v-for="i in tagColumnIndices" :key="i" class="border border-gray-300 p-2 text-gray-600 whitespace-nowrap">{{ (log.tags && log.tags[i]) || '—' }}</td>
                                        <td class="border border-gray-300 p-2 text-center whitespace-nowrap">
                                            <button
                                                v-if="canDeleteLogs"
                                                type="button"
                                                @click="deleteLog(log)"
                                                class="text-red-600 hover:text-red-800 hover:underline"
                                                title="Delete this log"
                                            >
                                                <i class="fa-solid fa-trash"></i> Delete
                                            </button>
                                            <span v-else class="text-gray-400">—</span>
                                        </td>
                                        <td v-if="canDeleteLogs" class="border border-gray-300 p-2 text-center whitespace-nowrap">
                                            <input
                                                type="checkbox"
                                                :checked="isLogSelected(log.id)"
                                                class="rounded border-gray-300"
                                                :value="log.id"
                                                @change="toggleLogSelection(log.id)"
                                            >
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <!-- Totals breakdown (based on current filter) -->
                            <div v-if="filteredLogs.length > 0" class="mt-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                                <h4 class="text-sm font-semibold text-gray-800 mb-3">Totals (filtered)</h4>
                                <div class="grid grid-cols-2 sm:grid-cols-5 gap-4 text-sm">
                                    <div class="bg-white p-3 rounded border border-gray-200">
                                        <div class="text-gray-500">Import sessions</div>
                                        <div class="font-semibold text-gray-900">{{ totalsBreakdown.totalImports }}</div>
                                    </div>
                                    <div class="bg-white p-3 rounded border border-gray-200">
                                        <div class="text-gray-500">Total data imported</div>
                                        <div class="font-semibold text-gray-900">{{ totalsBreakdown.totalData }}</div>
                                    </div>
                                    <div class="bg-white p-3 rounded border border-green-100">
                                        <div class="text-gray-500">New contacts</div>
                                        <div class="font-semibold text-green-700">{{ totalsBreakdown.newContacts }}</div>
                                    </div>
                                    <div class="bg-white p-3 rounded border border-blue-100">
                                        <div class="text-gray-500">Updated</div>
                                        <div class="font-semibold text-blue-700">{{ totalsBreakdown.updatedData }}</div>
                                    </div>
                                    <div class="bg-white p-3 rounded border border-red-100">
                                        <div class="text-gray-500">Rejected / with error</div>
                                        <div class="font-semibold text-red-700">{{ totalsBreakdown.dataWithError }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Queued / in-progress imports modal -->
        <div v-if="showQueuedImportsModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[85vh] flex flex-col">
                <div class="p-4 border-b flex justify-between items-center">
                    <h3 class="text-lg font-semibold">Imports still in queue</h3>
                    <button type="button" @click="closeQueuedImportsModal" class="text-gray-500 hover:text-gray-700 text-2xl leading-none">&times;</button>
                </div>
                <div class="p-4 overflow-auto flex-1">
                    <p class="text-sm text-gray-600 mb-3">One row per location. You can cancel individual queued locations; in-progress locations cannot be cancelled. List refreshes every 5 seconds.</p>
                    <div v-if="loadingQueuedImports && queuedImports.length === 0" class="text-center py-8 text-gray-500">
                        <i class="fa-solid fa-spinner fa-spin text-2xl mb-2"></i>
                        <p>Loading...</p>
                    </div>
                    <div v-else-if="queuedImportRows.length === 0" class="text-center py-8 text-gray-500">
                        <template v-if="queueConnection === 'sync'">
                            <i class="fa-solid fa-bolt text-2xl mb-2 text-amber-500"></i>
                            <p class="font-medium">Queue is running in sync mode.</p>
                            <p class="text-sm mt-1">Imports run immediately and are not stored, so nothing appears here after a refresh. Set <code class="bg-gray-100 px-1 rounded">QUEUE_CONNECTION=database</code> in <code class="bg-gray-100 px-1 rounded">.env</code> to use a queue and see jobs here.</p>
                        </template>
                        <template v-else>
                            <i class="fa-solid fa-check-circle text-2xl mb-2 text-green-500"></i>
                            <p>No imports in queue. All Import All jobs have been processed.</p>
                        </template>
                    </div>
                    <div v-else class="overflow-x-auto border rounded">
                        <table class="w-full text-sm border-collapse">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Event</th>
                                    <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Location</th>
                                    <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Status</th>
                                    <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Queued at</th>
                                    <th class="border border-gray-300 p-2 text-center whitespace-nowrap">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(row, idx) in queuedImportRows"
                                    :key="(row.job_id || '') + '-' + (row.location_id ?? idx)"
                                    class="hover:bg-gray-50"
                                >
                                    <td class="border border-gray-300 p-2 font-medium text-gray-900">{{ row.event_name }}</td>
                                    <td class="border border-gray-300 p-2 text-gray-700">{{ row.location_name }}</td>
                                    <td class="border border-gray-300 p-2">
                                        <span
                                            :class="row.status === 'in_progress' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800'"
                                            class="px-2 py-1 rounded text-xs font-medium"
                                        >
                                            {{ row.status === 'in_progress' ? 'In progress' : 'Queued' }}
                                        </span>
                                    </td>
                                    <td class="border border-gray-300 p-2 text-gray-500 whitespace-nowrap">{{ row.created_at || '—' }}</td>
                                    <td class="border border-gray-300 p-2 text-center">
                                        <button
                                            v-if="row.status === 'queued' && row.location_id != null"
                                            type="button"
                                            @click="cancelQueuedLocation(row)"
                                            :disabled="cancellingJobId === (row.job_id + '-' + row.location_id)"
                                            class="text-red-600 hover:text-red-800 hover:underline text-xs font-medium disabled:opacity-50 mr-2"
                                            title="Remove this location from the import"
                                        >
                                            <span v-if="cancellingJobId === (row.job_id + '-' + row.location_id)"><i class="fa-solid fa-spinner fa-spin mr-1"></i></span>
                                            <i v-else class="fa-solid fa-times-circle mr-1"></i> Cancel
                                        </button>
                                        <button
                                            v-else-if="row.status === 'in_progress'"
                                            type="button"
                                            @click="cancelQueuedImportInProgress(row)"
                                            :disabled="cancellingJobId === 'inprogress-' + row.job_id"
                                            class="text-amber-600 hover:text-amber-800 hover:underline text-xs font-medium disabled:opacity-50"
                                            title="Stop import after current location"
                                        >
                                            <span v-if="cancellingJobId === 'inprogress-' + row.job_id"><i class="fa-solid fa-spinner fa-spin mr-1"></i></span>
                                            <i v-else class="fa-solid fa-stop mr-1"></i> Stop import
                                        </button>
                                        <span v-else class="text-gray-400 text-xs">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="p-4 border-t flex justify-end">
                    <button type="button" @click="fetchQueuedImports" :disabled="loadingQueuedImports" class="px-4 py-2 border rounded text-gray-700 hover:bg-gray-50 disabled:opacity-50 text-sm">
                        <i class="fa-solid fa-arrows-rotate mr-2" :class="{ 'fa-spin': loadingQueuedImports }"></i> Refresh now
                    </button>
                    <button type="button" @click="closeQueuedImportsModal" class="px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700 ml-2">Close</button>
                </div>
            </div>
        </div>

        <!-- Fix and re-import failed rows modal -->
        <div v-if="showErrorsModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] flex flex-col">
                <div class="p-4 border-b flex justify-between items-center">
                    <h3 class="text-lg font-semibold">Fix and re-import failed data</h3>
                    <button type="button" @click="closeErrorsModal" class="text-gray-500 hover:text-gray-700 text-2xl leading-none">&times;</button>
                </div>
                <div class="p-4 overflow-auto flex-1">
                    <p class="text-sm text-gray-600 mb-3">The rows below are <strong>pre-filled with the data that had errors</strong>. Edit any field (e.g. fix email typos like mj@yaho.com → mj@yahoo.com), then click Re-import to send the corrected data to Mailchimp. A new log entry will be created.</p>
                    <div class="overflow-x-auto border rounded">
                        <table class="w-full text-sm border-collapse">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="border p-2 text-left">Email</th>
                                    <th class="border p-2 text-left">First name</th>
                                    <th class="border p-2 text-left">Last name</th>
                                    <th class="border p-2 text-left">Phone</th>
                                    <th class="border p-2 text-left text-red-600">Error</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, idx) in editableFailedRows" :key="idx" class="hover:bg-gray-50">
                                    <td class="border p-1"><input v-model="row.email_address" type="text" class="w-full border rounded px-2 py-1 text-sm" placeholder="Email" /></td>
                                    <td class="border p-1"><input v-model="row.first_name" type="text" class="w-full border rounded px-2 py-1 text-sm" placeholder="First" /></td>
                                    <td class="border p-1"><input v-model="row.last_name" type="text" class="w-full border rounded px-2 py-1 text-sm" placeholder="Last" /></td>
                                    <td class="border p-1"><input v-model="row.mobile_number" type="text" class="w-full border rounded px-2 py-1 text-sm" placeholder="Phone" /></td>
                                    <td class="border p-2 text-red-600 text-xs">{{ row.error_message }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="p-4 border-t flex justify-end gap-2">
                    <button type="button" @click="closeErrorsModal" class="px-4 py-2 border rounded text-gray-700 hover:bg-gray-50">Cancel</button>
                    <button type="button" @click="reimportCorrectedData" :disabled="isReimporting" class="px-4 py-2 bg-teal-600 text-white rounded hover:bg-teal-700 disabled:opacity-50">
                        <span v-if="isReimporting"><i class="fa-solid fa-spinner fa-spin mr-2"></i> Re-importing...</span>
                        <span v-else><i class="fa-solid fa-upload mr-2"></i> Re-import corrected data</span>
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
