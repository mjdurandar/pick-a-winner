<script setup>
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    logs: { type: Object, required: true },
    filters: { type: Object, required: true },
    routeNames: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
});

const filters = ref({ ...props.filters });
const expandedRow = ref(null);

let debounceTimer = null;
watch(
    () => filters.value.search,
    () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(applyFilters, 300);
    }
);

function applyFilters() {
    const params = {};
    Object.entries(filters.value).forEach(([k, v]) => {
        if (v !== '' && v !== null && v !== undefined) params[k] = v;
    });
    router.get(route('admin.exportLogs.index'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clearFilters() {
    filters.value = {
        user_id: '',
        route_name: '',
        category: '',
        search: '',
        date_from: '',
        date_to: '',
        per_page: '25',
    };
    applyFilters();
}

const CATEGORY_LABELS = {
    export: 'Data export',
    pickawinner_access: 'Draw access',
    login: 'Login',
    logout: 'Logout',
    login_failed: 'Failed login',
    create: 'Created',
    update: 'Updated',
    delete: 'Deleted',
};

function categoryLabel(c) {
    if (!c) return 'Activity';
    return CATEGORY_LABELS[c] || c;
}

const CATEGORY_BADGE_CLASSES = {
    pickawinner_access: 'bg-purple-100 text-purple-800',
    login: 'bg-green-100 text-green-800',
    logout: 'bg-gray-100 text-gray-700',
    login_failed: 'bg-red-100 text-red-800',
    create: 'bg-emerald-100 text-emerald-800',
    update: 'bg-amber-100 text-amber-800',
    delete: 'bg-red-100 text-red-800',
};

function categoryBadgeClass(c) {
    return CATEGORY_BADGE_CLASSES[c] || 'bg-blue-100 text-blue-800';
}

// Short human summary of a create/update/delete row for the Target column.
function activitySummary(log) {
    const p = log.params || {};
    if (!['create', 'update', 'delete'].includes(log.category)) return '';
    const what = `${log.target_type || p.model || 'record'} #${log.target_id ?? ''}`.trim();
    const label = p.label ? ` “${p.label}”` : '';
    if (log.category === 'update' && p.changes) {
        return `${what}${label} — ${Object.keys(p.changes).join(', ')}`;
    }
    return `${what}${label}`;
}

function formatDate(s) {
    if (!s) return '';
    const d = new Date(s);
    return d.toLocaleString();
}

function toggleRow(id) {
    expandedRow.value = expandedRow.value === id ? null : id;
}

function paramsPreview(p) {
    if (!p || Object.keys(p).length === 0) return '—';
    const parts = Object.entries(p)
        .filter(([k]) => !['_token'].includes(k))
        .slice(0, 3)
        .map(([k, v]) => `${k}=${typeof v === 'object' ? JSON.stringify(v) : v}`);
    return parts.join(', ');
}

function prettyRoute(name) {
    if (!name) return '—';
    return name;
}

// Build the export URL carrying the active filters (excluding pagination-only params).
function exportUrl() {
    const params = {};
    Object.entries(filters.value).forEach(([k, v]) => {
        if (k === 'per_page') return;
        if (v !== '' && v !== null && v !== undefined) params[k] = v;
    });
    return route('admin.exportLogs.export', params);
}
</script>

<template>
    <Head title="Activity Logs" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Activity Logs</h2>
        </template>

        <div class="p-2 pb-5 pt-4">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <p class="text-gray-600 mb-4">
                            Activity is logged here — data exports and Pick a Winner draw access (including
                            failed attempts) — showing who, what, when, and from which IP address.
                        </p>

                        <!-- Filters -->
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-6 mb-4">
                            <div class="md:col-span-2">
                                <label class="block text-xs font-medium text-gray-600 mb-1">Search</label>
                                <input
                                    v-model="filters.search"
                                    type="text"
                                    placeholder="name, email, url, ip..."
                                    class="w-full rounded border-gray-300 text-sm"
                                />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Activity</label>
                                <select
                                    v-model="filters.category"
                                    @change="applyFilters"
                                    class="w-full rounded border-gray-300 text-sm"
                                >
                                    <option value="">All</option>
                                    <option v-for="c in categories" :key="c" :value="c">
                                        {{ categoryLabel(c) }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">User</label>
                                <select
                                    v-model="filters.user_id"
                                    @change="applyFilters"
                                    class="w-full rounded border-gray-300 text-sm"
                                >
                                    <option value="">All</option>
                                    <option v-for="u in users" :key="u.id" :value="u.id">
                                        {{ u.name }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Route</label>
                                <select
                                    v-model="filters.route_name"
                                    @change="applyFilters"
                                    class="w-full rounded border-gray-300 text-sm"
                                >
                                    <option value="">All</option>
                                    <option v-for="r in routeNames" :key="r" :value="r">
                                        {{ r }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">From</label>
                                <input
                                    v-model="filters.date_from"
                                    @change="applyFilters"
                                    type="date"
                                    class="w-full rounded border-gray-300 text-sm"
                                />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">To</label>
                                <input
                                    v-model="filters.date_to"
                                    @change="applyFilters"
                                    type="date"
                                    class="w-full rounded border-gray-300 text-sm"
                                />
                            </div>
                        </div>

                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-2">
                                <span class="text-sm text-gray-600">Per page:</span>
                                <select
                                    v-model="filters.per_page"
                                    @change="applyFilters"
                                    class="rounded border-gray-300 text-sm"
                                >
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                                <button
                                    type="button"
                                    @click="clearFilters"
                                    class="text-sm text-gray-600 hover:text-gray-900 underline ml-2"
                                >
                                    Clear filters
                                </button>
                            </div>
                            <div class="flex items-center gap-3">
                                <a
                                    :href="exportUrl()"
                                    class="inline-flex items-center gap-1.5 rounded bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
                                    </svg>
                                    Export CSV
                                </a>
                                <div class="text-sm text-gray-500">
                                    {{ logs.total }} total record{{ logs.total === 1 ? '' : 's' }}
                                </div>
                            </div>
                        </div>

                        <!-- Table -->
                        <div class="overflow-x-auto rounded border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-semibold text-gray-700">When</th>
                                        <th class="px-3 py-2 text-left font-semibold text-gray-700">Activity</th>
                                        <th class="px-3 py-2 text-left font-semibold text-gray-700">Who</th>
                                        <th class="px-3 py-2 text-left font-semibold text-gray-700">Route</th>
                                        <th class="px-3 py-2 text-left font-semibold text-gray-700">Target</th>
                                        <th class="px-3 py-2 text-left font-semibold text-gray-700">Rows</th>
                                        <th class="px-3 py-2 text-left font-semibold text-gray-700">IP</th>
                                        <th class="px-3 py-2 text-left font-semibold text-gray-700">Status</th>
                                        <th class="px-3 py-2"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    <tr v-if="logs.data.length === 0">
                                        <td colspan="9" class="px-3 py-8 text-center text-gray-500">
                                            No activity yet.
                                        </td>
                                    </tr>
                                    <template v-for="row in logs.data" :key="row.id">
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2 whitespace-nowrap text-gray-700">{{ formatDate(row.created_at) }}</td>
                                            <td class="px-3 py-2">
                                                <span
                                                    :class="categoryBadgeClass(row.category)"
                                                    class="inline-flex rounded px-2 py-0.5 text-xs font-medium whitespace-nowrap"
                                                >
                                                    {{ categoryLabel(row.category) }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-2 text-gray-800">
                                                <div class="font-medium">{{ row.user_name_snapshot || row.user?.name || (row.category === 'pickawinner_access' ? 'Guest (host)' : 'Unknown') }}</div>
                                                <div class="text-xs text-gray-500">{{ row.user_email_snapshot || row.user?.email || '—' }}</div>
                                            </td>
                                            <td class="px-3 py-2 text-gray-700 font-mono text-xs">{{ prettyRoute(row.route_name) }}</td>
                                            <td class="px-3 py-2 text-gray-700">
                                                <span v-if="activitySummary(row)">{{ activitySummary(row) }}</span>
                                                <span v-else-if="row.target_type">{{ row.target_type }} #{{ row.target_id }}</span>
                                                <span v-else class="text-gray-400">—</span>
                                            </td>
                                            <td class="px-3 py-2 text-gray-700">{{ row.row_count ?? '—' }}</td>
                                            <td class="px-3 py-2 text-gray-500 font-mono text-xs">{{ row.ip_address || '—' }}</td>
                                            <td class="px-3 py-2">
                                                <span
                                                    :class="row.status === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                                                    class="inline-flex rounded px-2 py-0.5 text-xs font-medium"
                                                >
                                                    {{ row.status }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-2 text-right">
                                                <button
                                                    type="button"
                                                    @click="toggleRow(row.id)"
                                                    class="text-xs text-indigo-600 hover:text-indigo-800 underline"
                                                >
                                                    {{ expandedRow === row.id ? 'Hide' : 'Details' }}
                                                </button>
                                            </td>
                                        </tr>
                                        <tr v-if="expandedRow === row.id" class="bg-gray-50">
                                            <td colspan="9" class="px-3 py-3">
                                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                                                    <div>
                                                        <div class="font-semibold text-gray-600 mb-1">URL</div>
                                                        <div class="font-mono text-gray-800 break-all">{{ row.method }} {{ row.url }}</div>
                                                    </div>
                                                    <div>
                                                        <div class="font-semibold text-gray-600 mb-1">User-Agent</div>
                                                        <div class="font-mono text-gray-800 break-all">{{ row.user_agent || '—' }}</div>
                                                    </div>
                                                    <div class="md:col-span-2">
                                                        <div class="font-semibold text-gray-600 mb-1">Params</div>
                                                        <pre class="bg-white border border-gray-200 rounded p-2 overflow-x-auto">{{ JSON.stringify(row.params, null, 2) }}</pre>
                                                    </div>
                                                    <div v-if="row.file_name">
                                                        <div class="font-semibold text-gray-600 mb-1">File name</div>
                                                        <div class="font-mono text-gray-800">{{ row.file_name }}</div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div v-if="logs.last_page > 1" class="mt-4 flex flex-wrap items-center justify-center gap-1">
                            <template v-for="link in logs.links" :key="link.label">
                                <button
                                    type="button"
                                    :disabled="!link.url"
                                    @click="link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })"
                                    :class="[
                                        'rounded px-3 py-1 text-sm',
                                        link.active
                                            ? 'bg-gray-800 text-white'
                                            : link.url
                                            ? 'bg-white text-gray-700 hover:bg-gray-100 border border-gray-300'
                                            : 'bg-gray-50 text-gray-400 border border-gray-200 cursor-not-allowed',
                                    ]"
                                    v-html="link.label"
                                ></button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
