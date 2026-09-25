<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';

/**
 * "Send emails": pick any of the three automated emails — film site check,
 * master sheet sync, weekly digest — and send them now to whoever is typed in.
 *
 * Each ticked email is still sent on its own (one per check, one per tab, one
 * digest); they are never merged into one message. The server rebuilds each
 * from the data as it is at the moment of sending.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    // What to tick when it opens, e.g. { film: 3 } or { weekly: 'this_week' }.
    preselect: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'sent']);

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const split = (value) => (value ?? '').split(/[\s,;]+/).filter(Boolean);

const options = ref(null);
const loadError = ref('');

const film = ref({ on: false, choice: 'all' });
const sheet = ref({ on: false, choice: 'all' });
const weekly = ref({ on: false, choice: 'this_week' });

const to = ref('');
const cc = ref('');
// Once someone edits a field, ticking another email stops rewriting it.
const toTouched = ref(false);
const ccTouched = ref(false);

const sending = ref(false);
const error = ref('');
const results = ref(null);

watch(() => props.show, async (open) => {
    if (!open) return;

    const p = props.preselect ?? {};
    film.value = { on: p.film != null, choice: String(p.film ?? 'all') };
    sheet.value = { on: p.sheet != null, choice: String(p.sheet ?? 'all') };
    weekly.value = { on: p.weekly != null, choice: p.weekly ?? 'this_week' };
    toTouched.value = false;
    ccTouched.value = false;
    error.value = '';
    results.value = null;

    if (!options.value) {
        try {
            options.value = (await axios.get(route('sendEmails.options'))).data;
        } catch (e) {
            loadError.value = e.response?.data?.message ?? 'Could not load the email options.';
            return;
        }
    }

    fillDefaults();
});

/** The usual recipients of every ticked email, merged. */
function fillDefaults() {
    if (!options.value) return;

    const ticked = [
        film.value.on && options.value.film,
        sheet.value.on && options.value.sheet,
        weekly.value.on && options.value.weekly,
    ].filter(Boolean);

    const toList = [...new Set(ticked.flatMap((o) => o.defaults.to))];
    const ccList = [...new Set(ticked.flatMap((o) => o.defaults.cc))].filter((a) => !toList.includes(a));

    if (!toTouched.value) to.value = toList.join(', ');
    if (!ccTouched.value) cc.value = ccList.join(', ');
}

watch(() => [film.value.on, sheet.value.on, weekly.value.on], fillDefaults);

const count = computed(() => {
    let n = 0;
    if (film.value.on) n += film.value.choice === 'all' ? options.value?.film.items.filter((i) => i.enabled).length ?? 0 : 1;
    if (sheet.value.on) n += sheet.value.choice === 'all' ? options.value?.sheet.items.filter((i) => i.enabled).length ?? 0 : 1;
    if (weekly.value.on) n += 1;
    return n;
});

const allOn = computed({
    get: () => film.value.on
        && weekly.value.on
        && (sheet.value.on || !options.value?.sheet.available),
    set: (on) => {
        film.value.on = on;
        weekly.value.on = on;
        if (options.value?.sheet.available) sheet.value.on = on;
    },
});

async function send() {
    error.value = '';

    const toList = split(to.value);
    const ccList = split(cc.value);

    if (!film.value.on && !sheet.value.on && !weekly.value.on) {
        error.value = 'Tick at least one email to send.';
        return;
    }
    if (toList.length === 0) {
        error.value = 'Enter at least one address to send to.';
        return;
    }
    const bad = [...toList, ...ccList].find((a) => !EMAIL.test(a));
    if (bad) {
        error.value = `${bad} is not an email address.`;
        return;
    }

    sending.value = true;

    try {
        const { data } = await axios.post(route('sendEmails.send'), {
            to: toList.join(','),
            cc: ccList.join(','),
            film_check: film.value.on ? film.value.choice : null,
            sheet_source: sheet.value.on ? sheet.value.choice : null,
            weekly_period: weekly.value.on ? weekly.value.choice : null,
        });
        results.value = data.results;
        emit('sent', data.results);
    } catch (e) {
        const errors = e.response?.data?.errors;
        error.value = (errors && Object.values(errors).flat()[0]) || e.response?.data?.message || 'Could not send.';
    } finally {
        sending.value = false;
    }
}

function close() {
    if (!sending.value) emit('close');
}

const statusClass = {
    sent: 'text-green-700',
    skipped: 'text-gray-500',
    failed: 'text-red-700',
};
</script>

<template>
    <Modal :show="show" max-width="lg" :closeable="!sending" @close="close">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-gray-900">Send emails now</h2>
            <p class="mt-1 text-sm text-gray-500">
                Built from the data as it is right now. Each ticked email is sent separately.
            </p>

            <p v-if="loadError" class="mt-4 text-sm text-red-700">{{ loadError }}</p>
            <p v-else-if="!options" class="mt-4 text-sm text-gray-500">Loading…</p>

            <!-- Results after sending -->
            <template v-else-if="results">
                <ul class="mt-4 divide-y divide-gray-100 rounded-md border border-gray-200">
                    <li v-for="(r, i) in results" :key="i" class="flex items-start justify-between gap-3 px-3 py-2 text-sm">
                        <span class="text-gray-800">{{ r.email }}</span>
                        <span :class="statusClass[r.status]" class="text-right">
                            <strong class="capitalize">{{ r.status }}</strong>
                            <template v-if="r.status !== 'sent'"> — {{ r.message }}</template>
                        </span>
                    </li>
                    <li v-if="results.length === 0" class="px-3 py-2 text-sm text-gray-500">Nothing matched — no emails were sent.</li>
                </ul>
                <div class="mt-6 flex justify-end">
                    <button @click="close" class="rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-900">Done</button>
                </div>
            </template>

            <template v-else>
                <div class="mt-4 space-y-3">
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                        <input type="checkbox" v-model="allOn" class="rounded border-gray-300">
                        Send all
                    </label>

                    <div class="rounded-md border border-gray-200 p-3 space-y-2">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-800">
                            <input type="checkbox" v-model="film.on" class="rounded border-gray-300">
                            Film site check
                        </label>
                        <select v-if="film.on" v-model="film.choice" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="all">All active checks (one email each)</option>
                            <option v-for="c in options.film.items" :key="c.id" :value="String(c.id)">{{ c.label }}</option>
                        </select>
                    </div>

                    <div v-if="options.sheet.available" class="rounded-md border border-gray-200 p-3 space-y-2">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-800">
                            <input type="checkbox" v-model="sheet.on" class="rounded border-gray-300">
                            Master sheet sync
                        </label>
                        <select v-if="sheet.on" v-model="sheet.choice" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="all">All active tabs (one email each)</option>
                            <option v-for="s in options.sheet.items" :key="s.id" :value="String(s.id)">{{ s.label }}</option>
                        </select>
                    </div>

                    <div class="rounded-md border border-gray-200 p-3 space-y-2">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-800">
                            <input type="checkbox" v-model="weekly.on" class="rounded border-gray-300">
                            Weekly digest
                        </label>
                        <select v-if="weekly.on" v-model="weekly.choice" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="this_week">This week so far</option>
                            <option value="last_week">Last week</option>
                        </select>
                    </div>
                </div>

                <div class="mt-5 space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Send to</label>
                        <input v-model="to" @input="toTouched = true" type="text" placeholder="name@example.com"
                               class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">CC <span class="font-normal text-gray-400">(optional)</span></label>
                        <input v-model="cc" @input="ccTouched = true" type="text" placeholder="name@example.com"
                               class="mt-1 w-full rounded-md border-gray-300 text-sm">
                        <p class="mt-1 text-xs text-gray-400">Separate several addresses with commas.</p>
                    </div>
                </div>

                <p v-if="error" class="mt-4 text-sm text-red-700">{{ error }}</p>
                <p v-if="sending" class="mt-4 text-sm text-gray-500">
                    Re-checking and sending — this can take a minute when several sites or tabs are ticked…
                </p>

                <div class="mt-6 flex justify-end gap-2">
                    <button @click="close" :disabled="sending"
                            class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40">
                        Cancel
                    </button>
                    <button @click="send" :disabled="sending || count === 0"
                            class="rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-900 disabled:opacity-40">
                        {{ sending ? 'Sending…' : `Send ${count} email${count === 1 ? '' : 's'}` }}
                    </button>
                </div>
            </template>
        </div>
    </Modal>
</template>
