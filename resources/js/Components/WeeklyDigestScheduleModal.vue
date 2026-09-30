<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';

/**
 * When the automated weekly digest is sent: a weekday, a time and the timezone
 * that time is in.
 *
 * The default (from .env) is always shown under the form so whoever is changing
 * it can see what they are moving away from, and "Reset to default" goes back
 * to it. Saving takes effect on the next scheduler tick — there is nothing to
 * deploy or restart.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'saved']);

const data = ref(null);
const loadError = ref('');
const form = ref({ day: 1, time: '08:00', timezone: 'UTC' });
const saving = ref(false);
const error = ref('');
const notice = ref('');

watch(() => props.show, async (open) => {
    if (!open) return;
    error.value = '';
    notice.value = '';
    await load();
});

async function load() {
    data.value = null;
    loadError.value = '';
    try {
        data.value = (await axios.get(route('weeklyDigest.schedule.show'))).data;
        fill(data.value.schedule);
    } catch (e) {
        loadError.value = e.response?.data?.message ?? 'Could not load the schedule.';
    }
}

function fill(schedule) {
    form.value = { day: schedule.day, time: schedule.time, timezone: schedule.timezone };
}

const dirty = computed(() => {
    const s = data.value?.schedule;
    return !!s && (s.day !== Number(form.value.day) || s.time !== form.value.time || s.timezone !== form.value.timezone);
});

const isDefault = computed(() => {
    const d = data.value?.default;
    return !!d && d.day === Number(form.value.day) && d.time === form.value.time && d.timezone === form.value.timezone;
});

function useDefault() {
    if (data.value?.default) fill(data.value.default);
}

async function save() {
    error.value = '';
    notice.value = '';
    saving.value = true;
    try {
        // Picking the default values is the same as resetting: no override row
        // is left behind, so a later change to .env still takes effect.
        const { data: fresh } = isDefault.value
            ? await axios.delete(route('weeklyDigest.schedule.reset'))
            : await axios.put(route('weeklyDigest.schedule.update'), {
                day: Number(form.value.day),
                time: form.value.time,
                timezone: form.value.timezone,
            });
        data.value = fresh;
        fill(fresh.schedule);
        notice.value = `Saved. Next digest: ${fresh.schedule.next_run_label}.`;
        emit('saved', fresh.schedule);
    } catch (e) {
        const errors = e.response?.data?.errors;
        error.value = (errors && Object.values(errors).flat()[0]) || e.response?.data?.message || 'Could not save.';
    } finally {
        saving.value = false;
    }
}

function close() {
    if (!saving.value) emit('close');
}
</script>

<template>
    <Modal :show="show" max-width="md" :closeable="!saving" @close="close">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-gray-900">Weekly digest schedule</h2>
            <p class="mt-1 text-sm text-gray-500">
                When the automated weekly report email is sent. Changes apply from the next run — nothing to restart.
            </p>

            <p v-if="loadError" class="mt-4 text-sm text-red-700">{{ loadError }}</p>
            <p v-else-if="!data" class="mt-4 text-sm text-gray-500">Loading…</p>

            <template v-else>
                <div class="mt-4 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-gray-600">Currently</span>
                        <span class="font-medium text-gray-900">{{ data.schedule.label }}</span>
                    </div>
                    <div class="mt-1 flex items-center justify-between gap-3">
                        <span class="text-gray-600">Next send</span>
                        <span class="text-gray-800">{{ data.schedule.next_run_label }}</span>
                    </div>
                    <p v-if="data.overridden" class="mt-1 text-xs text-gray-400">
                        Changed here. Default is {{ data.default.label }}.
                    </p>
                    <p v-else class="mt-1 text-xs text-gray-400">This is the default.</p>
                </div>

                <div class="mt-5 grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Day</label>
                        <select v-model="form.day" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            <option v-for="(name, n) in data.days" :key="n" :value="Number(n)">{{ name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Time</label>
                        <input v-model="form.time" type="time" step="60" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700">Timezone</label>
                        <select v-model="form.timezone" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            <option v-for="tz in data.timezones" :key="tz" :value="tz">{{ tz }}</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-400">The time above is read in this timezone.</p>
                    </div>
                </div>

                <p v-if="error" class="mt-4 text-sm text-red-700">{{ error }}</p>
                <p v-if="notice" class="mt-4 text-sm text-green-700">{{ notice }}</p>

                <div class="mt-6 flex items-center justify-between gap-2">
                    <button @click="useDefault" :disabled="saving || isDefault" type="button"
                            class="text-sm text-gray-600 underline-offset-2 hover:underline disabled:opacity-40 disabled:no-underline">
                        Reset to default ({{ data.default.label }})
                    </button>
                    <div class="flex gap-2">
                        <button @click="close" :disabled="saving"
                                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40">
                            Close
                        </button>
                        <button @click="save" :disabled="saving || !dirty"
                                class="rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-900 disabled:opacity-40">
                            {{ saving ? 'Saving…' : 'Save' }}
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </Modal>
</template>
