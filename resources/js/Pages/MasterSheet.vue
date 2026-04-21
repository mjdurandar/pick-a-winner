<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import Swal from 'sweetalert2';
import axios from 'axios';

const props = defineProps({
    locations: { type: Array, default: () => [] },
    events: { type: Array, default: () => [] },
    years: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const page = usePage();
const userRole = computed(() => page?.props?.auth?.user?.role ?? null);

const selectedYear = ref(props.filters.year || new Date().getFullYear());
const saving = ref({});

// Column definitions in order
const columns = [
    { key: 'cinema_contact', label: 'Cinema Contact (Current)', editable: true, type: 'text' },
    { key: 'film', label: 'Film', editable: false },
    { key: 'location', label: 'Location', editable: false },
    { key: 'cinema', label: 'Cinema', editable: false },
    { key: 'country', label: 'Country', editable: false },
    { key: 'date', label: 'Date', editable: false, format: 'date' },
    { key: 'time', label: 'Time', editable: false, format: 'time' },
    { key: 'number_of_screenings', label: 'No. Screenings', editable: true, type: 'number' },
    { key: 'show_type', label: 'Show Type', editable: false },
    { key: 'status', label: 'Status', editable: true, type: 'select', options: ['', 'Confirmed', 'Pending', 'Cancelled', 'TBC'] },
    { key: 'ticketing_type', label: 'Ticketing Type', editable: true, type: 'select', options: ['', 'Eventbrite', 'Cinema', 'Free', 'Other'] },
    { key: 'booked_by', label: 'Booked By', editable: true, type: 'text' },
    { key: 'date_booking_confirmed', label: 'Date Booking Confirmed', editable: true, type: 'date' },
    { key: 'film_format', label: 'Film Format', editable: true, type: 'select', options: ['', 'DCP', 'Blu-ray', 'Digital', '35mm', 'Other'] },
    { key: 'dcp_trailer_sent', label: 'DCP Trailer Sent', editable: true, type: 'checkbox' },
    { key: 'media_kit_sent', label: 'Media Kit Sent', editable: true, type: 'checkbox' },
    { key: 'dcp_sent', label: 'DCP Sent', editable: true, type: 'checkbox' },
    { key: 'specific_deliverable_requests', label: 'Specific Deliverable Requests', editable: true, type: 'textarea' },
];

// Selection state: row index + col index
const selectedRow = ref(null);
const selectedCol = ref(null);

// Editing state
const editingCell = ref(null);
const editValue = ref('');

// Event tabs
const activeTab = ref(props.events.length ? props.events[0].id : null);

// Filtered locations for current event tab
const tabLocations = computed(() => {
    return props.locations.filter(l => l.event_id == activeTab.value);
});

function eventLocationCount(eventId) {
    return props.locations.filter(l => l.event_id == eventId).length;
}

// --- Selection ---
function selectCell(rowIdx, colIdx) {
    selectedRow.value = rowIdx;
    selectedCol.value = colIdx;
}

function isSelected(rowIdx, colIdx) {
    return selectedRow.value === rowIdx && selectedCol.value === colIdx;
}

function isSelectedRow(rowIdx) {
    return selectedRow.value === rowIdx;
}

function isSelectedCol(colIdx) {
    return selectedCol.value === colIdx;
}

function clearSelection() {
    if (editingCell.value) return; // don't clear while editing
    selectedRow.value = null;
    selectedCol.value = null;
}

// --- Keyboard navigation ---
function handleKeydown(e) {
    if (editingCell.value) return; // let edit inputs handle their own keys
    if (selectedRow.value === null || selectedCol.value === null) return;

    const maxRow = tabLocations.value.length - 1;
    const maxCol = columns.length - 1;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        selectedRow.value = Math.min(selectedRow.value + 1, maxRow);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        selectedRow.value = Math.max(selectedRow.value - 1, 0);
    } else if (e.key === 'ArrowRight' || e.key === 'Tab') {
        e.preventDefault();
        selectedCol.value = Math.min(selectedCol.value + 1, maxCol);
    } else if (e.key === 'ArrowLeft') {
        e.preventDefault();
        selectedCol.value = Math.max(selectedCol.value - 1, 0);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        const col = columns[selectedCol.value];
        const loc = tabLocations.value[selectedRow.value];
        if (col.editable && loc) {
            startCellEdit(loc, col.key, col.type);
        }
    } else if (e.key === 'Escape') {
        clearSelection();
    }
}

onMounted(() => {
    document.addEventListener('keydown', handleKeydown);
});
onUnmounted(() => {
    document.removeEventListener('keydown', handleKeydown);
});

function changeYear(year) {
    selectedYear.value = year;
    router.get(route('mastersheet.index'), { year }, { preserveState: false });
}

function formatDate(dateStr) {
    if (!dateStr || dateStr === 'TBA') return dateStr || '';
    try {
        const d = new Date(dateStr + 'T00:00:00');
        return d.toLocaleDateString('en-AU', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
    } catch {
        return dateStr;
    }
}

function formatTime(timeStr) {
    if (!timeStr || timeStr === 'TBA') return timeStr || '';
    try {
        const parts = timeStr.split(':');
        let h = parseInt(parts[0]);
        const m = parseInt(parts[1] || 0);
        const period = h >= 12 ? 'pm' : 'am';
        if (h === 0) h = 12;
        else if (h > 12) h -= 12;
        return `${h}:${String(m).padStart(2, '0')} ${period}`;
    } catch {
        return timeStr;
    }
}

function displayValue(loc, col) {
    const val = loc[col.key];
    if (col.format === 'date') return formatDate(val);
    if (col.format === 'time') return formatTime(val);
    if (col.key === 'date_booking_confirmed') return formatDate(val);
    return val ?? '';
}

// --- Cell editing ---
function cellKey(locId, field) {
    return `${locId}-${field}`;
}

function startCellEdit(loc, field, type) {
    if (userRole.value !== 'admin') return;
    editingCell.value = cellKey(loc.id, field);

    if (type === 'checkbox') {
        toggleCheckbox(loc, field);
        return;
    }
    editValue.value = loc[field] ?? '';
    nextTick(() => {
        const el = document.querySelector('.cell-editing');
        if (el) el.focus();
    });
}

function handleCellClick(loc, colIdx, rowIdx) {
    const col = columns[colIdx];
    selectCell(rowIdx, colIdx);

    // For checkboxes, toggle on single click
    if (col.editable && col.type === 'checkbox') {
        startCellEdit(loc, col.key, col.type);
    }
}

function handleCellDblClick(loc, colIdx) {
    const col = columns[colIdx];
    if (col.editable && col.type !== 'checkbox') {
        startCellEdit(loc, col.key, col.type);
    }
}

async function toggleCheckbox(loc, field) {
    const newVal = !loc[field];
    saving.value[loc.id] = true;
    try {
        await axios.patch(route('mastersheet.update', loc.id), { [field]: newVal });
        router.reload({ only: ['locations'] });
    } catch {
        Swal.fire('Error', 'Failed to save.', 'error');
    } finally {
        saving.value[loc.id] = false;
        editingCell.value = null;
    }
}

async function saveCellEdit(locId, field) {
    if (editingCell.value !== cellKey(locId, field)) return;
    saving.value[locId] = true;
    try {
        let val = editValue.value;
        if (field === 'number_of_screenings') {
            val = val === '' ? null : parseInt(val);
        }
        await axios.patch(route('mastersheet.update', locId), { [field]: val });
        editingCell.value = null;
        editValue.value = '';
        router.reload({ only: ['locations'] });
    } catch {
        Swal.fire('Error', 'Failed to save.', 'error');
    } finally {
        saving.value[locId] = false;
    }
}

function cancelCellEdit() {
    editingCell.value = null;
    editValue.value = '';
}

function handleEditKeydown(e, locId, field) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        saveCellEdit(locId, field);
    } else if (e.key === 'Escape') {
        cancelCellEdit();
    } else if (e.key === 'Tab') {
        e.preventDefault();
        saveCellEdit(locId, field);
    }
}

// Flash messages
watch(
    () => page.props.flash?.success,
    (msg) => { if (msg) Swal.fire({ icon: 'success', title: 'Saved', text: msg, timer: 1200, showConfirmButton: false }); },
    { immediate: true }
);
</script>

<template>
    <Head title="Master Sheet" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="text-xl font-semibold text-gray-800 leading-tight">Master Sheet</h2>
                <div class="flex items-center gap-2">
                    <label class="text-sm font-medium text-gray-500">Year:</label>
                    <select
                        :value="selectedYear"
                        @change="changeYear($event.target.value)"
                        class="form-select rounded-md border-gray-300 text-sm py-1 px-3"
                    >
                        <option v-for="year in props.years" :key="year" :value="year">{{ year }}</option>
                    </select>
                </div>
            </div>
        </template>

        <div class="py-4">
            <div class="mx-auto max-w-full px-2 sm:px-4 lg:px-6">

                <div v-if="props.events.length === 0" class="bg-white shadow-sm rounded-lg text-center py-12 text-gray-500">
                    <p class="text-lg">No events found for {{ selectedYear }}.</p>
                </div>

                <template v-else>
                    <!-- Event Tabs -->
                    <div class="bg-white shadow-sm rounded-t-lg border-b border-gray-200">
                        <div class="flex overflow-x-auto" role="tablist">
                            <button
                                v-for="evt in props.events"
                                :key="evt.id"
                                @click="activeTab = evt.id; selectedRow = null; selectedCol = null;"
                                :class="[
                                    'px-5 py-3 text-sm font-medium whitespace-nowrap border-b-2 transition-colors focus:outline-none',
                                    activeTab == evt.id
                                        ? 'border-teal-600 text-teal-700 bg-teal-50'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                ]"
                                role="tab"
                            >
                                {{ evt.event_name }}
                                <span class="ml-1.5 text-xs rounded-full px-2 py-0.5"
                                    :class="activeTab == evt.id ? 'bg-teal-100 text-teal-700' : 'bg-gray-100 text-gray-500'"
                                >
                                    {{ eventLocationCount(evt.id) }}
                                </span>
                            </button>
                        </div>
                    </div>

                    <!-- Spreadsheet Table -->
                    <div class="bg-white shadow-sm rounded-b-lg" @click.self="clearSelection">
                        <div v-if="tabLocations.length === 0" class="text-center py-12 text-gray-500">
                            <p class="text-lg">No locations for this event.</p>
                        </div>
                        <div v-else class="overflow-x-auto">
                            <table class="w-full border-collapse text-xs sheet-table" tabindex="0">
                                <thead>
                                    <tr>
                                        <th class="sheet-th sheet-th-rownum">#</th>
                                        <th v-for="(col, ci) in columns" :key="col.key"
                                            class="sheet-th"
                                            :class="{ 'col-selected': isSelectedCol(ci), 'min-w-[200px]': col.key === 'specific_deliverable_requests' }"
                                        >
                                            {{ col.label }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(loc, ri) in tabLocations" :key="loc.id"
                                        :class="{ 'row-selected': isSelectedRow(ri) }"
                                    >
                                        <!-- Row number -->
                                        <td class="sheet-td-row-num" :class="{ 'row-num-selected': isSelectedRow(ri) }">
                                            {{ ri + 1 }}
                                        </td>

                                        <!-- Data cells -->
                                        <td v-for="(col, ci) in columns" :key="col.key"
                                            class="sheet-td"
                                            :class="{
                                                'cell-active': isSelected(ri, ci),
                                                'col-highlight': isSelectedCol(ci) && !isSelected(ri, ci),
                                                'row-highlight': isSelectedRow(ri) && !isSelected(ri, ci),
                                                'editable': col.editable,
                                                'text-center': col.type === 'checkbox' || col.type === 'number',
                                                'cursor-pointer': col.type === 'checkbox',
                                                'font-medium': col.key === 'film',
                                            }"
                                            @click="handleCellClick(loc, ci, ri)"
                                            @dblclick="handleCellDblClick(loc, ci)"
                                        >
                                            <!-- Editing state -->
                                            <template v-if="editingCell === cellKey(loc.id, col.key)">
                                                <input v-if="col.type === 'text'"
                                                    v-model="editValue" type="text"
                                                    class="cell-editing sheet-input"
                                                    @blur="saveCellEdit(loc.id, col.key)"
                                                    @keydown="handleEditKeydown($event, loc.id, col.key)"
                                                />
                                                <input v-else-if="col.type === 'number'"
                                                    v-model="editValue" type="number" min="0"
                                                    class="cell-editing sheet-input w-16 text-center"
                                                    @blur="saveCellEdit(loc.id, col.key)"
                                                    @keydown="handleEditKeydown($event, loc.id, col.key)"
                                                />
                                                <input v-else-if="col.type === 'date'"
                                                    v-model="editValue" type="date"
                                                    class="cell-editing sheet-input"
                                                    @blur="saveCellEdit(loc.id, col.key)"
                                                    @keydown="handleEditKeydown($event, loc.id, col.key)"
                                                />
                                                <select v-else-if="col.type === 'select'"
                                                    v-model="editValue"
                                                    class="cell-editing sheet-input"
                                                    @blur="saveCellEdit(loc.id, col.key)"
                                                    @change="saveCellEdit(loc.id, col.key)"
                                                >
                                                    <option v-for="opt in col.options" :key="opt" :value="opt">
                                                        {{ opt || '--' }}
                                                    </option>
                                                </select>
                                                <textarea v-else-if="col.type === 'textarea'"
                                                    v-model="editValue" rows="2"
                                                    class="cell-editing sheet-input min-w-[180px]"
                                                    @blur="saveCellEdit(loc.id, col.key)"
                                                    @keydown="handleEditKeydown($event, loc.id, col.key)"
                                                ></textarea>
                                            </template>

                                            <!-- Display state -->
                                            <template v-else>
                                                <!-- Checkbox columns -->
                                                <template v-if="col.type === 'checkbox'">
                                                    <i v-if="loc[col.key]" class="fa-solid fa-check text-green-600"></i>
                                                    <i v-else class="fa-solid fa-xmark text-gray-300"></i>
                                                </template>

                                                <!-- Status with color badge -->
                                                <span v-else-if="col.key === 'status'" :class="{
                                                    'text-green-700 bg-green-50 px-1.5 py-0.5 rounded': loc.status === 'Confirmed',
                                                    'text-yellow-700 bg-yellow-50 px-1.5 py-0.5 rounded': loc.status === 'Pending',
                                                    'text-red-700 bg-red-50 px-1.5 py-0.5 rounded': loc.status === 'Cancelled',
                                                    'text-blue-700 bg-blue-50 px-1.5 py-0.5 rounded': loc.status === 'TBC',
                                                }">{{ loc.status }}</span>

                                                <!-- Deliverable requests (truncated) -->
                                                <span v-else-if="col.key === 'specific_deliverable_requests'"
                                                    class="block max-w-[200px] truncate"
                                                    :title="loc[col.key]"
                                                >{{ loc[col.key] }}</span>

                                                <!-- Normal display -->
                                                <span v-else>{{ displayValue(loc, col) }}</span>
                                            </template>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <!-- Footer -->
                        <div class="px-4 py-2 bg-gray-50 border-t border-gray-200 text-xs text-gray-400 rounded-b-lg flex justify-between">
                            <span>{{ tabLocations.length }} row{{ tabLocations.length !== 1 ? 's' : '' }}
                                <template v-if="selectedRow !== null && selectedCol !== null">
                                    &middot; Cell {{ selectedRow + 1 }}, {{ columns[selectedCol]?.label }}
                                </template>
                            </span>
                            <span v-if="userRole === 'admin'">Click to select &middot; Double-click to edit &middot; Arrow keys to navigate</span>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.sheet-table:focus {
    outline: none;
}

.sheet-th {
    @apply px-2 py-2 text-left text-[10px] font-semibold text-gray-600 uppercase tracking-wider whitespace-nowrap border border-gray-200 bg-gray-100 sticky top-0;
}

.sheet-th.col-selected {
    @apply bg-blue-100 text-blue-700;
}

.sheet-th-rownum {
    @apply w-8 text-center;
}

.sheet-td {
    @apply px-2 py-1.5 whitespace-nowrap border border-gray-200 text-gray-700 select-none;
}

.sheet-td.editable {
    @apply cursor-cell;
}

/* Selected cell — bold blue border like Excel */
.sheet-td.cell-active {
    @apply bg-white;
    box-shadow: inset 0 0 0 2px #3b82f6;
    position: relative;
    z-index: 1;
}

/* Row highlight (light blue band) */
.sheet-td.row-highlight {
    @apply bg-blue-50/40;
}

/* Column highlight */
.sheet-td.col-highlight {
    @apply bg-blue-50/40;
}

.sheet-td-row-num {
    @apply px-2 py-1.5 text-center text-gray-400 bg-gray-50 border border-gray-200 font-mono w-8 select-none;
}

.sheet-td-row-num.row-num-selected {
    @apply bg-blue-100 text-blue-700 font-semibold;
}

.sheet-input {
    @apply w-full border-0 bg-white text-xs p-0.5 focus:ring-2 focus:ring-blue-500 focus:outline-none;
}

/* Row selected — subtle left border accent */
tr.row-selected {
    /* no extra style needed, cells handle it */
}
</style>
