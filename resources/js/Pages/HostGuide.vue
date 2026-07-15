<script setup>
import { ref, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import PickaWinnerLayout from '@/Layouts/PickaWinnerLayout.vue';

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

            <!-- Steps -->
            <div class="mt-6 space-y-6">
                <!-- Step 1 -->
                <div class="rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full text-base font-bold text-white" style="background-color: #16C3D9;">1</span>
                        <h3 class="text-lg font-bold text-gray-900">Open the tool &amp; pick your screening</h3>
                    </div>
                    <p class="mt-3 text-gray-600">
                        Tap <strong>Open Pick a Winner</strong> above. On a black screen you'll see a white
                        box — choose the <strong>Event</strong> and then your <strong>Location</strong>
                        (screening) from the drop-downs.
                    </p>
                    <!-- illustration: real selection screen -->
                    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
                        <svg viewBox="0 0 560 320" class="w-full" role="img" aria-label="Pick a Winner selection screen">
                            <rect width="560" height="320" fill="#151515" />
                            <!-- logo wordmark -->
                            <text x="280" y="42" text-anchor="middle" font-family="sans-serif" font-size="16" font-weight="800" letter-spacing="2" fill="#ffffff">ADVENTURE</text>
                            <text x="280" y="60" text-anchor="middle" font-family="sans-serif" font-size="9" letter-spacing="6" fill="#9ca3af">ENTERTAINMENT</text>
                            <!-- white box -->
                            <rect x="140" y="80" width="280" height="212" fill="#ffffff" />
                            <!-- Select Event -->
                            <text x="164" y="108" font-family="sans-serif" font-size="11" font-weight="700" fill="#374151">Select Event</text>
                            <rect x="164" y="116" width="232" height="30" rx="5" fill="#ffffff" stroke="#d1d5db" />
                            <text x="176" y="135" font-family="sans-serif" font-size="11" fill="#374151">{{ event.event_name }}</text>
                            <path d="M378 127 l6 7 l6 -7" stroke="#9ca3af" stroke-width="1.5" fill="none" />
                            <!-- Select Location (highlighted) -->
                            <text x="164" y="168" font-family="sans-serif" font-size="11" font-weight="700" fill="#374151">Select Location</text>
                            <rect x="164" y="176" width="232" height="30" rx="5" fill="#ffffff" stroke="#06b6d4" stroke-width="2" />
                            <text x="176" y="195" font-family="sans-serif" font-size="11" fill="#6b7280">Select a location</text>
                            <path d="M378 187 l6 7 l6 -7" stroke="#06b6d4" stroke-width="1.5" fill="none" />
                            <!-- Password -->
                            <text x="164" y="228" font-family="sans-serif" font-size="11" font-weight="700" fill="#374151">Password</text>
                            <rect x="164" y="236" width="232" height="30" rx="5" fill="#ffffff" stroke="#d1d5db" />
                            <text x="176" y="255" font-family="sans-serif" font-size="11" fill="#9ca3af">Enter location password</text>
                            <!-- Enter button (cyan-500) -->
                            <rect x="164" y="272" width="232" height="12" rx="2" fill="#06b6d4" opacity="0.5" />
                        </svg>
                    </div>
                </div>

                <!-- Step 2 -->
                <div class="rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full text-base font-bold text-white" style="background-color: #16C3D9;">2</span>
                        <h3 class="text-lg font-bold text-gray-900">Enter your password &amp; press Enter</h3>
                    </div>
                    <p class="mt-3 text-gray-600">
                        In the same box, type your screening's <strong>Password</strong> (from the list
                        above — it's entered in CAPITALS) and press the cyan <strong>Enter</strong> button.
                        If it says the password is invalid, check you picked the right screening.
                    </p>
                    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
                        <svg viewBox="0 0 560 200" class="w-full" role="img" aria-label="Entering the password and pressing Enter">
                            <rect width="560" height="200" fill="#151515" />
                            <rect x="140" y="24" width="280" height="152" fill="#ffffff" />
                            <!-- Password field (highlighted) -->
                            <text x="164" y="52" font-family="sans-serif" font-size="11" font-weight="700" fill="#374151">Password</text>
                            <rect x="164" y="60" width="232" height="32" rx="5" fill="#ffffff" stroke="#06b6d4" stroke-width="2" />
                            <text x="176" y="81" font-family="monospace" font-size="16" fill="#374151" letter-spacing="4">••••••••</text>
                            <!-- Enter button (cyan-500, full width) -->
                            <rect x="164" y="108" width="232" height="36" rx="4" fill="#06b6d4" />
                            <text x="280" y="131" text-anchor="middle" font-family="sans-serif" font-size="13" font-weight="700" fill="#ffffff">Enter</text>
                        </svg>
                    </div>
                </div>

                <!-- Step 3 -->
                <div class="rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full text-base font-bold text-white" style="background-color: #16C3D9;">3</span>
                        <h3 class="text-lg font-bold text-gray-900">Draw a winner</h3>
                    </div>
                    <p class="mt-3 text-gray-600">
                        You're now on the draw page with your list of prizes. Press the blue
                        <strong>🎉 Pick a Winner 🎉</strong> button — the tool randomly picks an attendee
                        from everyone who signed up for your screening.
                    </p>
                    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
                        <svg viewBox="0 0 560 240" class="w-full" role="img" aria-label="The draw page with the Pick a Winner button">
                            <rect width="560" height="240" fill="#151515" />
                            <!-- event title -->
                            <text x="280" y="42" text-anchor="middle" font-family="sans-serif" font-size="17" font-weight="700" fill="#ffffff">{{ event.event_name }}</text>
                            <!-- Pick a Winner button (btn-primary blue) -->
                            <rect x="200" y="64" width="160" height="42" rx="6" fill="#0d6efd" />
                            <text x="280" y="91" text-anchor="middle" font-family="sans-serif" font-size="14" font-weight="700" fill="#ffffff">🎉 Pick a Winner 🎉</text>
                            <!-- prizes table -->
                            <rect x="90" y="132" width="380" height="28" fill="#06b6d4" />
                            <line x1="230" y1="132" x2="230" y2="220" stroke="#374151" />
                            <line x1="370" y1="132" x2="370" y2="220" stroke="#374151" />
                            <text x="150" y="151" text-anchor="middle" font-family="sans-serif" font-size="11" font-weight="700" fill="#ffffff">Name</text>
                            <text x="300" y="151" text-anchor="middle" font-family="sans-serif" font-size="11" font-weight="700" fill="#ffffff">Prize</text>
                            <text x="420" y="151" text-anchor="middle" font-family="sans-serif" font-size="11" font-weight="700" fill="#ffffff">Actions</text>
                            <rect x="90" y="160" width="380" height="30" fill="#151515" stroke="#374151" />
                            <rect x="90" y="190" width="380" height="30" fill="#151515" stroke="#374151" />
                            <text x="102" y="180" font-family="sans-serif" font-size="11" fill="#d1d5db">Jordan Avery</text>
                            <text x="242" y="180" font-family="sans-serif" font-size="11" fill="#d1d5db">Prize 1</text>
                            <text x="102" y="210" font-family="sans-serif" font-size="11" fill="#6b7280">—</text>
                            <text x="242" y="210" font-family="sans-serif" font-size="11" fill="#6b7280">Prize 2</text>
                        </svg>
                    </div>
                </div>

                <!-- Step 4 -->
                <div class="rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full text-base font-bold text-white" style="background-color: #16C3D9;">4</span>
                        <h3 class="text-lg font-bold text-gray-900">Confirm the winner</h3>
                    </div>
                    <p class="mt-3 text-gray-600">
                        A <strong>Winner Selected!</strong> pop-up shows the chosen name and their details.
                        Announce it, then press the green <strong>Confirm</strong> button to record it —
                        or <strong>Nope! Pick Again…</strong> to redraw. Repeat for each prize.
                    </p>
                    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
                        <svg viewBox="0 0 560 260" class="w-full" role="img" aria-label="The Winner Selected pop-up">
                            <rect width="560" height="260" fill="#151515" />
                            <!-- modal (bg-gray-800) -->
                            <rect x="150" y="24" width="260" height="212" rx="8" fill="#1f2937" stroke="#374151" />
                            <!-- close X -->
                            <text x="392" y="48" text-anchor="middle" font-family="sans-serif" font-size="14" fill="#9ca3af">×</text>
                            <!-- Winner Selected! (green) -->
                            <text x="280" y="76" text-anchor="middle" font-family="sans-serif" font-size="14" font-weight="700" fill="#22c55e">🎉 Winner Selected! 🎉</text>
                            <!-- winner name (green-400, large) -->
                            <text x="280" y="112" text-anchor="middle" font-family="sans-serif" font-size="22" font-weight="800" fill="#4ade80">Jordan Avery</text>
                            <!-- details -->
                            <text x="280" y="140" text-anchor="middle" font-family="sans-serif" font-size="10" fill="#d1d5db">Email: jordan@email.com</text>
                            <text x="280" y="156" text-anchor="middle" font-family="sans-serif" font-size="10" fill="#d1d5db">Phone: 0400 000 000</text>
                            <!-- Confirm button (btn-success green) -->
                            <rect x="205" y="176" width="150" height="34" rx="5" fill="#198754" />
                            <text x="280" y="198" text-anchor="middle" font-family="sans-serif" font-size="13" font-weight="700" fill="#ffffff">Confirm</text>
                            <!-- Pick again (secondary) -->
                            <text x="280" y="224" text-anchor="middle" font-family="sans-serif" font-size="10" fill="#9ca3af">Nope! Pick Again…</text>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Tips -->
            <div class="mt-6 rounded-2xl border-2 border-dashed p-6 sm:p-8" style="border-color: #16C3D9;">
                <h3 class="flex items-center gap-2 text-base font-bold text-white">
                    <svg class="h-5 w-5" style="color: #16C3D9;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Good to know
                </h3>
                <ul class="mt-3 space-y-2 text-sm text-gray-300">
                    <li>• Use a device connected to the internet — ideally the one plugged into the screen.</li>
                    <li>• Keep your password private; only share it with people running the draw.</li>
                    <li>• If you get stuck on the night, contact
                        <span v-if="event.event_coordinator" class="font-semibold text-white">{{ event.event_coordinator }}</span>
                        <span v-else>your event coordinator</span><template v-if="event.event_coordinator_email">
                        at
                        <a
                            :href="`mailto:${event.event_coordinator_email}?subject=${encodeURIComponent('Pick a Winner help — ' + event.event_name)}`"
                            class="font-semibold underline"
                            style="color: #16C3D9;"
                        >{{ event.event_coordinator_email }}</a></template>.
                    </li>
                    <li>• Or call
                        <a
                            :href="`tel:${event.event_coordinator_phone.replace(/\s/g, '')}`"
                            class="font-semibold underline"
                            style="color: #16C3D9;"
                        >{{ event.event_coordinator_phone }}</a>.
                    </li>
                </ul>
            </div>

            <p class="mt-8 text-center text-xs text-gray-500">
                Pick a Winner · {{ event.event_name }}
            </p>
        </div>
    </PickaWinnerLayout>
</template>
