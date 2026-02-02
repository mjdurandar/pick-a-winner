<script setup>
import { ref, computed } from 'vue';
import Swal from 'sweetalert2';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';

const page = usePage();
const canDeleteLogs = computed(() => (page.props.auth?.user?.email || '').toLowerCase() === 'mj@adventureentertainment.com');

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

const showFullErrors = (log) => {
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
};

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

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

const exportToCsv = () => {
    const n = maxTagColumns.value;
    const headers = [
        'Event', 'Location', 'Imported Date', 'Imported By', 'Source', 'Total Data', 'New Contacts', 'Updated Data', 'Data with Error', 'Errors', 'Imported data file',
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
    <Head title="Mailchimp import logs" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Mailchimp import logs
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
                                @click="exportToCsv"
                                class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 text-sm font-medium"
                            >
                                <i class="fa-solid fa-file-csv mr-2"></i> Export to CSV
                            </button>
                        </div>

                        <div v-if="filteredLogs.length === 0" class="text-gray-600 py-8 text-center">
                            No Mailchimp import logs found.
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
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Source</th>
                                        <th class="border border-gray-300 p-2 text-right whitespace-nowrap">Total Data</th>
                                        <th class="border border-gray-300 p-2 text-right whitespace-nowrap">New Contacts</th>
                                        <th class="border border-gray-300 p-2 text-right whitespace-nowrap">Updated Data</th>
                                        <th class="border border-gray-300 p-2 text-right whitespace-nowrap">Data with Error</th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Errors</th>
                                        <th class="border border-gray-300 p-2 text-left whitespace-nowrap">Imported data</th>
                                        <th v-for="i in tagColumnIndices" :key="i" class="border border-gray-300 p-2 text-left whitespace-nowrap">Tag {{ i + 1 }}</th>
                                        <th class="border border-gray-300 p-2 text-center whitespace-nowrap">Actions</th>
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
                                        <td class="border border-gray-300 p-2 whitespace-nowrap">{{ sourceLabel(log.source) }}</td>
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
    </AuthenticatedLayout>
</template>
