<script setup>
import { ref, computed, watch } from 'vue';
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
    pagination: { type: Object, default: null }, // { current_page, last_page, per_page, total, from, to, links }
    perPage: { type: String, default: '10' }, // '10' | '50' | '100' | 'all'
    totalsFiltered: { type: Object, default: null }, // { totalImports, totalData, newContacts, updatedData, dataWithError, totalResubscribed } – all matching logs (event/source filter only)
    events: { type: Array, default: () => [] },
    filterEventId: { type: Number, default: null },
    filterSource: { type: String, default: null }, // 'signup_form' | 'ticket_data' | null
    searchKeyword: { type: String, default: null },
    sortBy: { type: String, default: null },
    sortDir: { type: String, default: null }, // 'asc' | 'desc' | null
    autoImportRuns: { type: Array, default: () => [] } // scheduled "auto-import finished locations" run summaries
});

const showAutoRuns = ref(false);
const expandedRunId = ref(null);
const toggleRunDetails = (id) => {
    expandedRunId.value = expandedRunId.value === id ? null : id;
};

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

// Show "See more" when we have error text, failed_rows (detailed errors), or data_with_error count
const hasErrorsToShow = (log) => {
    return hasErrors(log.errors) || hasFailedRows(log) || (log.data_with_error > 0);
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

// Normalize a failed row to the shape expected by the errors modal (manual import stores { email, error, subscriber_data })
const normalizeFailedRow = (row) => {
    const data = row.subscriber_data && typeof row.subscriber_data === 'object'
        ? { ...row.subscriber_data, error_message: row.error ?? row.error_message }
        : { ...row };
    return {
        email_address: data.email_address ?? data.email ?? '',
        first_name: data.first_name ?? '',
        last_name: data.last_name ?? '',
        mobile_number: data.mobile_number ?? '',
        street_address: data.street_address ?? '',
        street_address_2: data.street_address_2 ?? '',
        city: data.city ?? '',
        state: data.state ?? '',
        zip_code: data.zip_code ?? '',
        country: data.country ?? '',
        gender: data.gender ?? '',
        age: data.age ?? '',
        error_message: data.error_message ?? data.error ?? '',
    };
};

const showFullErrors = (log) => {
    if (hasFailedRows(log)) {
        errorsModalLog.value = log;
        const rows = getFailedRows(log);
        // Pre-fill every field from the failed row data; add selected: true for re-import checkboxes
        editableFailedRows.value = rows.map((row) => ({ ...normalizeFailedRow(row), selected: true }));
        showErrorsModal.value = true;
    } else if (hasErrors(log.errors)) {
        // Fallback: no failed_rows (e.g. old log) – parse error strings so user can still fix & re-import
        errorsModalLog.value = log;
        editableFailedRows.value = parseErrorsToRows(log.errors).map((row) => ({ ...row, selected: true }));
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
        if (text && text !== '—') {
            Swal.fire({
                title: 'Import errors',
                html: `<div class="text-left text-sm text-gray-700 whitespace-pre-wrap break-words max-h-96 overflow-y-auto p-2 bg-gray-50 rounded border border-gray-200">${escapeHtml(text)}</div>`,
                width: '32rem',
                showCloseButton: true,
                showConfirmButton: true,
                confirmButtonText: 'Close',
            });
        } else if ((log.data_with_error ?? 0) > 0) {
            Swal.fire({
                title: 'Import errors',
                html: `<p class="text-sm text-gray-700">No detailed error information is available for this import (${log.data_with_error} row(s) had errors).</p>`,
                width: '28rem',
                showCloseButton: true,
                confirmButtonText: 'Close',
            });
        }
    }
};

const closeErrorsModal = () => {
    showErrorsModal.value = false;
    errorsModalLog.value = null;
    editableFailedRows.value = [];
};

const selectedFailedRows = computed(() => editableFailedRows.value.filter((row) => row.selected !== false));

const setAllReimportSelected = (checked) => {
    editableFailedRows.value.forEach((row) => { row.selected = !!checked; });
};

const reimportCorrectedData = async () => {
    const toImport = selectedFailedRows.value;
    if (!errorsModalLog.value || !toImport.length) return;
    isReimporting.value = true;
    try {
        const res = await axios.post(route('mailchimpImportLogs.reimportFailed'), {
            log_id: errorsModalLog.value.id,
            subscribers: toImport.map((row) => ({
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
        const totalToImport = job.total_locations_to_import ?? job.locations_count ?? locations.length;
        const importedSoFar = job.locations_imported_so_far ?? null;
        const failedSoFar = job.locations_failed_so_far ?? null;
        const jobType = job.job_type || null;
        const source = job.source || '—';
        for (const loc of locations) {
            rows.push({
                job_id: job.job_id,
                location_id: loc.location_id,
                location_name: loc.location_name || '—',
                event_name: job.event_name || '—',
                source,
                status: job.status,
                created_at: job.created_at,
                total_locations_to_import: jobType === 'import_all' ? totalToImport : null,
                locations_imported_so_far: jobType === 'import_all' ? importedSoFar : null,
                locations_failed_so_far: jobType === 'import_all' ? failedSoFar : null,
            });
        }
        if (locations.length === 0 && (job.job_id || job.event_name)) {
            rows.push({
                job_id: job.job_id,
                location_id: null,
                location_name: '—',
                event_name: job.event_name || '—',
                source,
                status: job.status,
                created_at: job.created_at,
                total_locations_to_import: jobType === 'import_all' ? totalToImport : null,
                locations_imported_so_far: jobType === 'import_all' ? importedSoFar : null,
                locations_failed_so_far: jobType === 'import_all' ? failedSoFar : null,
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

const cancelQueuedImportByJobId = async (row) => {
    if (row.status !== 'queued' || !row.job_id) return;
    cancellingJobId.value = 'job-' + row.job_id;
    try {
        await axios.delete(route('mailchimpImportLogs.cancelQueuedImport', row.job_id));
        await fetchQueuedImports();
        Swal.fire('Removed', 'The import was removed from the queue.', 'success');
    } catch (err) {
        const msg = err.response?.data?.error || err.response?.data?.message || err.message || 'Failed to remove.';
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

// Fixed 6 tag columns; if a log has more than 6 tags, extras are merged into the last column with commas
const MAX_TAG_COLUMNS = 6;
const maxTagColumns = computed(() => {
    const logs = filteredLogs.value;
    if (!logs.length) return 0;
    return MAX_TAG_COLUMNS;
});

const tagColumnIndices = computed(() => Array.from({ length: maxTagColumns.value }, (_, i) => i));

/** Value for tag column i (0-based). Column 5 (last) shows tag[5], tag[6], ... joined by comma when there are more than 6 tags. */
function tagCellDisplay(log, columnIndex) {
    const tags = log.tags && Array.isArray(log.tags) ? log.tags : [];
    if (columnIndex < MAX_TAG_COLUMNS - 1) {
        return tags[columnIndex] ?? '—';
    }
    // Last column: merge extras into it with comma
    if (tags.length <= MAX_TAG_COLUMNS) {
        return tags[MAX_TAG_COLUMNS - 1] ?? '—';
    }
    return tags.slice(MAX_TAG_COLUMNS - 1).join(', ');
}

const buildLogsQuery = (opts = {}) => {
    const {
        eventId = props.filterEventId,
        source = props.filterSource,
        per_page = props.perPage,
        page = 1,
        search = props.searchKeyword,
        sort_by = props.sortBy,
        sort_dir = props.sortDir
    } = opts;
    const params = new URLSearchParams();
    if (eventId) params.set('event_id', eventId);
    if (source) params.set('source', source);
    if (per_page) params.set('per_page', per_page);
    if (per_page && per_page !== 'all' && page > 1) params.set('page', String(page));
    if (search && search.trim()) params.set('search', search.trim());
    if (sort_by) {
        params.set('sort_by', sort_by);
        params.set('sort_dir', sort_dir === 'asc' ? 'asc' : 'desc');
    }
    return params.toString();
};

const buildLogsUrl = (eventId, source, per_page, page) => {
    const q = buildLogsQuery({ eventId, source, per_page, page });
    return q ? `/mailchimp-import-logs?${q}` : '/mailchimp-import-logs';
};

const visitLogs = (opts = {}) => {
    const q = buildLogsQuery(opts);
    router.visit(q ? `/mailchimp-import-logs?${q}` : '/mailchimp-import-logs');
};

const baseVisitOpts = () => ({
    eventId: props.filterEventId || '',
    source: props.filterSource || '',
    per_page: props.perPage,
    search: props.searchKeyword || '',
    sort_by: props.sortBy || '',
    sort_dir: props.sortDir || ''
});

const applyEventFilter = (value) => {
    const eventId = value === '' || value == null ? null : Number(value);
    visitLogs({ ...baseVisitOpts(), eventId, page: 1 });
};

const applySourceFilter = (value) => {
    const source = value === '' || value == null ? null : value;
    visitLogs({ ...baseVisitOpts(), source, page: 1 });
};

const applyPerPage = (value) => {
    const per_page = value === '' || value == null ? '10' : value;
    visitLogs({ ...baseVisitOpts(), per_page, page: 1 });
};

const goToPage = (page) => {
    if (page < 1 || (props.pagination && page > props.pagination.last_page)) return;
    visitLogs({ ...baseVisitOpts(), page });
};

const searchKeywordInput = ref(props.searchKeyword ?? '');
watch(() => props.searchKeyword, (v) => { searchKeywordInput.value = v ?? ''; }, { immediate: true });
const applySearch = () => {
    const search = (searchKeywordInput.value || '').trim();
    visitLogs({ ...baseVisitOpts(), search, page: 1 });
};

// Click-to-sort: first click = asc, second click on same column = desc, third = clear sort
const sortableColumns = ['event_name', 'location_name', 'created_at', 'imported_by_name', 'mailchimp_account', 'list_name', 'source', 'status', 'total_data', 'new_contacts', 'updated_data', 'data_with_error', 'total_resubscribed'];
const applySort = (column) => {
    if (!sortableColumns.includes(column)) return;
    let nextBy = column;
    let nextDir = 'asc';
    if (props.sortBy === column) {
        if (props.sortDir === 'asc') {
            nextDir = 'desc';
        } else {
            nextBy = '';
            nextDir = '';
        }
    }
    visitLogs({ ...baseVisitOpts(), sort_by: nextBy, sort_dir: nextDir, page: 1 });
};
const sortIcon = (column) => {
    if (props.sortBy !== column) return 'fa-solid fa-sort text-gray-300';
    return props.sortDir === 'asc' ? 'fa-solid fa-sort-up text-teal-600' : 'fa-solid fa-sort-down text-teal-600';
};

const sourceLabel = (source) => {
    if (!source) return '—';
    if (source === 'ticket_data') return 'Ticket data';
    if (source === 'manual_csv') return 'Manual CSV';
    if (source === 'signup_form_resub') return 'Newsletter resubscribe';
    return 'Signup form';
};

// Decode Laravel pagination link labels so « / » render instead of &laquo; / &raquo;
const paginationLinkLabel = (label) => {
    if (typeof label !== 'string') return label;
    return label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»');
};

// Totals: use server-provided totalsFiltered (all matching logs by event/source only), fallback to current page when not provided
const totalsBreakdown = computed(() => {
    const t = props.totalsFiltered;
    if (t && typeof t.totalImports === 'number') {
        return {
            totalImports: t.totalImports,
            totalData: t.totalData ?? 0,
            newContacts: t.newContacts ?? 0,
            updatedData: t.updatedData ?? 0,
            dataWithError: t.dataWithError ?? 0,
            totalResubscribed: t.totalResubscribed ?? 0,
        };
    }
    const logs = filteredLogs.value;
    return {
        totalImports: logs.length,
        totalData: logs.reduce((s, l) => s + (l.total_data ?? 0), 0),
        newContacts: logs.reduce((s, l) => s + (l.new_contacts ?? 0), 0),
        updatedData: logs.reduce((s, l) => s + (l.updated_data ?? 0), 0),
        dataWithError: logs.reduce((s, l) => s + (l.data_with_error ?? 0), 0),
        totalResubscribed: logs.reduce((s, l) => s + (l.total_resubscribed ?? 0), 0),
    };
});

const savingNotesLogId = ref(null);
const lastNotesSent = ref({});
const notesExpandLog = ref(null);
const notesExpandValue = ref('');
const showNotesExpandModal = ref(false);

watch(() => props.mailchimpImportLogs, (logs) => {
    logs?.forEach((log) => {
        lastNotesSent.value[log.id] = log.notes ?? '';
    });
}, { immediate: true });

const saveNotes = async (log) => {
    const value = (log.notes ?? '').trim();
    if (lastNotesSent.value[log.id] === value) return true;
    if (savingNotesLogId.value === log.id) return false;
    savingNotesLogId.value = log.id;
    try {
        const res = await axios.patch(route('mailchimpImportLogs.updateNotes', log.id), { notes: value });
        if (res.data?.notes !== undefined) log.notes = res.data.notes;
        lastNotesSent.value[log.id] = value;
        return true;
    } catch (err) {
        const msg = err.response?.data?.message || err.response?.data?.errors?.notes?.[0] || err.message || 'Failed to save notes.';
        Swal.fire('Error', msg, 'error');
        return false;
    } finally {
        savingNotesLogId.value = null;
    }
};

const openNotesExpand = (log) => {
    notesExpandLog.value = log;
    notesExpandValue.value = log.notes ?? '';
    showNotesExpandModal.value = true;
};

const closeNotesExpand = () => {
    showNotesExpandModal.value = false;
    notesExpandLog.value = null;
    notesExpandValue.value = '';
};

const saveNotesExpand = async () => {
    if (!notesExpandLog.value) return;
    const log = notesExpandLog.value;
    log.notes = notesExpandValue.value;
    const ok = await saveNotes(log);
    if (ok) closeNotesExpand();
};

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

const deleteAutoRun = (run) => {
    Swal.fire({
        title: 'Delete this run log?',
        text: 'This removes the automated run summary. This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete it',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626',
    }).then((result) => {
        if (!result.isConfirmed) return;
        router.delete(route('mailchimpImportLogs.destroyAutoRun', run.id), {
            preserveScroll: true,
            onSuccess: () => Swal.fire('Deleted', 'The run log has been removed.', 'success'),
            onError: () => Swal.fire('Error', 'Failed to delete the run log.', 'error'),
        });
    });
};

const deleteAllAutoRuns = () => {
    Swal.fire({
        title: 'Clear all automated runs?',
        html: 'This will permanently delete <strong>all</strong> automated run logs. This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, clear all',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626',
    }).then((result) => {
        if (!result.isConfirmed) return;
        router.delete(route('mailchimpImportLogs.destroyAllAutoRuns'), {
            preserveScroll: true,
            onSuccess: () => Swal.fire('Deleted', 'All automated run logs have been removed.', 'success'),
            onError: () => Swal.fire('Error', 'Failed to clear run logs.', 'error'),
        });
    });
};

const deletingAllLogs = ref(false);
const deletingSelectedLogs = ref(false);

// Manual import from CSV (same logic as per-location import: account, audience, map columns)
const showManualImportModal = ref(false);
const manualImportAccount = ref('anz');
const manualImportListId = ref('');
const manualImportLists = ref([]);
const manualImportListsLoading = ref(false);
const manualImportCsvFile = ref(null);
const manualImportCsvHeaders = ref([]);
const manualImportCsvRows = ref([]);
const manualImportMergeFields = ref([]);
const manualImportSourceColumns = ref([]); // from CSV headers
const manualImportFieldMapping = ref({});
const manualImportShowMapping = ref(false);
const manualImportMergeFieldsLoading = ref(false);
const manualImportTags = ref('');
const manualImportEventName = ref('');
const manualImportSourceName = ref('');
const manualImportImporting = ref(false);

const openManualImportModal = () => {
    showManualImportModal.value = true;
    manualImportListId.value = '';
    manualImportLists.value = [];
    manualImportCsvFile.value = null;
    manualImportCsvHeaders.value = [];
    manualImportCsvRows.value = [];
    manualImportMergeFields.value = [];
    manualImportSourceColumns.value = [];
    manualImportFieldMapping.value = {};
    manualImportShowMapping.value = false;
    manualImportTags.value = '';
    manualImportEventName.value = '';
    manualImportSourceName.value = '';
    if (manualImportAccount.value) loadManualImportLists(manualImportAccount.value);
};

const loadManualImportLists = async (account) => {
    manualImportListsLoading.value = true;
    manualImportListId.value = '';
    manualImportLists.value = [];
    try {
        const res = await axios.get(route('location.mailchimpLists'), { params: { account: account || manualImportAccount.value } });
        manualImportLists.value = res.data.lists || [];
    } catch (e) {
        manualImportLists.value = [];
    } finally {
        manualImportListsLoading.value = false;
    }
};

const onManualImportAudienceChange = async (listId) => {
    if (!listId) {
        manualImportMergeFields.value = [];
        manualImportFieldMapping.value = {};
        return;
    }
    manualImportMergeFieldsLoading.value = true;
    try {
        const res = await axios.get(route('location.mailchimpMergeFields'), {
            params: { list_id: listId, account: manualImportAccount.value }
        });
        const mf = res.data.merge_fields_with_validation ?? res.data.merge_fields ?? [];
        manualImportMergeFields.value = mf;
        const tagToDefault = {
            FNAME: 'first_name', LNAME: 'last_name',
            PHONE: 'mobile_number', MERGE4: 'mobile_number', MERGE30: 'mobile_number',
            ADDRESSWIN: 'address_full', MMERGE10: 'address_full', MERGE10: 'address_full', MERGE11: 'address_full',
            SHOWCITY: 'city', CITY: 'city', MERGE3: 'city', MERGE5: 'city',
            STATEWIN: 'state', STATE: 'state', MERGE6: 'state',
            ZIPCODEWIN: 'zip_code', ZIPCODE: 'zip_code', MERGE7: 'zip_code',
            COUNTRYWIN: 'country', COUNTRY: 'country', MERGE8: 'country',
            GENDER: 'gender', MERGE17: 'gender',
            AGEWIN: 'age', MERGE14: 'age', MMERGE14: 'age'
        };
        const mapping = {};
        const emailCol = manualImportSourceColumns.value.find((sc) => /email/i.test(sc.key))?.key || manualImportSourceColumns.value[0]?.key || '';
        mapping.EMAIL = manualImportFieldMapping.value.EMAIL || emailCol;
        (res.data.merge_fields || []).forEach((f) => {
            const tag = f.tag || f;
            if (tag === 'EMAIL') return;
            mapping[tag] = manualImportFieldMapping.value[tag] ?? tagToDefault[tag] ?? '';
        });
        manualImportFieldMapping.value = mapping;
    } catch (e) {
        manualImportMergeFields.value = [];
    } finally {
        manualImportMergeFieldsLoading.value = false;
    }
};

const parseCsvManualImport = (file) => {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = (e) => {
            const text = (e.target?.result || '').trim();
            const lines = text.split(/\r?\n/).filter(Boolean);
            if (lines.length === 0) {
                resolve({ headers: [], rows: [] });
                return;
            }
            const parseRow = (line) => {
                const out = [];
                let cur = '';
                let inQuotes = false;
                for (let i = 0; i < line.length; i++) {
                    const c = line[i];
                    if (c === '"') {
                        if (inQuotes && line[i + 1] === '"') {
                            cur += '"';
                            i++;
                        } else {
                            inQuotes = !inQuotes;
                        }
                    } else if (c === ',' && !inQuotes) {
                        out.push(cur.trim());
                        cur = '';
                    } else {
                        cur += c;
                    }
                }
                out.push(cur.trim());
                return out;
            };
            const headers = parseRow(lines[0]).map((h) => h.replace(/^"|"$/g, '').replace(/""/g, '"').trim());
            const rows = [];
            for (let i = 1; i < lines.length; i++) {
                const cells = parseRow(lines[i]).map((c) => c.replace(/^"|"$/g, '').replace(/""/g, '"').trim());
                const row = {};
                headers.forEach((h, j) => { row[h] = cells[j] ?? ''; });
                rows.push(row);
            }
            resolve({ headers, rows });
        };
        reader.onerror = () => reject(new Error('Failed to read file'));
        reader.readAsText(file, 'UTF-8');
    });
};

const onManualImportCsvSelected = async (event) => {
    const file = event.target?.files?.[0];
    if (!file) return;
    manualImportCsvFile.value = file.name;
    try {
        const { headers, rows } = await parseCsvManualImport(file);
        manualImportCsvHeaders.value = headers;
        manualImportCsvRows.value = rows;
        manualImportSourceColumns.value = headers.map((h) => ({ key: h, label: h }));
        const emailCol = headers.find((h) => /email/i.test(h)) || headers[0] || '';
        manualImportFieldMapping.value = { EMAIL: emailCol };
    } catch (e) {
        Swal.fire('Error', 'Could not parse CSV. Use UTF-8 and comma-separated columns.', 'error');
    }
    event.target.value = '';
};

// Map Mailchimp merge tags to storage CSV headers for manual import log file
const MANUAL_IMPORT_TAG_TO_KEY = {
    EMAIL: 'email_address', FNAME: 'first_name', LNAME: 'last_name',
    PHONE: 'mobile_number', MERGE4: 'mobile_number', MERGE30: 'mobile_number',
    ADDRESSWIN: 'street_address', MMERGE10: 'street_address', MERGE10: 'street_address', MERGE11: 'street_address',
    SHOWCITY: 'city', CITY: 'city', MERGE3: 'city', MERGE5: 'city',
    STATEWIN: 'state', STATE: 'state', MERGE6: 'state',
    ZIPCODEWIN: 'zip_code', ZIPCODE: 'zip_code', MERGE7: 'zip_code',
    COUNTRYWIN: 'country', COUNTRY: 'country', MERGE8: 'country',
    GENDER: 'gender', MERGE17: 'gender',
    AGEWIN: 'age', MERGE14: 'age', MMERGE14: 'age'
};
const MANUAL_IMPORT_HEADERS = ['email_address', 'first_name', 'last_name', 'mobile_number', 'street_address', 'street_address_2', 'city', 'state', 'zip_code', 'country', 'gender', 'age'];

function buildNormalizedSubscribers(rows, fieldMapping) {
    const fm = fieldMapping || {};
    return rows.map((row) => {
        const norm = {};
        MANUAL_IMPORT_HEADERS.forEach((h) => { norm[h] = ''; });
        for (const [tag, col] of Object.entries(fm)) {
            if (!col) continue;
            const key = MANUAL_IMPORT_TAG_TO_KEY[tag];
            if (key) norm[key] = (row[col] ?? '').toString().trim();
        }
        if (!norm.email_address && fm.EMAIL) norm.email_address = (row[fm.EMAIL] ?? '').toString().trim();
        return norm;
    });
}

/** Preview value for mapping table: first CSV row value for the given Mailchimp tag's mapped column */
function getManualImportPreviewValue(tag) {
    const first = manualImportCsvRows.value[0];
    if (!first) return '—';
    const col = manualImportFieldMapping.value[tag];
    if (!col) return '—';
    const val = first[col];
    if (val == null || val === '') return '—';
    const s = String(val).trim();
    return s.length > 50 ? s.slice(0, 50) + '…' : s;
}

const manualImportRun = async () => {
    if (!manualImportListId.value || manualImportCsvRows.value.length === 0) {
        Swal.fire('Error', 'Select an audience and upload a CSV with at least one data row.', 'error');
        return;
    }
    const emailKey = manualImportFieldMapping.value.EMAIL || manualImportCsvHeaders.value.find((h) => /email/i.test(h)) || manualImportCsvHeaders.value[0];
    const hasEmail = manualImportCsvRows.value.some((row) => (row[emailKey] || '').toString().trim());
    if (!hasEmail) {
        Swal.fire('Error', 'Map the Email column and ensure at least one row has an email.', 'error');
        return;
    }
    manualImportImporting.value = true;
    const tags = (manualImportTags.value || '').split(/[;\n]/).map((t) => t.trim()).filter(Boolean);
    const fm = { ...manualImportFieldMapping.value };
    Object.keys(fm).forEach((k) => { if (fm[k] === '') delete fm[k]; });
    const listName = (manualImportLists.value.find((l) => l.id === manualImportListId.value)?.name ?? null) || '';
    try {
        await axios.post(route('mailchimpImportLogs.queueManualImport'), {
            subscribers: manualImportCsvRows.value,
            list_id: manualImportListId.value,
            list_name: listName,
            mailchimp_account: manualImportAccount.value,
            tags,
            field_mapping: Object.keys(fm).length ? fm : null,
            custom_event_name: manualImportEventName.value?.trim() || null,
            custom_source: manualImportSourceName.value?.trim() || null
        });
        Swal.fire(
            'Import queued',
            `Your import of ${manualImportCsvRows.value.length} rows has been queued. You can continue using the app. The log will appear when the import finishes.`,
            'success'
        );
        showManualImportModal.value = false;
        router.reload();
    } catch (err) {
        const msg = err.response?.data?.message || err.response?.data?.errors?.subscribers?.[0] || err.message || 'Import failed.';
        Swal.fire('Error', msg, 'error');
    } finally {
        manualImportImporting.value = false;
    }
};
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
        'Event', 'Location', 'Imported Date', 'Imported By', 'Source', 'Status', 'Total Data', 'New Contacts', 'Updated Data', 'Data with Error', 'Resubscribed', 'Errors', 'Imported data file',
        ...Array.from({ length: n }, (_, i) => `Tag ${i + 1}`)
    ];
    const escape = (v) => {
        const s = v == null ? '' : String(v);
        return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
    };
    const rows = filteredLogs.value.map((log) => {
        const base = [
            log.event_name ?? '—',
            log.location_name ?? '—',
            formatImportDate(log.created_at),
            log.imported_by_name ?? '—',
            log.custom_source || sourceLabel(log.source),
            statusLabel(log.status),
            log.total_data ?? 0,
            log.new_contacts ?? 0,
            log.updated_data ?? 0,
            log.data_with_error ?? 0,
            log.total_resubscribed ?? 0,
            formatErrors(log.errors),
            log.has_import_file ? 'Yes' : 'No'
        ];
        const tagCells = Array.from({ length: n }, (_, i) => tagCellDisplay(log, i));
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
            <div class="mx-auto max-w-12xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <p class="text-gray-600 mb-4">
                            All Mailchimp imports recorded when you import attendees to Mailchimp from a location page.
                        </p>

                        <!-- Automated import runs (scheduled "import finished locations") -->
                        <div class="mb-6 border border-gray-200 rounded-lg">
                            <button
                                type="button"
                                class="w-full flex items-center justify-between px-4 py-3 text-left"
                                @click="showAutoRuns = !showAutoRuns"
                            >
                                <span class="font-semibold text-gray-800">
                                    <i class="fa-solid fa-robot mr-1"></i>
                                    Automated runs
                                    <span class="text-sm font-normal text-gray-500">({{ autoImportRuns.length }})</span>
                                </span>
                                <span class="text-sm text-gray-500">
                                    <template v-if="autoImportRuns.length">Last run: {{ formatImportDate(autoImportRuns[0].ran_at) }}</template>
                                    <template v-else>No automated runs yet</template>
                                    <i class="fa-solid ml-2" :class="showAutoRuns ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                                </span>
                            </button>
                            <div v-if="showAutoRuns" class="border-t border-gray-200 px-4 py-3">
                                <p class="text-sm text-gray-600 mb-3">
                                    Daily job that imports locations finished 4+ days ago for events with auto-import enabled.
                                    Turn it on per event via <span class="font-medium">Mailchimp Auto-Sync Settings</span> on a location page.
                                </p>
                                <div v-if="autoImportRuns.length" class="mb-2 flex justify-end">
                                    <button
                                        type="button"
                                        class="text-xs text-red-600 hover:underline"
                                        @click="deleteAllAutoRuns"
                                    >
                                        <i class="fa-solid fa-trash-can mr-1"></i>Clear all runs
                                    </button>
                                </div>
                                <div v-if="autoImportRuns.length === 0" class="text-sm text-gray-500">Nothing yet.</div>
                                <div v-else class="overflow-x-auto">
                                    <table class="min-w-full text-sm">
                                        <thead>
                                            <tr class="text-left text-gray-500 border-b">
                                                <th class="py-2 pr-4">Ran at</th>
                                                <th class="py-2 pr-4">Status</th>
                                                <th class="py-2 pr-4">Imported</th>
                                                <th class="py-2 pr-4">Skipped</th>
                                                <th class="py-2 pr-4">New</th>
                                                <th class="py-2 pr-4">Updated</th>
                                                <th class="py-2 pr-4">Errors</th>
                                                <th class="py-2 pr-4"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template v-for="run in autoImportRuns" :key="run.id">
                                                <tr class="border-b">
                                                    <td class="py-2 pr-4 whitespace-nowrap">
                                                        {{ formatImportDate(run.ran_at) }}
                                                        <span v-if="run.dry_run" class="ml-1 inline-block px-1.5 py-0.5 text-xs bg-gray-100 text-gray-600 rounded">dry run</span>
                                                    </td>
                                                    <td class="py-2 pr-4">
                                                        <span
                                                            class="inline-block px-2 py-0.5 text-xs rounded"
                                                            :class="{
                                                                'bg-green-100 text-green-700': run.status === 'completed',
                                                                'bg-yellow-100 text-yellow-700': run.status === 'running',
                                                                'bg-red-100 text-red-700': run.status === 'failed'
                                                            }"
                                                        >{{ run.status }}</span>
                                                    </td>
                                                    <td class="py-2 pr-4">{{ run.locations_imported }}</td>
                                                    <td class="py-2 pr-4">{{ run.locations_skipped }}</td>
                                                    <td class="py-2 pr-4">{{ run.total_new }}</td>
                                                    <td class="py-2 pr-4">{{ run.total_updated }}</td>
                                                    <td class="py-2 pr-4">{{ run.total_errors }}</td>
                                                    <td class="py-2 pr-4 whitespace-nowrap">
                                                        <button
                                                            v-if="Array.isArray(run.details) && run.details.length"
                                                            type="button"
                                                            class="text-blue-600 hover:underline mr-3"
                                                            @click="toggleRunDetails(run.id)"
                                                        >{{ expandedRunId === run.id ? 'Hide' : 'Details' }}</button>
                                                        <button
                                                            type="button"
                                                            class="text-red-600 hover:underline"
                                                            title="Delete this run"
                                                            @click="deleteAutoRun(run)"
                                                        ><i class="fa-solid fa-trash-can"></i></button>
                                                    </td>
                                                </tr>
                                                <tr v-if="expandedRunId === run.id">
                                                    <td colspan="8" class="py-2 px-3 bg-gray-50">
                                                        <div class="text-xs text-gray-700">
                                                            <div
                                                                v-for="(d, i) in run.details"
                                                                :key="i"
                                                                class="py-1 border-b border-gray-100 last:border-0"
                                                            >
                                                                <span class="font-medium">{{ d.location_name }}</span>
                                                                <span class="text-gray-500"> — {{ d.status }}</span>
                                                                <span v-if="d.status === 'imported'"> · new {{ d.new }}, updated {{ d.updated }}, errors {{ d.errors }}</span>
                                                                <span v-else-if="d.reason" class="text-gray-500"> ({{ d.reason }})</span>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                                <p v-if="autoImportRuns.length && autoImportRuns[0].status === 'running'" class="text-xs text-gray-500 mt-2">
                                    A run is in progress — counts update as locations finish importing. Refresh to see the latest.
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
                            <div class="flex items-center gap-4">
                                <div class="flex items-center gap-2">
                                    <label for="event-filter" class="text-sm font-medium text-gray-700">Filter by event</label>
                                    <select
                                        id="event-filter"
                                        :value="filterEventId ?? ''"
                                        class="border rounded px-3 py-2 text-sm"
                                        @change="applyEventFilter($event.target.value)"
                                    >
                                        <option value="">Any event</option>
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
                                        <option value="">Any source</option>
                                        <option value="signup_form">Signup form</option>
                                        <option value="ticket_data">Ticket data</option>
                                        <option value="manual_csv">Manual CSV</option>
                                        <option value="signup_form_resub">Newsletter resubscribe</option>
                                    </select>
                                </div>
                                <div class="flex items-center gap-2">
                                    <label for="per-page" class="text-sm font-medium text-gray-700">Show</label>
                                    <select
                                        id="per-page"
                                        :value="perPage ?? '10'"
                                        class="border rounded px-3 py-2 text-sm"
                                        @change="applyPerPage($event.target.value)"
                                    >
                                        <option value="10">10</option>
                                        <option value="50">50</option>
                                        <option value="100">100</option>
                                        <option value="all">All</option>
                                    </select>
                                </div>
                                <div class="flex items-center gap-2">
                                    <label for="search-keyword" class="text-sm font-medium text-gray-700 sr-only">Search</label>
                                    <input
                                        id="search-keyword"
                                        v-model="searchKeywordInput"
                                        type="text"
                                        class="border rounded px-3 py-2 text-sm w-48 min-w-0"
                                        placeholder="Search event, location, list, notes…"
                                        @keyup.enter="applySearch"
                                    />
                                    <button
                                        type="button"
                                        @click="applySearch"
                                        class="px-3 py-2 text-sm border border-gray-300 rounded hover:bg-gray-50"
                                    >
                                        Search
                                    </button>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    @click="exportToCsv"
                                    class="bg-green-600 text-white px-3 py-2.5 rounded hover:bg-green-700 text-sm font-medium"
                                    title="Export to CSV"
                                >
                                    <i class="fa-solid fa-file-csv"></i>
                                </button>
                                <button
                                    type="button"
                                    @click="openManualImportModal"
                                    class="bg-teal-600 text-white px-3 py-2.5 rounded hover:bg-teal-700 text-sm font-medium"
                                    title="Import CSV to Mailchimp"
                                >
                                    <i class="fa-solid fa-upload"></i>
                                </button>
                                <button
                                    type="button"
                                    @click="openQueuedImportsModal"
                                    class="bg-amber-600 text-white px-3 py-2.5 rounded hover:bg-amber-700 text-sm font-medium"
                                    title="Queued imports"
                                >
                                    <i class="fa-solid fa-clock-rotate-left"></i>
                                </button>
                                <button
                                    v-if="canDeleteLogs && selectedLogIds.length > 0"
                                    type="button"
                                    @click="deleteSelectedLogs"
                                    :disabled="deletingSelectedLogs"
                                    class="bg-red-500 text-white px-3 py-2.5 rounded hover:bg-red-600 disabled:opacity-50 text-sm font-medium inline-flex items-center gap-1.5"
                                    :title="'Delete ' + selectedLogIds.length + ' selected'"
                                >
                                    <span v-if="deletingSelectedLogs"><i class="fa-solid fa-spinner fa-spin"></i></span>
                                    <template v-else>
                                        <i class="fa-solid fa-trash"></i>
                                        <span class="text-xs font-semibold">{{ selectedLogIds.length }}</span>
                                    </template>
                                </button>
                                <button
                                    v-if="canDeleteLogs"
                                    type="button"
                                    @click="deleteAllLogs"
                                    :disabled="deletingAllLogs || filteredLogs.length === 0"
                                    class="bg-red-600 text-white px-3 py-2.5 rounded hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed text-sm font-medium"
                                    title="Delete every MC log and stored CSV (mj only)"
                                >
                                    <span v-if="deletingAllLogs"><i class="fa-solid fa-spinner fa-spin"></i></span>
                                    <i v-else class="fa-solid fa-trash-can"></i>
                                </button>
                            </div>
                        </div>

                        <div v-if="filteredLogs.length === 0" class="text-gray-600 py-8 text-center">
                            No MC logs found.
                            <span v-if="filterEventId || filterSource || searchKeyword">Try changing the event, source, or search keyword.</span>
                        </div>

                        <div v-else class="overflow-x-auto">
                            <table class="w-full border-collapse border border-gray-300 text-sm">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap cursor-pointer select-none hover:bg-gray-200" @click="applySort('event_name')" :title="'Sort by Event'">Event <i :class="sortIcon('event_name')" class="ml-1 text-xs"></i></th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap cursor-pointer select-none hover:bg-gray-200" @click="applySort('location_name')" :title="'Sort by Location'">Location <i :class="sortIcon('location_name')" class="ml-1 text-xs"></i></th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap cursor-pointer select-none hover:bg-gray-200" @click="applySort('created_at')" :title="'Sort by Imported Date'">Imported Date <i :class="sortIcon('created_at')" class="ml-1 text-xs"></i></th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap cursor-pointer select-none hover:bg-gray-200" @click="applySort('imported_by_name')" :title="'Sort by Imported By'">Imported By <i :class="sortIcon('imported_by_name')" class="ml-1 text-xs"></i></th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap cursor-pointer select-none hover:bg-gray-200" @click="applySort('mailchimp_account')" :title="'Sort by Mailchimp Account'">Mailchimp Account <i :class="sortIcon('mailchimp_account')" class="ml-1 text-xs"></i></th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap cursor-pointer select-none hover:bg-gray-200" @click="applySort('list_name')" :title="'Sort by Audience'">Audience <i :class="sortIcon('list_name')" class="ml-1 text-xs"></i></th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap cursor-pointer select-none hover:bg-gray-200" @click="applySort('source')" :title="'Sort by Source'">Source <i :class="sortIcon('source')" class="ml-1 text-xs"></i></th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap cursor-pointer select-none hover:bg-gray-200" @click="applySort('status')" :title="'Sort by Status'">Status <i :class="sortIcon('status')" class="ml-1 text-xs"></i></th>
                                        <th class="border border-gray-300 p-2 text-right whitespace-nowrap cursor-pointer select-none hover:bg-gray-200" @click="applySort('total_data')" :title="'Sort by Total Data'">Total Data <i :class="sortIcon('total_data')" class="ml-1 text-xs"></i></th>
                                        <th class="border border-gray-300 p-2 text-right whitespace-nowrap cursor-pointer select-none hover:bg-gray-200" @click="applySort('new_contacts')" :title="'Sort by New Contacts'">New Contacts <i :class="sortIcon('new_contacts')" class="ml-1 text-xs"></i></th>
                                        <th class="border border-gray-300 p-2 text-right whitespace-nowrap cursor-pointer select-none hover:bg-gray-200" @click="applySort('updated_data')" :title="'Sort by Updated Data'">Updated Data <i :class="sortIcon('updated_data')" class="ml-1 text-xs"></i></th>
                                        <th class="border border-gray-300 p-2 text-right whitespace-nowrap cursor-pointer select-none hover:bg-gray-200" @click="applySort('data_with_error')" :title="'Sort by Data with Error'">Data with Error <i :class="sortIcon('data_with_error')" class="ml-1 text-xs"></i></th>
                                        <th class="border border-gray-300 p-2 text-right whitespace-nowrap cursor-pointer select-none hover:bg-gray-200" @click="applySort('total_resubscribed')" :title="'Sort by Resubscribed'">Resubscribed <i :class="sortIcon('total_resubscribed')" class="ml-1 text-xs"></i></th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Errors</th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Imported data</th>
                                        <th v-for="i in tagColumnIndices" :key="i" class="border border-gray-300 p-2 text-left whitespace-nowrap">Tag {{ i + 1 }}</th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Notes</th>
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
                                        <td class="border border-gray-300 p-2 whitespace-nowrap">{{ log.custom_source || sourceLabel(log.source) }}</td>
                                        <td class="border border-gray-300 p-2 whitespace-nowrap">
                                            <span :class="log.status === 'reimport' ? 'text-amber-600 font-medium' : 'text-gray-700'">{{ statusLabel(log.status) }}</span>
                                        </td>
                                        <td class="border border-gray-300 p-2 text-right whitespace-nowrap">{{ log.total_data ?? 0 }}</td>
                                        <td class="border border-gray-300 p-2 text-right whitespace-nowrap">{{ log.new_contacts ?? 0 }}</td>
                                        <td class="border border-gray-300 p-2 text-right whitespace-nowrap">{{ log.updated_data ?? 0 }}</td>
                                        <td class="border border-gray-300 p-2 text-right whitespace-nowrap">{{ log.data_with_error ?? 0 }}</td>
                                        <td class="border border-gray-300 p-2 text-right whitespace-nowrap">{{ log.total_resubscribed ?? 0 }}</td>
                                        <td class="border border-gray-300 p-2 text-gray-600 max-w-xs whitespace-nowrap overflow-hidden">
                                            <span v-if="!hasErrorsToShow(log)">—</span>
                                            <span v-else class="inline-flex items-baseline gap-1 max-w-full">
                                                <span v-if="(log.data_with_error ?? 0) > 0" class="truncate min-w-0">{{ log.data_with_error }} error(s)</span>
                                                <span v-else-if="hasErrors(log.errors)" class="truncate min-w-0">{{ formatErrors(log.errors) }}</span>
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
                                        <td v-for="i in tagColumnIndices" :key="i" class="border border-gray-300 p-2 text-gray-600 whitespace-nowrap">{{ tagCellDisplay(log, i) }}</td>
                                        <td class="border border-gray-300 p-2 align-top min-w-[180px] max-w-[280px]">
                                            <div class="flex flex-col gap-1">
                                                <textarea
                                                    :value="log.notes ?? ''"
                                                    placeholder="Add note..."
                                                    rows="2"
                                                    class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm focus:ring-1 focus:ring-teal-500 focus:border-teal-500 resize-y min-h-[2.5rem]"
                                                    :disabled="savingNotesLogId === log.id"
                                                    @input="log.notes = $event.target.value"
                                                    @blur="saveNotes(log)"
                                                />
                                                <div class="flex items-center gap-2">
                                                    <span v-if="savingNotesLogId === log.id" class="text-xs text-gray-500"><i class="fa-solid fa-spinner fa-spin mr-1"></i> Saving...</span>
                                                    <button
                                                        type="button"
                                                        @click="openNotesExpand(log)"
                                                        class="text-xs text-teal-600 hover:underline"
                                                        title="Expand to read or edit full note"
                                                    >
                                                        <i class="fa-solid fa-up-right-from-square mr-1"></i> Expand
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
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

                            <!-- Pagination -->
                            <div v-if="pagination && pagination.last_page > 1" class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-4">
                                <div class="text-sm text-gray-600">
                                    Showing <span class="font-medium">{{ pagination.from }}</span>–<span class="font-medium">{{ pagination.to }}</span> of <span class="font-medium">{{ pagination.total }}</span>
                                </div>
                                <div class="flex items-center gap-1 flex-wrap">
                                    <template v-for="(link, idx) in pagination.links" :key="idx">
                                        <span
                                            v-if="!link.url || link.label === '...'"
                                            class="px-2 py-1.5 text-gray-400"
                                        >{{ link.label === '...' ? '…' : paginationLinkLabel(link.label) }}</span>
                                        <a
                                            v-else
                                            :href="link.url"
                                            class="px-3 py-1.5 text-sm border rounded min-w-[2.25rem] text-center"
                                            :class="link.active ? 'border-blue-600 bg-blue-50 text-blue-700 font-medium' : 'border-gray-300 hover:bg-gray-50'"
                                            @click.prevent="router.visit(link.url)"
                                        >
                                            {{ paginationLinkLabel(link.label) }}
                                        </a>
                                    </template>
                                </div>
                            </div>

                            <!-- Totals: all matching logs (filtered by event/source only, not by page) -->
                            <div v-if="totalsBreakdown.totalImports > 0" class="mt-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                                <h4 class="text-sm font-semibold text-gray-800 mb-3">Totals{{ filterEventId || filterSource ? ' (filtered by event/source)' : '' }}</h4>
                                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 text-sm">
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
                                    <div class="bg-white p-3 rounded border border-purple-100">
                                        <div class="text-gray-500">Resubscribed</div>
                                        <div class="font-semibold text-purple-700">{{ totalsBreakdown.totalResubscribed }}</div>
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
                                    <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Source</th>
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
                                    <td class="border border-gray-300 p-2">
                                        <div class="font-medium text-gray-900">{{ row.event_name }}</div>
                                        <div class="text-xs text-gray-500 mt-0.5">
                                            <template v-if="row.total_locations_to_import != null">
                                                <template v-if="row.status === 'in_progress' && row.locations_imported_so_far != null">
                                                    {{ row.locations_imported_so_far }} of {{ row.total_locations_to_import }} locations finished
                                                    <span v-if="row.locations_failed_so_far > 0" class="text-amber-600"> ({{ row.locations_failed_so_far }} failed)</span>
                                                </template>
                                                <template v-else>
                                                    {{ row.total_locations_to_import }} location{{ row.total_locations_to_import !== 1 ? 's' : '' }} to import
                                                </template>
                                            </template>
                                        </div>
                                    </td>
                                    <td class="border border-gray-300 p-2 text-gray-700">{{ row.location_name }}</td>
                                    <td class="border border-gray-300 p-2 text-gray-700">
                                        <span class="text-xs font-medium" :title="row.source === 'Ticket' ? 'Ticket data (Eventbrite/CSV)' : row.source === 'Signup form' ? 'Win form / signup data' : row.source === 'Manual CSV' ? 'Manual CSV upload' : ''">
                                            {{ row.source || '—' }}
                                        </span>
                                    </td>
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
                                            v-else-if="row.status === 'queued' && row.location_id == null"
                                            type="button"
                                            @click="cancelQueuedImportByJobId(row)"
                                            :disabled="cancellingJobId === 'job-' + row.job_id"
                                            class="text-red-600 hover:text-red-800 hover:underline text-xs font-medium disabled:opacity-50"
                                            title="Remove this import from the queue"
                                        >
                                            <span v-if="cancellingJobId === 'job-' + row.job_id"><i class="fa-solid fa-spinner fa-spin mr-1"></i></span>
                                            <i v-else class="fa-solid fa-times-circle mr-1"></i> Remove from queue
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
                    <p class="text-sm text-gray-600 mb-3">The rows below are <strong>pre-filled with the data that had errors</strong>. Use the checkboxes to <strong>choose which rows to re-import</strong>; uncheck any you want to skip. Edit fields as needed (e.g. fix email typos), then click Re-import. A new log entry will be created for the selected rows only.</p>
                    <div class="overflow-x-auto border rounded">
                        <table class="w-full text-sm border-collapse">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="border p-2 text-left w-10">
                                        <label class="flex items-center gap-1 cursor-pointer">
                                            <input type="checkbox" :checked="editableFailedRows.length > 0 && editableFailedRows.every(r => r.selected)" :indeterminate="editableFailedRows.length > 0 && selectedFailedRows.length > 0 && selectedFailedRows.length < editableFailedRows.length" @change="setAllReimportSelected($event.target.checked)" class="rounded border-gray-300" />
                                            <span class="text-xs">All</span>
                                        </label>
                                    </th>
                                    <th class="border p-2 text-left">Email</th>
                                    <th class="border p-2 text-left">First name</th>
                                    <th class="border p-2 text-left">Last name</th>
                                    <th class="border p-2 text-left">Phone</th>
                                    <th class="border p-2 text-left text-red-600">Error</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, idx) in editableFailedRows" :key="idx" class="hover:bg-gray-50" :class="{ 'opacity-60': row.selected === false }">
                                    <td class="border p-2 w-10">
                                        <input v-model="row.selected" type="checkbox" class="rounded border-gray-300" />
                                    </td>
                                    <td class="border p-1"><input v-model="row.email_address" type="text" class="w-full border rounded px-2 py-1 text-sm" placeholder="Email" /></td>
                                    <td class="border p-1"><input v-model="row.first_name" type="text" class="w-full border rounded px-2 py-1 text-sm" placeholder="First" /></td>
                                    <td class="border p-1"><input v-model="row.last_name" type="text" class="w-full border rounded px-2 py-1 text-sm" placeholder="Last" /></td>
                                    <td class="border p-1"><input v-model="row.mobile_number" type="text" class="w-full border rounded px-2 py-1 text-sm" placeholder="Phone" /></td>
                                    <td class="border p-2 text-red-600 text-xs">{{ row.error_message }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="editableFailedRows.length > 0" class="text-xs text-gray-500 mt-2">{{ selectedFailedRows.length }} of {{ editableFailedRows.length }} row(s) selected for re-import.</p>
                </div>
                <div class="p-4 border-t flex justify-end gap-2">
                    <button type="button" @click="closeErrorsModal" class="px-4 py-2 border rounded text-gray-700 hover:bg-gray-50">Cancel</button>
                    <button type="button" @click="reimportCorrectedData" :disabled="isReimporting || selectedFailedRows.length === 0" class="px-4 py-2 bg-teal-600 text-white rounded hover:bg-teal-700 disabled:opacity-50">
                        <span v-if="isReimporting"><i class="fa-solid fa-spinner fa-spin mr-2"></i> Re-importing...</span>
                        <span v-else><i class="fa-solid fa-upload mr-2"></i> Re-import selected ({{ selectedFailedRows.length }})</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Expand notes modal: read/edit full note in a larger area -->
        <div v-if="showNotesExpandModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4" @click.self="closeNotesExpand">
            <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[85vh] flex flex-col">
                <div class="p-4 border-b flex justify-between items-center">
                    <h3 class="text-lg font-semibold">Note</h3>
                    <button type="button" @click="closeNotesExpand" class="text-gray-500 hover:text-gray-700 text-2xl leading-none">&times;</button>
                </div>
                <div class="p-4 flex-1 overflow-hidden flex flex-col">
                    <textarea
                        v-model="notesExpandValue"
                        placeholder="Add or edit note..."
                        rows="10"
                        class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-teal-500 focus:border-teal-500 resize-y min-h-[200px]"
                    />
                </div>
                <div class="p-4 border-t flex justify-end gap-2">
                    <button type="button" @click="closeNotesExpand" class="px-4 py-2 border rounded text-gray-700 hover:bg-gray-50">Cancel</button>
                    <button type="button" @click="saveNotesExpand" class="px-4 py-2 bg-teal-600 text-white rounded hover:bg-teal-700">
                        <i class="fa-solid fa-check mr-2"></i> Save
                    </button>
                </div>
            </div>
        </div>

        <!-- Manual import modal: same flow as other Mailchimp imports — select audience, upload CSV, map columns, add tags -->
        <div v-if="showManualImportModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">Manual import to Mailchimp</h3>
                    <button type="button" @click="showManualImportModal = false" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                <p class="text-gray-600 mb-4">Select audience first, then upload your CSV so we know what data to map. Map your CSV columns to Mailchimp audience fields, then add tags.</p>

                <!-- Step-by-step: what to do next -->
                <div class="flex flex-wrap items-center gap-2 sm:gap-4 mb-6 py-3 px-4 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                    <template v-for="(step, idx) in [
                        { n: 1, label: 'Select audience', done: !!manualImportListId, next: 'Choose account and audience' },
                        { n: 2, label: 'Upload CSV', done: manualImportCsvRows.length > 0, next: 'Upload file to see columns' },
                        { n: 3, label: 'Map columns', done: manualImportSourceColumns.length > 0 && manualImportMergeFields.length > 0 && manualImportFieldMapping.EMAIL, next: 'Map CSV columns to Mailchimp fields' },
                        { n: 4, label: 'Tags & Import', done: false, next: 'Add tags (optional) and run import' }
                    ]" :key="step.n">
                        <span v-if="idx > 0" class="text-gray-400 hidden sm:inline">→</span>
                        <span
                            class="inline-flex items-center gap-1.5 px-2 py-1 rounded"
                            :class="step.done ? 'bg-teal-100 text-teal-800' : 'bg-white border border-gray-300 text-gray-600'"
                        >
                            <i v-if="step.done" class="fa-solid fa-check text-teal-600"></i>
                            <span v-else class="w-5 h-5 inline-flex items-center justify-center rounded-full bg-gray-200 text-xs font-medium text-gray-600">{{ step.n }}</span>
                            <span class="font-medium">{{ step.label }}</span>
                        </span>
                    </template>
                </div>
                <p class="text-xs text-gray-500 mb-6" v-if="!manualImportListId">Next: select Mailchimp account and audience above.</p>
                <p class="text-xs text-gray-500 mb-6" v-else-if="manualImportCsvRows.length === 0">Next: upload your CSV file.</p>
                <p class="text-xs text-gray-500 mb-6" v-else-if="!manualImportFieldMapping.EMAIL">Next: open Field mapping below and map the Email column (required).</p>
                <p class="text-xs text-gray-500 mb-6" v-else>Ready: add tags if you want, then click Import.</p>

                <!-- Step 1: Select audience (same as Import All / location import) -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Mailchimp account</label>
                        <select v-model="manualImportAccount" class="w-full border rounded px-3 py-2" @change="loadManualImportLists(manualImportAccount)">
                            <option value="anz">ANZ</option>
                            <option value="usa">USA</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Mailchimp audience</label>
                        <select
                            v-model="manualImportListId"
                            class="w-full border rounded px-3 py-2"
                            :disabled="manualImportListsLoading"
                            @change="onManualImportAudienceChange(manualImportListId)"
                        >
                            <option value="">{{ manualImportListsLoading ? 'Loading...' : 'Select audience...' }}</option>
                            <option v-for="list in manualImportLists" :key="list.id" :value="list.id">
                                {{ list.name }} ({{ list.stats?.member_count ?? 0 }} members)
                            </option>
                        </select>
                    </div>
                </div>

                <!-- Step 2: Upload CSV (to know what columns to map) -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Upload CSV</label>
                    <div class="flex items-center gap-2">
                        <input type="file" accept=".csv,.txt" class="text-sm" @change="onManualImportCsvSelected" />
                        <span v-if="manualImportCsvFile" class="text-sm text-gray-600">{{ manualImportCsvFile }} — {{ manualImportCsvRows.length }} rows</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Upload your file so we know which columns you can map to Mailchimp fields.</p>
                    <!-- Preview: first rows of CSV -->
                    <div v-if="manualImportCsvRows.length > 0 && manualImportSourceColumns.length > 0" class="mt-3 p-3 bg-gray-50 border border-gray-200 rounded-lg">
                        <p class="text-xs font-medium text-gray-700 mb-2">Preview (first {{ Math.min(5, manualImportCsvRows.length) }} rows)</p>
                        <div class="overflow-x-auto max-h-40 overflow-y-auto border rounded bg-white">
                            <table class="w-full text-xs border-collapse">
                                <thead class="bg-gray-100 sticky top-0">
                                    <tr>
                                        <th v-for="sc in manualImportSourceColumns" :key="sc.key" class="border border-gray-200 p-1.5 text-left font-medium">{{ sc.label }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, ri) in manualImportCsvRows.slice(0, 5)" :key="ri" class="bg-white">
                                        <td v-for="sc in manualImportSourceColumns" :key="sc.key" class="border border-gray-200 p-1.5 text-gray-700 max-w-[180px] truncate" :title="(row[sc.key] ?? '')">
                                            {{ (row[sc.key] ?? '') }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Field mapping (same UI as Import All: map CSV columns to Mailchimp audience columns) -->
                <div class="mb-4 p-4 bg-purple-50 border border-purple-200 rounded-lg">
                    <button
                        type="button"
                        @click="manualImportShowMapping = !manualImportShowMapping"
                        class="flex items-center gap-2 w-full text-left text-sm font-medium text-gray-800"
                    >
                        <i :class="manualImportShowMapping ? 'fa-solid fa-chevron-down' : 'fa-solid fa-chevron-right'" class="text-purple-600"></i>
                        Field mapping: map CSV columns to Mailchimp audience columns
                    </button>
                    <p v-if="!manualImportShowMapping && (manualImportSourceColumns.length > 0 || manualImportMergeFields.length > 0)" class="text-xs text-gray-600 mt-1 ml-6">Map each Mailchimp field to a column from your CSV. Email is required.</p>
                    <p v-else-if="!manualImportListId || manualImportSourceColumns.length === 0" class="text-xs text-gray-600 mt-1 ml-6">Select an audience and upload a CSV above to map columns.</p>
                    <div v-show="manualImportShowMapping" class="mt-4">
                        <p class="text-xs text-gray-600 mb-3">Map each Mailchimp field to a column from your CSV. Email is required.</p>
                        <div v-if="manualImportMergeFieldsLoading" class="text-sm text-gray-500 py-2"><i class="fa-solid fa-spinner fa-spin mr-2"></i> Loading audience fields...</div>
                        <div v-else-if="!manualImportListId" class="text-sm text-gray-500 py-2">Select a Mailchimp audience above to load fields.</div>
                        <div v-else-if="manualImportSourceColumns.length === 0" class="text-sm text-gray-500 py-2">Upload a CSV above to see columns to map.</div>
                        <div v-else class="overflow-x-auto max-h-64 overflow-y-auto border rounded">
                            <table class="w-full text-sm border-collapse">
                                <thead class="bg-purple-100 sticky top-0">
                                    <tr>
                                        <th class="border border-purple-200 p-2 text-left">Mailchimp field (label)</th>
                                        <th class="border border-purple-200 p-2 text-left">Map from CSV column</th>
                                        <th class="border border-purple-200 p-2 text-left">Validation</th>
                                        <th class="border border-purple-200 p-2 text-left">Value we'll import (1st row)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="border border-purple-200 p-2 font-medium">Email Address</td>
                                        <td class="border border-purple-200 p-2">
                                            <select
                                                :value="manualImportFieldMapping.EMAIL"
                                                @change="manualImportFieldMapping = { ...manualImportFieldMapping, EMAIL: $event.target.value }"
                                                class="w-full border rounded px-2 py-1 text-sm"
                                            >
                                                <option value="">— Select column</option>
                                                <option v-for="sc in manualImportSourceColumns" :key="sc.key" :value="sc.key">{{ sc.label }}</option>
                                            </select>
                                        </td>
                                        <td class="border border-purple-200 p-2 text-xs text-gray-600">Required, valid email</td>
                                        <td class="border border-purple-200 p-2 text-xs text-gray-700 font-mono max-w-[200px] truncate" :title="getManualImportPreviewValue('EMAIL')">
                                            {{ getManualImportPreviewValue('EMAIL') }}
                                        </td>
                                    </tr>
                                    <tr v-for="mf in manualImportMergeFields" :key="mf.tag" class="bg-white">
                                        <td class="border border-purple-200 p-2 font-medium">{{ mf.name || mf.tag }}</td>
                                        <td class="border border-purple-200 p-2">
                                            <select
                                                :value="manualImportFieldMapping[mf.tag]"
                                                @change="manualImportFieldMapping = { ...manualImportFieldMapping, [mf.tag]: $event.target.value }"
                                                class="w-full border rounded px-2 py-1 text-sm"
                                            >
                                                <option value="">— Don't map</option>
                                                <option v-for="sc in manualImportSourceColumns" :key="sc.key" :value="sc.key">{{ sc.label }}</option>
                                            </select>
                                        </td>
                                        <td class="border border-purple-200 p-2 text-xs text-gray-600">
                                            <span v-if="mf.validation">{{ mf.validation.type }}{{ mf.validation.required ? ', required' : '' }}</span>
                                            <span v-if="mf.validation?.choices" class="block mt-1">Allowed: {{ mf.validation.choices.slice(0, 5).join(', ') }}{{ mf.validation.choices.length > 5 ? '…' : '' }}</span>
                                        </td>
                                        <td class="border border-purple-200 p-2 text-xs text-gray-700 font-mono max-w-[200px] truncate" :title="getManualImportPreviewValue(mf.tag)">
                                            {{ getManualImportPreviewValue(mf.tag) }}
                                        </td>
                                    </tr>
                                    <tr v-if="manualImportMergeFields.length === 0 && manualImportListId">
                                        <td colspan="4" class="border border-purple-200 p-4 text-gray-500 text-center">No merge fields loaded. Try selecting the audience again.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Add tags -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Tags (optional)</label>
                    <input v-model="manualImportTags" type="text" class="w-full border rounded px-3 py-2" placeholder="Tag1; Tag2; Tag3; Tag4; Tag5; Tag6; Tag7" />
                    <p class="text-xs text-gray-500 mt-1">Separate with semicolons. Each tag goes in its own column (Tag 1–6). If you add more than 6 tags, the extra ones are shown together in the Tag 6 column (e.g. Tag6, Tag7).</p>
                </div>

                <!-- Optional: log labels (event / source name) -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Event name (for this log)</label>
                        <input v-model="manualImportEventName" type="text" class="w-full border rounded px-3 py-2" placeholder="e.g. Newsletter Feb 2026" />
                        <p class="text-xs text-gray-500 mt-1">Shown in the import logs table.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Source name (for this log)</label>
                        <input v-model="manualImportSourceName" type="text" class="w-full border rounded px-3 py-2" placeholder="e.g. CSV upload, Partner list" />
                        <p class="text-xs text-gray-500 mt-1">Shown in the import logs table.</p>
                    </div>
                </div>

                <div class="flex justify-end gap-3 pt-4 border-t">
                    <button type="button" @click="showManualImportModal = false" class="px-4 py-2 border rounded text-gray-600 hover:bg-gray-50">Cancel</button>
                    <button
                        type="button"
                        @click="manualImportRun"
                        :disabled="manualImportImporting || !manualImportListId || manualImportCsvRows.length === 0"
                        class="px-4 py-2 bg-teal-600 text-white rounded hover:bg-teal-700 disabled:opacity-50"
                    >
                        <span v-if="manualImportImporting"><i class="fa-solid fa-spinner fa-spin mr-2"></i> Importing...</span>
                        <span v-else><i class="fa-solid fa-upload mr-2"></i> Import {{ manualImportCsvRows.length }} rows</span>
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
