<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted, onBeforeUnmount } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Swal from 'sweetalert2';
import axios from 'axios';

const props = defineProps({
    configured: { type: Boolean, default: false },
    listId: { type: String, default: '' },
    defaults: { type: Object, default: () => ({ send_time: '13:00', timezone: 'Australia/Sydney' }) },
    timezones: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

// --- Columns (the planning sheet's order, plus a Send Time next to Send Date) ---

const columns = [
    { key: 'year', label: 'Year', editable: true, type: 'number', width: 'w-14' },
    { key: 'film_tour', label: 'Film Tour', editable: true, type: 'text' },
    { key: 'location', label: 'Location', editable: true, type: 'text' },
    { key: 'screening_date', label: 'Screening Date', editable: true, type: 'date', format: 'date' },
    { key: 'tag', label: 'Tag', editable: true, type: 'text' },
    { key: 'trigger_event', label: 'Trigger Event', editable: true, type: 'text' },
    { key: 'send_date', label: 'Send Date', editable: true, type: 'date', format: 'date' },
    { key: 'send_time', label: 'Send Time', editable: true, type: 'time', format: 'time' },
    { key: 'status', label: 'Status', editable: false },
    { key: 'sms_text', label: 'SMS Text (Max 160 chars)', editable: true, type: 'textarea' },
    { key: 'link', label: 'Bitly Ticket Link', editable: true, type: 'text' },
    { key: 'mailchimp_url', label: 'Mailchimp SMS Link', editable: false },
    { key: 'recipient_count', label: 'Data Count', editable: false, align: 'right' },
    { key: 'credits', label: 'Credits', editable: false, align: 'right' },
];

const STATUS = {
    ready:           { label: 'Ready',            cls: 'text-teal-800 bg-teal-50' },
    needs_attention: { label: 'Needs attention',  cls: 'text-amber-800 bg-amber-50' },
    queued:          { label: 'Working…',         cls: 'text-blue-800 bg-blue-50' },
    draft:           { label: 'Draft',            cls: 'text-gray-700 bg-gray-100' },
    scheduled:       { label: 'Scheduled',        cls: 'text-green-800 bg-green-50' },
    sent:            { label: 'Sent',             cls: 'text-green-900 bg-green-100' },
    failed:          { label: 'Failed',           cls: 'text-red-800 bg-red-50' },
    external:        { label: 'Scheduled by hand', cls: 'text-purple-800 bg-purple-50' },
};

// Rows already handed to Mailchimp (or queued for it) can't be edited in place.
const locked = row => ['queued', 'scheduled', 'sent', 'external'].includes(row.status);

// --- Defaults: send time, timezone ---------------------------------------------

const sendTime = ref(props.defaults.send_time);
const timezone = ref(props.defaults.timezone);

// Mailchimp charges several credits per message segment for Australian
// numbers (4 at the time of writing) — not one. Remembered per browser.
const creditsPerSegment = ref(props.defaults.credits_per_segment ?? 4);
try { const v = parseInt(localStorage.getItem('paw_sms_credits_per_segment') || ''); if (v > 0) creditsPerSegment.value = v; } catch { /* private mode */ }
watch(creditsPerSegment, v => { try { localStorage.setItem('paw_sms_credits_per_segment', String(v)); } catch { /* ignore */ } });

// --- Credits -------------------------------------------------------------------

// What lands on the phone is Mailchimp's company prefix + the body + the
// opt-out line (both compulsory in Australia), and that whole thing is what
// 160 applies to. Links in the body are already the app's short ones.
// 160 chars = 1 segment, then one more per 153; each segment is charged per
// recipient. (Mailchimp's estimated_segments is 0 for API content — unusable.)
const overhead = (props.defaults.sms_prefix || '').length + (props.defaults.sms_suffix || '').length;
const deliveredLength = row => overhead + (row.message_body || row.sms_text || '').length;
function segments(row) {
    const len = deliveredLength(row);
    return len <= 160 ? 1 : Math.ceil(len / 153);
}
// Real recipients from Mailchimp beat the sheet's estimate.
const recipients = row => row.recipient_count ?? row.sheet_data_count ?? null;
const credits = row => recipients(row) === null ? null : recipients(row) * segments(row) * (creditsPerSegment.value || 1);

// --- Filter & selection ---------------------------------------------------------

const filter = ref('all');
const counts = computed(() => {
    const c = { all: props.rows.length };
    for (const r of props.rows) c[r.status] = (c[r.status] ?? 0) + 1;
    return c;
});
const visibleRows = computed(() => filter.value === 'all' ? props.rows : props.rows.filter(r => r.status === filter.value));

// Credits by where each row is in its life: still to schedule, booked, gone.
// "Needed" is only what has NOT been scheduled yet — Mailchimp has already
// taken the credits for anything scheduled or sent.
const totals = computed(() => {
    const t = { rec: 0, cr: 0, pending: 0, scheduled: 0, sent: 0, pendingRows: 0, scheduledRows: 0, sentRows: 0 };
    for (const r of visibleRows.value) {
        const c = credits(r) ?? 0;
        t.rec += recipients(r) ?? 0;
        t.cr += c;
        if (r.status === 'sent') { t.sent += c; t.sentRows++; }
        else if (r.status === 'scheduled' || r.status === 'external') { t.scheduled += c; t.scheduledRows++; }
        else { t.pending += c; t.pendingRows++; }
    }
    t.needed = t.pending;
    return t;
});

const selected = ref([]);
const isChecked = id => selected.value.includes(id);
function toggleChecked(id) {
    selected.value = isChecked(id) ? selected.value.filter(x => x !== id) : [...selected.value, id];
}
const allVisibleChecked = computed(() => visibleRows.value.length > 0 && visibleRows.value.every(r => isChecked(r.id)));
function toggleAllChecked() {
    selected.value = allVisibleChecked.value
        ? selected.value.filter(id => !visibleRows.value.some(r => r.id === id))
        : [...new Set([...selected.value, ...visibleRows.value.map(r => r.id)])];
}
watch(() => props.rows, rows => {
    const ids = new Set(rows.map(r => r.id));
    selected.value = selected.value.filter(id => ids.has(id));
});

const selectedRows = computed(() => props.rows.filter(r => isChecked(r.id)));
const canDraft = computed(() => selectedRows.value.some(r => ['ready', 'draft', 'failed'].includes(r.status)));
const canCancel = computed(() => selectedRows.value.some(r => r.status === 'scheduled'));
const canRefresh = computed(() => selectedRows.value.some(r => r.mailchimp_campaign_id));
const canDeleteCampaign = computed(() => selectedRows.value.some(r => ['draft', 'failed'].includes(r.status) && r.mailchimp_campaign_id));
const canRemove = computed(() => selectedRows.value.some(r => !['scheduled', 'queued'].includes(r.status)));

// --- Bulk actions --------------------------------------------------------------

const acting = ref(false);

async function act(action) {
    const ids = selectedRows.value.map(r => r.id);
    if (!ids.length) return;

    const confirmations = {
        schedule: { title: `Schedule ${ids.length} SMS in Mailchimp?`, text: 'Each becomes a real, timed campaign. You can cancel from here until it starts sending.', confirmButtonText: 'Schedule' },
        delete_campaign: { title: `Delete ${ids.length} draft(s) from Mailchimp?`, text: 'The rows stay here and go back to Ready.', confirmButtonText: 'Delete drafts' },
        remove: { title: `Remove ${ids.length} row(s)?`, text: 'Only removes them from this grid. Anything already in Mailchimp stays there.', confirmButtonText: 'Remove' },
        cancel: { title: `Unschedule ${ids.length} send(s)?`, text: 'Mailchimp cannot unschedule an SMS, so the campaign is deleted there. The row comes back as Ready and Schedule creates a fresh one.', confirmButtonText: 'Unschedule' },
    };
    if (confirmations[action]) {
        const res = await Swal.fire({ icon: 'warning', showCancelButton: true, ...confirmations[action] });
        if (!res.isConfirmed) return;
    }

    router.post(route('sms.actions'), { ids, action }, {
        preserveScroll: true,
        onStart: () => { acting.value = true; },
        onFinish: () => { acting.value = false; },
        onSuccess: () => { if (action === 'remove') selected.value = []; },
    });
}

function addRow() {
    router.post(route('sms.store'), { send_time: sendTime.value, timezone: timezone.value }, {
        preserveScroll: true,
        onSuccess: () => nextTick(() => {
            // Land on the new row's Film Tour cell, ready to type.
            const idx = visibleRows.value.length - 1;
            if (idx >= 0) { selectedRow.value = idx; selectedCol.value = 1; }
        }),
    });
}

// --- Paste from the sheet ------------------------------------------------------

const pasting = ref(false);

function submitPaste(text) {
    if (!text || !text.trim()) return;
    router.post(route('sms.paste'), { text, send_time: sendTime.value, timezone: timezone.value }, {
        preserveScroll: true,
        onStart: () => { pasting.value = true; },
        onFinish: () => { pasting.value = false; },
    });
}

// Ctrl/Cmd+V anywhere on the grid (not inside a cell being edited) brings the
// copied sheet rows in. Cell-level paste while editing is left to the browser.
function handlePaste(e) {
    if (editingCell.value) return;
    const text = e.clipboardData?.getData('text/plain') ?? '';
    if (!text.includes('\t')) return; // not tabular — nothing to import
    e.preventDefault();
    submitPaste(text);
}

async function pasteDialog() {
    const { value } = await Swal.fire({
        title: 'Paste rows from the sheet',
        input: 'textarea',
        inputAttributes: { rows: 8, style: 'font-family: monospace; font-size: 12px;' },
        inputPlaceholder: 'Select whole rows in the sheet (Year → Data Count), copy, paste here. With or without the heading row.',
        showCancelButton: true,
        confirmButtonText: 'Add rows',
        width: '48rem',
    });
    if (value) submitPaste(value);
}

// --- Copy Mailchimp links back to the sheet ---------------------------------------

// Rows to copy: the ticked ones, or every visible row if nothing is ticked.
const copyTarget = computed(() => selectedRows.value.length ? selectedRows.value : visibleRows.value);

async function copyText(text, what) {
    try {
        await navigator.clipboard.writeText(text);
        Swal.fire({ icon: 'success', title: 'Copied', text: what, timer: 1500, showConfirmButton: false });
    } catch {
        // Clipboard blocked (non-https, permissions) — show it for manual copy.
        Swal.fire({ title: what, input: 'textarea', inputValue: text, inputAttributes: { rows: 12, readonly: true, style: 'font-family: monospace; font-size: 12px;' }, width: '48rem', showConfirmButton: false, showCloseButton: true });
    }
}

// Just the URLs, one per line, blank where a row has none — grid order — so it
// pastes straight down the sheet's "Mailchimp SMS Link" column.
function copyLinksColumn() {
    const rows = copyTarget.value;
    copyText(rows.map(r => r.mailchimp_url ?? '').join('\n'), `${rows.filter(r => r.mailchimp_url).length} link(s) for ${rows.length} row(s), in grid order`);
}

// A readable list for sharing or checking against Mailchimp.
function showLinksList() {
    const rows = copyTarget.value.filter(r => r.mailchimp_url);
    const text = rows.map(r => `${r.film_tour}\t${r.location}\t${r.trigger_event ?? ''}\t${r.send_date} ${r.send_time}\t${r.recipient_count ?? ''}\t${r.mailchimp_url}`).join('\n');
    Swal.fire({
        title: `${rows.length} Mailchimp link${rows.length === 1 ? '' : 's'}`,
        html: `<p class="text-left text-xs text-gray-500 mb-2">Tour · Location · Trigger · Send · Recipients · Link — tab-separated, pastes as columns.</p>`
            + `<textarea id="paw-links" rows="14" readonly style="width:100%;font-family:monospace;font-size:12px;white-space:pre;overflow:auto">${text.replace(/</g, '&lt;')}</textarea>`,
        width: '56rem',
        showCancelButton: true,
        confirmButtonText: 'Copy all',
        cancelButtonText: 'Close',
    }).then(res => { if (res.isConfirmed) copyText(text, `${rows.length} row(s) with links`); });
}

// Poll while any row is queued so status flips without a manual refresh.
let timer = null;
watch(() => props.rows.some(r => r.status === 'queued'), queued => {
    if (queued && !timer) timer = setInterval(() => router.reload({ only: ['rows'] }), 4000);
    if (!queued && timer) { clearInterval(timer); timer = null; }
}, { immediate: true });
onBeforeUnmount(() => { if (timer) clearInterval(timer); });

// --- Cell selection & keyboard (same model as the master sheet) ------------------

const selectedRow = ref(null);
const selectedCol = ref(null);
const editingCell = ref(null);
const editValue = ref('');
const saving = ref({});

function selectCell(ri, ci) { selectedRow.value = ri; selectedCol.value = ci; }
const isActive = (ri, ci) => selectedRow.value === ri && selectedCol.value === ci;
const isSelectedRow = ri => selectedRow.value === ri;
const isSelectedCol = ci => selectedCol.value === ci;
function clearSelection() {
    if (editingCell.value) return;
    selectedRow.value = null;
    selectedCol.value = null;
}

function handleKeydown(e) {
    if (editingCell.value) return;
    if (selectedRow.value === null || selectedCol.value === null) return;
    if (e.metaKey || e.ctrlKey) return; // leave copy/paste shortcuts alone

    const maxRow = visibleRows.value.length - 1;
    const maxCol = columns.length - 1;

    if (e.key === 'ArrowDown') { e.preventDefault(); selectedRow.value = Math.min(selectedRow.value + 1, maxRow); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); selectedRow.value = Math.max(selectedRow.value - 1, 0); }
    else if (e.key === 'ArrowRight' || e.key === 'Tab') { e.preventDefault(); selectedCol.value = Math.min(selectedCol.value + 1, maxCol); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); selectedCol.value = Math.max(selectedCol.value - 1, 0); }
    else if (e.key === 'Enter') {
        e.preventDefault();
        const col = columns[selectedCol.value];
        const row = visibleRows.value[selectedRow.value];
        if (row && col.editable) startCellEdit(row, col);
    }
    else if (e.key === 'Escape') clearSelection();
}
onMounted(() => document.addEventListener('keydown', handleKeydown));
onUnmounted(() => document.removeEventListener('keydown', handleKeydown));

// --- Cell editing ----------------------------------------------------------------

const cellKey = (id, field) => `${id}-${field}`;

function startCellEdit(row, col) {
    if (locked(row)) return;
    editingCell.value = cellKey(row.id, col.key);
    editValue.value = row[col.key] ?? '';
    nextTick(() => document.querySelector('.cell-editing')?.focus());
}

function handleCellClick(row, ci, ri) {
    selectCell(ri, ci);
}
function handleCellDblClick(row, ci) {
    const col = columns[ci];
    if (col.editable) startCellEdit(row, col);
}

async function saveCellEdit(row, field) {
    if (editingCell.value !== cellKey(row.id, field)) return;
    let val = editValue.value;
    if (field === 'year') val = val === '' ? null : parseInt(val);
    if (val === '') val = null;

    // Nothing changed — just close the editor.
    if ((row[field] ?? null) === val) { cancelCellEdit(); return; }

    saving.value[row.id] = true;
    try {
        await axios.patch(route('sms.update', { smsSchedule: row.id }), { [field]: val }, { headers: { Accept: 'application/json' } });
        editingCell.value = null;
        editValue.value = '';
        router.reload({ only: ['rows'] });
    } catch (e) {
        const msg = e.response?.data?.message ?? 'Failed to save.';
        Swal.fire({ icon: 'error', title: 'Not saved', text: msg });
    } finally {
        saving.value[row.id] = false;
    }
}

function cancelCellEdit() {
    editingCell.value = null;
    editValue.value = '';
}

function handleEditKeydown(e, row, field) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); saveCellEdit(row, field); }
    else if (e.key === 'Escape') cancelCellEdit();
    else if (e.key === 'Tab') { e.preventDefault(); saveCellEdit(row, field); }
}

// --- Display -----------------------------------------------------------------------

function formatDate(str) {
    if (!str) return '';
    try { return new Date(str + 'T00:00:00').toLocaleDateString('en-AU', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }); }
    catch { return str; }
}
function formatTime(str) {
    if (!str) return '';
    const [hh, mm] = str.split(':');
    let h = parseInt(hh); const period = h >= 12 ? 'pm' : 'am';
    if (h === 0) h = 12; else if (h > 12) h -= 12;
    return `${h}:${mm} ${period}`;
}
function displayValue(row, col) {
    const v = row[col.key];
    if (col.format === 'date') return formatDate(v);
    if (col.format === 'time') return formatTime(v);
    return v ?? '';
}
const charCount = row => deliveredLength(row);
function lengthNote(row) {
    const n = segments(row);
    return n === 1 ? `${charCount(row)}/160 as delivered` : `${charCount(row)}/160 = ${n} segments (${n}× credits)`;
}
const errorsFor = row => (row.issues || []).filter(i => i.level === 'error').map(i => i.text).concat(row.error ? [row.error] : []);
const warningsFor = row => (row.issues || []).filter(i => i.level === 'warning').map(i => i.text);
const issueTitle = row => [...errorsFor(row), ...warningsFor(row)].join('\n');
</script>

<template>
    <Head title="SMS" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">SMS scheduler</h2>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <label class="flex items-center gap-1.5 text-gray-600">
                        Default send time
                        <input v-model="sendTime" type="time" class="rounded-md border-gray-300 py-1 text-sm" />
                    </label>
                    <label class="flex items-center gap-1.5 text-gray-600">
                        Timezone
                        <select v-model="timezone" class="rounded-md border-gray-300 py-1 text-sm">
                            <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                        </select>
                    </label>
                    <label class="flex items-center gap-1.5 text-gray-600" title="Mailchimp credits charged per SMS segment to this country. Australia: 4.">
                        Credits / SMS
                        <input v-model.number="creditsPerSegment" type="number" min="1" max="20" class="w-16 rounded-md border-gray-300 py-1 text-sm" />
                    </label>
                </div>
            </div>
        </template>

        <div class="py-4">
            <div class="mx-auto max-w-full space-y-3 px-2 sm:px-4 lg:px-6">

                <div v-if="!configured" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    Mailchimp ANZ isn't configured for SMS: set <code>MAILCHIMP_API_KEY</code>, <code>MAILCHIMP_SERVER_PREFIX</code> and <code>MAILCHIMP_SMS_LIST_ID</code>.
                </div>
                <div v-if="/localhost|127\.0\.0\.1|\[::1\]/.test(defaults.short_link_base || '')" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    <span class="font-semibold">Short links currently point at {{ defaults.short_link_base }}</span> — a phone can't open that.
                    Don't schedule from here: set <code>SHORT_LINK_BASE_URL</code> (or <code>APP_URL</code>) to the public domain of the server that holds this database, or run the scheduler on that server.
                </div>
                <div v-if="flash.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">{{ flash.error }}</div>
                <div v-if="flash.warning" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">{{ flash.warning }}</div>
                <div v-if="flash.success" class="rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">{{ flash.success }}</div>

                <!-- Toolbar -->
                <div class="flex flex-wrap items-center gap-2 rounded-t-lg border-b border-gray-200 bg-white px-3 py-2 shadow-sm">
                    <button type="button" @click="pasteDialog" :disabled="pasting || !configured"
                            class="rounded-md bg-gray-800 px-3 py-1.5 text-xs font-medium text-white hover:bg-gray-900 disabled:opacity-40">
                        {{ pasting ? 'Reading…' : 'Paste from sheet' }}
                    </button>
                    <button type="button" @click="addRow" :disabled="!configured"
                            class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40">
                        + Add row
                    </button>

                    <span class="mx-2 h-5 w-px bg-gray-200"></span>
                    <span class="text-xs text-gray-500">{{ selected.length }} selected</span>

                    <button type="button" @click="act('draft')" :disabled="acting || !canDraft"
                            class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40"
                            title="Creates the campaign in Mailchimp with its audience and text but no send time — a safe rehearsal.">
                        Create drafts
                    </button>
                    <button type="button" @click="act('schedule')" :disabled="acting || !canDraft"
                            class="rounded-md bg-teal-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-teal-700 disabled:opacity-40">
                        Schedule
                    </button>
                    <button type="button" @click="act('cancel')" :disabled="acting || !canCancel"
                            class="rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-800 hover:bg-amber-100 disabled:opacity-40">
                        Unschedule
                    </button>
                    <button type="button" @click="act('refresh')" :disabled="acting || !canRefresh"
                            class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40">
                        Refresh
                    </button>
                    <button type="button" @click="act('delete_campaign')" :disabled="acting || !canDeleteCampaign"
                            class="rounded-md border border-red-300 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50 disabled:opacity-40">
                        Delete draft in Mailchimp
                    </button>
                    <button type="button" @click="act('remove')" :disabled="acting || !canRemove"
                            class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-500 hover:bg-gray-50 disabled:opacity-40">
                        Remove row
                    </button>

                    <span class="mx-2 h-5 w-px bg-gray-200"></span>
                    <button type="button" @click="copyLinksColumn" :disabled="!rows.length"
                            class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40"
                            title="Copies the Mailchimp link (or a blank) for each row in grid order — paste it down the sheet's Mailchimp SMS Link column. Ticked rows only, if any are ticked.">
                        Copy links column
                    </button>
                    <button type="button" @click="showLinksList" :disabled="!rows.some(r => r.mailchimp_url)"
                            class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40">
                        List links
                    </button>

                    <div class="ml-auto flex flex-wrap gap-1 text-xs">
                        <button type="button" @click="filter = 'all'"
                                :class="['rounded-full border px-2.5 py-0.5', filter === 'all' ? 'border-gray-800 bg-gray-800 text-white' : 'border-gray-300 text-gray-700 hover:bg-gray-50']">
                            All {{ counts.all }}
                        </button>
                        <button v-for="(meta, key) in STATUS" :key="key" v-show="counts[key]" type="button" @click="filter = key"
                                :class="['rounded-full border px-2.5 py-0.5', filter === key ? 'border-gray-800 bg-gray-800 text-white' : 'border-gray-300 text-gray-700 hover:bg-gray-50']">
                            {{ meta.label }} {{ counts[key] }}
                        </button>
                    </div>
                </div>

                <!-- Grid -->
                <div class="rounded-b-lg bg-white shadow-sm" @click.self="clearSelection" @paste="handlePaste">
                    <div v-if="!rows.length" class="py-12 text-center text-sm text-gray-500">
                        Empty. Copy rows from the planning sheet and press <kbd class="rounded border border-gray-300 bg-gray-50 px-1">⌘V</kbd> here,
                        use <span class="font-medium">Paste from sheet</span>, or <span class="font-medium">Add row</span> and type.
                    </div>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full border-collapse text-xs sheet-table" tabindex="0">
                            <thead>
                                <tr>
                                    <th class="sheet-th sheet-th-rownum">#</th>
                                    <th class="sheet-th w-8 text-center">
                                        <input type="checkbox" :checked="allVisibleChecked" @change="toggleAllChecked" class="rounded border-gray-300" />
                                    </th>
                                    <th v-for="(col, ci) in columns" :key="col.key" class="sheet-th"
                                        :class="{ 'col-selected': isSelectedCol(ci), 'min-w-[260px]': col.key === 'sms_text', 'min-w-[150px]': col.key === 'tag' }">
                                        {{ col.label }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, ri) in visibleRows" :key="row.id" :class="{ 'opacity-60': saving[row.id] }">
                                    <td class="sheet-td-row-num" :class="{ 'row-num-selected': isSelectedRow(ri) }">{{ ri + 1 }}</td>
                                    <td class="sheet-td text-center">
                                        <input type="checkbox" :checked="isChecked(row.id)" @change="toggleChecked(row.id)" class="rounded border-gray-300" />
                                    </td>

                                    <td v-for="(col, ci) in columns" :key="col.key" class="sheet-td"
                                        :class="{
                                            'cell-active': isActive(ri, ci),
                                            'col-highlight': isSelectedCol(ci) && !isActive(ri, ci),
                                            'row-highlight': isSelectedRow(ri) && !isActive(ri, ci),
                                            'editable': col.editable && !locked(row),
                                            'cell-locked': locked(row) && col.editable,
                                            'text-right tabular-nums': col.align === 'right',
                                            'whitespace-normal': col.key === 'sms_text',
                                        }"
                                        @click="handleCellClick(row, ci, ri)"
                                        @dblclick="handleCellDblClick(row, ci)">

                                        <!-- Editing -->
                                        <template v-if="editingCell === cellKey(row.id, col.key)">
                                            <input v-if="col.type === 'text'" v-model="editValue" type="text" class="cell-editing sheet-input"
                                                   @blur="saveCellEdit(row, col.key)" @keydown="handleEditKeydown($event, row, col.key)" />
                                            <input v-else-if="col.type === 'number'" v-model="editValue" type="number" class="cell-editing sheet-input w-16"
                                                   @blur="saveCellEdit(row, col.key)" @keydown="handleEditKeydown($event, row, col.key)" />
                                            <input v-else-if="col.type === 'date'" v-model="editValue" type="date" class="cell-editing sheet-input"
                                                   @blur="saveCellEdit(row, col.key)" @keydown="handleEditKeydown($event, row, col.key)" />
                                            <input v-else-if="col.type === 'time'" v-model="editValue" type="time" class="cell-editing sheet-input"
                                                   @blur="saveCellEdit(row, col.key)" @keydown="handleEditKeydown($event, row, col.key)" />
                                            <textarea v-else-if="col.type === 'textarea'" v-model="editValue" rows="3" class="cell-editing sheet-input min-w-[260px]"
                                                      @blur="saveCellEdit(row, col.key)" @keydown="handleEditKeydown($event, row, col.key)"></textarea>
                                        </template>

                                        <!-- Display -->
                                        <template v-else>
                                            <template v-if="col.key === 'status'">
                                                <span class="rounded px-1.5 py-0.5 font-medium" :class="STATUS[row.status]?.cls" :title="issueTitle(row)">
                                                    {{ STATUS[row.status]?.label ?? row.status }}
                                                </span>
                                                <div v-for="(t, i) in errorsFor(row)" :key="'e' + i" class="mt-0.5 max-w-[220px] whitespace-normal text-[11px] leading-tight text-red-700">{{ t }}</div>
                                                <div v-for="(t, i) in warningsFor(row)" :key="'w' + i" class="mt-0.5 max-w-[220px] whitespace-normal text-[11px] leading-tight text-amber-700">{{ t }}</div>
                                            </template>

                                            <template v-else-if="col.key === 'tag'">
                                                <span>{{ row.tag }}</span>
                                                <div v-if="row.segment_name" class="text-[10px] text-teal-700">→ {{ row.segment_name }}<span v-if="row.segment_id" class="text-gray-400"> #{{ row.segment_id }}</span></div>
                                            </template>

                                            <template v-else-if="col.key === 'sms_text'">
                                                <div class="max-w-md whitespace-normal break-words">{{ row.message_body || row.sms_text }}</div>
                                                <div class="text-[10px]" :class="segments(row) > 1 ? 'text-amber-700' : 'text-gray-400'">{{ lengthNote(row) }}</div>
                                            </template>

                                            <a v-else-if="col.key === 'mailchimp_url' && row.mailchimp_url" :href="row.mailchimp_url" target="_blank" rel="noopener"
                                               class="text-teal-700 underline" @click.stop>Open in Mailchimp ↗</a>

                                            <template v-else-if="col.key === 'recipient_count'">
                                                <span v-if="row.recipient_count !== null" :title="'From Mailchimp'">{{ row.recipient_count.toLocaleString() }}</span>
                                                <span v-else-if="row.sheet_data_count !== null" class="text-gray-400" :title="'Sheet estimate — Mailchimp fills the real count once scheduled'">~{{ row.sheet_data_count.toLocaleString() }}</span>
                                            </template>

                                            <span v-else-if="col.key === 'credits'" :class="{ 'text-gray-400': row.recipient_count === null }" :title="`${recipients(row) ?? '?'} recipients × ${segments(row)} segment(s) × ${creditsPerSegment} credits`">
                                                {{ credits(row) === null ? '' : credits(row).toLocaleString() }}
                                            </span>

                                            <span v-else-if="col.key === 'link'" class="block max-w-[200px] truncate" :title="row.link">{{ row.link }}</span>

                                            <span v-else>{{ displayValue(row, col) }}</span>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="bg-gray-50 text-[11px] text-gray-600">
                                    <td colspan="14" class="border border-gray-200 px-2 py-1.5 text-right font-medium">Total for {{ visibleRows.length }} row{{ visibleRows.length === 1 ? '' : 's' }}</td>
                                    <td class="border border-gray-200 px-2 py-1.5 text-right tabular-nums">{{ totals.rec.toLocaleString() }}</td>
                                    <td class="border border-gray-200 px-2 py-1.5 text-right font-semibold tabular-nums text-gray-800">{{ totals.cr.toLocaleString() }} credits</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div v-if="rows.length" class="flex flex-wrap items-baseline gap-x-6 gap-y-1 border-t border-gray-200 bg-white px-4 py-3 text-sm">
                        <span class="text-gray-900">
                            <span class="font-semibold">Credits needed: {{ totals.needed.toLocaleString() }}</span>
                            <span class="text-gray-500"> for {{ totals.pendingRows }} row{{ totals.pendingRows === 1 ? '' : 's' }} not yet scheduled</span>
                        </span>
                        <span v-if="totals.scheduled" class="text-gray-500">Already scheduled: {{ totals.scheduled.toLocaleString() }} ({{ totals.scheduledRows }} rows, credits taken)</span>
                        <span v-if="totals.sent" class="text-gray-500">Sent: {{ totals.sent.toLocaleString() }}</span>
                        <span class="text-xs text-gray-400">Recipients × segments × {{ creditsPerSegment }} credits per SMS. Lengths include Mailchimp's "{{ defaults.sms_prefix }}" prefix and opt-out line; long links are replaced with {{ defaults.short_link_base }}/s/… automatically.</span>
                    </div>
                    <div class="flex flex-wrap justify-between gap-2 rounded-b-lg border-t border-gray-200 bg-gray-50 px-4 py-2 text-xs text-gray-400">
                        <span>
                            Times are {{ timezone }} · <span class="font-medium">Create drafts</span> puts the campaign in Mailchimp untimed; <span class="font-medium">Schedule</span> books it. The app never presses send.
                            Pushing needs the queue worker (<code>composer dev</code>).
                        </span>
                        <span>Click to select · Double-click or Enter to edit · Arrows to move · ⌘V to paste sheet rows</span>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.sheet-table:focus { outline: none; }

.sheet-th {
    @apply px-2 py-2 text-left text-[10px] font-semibold text-gray-600 uppercase tracking-wider whitespace-nowrap border border-gray-200 bg-gray-100 sticky top-0;
}
.sheet-th.col-selected { @apply bg-blue-100 text-blue-700; }
.sheet-th-rownum { @apply w-8 text-center; }

.sheet-td {
    @apply px-2 py-1.5 whitespace-nowrap border border-gray-200 text-gray-700 select-none align-top;
}
.sheet-td.editable { @apply cursor-cell; }
.sheet-td.cell-locked { @apply bg-gray-50 text-gray-500; }
.sheet-td.cell-active {
    @apply bg-white;
    box-shadow: inset 0 0 0 2px #3b82f6;
    position: relative;
    z-index: 1;
}
.sheet-td.row-highlight, .sheet-td.col-highlight { @apply bg-blue-50/40; }

.sheet-td-row-num {
    @apply px-2 py-1.5 text-center text-gray-400 bg-gray-50 border border-gray-200 font-mono w-8 select-none;
}
.sheet-td-row-num.row-num-selected { @apply bg-blue-100 text-blue-700 font-semibold; }

.sheet-input {
    @apply w-full border-0 bg-white text-xs p-0.5 focus:ring-2 focus:ring-blue-500 focus:outline-none;
}
</style>
