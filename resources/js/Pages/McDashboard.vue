<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    anzKeys: { type: Array, default: () => [] },
    usaKeys: { type: Array, default: () => [] },
    anzFilmKeys: { type: Array, default: () => [] },
    anzMagKeys: { type: Array, default: () => [] },
    usaFilmKeys: { type: Array, default: () => [] },
});

const snapshotLoading = ref(false);

const page = usePage();
const flash = computed(() => page.props.flash || {});

const totalFixedCols = 4;
const totalCols = computed(() =>
    totalFixedCols
    + props.anzKeys.length
    + props.anzMagKeys.length
    + props.usaKeys.length
    + props.anzFilmKeys.length
    + props.usaFilmKeys.length
    + 1
);

function snapshotNow() {
    snapshotLoading.value = true;
    router.post(route('mcDashboard.snapshotNow'), {}, {
        onFinish: () => {
            snapshotLoading.value = false;
        },
    });
}

function deleteRow(row) {
    if (confirm('Delete this snapshot row (both ANZ & USA)?')) {
        router.delete(route('mcDashboard.destroy', row.ids[0]));
    }
}

function formatNumber(num) {
    if (num === undefined || num === null || num === 0) return '-';
    return Number(num).toLocaleString();
}

const copied = ref(false);

function copyTable() {
    const sep = '\t';
    const lines = [];

    // Row 1: group headers
    const groupRow = [];
    for (let i = 0; i < totalFixedCols; i++) groupRow.push('');
    if (props.anzKeys.length) {
        groupRow.push('ANZ Audiences');
        for (let i = 1; i < props.anzKeys.length; i++) groupRow.push('');
    }
    if (props.anzMagKeys.length) {
        groupRow.push('ANZ Magazines');
        for (let i = 1; i < props.anzMagKeys.length; i++) groupRow.push('');
    }
    if (props.usaKeys.length) {
        groupRow.push('USA Audiences');
        for (let i = 1; i < props.usaKeys.length; i++) groupRow.push('');
    }
    if (props.anzFilmKeys.length) {
        groupRow.push('ANZ - Film');
        for (let i = 1; i < props.anzFilmKeys.length; i++) groupRow.push('');
    }
    if (props.usaFilmKeys.length) {
        groupRow.push('FILM-USA');
        for (let i = 1; i < props.usaFilmKeys.length; i++) groupRow.push('');
    }
    lines.push(groupRow.join(sep));

    // Row 2: column headers
    const headerRow = ['SUBSCRIBED', 'Total', 'AU Total', 'US Total'];
    props.anzKeys.forEach(k => headerRow.push(k));
    props.anzMagKeys.forEach(k => headerRow.push(k));
    props.usaKeys.forEach(k => headerRow.push(k));
    props.anzFilmKeys.forEach(k => headerRow.push(k));
    props.usaFilmKeys.forEach(k => headerRow.push(k));
    lines.push(headerRow.join(sep));

    // Data rows
    props.rows.forEach(row => {
        const dataRow = [
            row.period_label,
            row.total,
            row.au_total,
            row.us_total,
        ];
        props.anzKeys.forEach(k => dataRow.push(row.anz_audiences[k] ?? ''));
        props.anzMagKeys.forEach(k => dataRow.push(row.anz_mag_tags[k] ?? ''));
        props.usaKeys.forEach(k => dataRow.push(row.usa_audiences[k] ?? ''));
        props.anzFilmKeys.forEach(k => dataRow.push(row.anz_film_tags[k] ?? ''));
        props.usaFilmKeys.forEach(k => dataRow.push(row.usa_film_tags[k] ?? ''));
        lines.push(dataRow.join(sep));
    });

    navigator.clipboard.writeText(lines.join('\n')).then(() => {
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    });
}
</script>

<template>
    <Head title="MC Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    MC Dashboard
                </h2>
                <div class="flex items-center space-x-2">
                    <button
                        @click="copyTable"
                        :disabled="rows.length === 0"
                        class="rounded-md bg-gray-600 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-50"
                    >
                        {{ copied ? 'Copied!' : 'Copy Table' }}
                    </button>
                    <button
                        @click="snapshotNow"
                        :disabled="snapshotLoading"
                        class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                    >
                        {{ snapshotLoading ? 'Fetching...' : 'Snapshot Now' }}
                    </button>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-full px-4 sm:px-6 lg:px-8">
                <!-- Flash Messages -->
                <div v-if="flash.success" class="mb-4 rounded-md bg-green-50 p-4">
                    <p class="text-sm text-green-700">{{ flash.success }}</p>
                </div>
                <div v-if="flash.error" class="mb-4 rounded-md bg-red-50 p-4">
                    <p class="text-sm text-red-700">{{ flash.error }}</p>
                </div>

                <!-- Info -->
                <div class="mb-4 rounded-md bg-blue-50 p-3 text-sm text-blue-700">
                    Snapshots are automatically taken every Friday at 9:00 AM. Click "Snapshot Now" to capture current data for both ANZ &amp; USA.
                </div>

                <!-- Spreadsheet Table -->
                <div class="overflow-hidden rounded-lg bg-white shadow">
                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse border border-gray-300 text-sm">
                            <thead>
                                <!-- Row 1: Group headers -->
                                <tr>
                                    <th :colspan="totalFixedCols" class="border border-gray-300 bg-white px-3 py-2"></th>
                                    <th
                                        v-if="anzKeys.length"
                                        :colspan="anzKeys.length"
                                        class="border border-gray-300 bg-[#4472C4] px-3 py-2 text-center text-base font-bold text-white"
                                    >
                                        ANZ Audiences
                                    </th>
                                    <th
                                        v-if="anzMagKeys.length"
                                        :colspan="anzMagKeys.length"
                                        class="border border-gray-300 bg-[#4472C4] px-3 py-2 text-center text-base font-bold text-white"
                                    >
                                        ANZ Magazines
                                    </th>
                                    <th
                                        v-if="usaKeys.length"
                                        :colspan="usaKeys.length"
                                        class="border border-gray-300 bg-[#4472C4] px-3 py-2 text-center text-base font-bold text-white"
                                    >
                                        USA Audiences
                                    </th>
                                    <th
                                        v-if="anzFilmKeys.length"
                                        :colspan="anzFilmKeys.length"
                                        class="border border-gray-300 bg-[#2F5496] px-3 py-2 text-center text-base font-bold text-white"
                                    >
                                        ANZ - Film
                                    </th>
                                    <th
                                        v-if="usaFilmKeys.length"
                                        :colspan="usaFilmKeys.length"
                                        class="border border-gray-300 bg-[#FFD966] px-3 py-2 text-center text-base font-bold text-gray-900"
                                    >
                                        FILM-USA
                                    </th>
                                    <th class="border border-gray-300 bg-white px-3 py-2"></th>
                                </tr>
                                <!-- Row 2: Column headers -->
                                <tr class="bg-gray-50">
                                    <th class="border border-gray-300 px-3 py-2 text-left font-bold text-gray-900">SUBSCRIBED</th>
                                    <th class="border border-gray-300 px-3 py-2 text-center font-bold text-gray-900">Total</th>
                                    <th class="border border-gray-300 bg-gray-100 px-3 py-2 text-center font-medium text-gray-700">AU Total</th>
                                    <th class="border border-gray-300 bg-gray-200 px-3 py-2 text-center font-bold text-gray-900">US Total</th>
                                    <th
                                        v-for="key in anzKeys"
                                        :key="'anz-' + key"
                                        class="border border-gray-300 bg-[#B4C6E7] px-3 py-2 text-center font-medium text-gray-900"
                                    >
                                        {{ key }}
                                    </th>
                                    <th
                                        v-for="key in anzMagKeys"
                                        :key="'mag-' + key"
                                        class="border border-gray-300 bg-[#D6DCE4] px-3 py-2 text-center font-medium text-gray-900"
                                    >
                                        {{ key }}
                                    </th>
                                    <th
                                        v-for="key in usaKeys"
                                        :key="'usa-' + key"
                                        class="border border-gray-300 bg-[#FCE4CC] px-3 py-2 text-center font-medium text-gray-900"
                                    >
                                        {{ key }}
                                    </th>
                                    <th
                                        v-for="key in anzFilmKeys"
                                        :key="'film-' + key"
                                        class="border border-gray-300 bg-[#C5D3E8] px-3 py-2 text-center font-medium text-gray-900"
                                    >
                                        {{ key }}
                                    </th>
                                    <th
                                        v-for="key in usaFilmKeys"
                                        :key="'usafilm-' + key"
                                        class="border border-gray-300 bg-[#FFF2CC] px-3 py-2 text-center font-bold text-gray-900"
                                    >
                                        {{ key }}
                                    </th>
                                    <th class="border border-gray-300 px-3 py-2 text-center font-medium text-gray-500">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="rows.length === 0">
                                    <td :colspan="totalCols" class="border border-gray-300 px-4 py-8 text-center text-gray-500">
                                        No snapshots yet. Click "Snapshot Now" to fetch audience data.
                                    </td>
                                </tr>
                                <tr v-for="row in rows" :key="row.snapshot_date" class="hover:bg-gray-50">
                                    <td class="border border-gray-300 px-3 py-2 font-medium text-gray-900">
                                        {{ row.period_label }}
                                    </td>
                                    <td class="border border-gray-300 px-3 py-2 text-center text-lg font-bold text-gray-900">
                                        {{ formatNumber(row.total) }}
                                    </td>
                                    <td class="border border-gray-300 bg-gray-50 px-3 py-2 text-center text-gray-700">
                                        {{ formatNumber(row.au_total) }}
                                    </td>
                                    <td class="border border-gray-300 bg-gray-100 px-3 py-2 text-center font-medium text-gray-900">
                                        {{ formatNumber(row.us_total) }}
                                    </td>
                                    <td
                                        v-for="key in anzKeys"
                                        :key="'anz-' + key"
                                        class="border border-gray-300 bg-[#D9E2F3] px-3 py-2 text-center text-gray-800"
                                    >
                                        {{ row.anz_audiences[key] ? formatNumber(row.anz_audiences[key]) : '-' }}
                                    </td>
                                    <td
                                        v-for="key in anzMagKeys"
                                        :key="'mag-' + key"
                                        class="border border-gray-300 bg-[#E8ECF1] px-3 py-2 text-center text-gray-800"
                                    >
                                        {{ row.anz_mag_tags[key] ? formatNumber(row.anz_mag_tags[key]) : '-' }}
                                    </td>
                                    <td
                                        v-for="key in usaKeys"
                                        :key="'usa-' + key"
                                        class="border border-gray-300 bg-[#FFF2E5] px-3 py-2 text-center text-gray-800"
                                    >
                                        {{ row.usa_audiences[key] ? formatNumber(row.usa_audiences[key]) : '-' }}
                                    </td>
                                    <td
                                        v-for="key in anzFilmKeys"
                                        :key="'film-' + key"
                                        class="border border-gray-300 bg-[#E8EDF5] px-3 py-2 text-center text-gray-800"
                                    >
                                        {{ row.anz_film_tags[key] ? formatNumber(row.anz_film_tags[key]) : '-' }}
                                    </td>
                                    <td
                                        v-for="key in usaFilmKeys"
                                        :key="'usafilm-' + key"
                                        class="border border-gray-300 bg-[#FFFAE5] px-3 py-2 text-center text-gray-800"
                                    >
                                        {{ row.usa_film_tags[key] ? formatNumber(row.usa_film_tags[key]) : '-' }}
                                    </td>
                                    <td class="border border-gray-300 px-3 py-2 text-center">
                                        <button
                                            @click="deleteRow(row)"
                                            class="text-red-600 hover:text-red-800"
                                        >
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
