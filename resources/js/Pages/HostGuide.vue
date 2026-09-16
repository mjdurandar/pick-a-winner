<script setup>
import { ref, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import PickaWinnerLayout from '@/Layouts/PickaWinnerLayout.vue';
import HowToPickAWinner from '@/Components/HowToPickAWinner.vue';

const props = defineProps({
    event: { type: Object, required: true },
    drawPassword: { type: String, default: null },
    locked: { type: Boolean, default: false },
});

const copied = ref(null);

// Password gate for the private guide.
const unlockForm = useForm({ password: '' });
const submitUnlock = () => {
    unlockForm.post(route('pickawinner.hostguide.verify', { event_uuid: props.event.event_uuid }), {
        preserveScroll: true,
        onFinish: () => unlockForm.reset('password'),
    });
};

// Direct entry point to the Pick a Winner tool.
const pickAWinnerUrl = computed(() => route('pickawinner.index'));

async function copyText(text, key) {
    try {
        await navigator.clipboard.writeText(text);
    } catch (e) {
        // Fallback for non-secure contexts
        const el = document.createElement('textarea');
        el.value = text;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    }
    copied.value = key;
    setTimeout(() => {
        if (copied.value === key) copied.value = null;
    }, 1500);
}
</script>

<template>
    <Head :title="`Host Guide — ${event.event_name}`" />

    <PickaWinnerLayout>
        <!-- Locked: password gate -->
        <div v-if="locked" class="flex min-h-screen flex-col items-center justify-center px-4 py-12">
            <div class="w-full max-w-sm overflow-hidden rounded-2xl bg-white shadow-xl">
                <img
                    v-if="event.event_banner"
                    :src="'/storage/' + event.event_banner"
                    class="h-32 w-full object-cover"
                    :alt="event.event_name"
                />
                <div class="p-6 sm:p-8">
                    <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-full" style="background-color: #ecfeff;">
                        <svg class="h-6 w-6" style="color: #16C3D9;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                    </div>
                    <h1 class="text-lg font-bold text-gray-900">Host guide — {{ event.event_name }}</h1>
                    <p class="mt-1 text-sm text-gray-500">
                        This guide is private. Enter the password from your event coordinator to view the
                        instructions and draw passwords.
                    </p>
                    <form class="mt-5" @submit.prevent="submitUnlock">
                        <input
                            v-model="unlockForm.password"
                            type="password"
                            autofocus
                            placeholder="Enter password"
                            class="w-full rounded-lg border-gray-300 text-sm focus:border-cyan-500 focus:ring-cyan-500"
                            :class="{ 'border-red-400': unlockForm.errors.password }"
                        />
                        <p v-if="unlockForm.errors.password" class="mt-1 text-sm text-red-500">
                            {{ unlockForm.errors.password }}
                        </p>
                        <button
                            type="submit"
                            :disabled="unlockForm.processing || !unlockForm.password"
                            class="mt-4 w-full rounded-lg px-4 py-2.5 text-sm font-semibold text-white shadow transition hover:opacity-90 disabled:opacity-50"
                            style="background-color: #16C3D9;"
                        >
                            {{ unlockForm.processing ? 'Checking…' : 'View guide' }}
                        </button>
                    </form>
                </div>
            </div>
            <p class="mt-6 text-center text-xs text-gray-500">Pick a Winner · {{ event.event_name }}</p>
        </div>

        <!-- Unlocked: full guide -->
        <div v-else class="mx-auto max-w-3xl px-4 py-8 sm:py-12">
            <!-- Header / event -->
            <div class="overflow-hidden rounded-2xl bg-white shadow-xl">
                <img
                    v-if="event.event_banner"
                    :src="'/storage/' + event.event_banner"
                    class="h-40 w-full object-cover sm:h-52"
                    :alt="event.event_name"
                />
                <div class="p-6 sm:p-8">
                    <p class="text-xs font-semibold uppercase tracking-wider" style="color: #16C3D9;">
                        Pick a Winner — Host Guide
                    </p>
                    <h1 class="mt-1 text-2xl font-bold text-gray-900 sm:text-3xl">
                        {{ event.event_name }}
                    </h1>
                    <p v-if="event.event_country" class="mt-1 text-sm text-gray-500">
                        {{ event.event_country }}
                    </p>
                    <p class="mt-4 text-gray-600">
                        This page walks you through everything you need to run the prize draw at your
                        screening — how to open the tool, unlock your session with your password, spin
                        for a winner, and record the result. Keep this link handy on the night.
                    </p>

                    <a
                        :href="pickAWinnerUrl"
                        target="_blank"
                        rel="noopener"
                        class="mt-6 inline-flex items-center gap-2 rounded-lg px-5 py-3 text-base font-semibold text-white shadow transition hover:opacity-90"
                        style="background-color: #16C3D9;"
                    >
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                        Open Pick a Winner
                    </a>
                </div>
            </div>

            <!-- Draw password for this event -->
            <div class="mt-6 rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                <h2 class="flex items-center gap-2 text-lg font-bold text-gray-900">
                    <svg class="h-5 w-5" style="color: #16C3D9;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                    Your draw password
                </h2>
                <p class="mt-1 text-sm text-gray-500">
                    Use this password to unlock the draw for any of your screenings. It is not
                    case-sensitive.
                </p>

                <div
                    v-if="drawPassword"
                    class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3"
                >
                    <code class="font-mono text-xl font-bold tracking-widest text-gray-900">{{ drawPassword }}</code>
                    <button
                        type="button"
                        @click="copyText(drawPassword, 'draw')"
                        class="rounded-md px-3 py-1.5 text-xs font-semibold text-white transition hover:opacity-90"
                        style="background-color: #16C3D9;"
                    >
                        {{ copied === 'draw' ? 'Copied!' : 'Copy' }}
                    </button>
                </div>
                <p v-else class="mt-4 text-sm text-gray-500">
                    No draw password has been set for this event yet. Please contact your coordinator.
                </p>
            </div>

            <!-- Steps + tips — shared with the in-draw modal -->
            <div class="mt-6">
                <HowToPickAWinner :event="event" />
            </div>

            <p class="mt-8 text-center text-xs text-gray-500">
                Pick a Winner · {{ event.event_name }}
            </p>
        </div>
    </PickaWinnerLayout>
</template>
