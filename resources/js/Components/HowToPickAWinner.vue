<script setup>
import { computed } from 'vue';

/**
 * The "how to run a draw" instructions, shared by the standalone host guide
 * page and the in-draw modal, so the steps only ever exist in one place.
 *
 * Only the steps and tips live here. The draw password and the "Open Pick a
 * Winner" button belong to the shareable guide page alone — inside a draw the
 * host has already unlocked the tool, so both are meaningless there.
 */
const props = defineProps({
    event: { type: Object, required: true },
    // Shown from inside a draw: the host is already past logging in, so the
    // steps start at the draw itself and cover the buttons on that page.
    inDraw: { type: Boolean, default: false },
    // The tour-wide draw adds a locations picker the location draw has no use for.
    tourWide: { type: Boolean, default: false },
});

// Which steps this context shows, in order. The numbers are derived from this
// list so removing a step never leaves a gap in the numbering.
const steps = computed(() => {
    const all = [
        { key: 'open', guideOnly: true },
        { key: 'password', guideOnly: true },
        { key: 'filter', drawOnly: true },
        { key: 'pick' },
        { key: 'confirm' },
        { key: 'prize', drawOnly: true },
        { key: 'remove', drawOnly: true },
    ];

    return all.filter(step => (props.inDraw ? !step.guideOnly : !step.drawOnly));
});

const showStep = (key) => steps.value.some(step => step.key === key);
const stepNumber = (key) => steps.value.findIndex(step => step.key === key) + 1;
</script>

<template>
    <div>
        <!-- Steps -->
        <div class="space-y-6">
            <!-- Step 1 -->
            <div v-if="showStep('open')" class="rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full text-base font-bold text-white" style="background-color: #16C3D9;">{{ stepNumber('open') }}</span>
                    <h3 class="text-lg font-bold text-gray-900">Open the tool &amp; pick your screening</h3>
                </div>
                <p class="mt-3 text-gray-600">
                    Open the <strong>Pick a Winner</strong> page. On a black screen you'll see a
                    white box — choose the <strong>Event</strong> and then your
                    <strong>Location</strong> (screening) from the drop-downs.
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
            <div v-if="showStep('password')" class="rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full text-base font-bold text-white" style="background-color: #16C3D9;">{{ stepNumber('password') }}</span>
                    <h3 class="text-lg font-bold text-gray-900">Enter your password &amp; press Enter</h3>
                </div>
                <p class="mt-3 text-gray-600">
                    In the same box, type your screening's <strong>Password</strong> (it's entered
                    in CAPITALS) and press the cyan <strong>Enter</strong> button.
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

            <!-- Narrow the pool before drawing (in-draw only) -->
            <div v-if="showStep('filter')" class="rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full text-base font-bold text-white" style="background-color: #16C3D9;">{{ stepNumber('filter') }}</span>
                    <h3 class="text-lg font-bold text-gray-900">Choose who can win (optional)</h3>
                </div>
                <p class="mt-3 text-gray-600">
                    By default everyone who signed up can win. To narrow it down, press
                    <strong>Filters</strong>, then <strong>Add Filter</strong>, pick a
                    <strong>Question</strong> from the sign-up form and the
                    <strong>Filter Value</strong> you want — for example Gender = Female.
                    Add more than one filter and you can choose whether they
                    <strong>ALL</strong> must match or <strong>ANY</strong> of them.
                </p>
                <p v-if="tourWide" class="mt-2 text-gray-600">
                    This draw also has a <strong>Locations in this draw</strong> list at the top
                    of the panel. Tick the screenings you want to draw from — leave them all
                    unticked to draw from the whole tour.
                </p>
                <p class="mt-2 text-gray-600">
                    The blue box shows how many people are still eligible. Press
                    <strong>Reset All Filters</strong> to go back to everyone.
                </p>
                <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
                    <svg viewBox="0 0 560 250" class="w-full" role="img" aria-label="The Filters panel with a question filter applied">
                        <rect width="560" height="250" fill="#151515" />
                        <!-- Filters button (orange = active) -->
                        <rect x="230" y="16" width="100" height="28" rx="5" fill="#f97316" />
                        <text x="280" y="35" text-anchor="middle" font-family="sans-serif" font-size="12" font-weight="700" fill="#ffffff">Filters (1)</text>
                        <!-- panel -->
                        <rect x="60" y="58" width="440" height="176" rx="6" fill="#1f2937" stroke="#4b5563" />
                        <text x="78" y="80" font-family="sans-serif" font-size="11" font-weight="700" fill="#ffffff">Filter Attendees by Question</text>
                        <rect x="400" y="66" width="82" height="22" rx="4" fill="#0d6efd" />
                        <text x="441" y="81" text-anchor="middle" font-family="sans-serif" font-size="10" font-weight="700" fill="#ffffff">+ Add Filter</text>
                        <!-- filter row -->
                        <rect x="78" y="96" width="404" height="56" rx="5" fill="#374151" />
                        <text x="90" y="113" font-family="sans-serif" font-size="9" fill="#e5e7eb">Question</text>
                        <rect x="90" y="119" width="150" height="24" rx="4" fill="#4b5563" stroke="#6b7280" />
                        <text x="100" y="135" font-family="sans-serif" font-size="10" fill="#ffffff">Gender</text>
                        <text x="256" y="113" font-family="sans-serif" font-size="9" fill="#e5e7eb">Filter Value</text>
                        <rect x="256" y="119" width="150" height="24" rx="4" fill="#4b5563" stroke="#6b7280" />
                        <text x="266" y="135" font-family="sans-serif" font-size="10" fill="#ffffff">Female</text>
                        <rect x="418" y="119" width="54" height="24" rx="4" fill="#dc3545" />
                        <text x="445" y="135" text-anchor="middle" font-family="sans-serif" font-size="9" font-weight="700" fill="#ffffff">Remove</text>
                        <!-- eligible count -->
                        <rect x="78" y="162" width="404" height="56" rx="5" fill="#1e3a8a" stroke="#1d4ed8" />
                        <text x="90" y="182" font-family="sans-serif" font-size="10" font-weight="700" fill="#ffffff">Filter Explanation:</text>
                        <text x="90" y="200" font-family="sans-serif" font-size="10" fill="#e5e7eb">Only attendees matching ALL filters are eligible.</text>
                        <text x="90" y="213" font-family="sans-serif" font-size="10" fill="#e5e7eb">Eligible attendees: 899 out of 1481</text>
                    </svg>
                </div>
            </div>

            <!-- Step 3 -->
            <div v-if="showStep('pick')" class="rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full text-base font-bold text-white" style="background-color: #16C3D9;">{{ stepNumber('pick') }}</span>
                    <h3 class="text-lg font-bold text-gray-900">Draw a winner</h3>
                </div>
                <p class="mt-3 text-gray-600">
                    You're now on the draw page. Press the blue
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
            <div v-if="showStep('confirm')" class="rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full text-base font-bold text-white" style="background-color: #16C3D9;">{{ stepNumber('confirm') }}</span>
                    <h3 class="text-lg font-bold text-gray-900">Confirm the winner</h3>
                </div>
                <p class="mt-3 text-gray-600">
                    A <strong>Winner Selected!</strong> pop-up shows the chosen name and their details.
                    Announce it, then press the green <strong>Save Winner</strong> button to record it —
                    or <strong>Nope! Pick Again…</strong> to redraw. Repeat for each prize, then use the
                    green <strong>Prize</strong> button on the row to type what they won.
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
                        <!-- Save Winner button (btn-success green) -->
                        <rect x="205" y="176" width="150" height="34" rx="5" fill="#198754" />
                        <text x="280" y="198" text-anchor="middle" font-family="sans-serif" font-size="13" font-weight="700" fill="#ffffff">Save Winner</text>
                        <!-- Pick again (secondary) -->
                        <text x="280" y="224" text-anchor="middle" font-family="sans-serif" font-size="10" fill="#9ca3af">Nope! Pick Again…</text>
                    </svg>
                </div>
            </div>

            <!-- Record what they won (in-draw only) -->
            <div v-if="showStep('prize')" class="rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full text-base font-bold text-white" style="background-color: #16C3D9;">{{ stepNumber('prize') }}</span>
                    <h3 class="text-lg font-bold text-gray-900">Add the prize they won</h3>
                </div>
                <p class="mt-3 text-gray-600">
                    Every winner you save appears in the table below the button. On their row,
                    press the green <strong>Prize</strong> button, type what they won in
                    <strong>Prize Description</strong>, then press <strong>Update Prize</strong>.
                    The prize shows in the <strong>Prize</strong> column next to their name.
                </p>
                <p class="mt-2 text-gray-600">
                    You can do this straight after each draw, or pick all your winners first and
                    fill in the prizes afterwards.
                </p>
                <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
                    <svg viewBox="0 0 560 240" class="w-full" role="img" aria-label="Adding a prize to a saved winner">
                        <rect width="560" height="240" fill="#151515" />
                        <!-- winners table -->
                        <rect x="40" y="20" width="480" height="26" fill="#06b6d4" />
                        <line x1="220" y1="20" x2="220" y2="100" stroke="#374151" />
                        <line x1="360" y1="20" x2="360" y2="100" stroke="#374151" />
                        <text x="130" y="38" text-anchor="middle" font-family="sans-serif" font-size="11" font-weight="700" fill="#ffffff">Name</text>
                        <text x="290" y="38" text-anchor="middle" font-family="sans-serif" font-size="11" font-weight="700" fill="#ffffff">Prize</text>
                        <text x="440" y="38" text-anchor="middle" font-family="sans-serif" font-size="11" font-weight="700" fill="#ffffff">Actions</text>
                        <rect x="40" y="46" width="480" height="54" fill="#0e7490" stroke="#374151" />
                        <text x="54" y="78" font-family="sans-serif" font-size="12" fill="#ffffff">Jordan Avery</text>
                        <!-- green Prize button (highlighted) -->
                        <rect x="376" y="60" width="74" height="26" rx="5" fill="#198754" stroke="#4ade80" stroke-width="2" />
                        <text x="413" y="77" text-anchor="middle" font-family="sans-serif" font-size="10" font-weight="700" fill="#ffffff">🏆 Prize</text>
                        <rect x="458" y="60" width="34" height="26" rx="5" fill="#dc3545" />
                        <text x="475" y="78" text-anchor="middle" font-family="sans-serif" font-size="11" fill="#ffffff">🗑</text>
                        <!-- prize modal -->
                        <rect x="150" y="116" width="260" height="106" rx="8" fill="#111827" stroke="#374151" />
                        <text x="166" y="138" font-family="sans-serif" font-size="11" font-weight="700" fill="#ffffff">🏆 Winner Details &amp; Prize</text>
                        <text x="166" y="158" font-family="sans-serif" font-size="9" fill="#9ca3af">Prize Description</text>
                        <rect x="166" y="164" width="228" height="26" rx="4" fill="#1f2937" stroke="#4b5563" />
                        <text x="176" y="181" font-family="sans-serif" font-size="10" fill="#ffffff">Double pass + cap</text>
                        <rect x="300" y="196" width="94" height="20" rx="4" fill="#198754" />
                        <text x="347" y="210" text-anchor="middle" font-family="sans-serif" font-size="9" font-weight="700" fill="#ffffff">Update Prize</text>
                    </svg>
                </div>
            </div>

            <!-- Remove a winner (in-draw only) -->
            <div v-if="showStep('remove')" class="rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full text-base font-bold text-white" style="background-color: #16C3D9;">{{ stepNumber('remove') }}</span>
                    <h3 class="text-lg font-bold text-gray-900">Remove a winner</h3>
                </div>
                <p class="mt-3 text-gray-600">
                    Drew someone by mistake, or they weren't in the room? On their row press the
                    red <strong>bin</strong> button, type <strong>DELETE</strong> to confirm, and
                    the row is removed.
                </p>
                <p class="mt-2 text-gray-600">
                    They go back into the pool, so they can be drawn again. This can't be undone —
                    if you only need to change what they won, use the green
                    <strong>Prize</strong> button instead.
                </p>
                <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
                    <svg viewBox="0 0 560 200" class="w-full" role="img" aria-label="Confirming the removal of a winner by typing DELETE">
                        <rect width="560" height="200" fill="#151515" />
                        <!-- confirm dialog -->
                        <rect x="140" y="20" width="280" height="160" rx="8" fill="#ffffff" />
                        <circle cx="280" cy="52" r="16" fill="none" stroke="#f8bb86" stroke-width="2" />
                        <text x="280" y="59" text-anchor="middle" font-family="sans-serif" font-size="20" font-weight="700" fill="#f8bb86">!</text>
                        <text x="280" y="88" text-anchor="middle" font-family="sans-serif" font-size="13" font-weight="700" fill="#374151">Are you sure?</text>
                        <text x="280" y="106" text-anchor="middle" font-family="sans-serif" font-size="9" fill="#6b7280">This action cannot be undone!</text>
                        <rect x="170" y="116" width="220" height="24" rx="4" fill="#ffffff" stroke="#d1d5db" />
                        <text x="180" y="132" font-family="monospace" font-size="11" font-weight="700" fill="#374151">DELETE</text>
                        <rect x="170" y="148" width="104" height="24" rx="4" fill="#dc3545" />
                        <text x="222" y="164" text-anchor="middle" font-family="sans-serif" font-size="10" font-weight="700" fill="#ffffff">Delete Prize</text>
                        <rect x="286" y="148" width="104" height="24" rx="4" fill="#6c757d" />
                        <text x="338" y="164" text-anchor="middle" font-family="sans-serif" font-size="10" font-weight="700" fill="#ffffff">Cancel</text>
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
                <li>• If the internet drops out, keep drawing: winners are saved on the device and send
                    themselves once you're back online. Just leave the page open.</li>
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
                <li v-if="event.event_coordinator_phone">• Or call
                    <a
                        :href="`tel:${event.event_coordinator_phone.replace(/\s/g, '')}`"
                        class="font-semibold underline"
                        style="color: #16C3D9;"
                    >{{ event.event_coordinator_phone }}</a>.
                </li>
            </ul>
        </div>
    </div>
</template>
