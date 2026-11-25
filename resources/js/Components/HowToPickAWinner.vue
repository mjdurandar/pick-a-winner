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
        <!-- Steps. The pictures are drawings of the real screens, kept in step
             with Pages/PickaWinner.vue and the two draw pages: when a label
             changes there, change it here. -->
        <div class="space-y-6">
            <!-- Open the tool, choose the film and the screening -->
            <div v-if="showStep('open')" class="rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full text-base font-bold" style="background-color: #16C3D9; color: #00171C;">{{ stepNumber('open') }}</span>
                    <h3 class="text-lg font-bold text-gray-900">Open the tool &amp; pick your screening</h3>
                </div>
                <p class="mt-3 text-gray-600">
                    Open the <strong>Pick a Winner</strong> page on your phone or laptop. Tap your
                    <strong>film</strong>, then tap your <strong>screening</strong>. Screenings are
                    grouped by night, and a search box appears when there are a lot of them.
                </p>
                <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
                    <svg viewBox="0 0 560 300" class="w-full" role="img" aria-label="Choosing your film, then your screening" style="font-family: ui-sans-serif, system-ui, sans-serif;">
                        <rect width="560" height="300" fill="#000000" />

                        <!-- screen 1: which film -->
                        <rect x="20" y="16" width="250" height="268" rx="14" fill="#0b0b0b" stroke="#2e2e2e" />
                        <rect x="36" y="34" width="68" height="3" rx="1.5" fill="#16c3d9" />
                        <rect x="111" y="34" width="68" height="3" rx="1.5" fill="#2e2e2e" />
                        <rect x="186" y="34" width="68" height="3" rx="1.5" fill="#2e2e2e" />
                        <text x="36" y="58" font-size="8" font-weight="700" letter-spacing="1" fill="#74797a">STEP 1 OF 3</text>
                        <text x="36" y="80" font-size="16" font-weight="800" fill="#ffffff">Which film are</text>
                        <text x="36" y="99" font-size="16" font-weight="800" fill="#ffffff">you hosting?</text>
                        <rect x="36" y="114" width="218" height="46" rx="10" fill="#151515" stroke="#16c3d9" stroke-width="2" />
                        <text x="48" y="134" font-size="11" font-weight="700" fill="#ffffff">{{ event.event_name }}</text>
                        <text x="48" y="149" font-size="9" fill="#abb0b2">Tap to see its screenings</text>
                        <path d="M238 131 l5 6 l-5 6" stroke="#16c3d9" stroke-width="2" fill="none" stroke-linecap="round" />
                        <rect x="36" y="168" width="218" height="46" rx="10" fill="#151515" stroke="#2e2e2e" />
                        <rect x="48" y="181" width="96" height="8" rx="4" fill="#3a3a3a" />
                        <rect x="48" y="196" width="124" height="6" rx="3" fill="#2b2b2b" />
                        <path d="M238 185 l5 6 l-5 6" stroke="#74797a" stroke-width="2" fill="none" stroke-linecap="round" />

                        <!-- screen 2: which screening -->
                        <rect x="290" y="16" width="250" height="268" rx="14" fill="#0b0b0b" stroke="#2e2e2e" />
                        <rect x="306" y="34" width="68" height="3" rx="1.5" fill="#16c3d9" opacity="0.45" />
                        <rect x="381" y="34" width="68" height="3" rx="1.5" fill="#16c3d9" />
                        <rect x="456" y="34" width="68" height="3" rx="1.5" fill="#2e2e2e" />
                        <text x="306" y="58" font-size="8" font-weight="700" letter-spacing="1" fill="#74797a">STEP 2 OF 3</text>
                        <text x="306" y="84" font-size="16" font-weight="800" fill="#ffffff">Which screening?</text>
                        <text x="306" y="108" font-size="8" font-weight="700" letter-spacing="1" fill="#74797a">SAT 15 AUG</text>
                        <rect x="306" y="116" width="218" height="46" rx="10" fill="#151515" stroke="#16c3d9" stroke-width="2" />
                        <text x="318" y="136" font-size="11" font-weight="700" fill="#ffffff">Your town, your cinema</text>
                        <text x="318" y="151" font-size="9" fill="#abb0b2">7:00 PM</text>
                        <path d="M508 133 l5 6 l-5 6" stroke="#16c3d9" stroke-width="2" fill="none" stroke-linecap="round" />
                        <text x="306" y="184" font-size="8" font-weight="700" letter-spacing="1" fill="#74797a">SUN 16 AUG</text>
                        <rect x="306" y="192" width="218" height="46" rx="10" fill="#151515" stroke="#2e2e2e" />
                        <rect x="318" y="205" width="110" height="8" rx="4" fill="#3a3a3a" />
                        <rect x="318" y="220" width="46" height="6" rx="3" fill="#2b2b2b" />
                        <path d="M508 209 l5 6 l-5 6" stroke="#74797a" stroke-width="2" fill="none" stroke-linecap="round" />
                    </svg>
                </div>
            </div>

            <!-- Password -->
            <div v-if="showStep('password')" class="rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full text-base font-bold" style="background-color: #16C3D9; color: #00171C;">{{ stepNumber('password') }}</span>
                    <h3 class="text-lg font-bold text-gray-900">Enter your password &amp; unlock the draw</h3>
                </div>
                <p class="mt-3 text-gray-600">
                    Type your screening's <strong>password</strong>. It shows as dots; tap the
                    <strong>eye</strong> to see what you typed. Then press the cyan
                    <strong>Unlock the draw</strong> button. If it says the password is invalid,
                    go back and check you picked the right screening.
                </p>
                <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
                    <svg viewBox="0 0 560 270" class="w-full" role="img" aria-label="Typing the password and pressing Unlock the draw" style="font-family: ui-sans-serif, system-ui, sans-serif;">
                        <rect width="560" height="270" fill="#000000" />
                        <rect x="120" y="16" width="320" height="238" rx="14" fill="#0b0b0b" stroke="#2e2e2e" />
                        <rect x="140" y="34" width="88" height="3" rx="1.5" fill="#16c3d9" opacity="0.45" />
                        <rect x="236" y="34" width="88" height="3" rx="1.5" fill="#16c3d9" opacity="0.45" />
                        <rect x="332" y="34" width="88" height="3" rx="1.5" fill="#16c3d9" />
                        <text x="140" y="58" font-size="8" font-weight="700" letter-spacing="1" fill="#74797a">STEP 3 OF 3</text>
                        <text x="140" y="84" font-size="18" font-weight="800" fill="#ffffff">Unlock this draw</text>
                        <text x="140" y="102" font-size="9.5" fill="#abb0b2">The password is in your host pack.</text>
                        <!-- password box with the eye -->
                        <rect x="140" y="116" width="280" height="46" rx="12" fill="#151515" stroke="#16c3d9" stroke-width="2" />
                        <text x="280" y="146" text-anchor="middle" font-family="monospace" font-size="18" letter-spacing="5" fill="#ffffff">••••••••</text>
                        <circle cx="396" cy="139" r="15" fill="#1f1f1f" stroke="#16c3d9" stroke-width="1.5" />
                        <g transform="translate(387 130) scale(0.75)" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z" /><circle cx="12" cy="12" r="3" /></g>
                        <!-- unlock button -->
                        <rect x="140" y="174" width="280" height="44" rx="12" fill="#16c3d9" />
                        <text x="280" y="201" text-anchor="middle" font-size="13" font-weight="800" fill="#00171c">Unlock the draw</text>
                        <text x="280" y="240" text-anchor="middle" font-size="9.5" font-weight="600" fill="#abb0b2">I do not have the password</text>
                    </svg>
                </div>
            </div>

            <!-- Narrow the pool before drawing (in-draw only) -->
            <div v-if="showStep('filter')" class="rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full text-base font-bold" style="background-color: #16C3D9; color: #00171C;">{{ stepNumber('filter') }}</span>
                    <h3 class="text-lg font-bold text-gray-900">Choose who can win (optional)</h3>
                </div>
                <p class="mt-3 text-gray-600">
                    By default everyone who signed up can win. To narrow it down, press
                    <strong>Filter</strong> under the Pick a winner button, then
                    <strong>+ Add a filter</strong>. Choose a <strong>question</strong> from the
                    sign-up form and the <strong>answer</strong> you want, for example Gender, then
                    Female. With more than one filter you choose <strong>Match all</strong> or
                    <strong>Match any</strong>.
                </p>
                <p v-if="tourWide" class="mt-2 text-gray-600">
                    This draw also has a <strong>Screenings</strong> list at the top of the panel.
                    Tick the screenings you want to draw from. Leave every one unticked and the
                    whole tour is in the draw.
                </p>
                <p class="mt-2 text-gray-600">
                    The line at the bottom says how many people are still in the draw. Press
                    <strong>Clear</strong> to go back to everyone.
                </p>
                <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
                    <svg viewBox="0 0 560 270" class="w-full" role="img" aria-label="The Filter panel with one filter set" style="font-family: ui-sans-serif, system-ui, sans-serif;">
                        <rect width="560" height="270" fill="#000000" />
                        <text x="280" y="30" text-anchor="middle" font-size="11" font-weight="600" fill="#abb0b2">Hide filter</text>
                        <rect x="60" y="44" width="440" height="210" rx="14" fill="#151515" stroke="#2e2e2e" />
                        <text x="78" y="68" font-size="12" font-weight="700" fill="#ffffff">Filter</text>
                        <text x="78" y="84" font-size="9.5" fill="#74797a">Built from the questions on your sign-up form.</text>
                        <!-- one filter -->
                        <rect x="78" y="96" width="404" height="82" rx="10" fill="#1f1f1f" stroke="#2e2e2e" />
                        <rect x="90" y="106" width="380" height="24" rx="7" fill="#151515" stroke="#16c3d9" stroke-width="1.5" />
                        <text x="102" y="122" font-size="10.5" fill="#ffffff">Gender</text>
                        <path d="M450 115 l5 6 l5 -6" stroke="#abb0b2" stroke-width="1.5" fill="none" />
                        <rect x="90" y="136" width="380" height="24" rx="7" fill="#151515" stroke="#16c3d9" stroke-width="1.5" />
                        <text x="102" y="152" font-size="10.5" fill="#ffffff">Female</text>
                        <path d="M450 145 l5 6 l5 -6" stroke="#abb0b2" stroke-width="1.5" fill="none" />
                        <text x="280" y="172" text-anchor="middle" font-size="9" font-weight="600" fill="#f77d7d">Remove this filter</text>
                        <!-- add / clear -->
                        <rect x="78" y="188" width="330" height="28" rx="9" fill="#1f1f1f" stroke="#2e2e2e" />
                        <text x="243" y="206" text-anchor="middle" font-size="10.5" font-weight="700" fill="#ffffff">+ Add a filter</text>
                        <text x="450" y="206" text-anchor="middle" font-size="10.5" font-weight="600" fill="#abb0b2">Clear</text>
                        <text x="78" y="238" font-family="monospace" font-size="10" fill="#74797a">899 in the draw with these settings</text>
                    </svg>
                </div>
            </div>

            <!-- Draw -->
            <div v-if="showStep('pick')" class="rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full text-base font-bold" style="background-color: #16C3D9; color: #00171C;">{{ stepNumber('pick') }}</span>
                    <h3 class="text-lg font-bold text-gray-900">Draw a winner</h3>
                </div>
                <p class="mt-3 text-gray-600">
                    You're now on the draw page. The two boxes at the top show how many people are
                    <strong>in the draw</strong> and how many have been <strong>drawn</strong>. Press
                    the big cyan <strong>Pick a winner</strong> button and the tool picks someone at
                    random from everyone who signed up for your screening.
                </p>
                <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
                    <svg viewBox="0 0 560 300" class="w-full" role="img" aria-label="The draw page with the Pick a winner button" style="font-family: ui-sans-serif, system-ui, sans-serif;">
                        <rect width="560" height="300" fill="#000000" />
                        <!-- header -->
                        <rect width="560" height="46" fill="#151515" />
                        <line x1="0" y1="46" x2="560" y2="46" stroke="#2e2e2e" />
                        <text x="24" y="22" font-size="11.5" font-weight="700" fill="#ffffff">Your town, your cinema</text>
                        <text x="24" y="36" font-size="9" fill="#74797a">Sat 15 Aug · 7:00 PM</text>
                        <rect x="430" y="13" width="58" height="20" rx="10" fill="#0d2419" />
                        <circle cx="442" cy="23" r="2.5" fill="#3dd68c" />
                        <text x="464" y="26.5" text-anchor="middle" font-size="8.5" font-weight="700" fill="#3dd68c">Online</text>
                        <rect x="500" y="10" width="28" height="26" rx="7" fill="#1f1f1f" stroke="#2e2e2e" />
                        <circle cx="514" cy="17" r="1.6" fill="#abb0b2" /><circle cx="514" cy="23" r="1.6" fill="#abb0b2" /><circle cx="514" cy="29" r="1.6" fill="#abb0b2" />
                        <!-- counts -->
                        <rect x="24" y="60" width="250" height="60" rx="10" fill="#151515" stroke="#2e2e2e" />
                        <text x="38" y="78" font-size="8" font-weight="700" letter-spacing="0.8" fill="#74797a">IN THE DRAW</text>
                        <text x="38" y="100" font-family="monospace" font-size="19" fill="#ffffff">153</text>
                        <text x="38" y="113" font-size="8.5" fill="#abb0b2">of 154 signed up</text>
                        <rect x="286" y="60" width="250" height="60" rx="10" fill="#151515" stroke="#2e2e2e" />
                        <text x="300" y="78" font-size="8" font-weight="700" letter-spacing="0.8" fill="#74797a">DRAWN</text>
                        <text x="300" y="100" font-family="monospace" font-size="19" fill="#ffffff">1</text>
                        <text x="300" y="113" font-size="8.5" fill="#abb0b2">winner tonight</text>
                        <!-- the button -->
                        <rect x="20" y="130" width="520" height="54" rx="16" fill="none" stroke="#16c3d9" stroke-width="1.5" opacity="0.5" />
                        <rect x="24" y="134" width="512" height="46" rx="13" fill="#16c3d9" />
                        <text x="280" y="162" text-anchor="middle" font-size="14" font-weight="800" fill="#00171c">Pick a winner</text>
                        <text x="280" y="204" text-anchor="middle" font-size="10.5" font-weight="600" fill="#abb0b2">Filter</text>
                        <!-- winners -->
                        <text x="24" y="230" font-size="8" font-weight="700" letter-spacing="1" fill="#74797a">WINNERS TONIGHT</text>
                        <rect x="24" y="238" width="512" height="50" rx="12" fill="#151515" stroke="#2e2e2e" />
                        <circle cx="46" cy="263" r="10" fill="#0d2419" />
                        <path d="M41.5 263 l3 3 l5.5 -6" stroke="#3dd68c" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round" />
                        <text x="66" y="260" font-size="11.5" font-weight="700" fill="#ffffff">Jordan Avery</text>
                        <text x="66" y="274" font-size="9" fill="#abb0b2">Double pass + cap</text>
                    </svg>
                </div>
            </div>

            <!-- Confirm -->
            <div v-if="showStep('confirm')" class="rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full text-base font-bold" style="background-color: #16C3D9; color: #00171C;">{{ stepNumber('confirm') }}</span>
                    <h3 class="text-lg font-bold text-gray-900">Confirm the winner</h3>
                </div>
                <p class="mt-3 text-gray-600">
                    The winner's name fills the screen, with their email, phone, gender and age
                    underneath so you can check you have the right person. Announce the name, then
                    press <strong>Save this winner</strong>. If they are not in the room, press
                    <strong>{{ tourWide ? 'Draw again' : 'Not here — draw again' }}</strong>. Nothing
                    is saved until you press save.
                </p>
                <p v-if="!inDraw" class="mt-2 text-gray-600">
                    Repeat for each prize. Each saved winner appears in the
                    <strong>Winners tonight</strong> list, where <strong>Name the prize</strong>
                    lets you type what they won.
                </p>
                <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
                    <svg viewBox="0 0 560 340" class="w-full" role="img" aria-label="The winner screen with Save this winner" style="font-family: ui-sans-serif, system-ui, sans-serif;">
                        <rect width="560" height="340" fill="#000000" />
                        <circle cx="280" cy="34" r="18" fill="#0b2a30" />
                        <g transform="translate(270 24) scale(0.83)" fill="none" stroke="#16c3d9" stroke-width="2" stroke-linecap="round"><path d="M8 4h8v4a4 4 0 0 1-8 0V4zM8 5H5a3 3 0 0 0 3 3M16 5h3a3 3 0 0 1-3 3M10 12v3h4v-3M8 20h8" /></g>
                        <text x="280" y="70" text-anchor="middle" font-size="8.5" font-weight="700" letter-spacing="1.2" fill="#74797a">TONIGHT'S WINNER</text>
                        <text x="280" y="104" text-anchor="middle" font-size="30" font-weight="900" fill="#16c3d9">Jordan Avery</text>
                        <!-- details -->
                        <rect x="150" y="120" width="260" height="78" rx="12" fill="#151515" stroke="#2e2e2e" />
                        <line x1="162" y1="146" x2="398" y2="146" stroke="#242424" />
                        <line x1="162" y1="172" x2="398" y2="172" stroke="#242424" />
                        <text x="164" y="137" font-size="8" font-weight="700" letter-spacing="0.8" fill="#74797a">EMAIL</text>
                        <text x="396" y="137" text-anchor="end" font-size="10" font-weight="600" fill="#ffffff">jordan@email.com</text>
                        <text x="164" y="163" font-size="8" font-weight="700" letter-spacing="0.8" fill="#74797a">PHONE</text>
                        <text x="396" y="163" text-anchor="end" font-size="10" font-weight="600" fill="#ffffff">0400 000 000</text>
                        <text x="164" y="189" font-size="8" font-weight="700" letter-spacing="0.8" fill="#74797a">AGE</text>
                        <text x="396" y="189" text-anchor="end" font-size="10" font-weight="600" fill="#ffffff">25 to 34</text>
                        <!-- buttons -->
                        <rect x="116" y="212" width="328" height="50" rx="16" fill="none" stroke="#16c3d9" stroke-width="1.5" opacity="0.5" />
                        <rect x="120" y="216" width="320" height="42" rx="13" fill="#16c3d9" />
                        <text x="280" y="242" text-anchor="middle" font-size="13" font-weight="800" fill="#00171c">Save this winner</text>
                        <rect x="120" y="268" width="320" height="34" rx="12" fill="none" stroke="#2e2e2e" />
                        <text x="280" y="289" text-anchor="middle" font-size="11" font-weight="700" fill="#f77d7d">{{ tourWide ? 'Draw again' : 'Not here — draw again' }}</text>
                        <text x="280" y="324" text-anchor="middle" font-size="10" font-weight="600" fill="#74797a">Cancel</text>
                    </svg>
                </div>
            </div>

            <!-- Record what they won (in-draw only) -->
            <div v-if="showStep('prize')" class="rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full text-base font-bold" style="background-color: #16C3D9; color: #00171C;">{{ stepNumber('prize') }}</span>
                    <h3 class="text-lg font-bold text-gray-900">Add the prize they won</h3>
                </div>
                <p class="mt-3 text-gray-600">
                    Every winner you save appears under <strong>Winners tonight</strong>. On their
                    row press the cyan <strong>Name the prize</strong> button, type what they won
                    under <strong>What did they win?</strong>, then press
                    <strong>Save the prize</strong>. The prize then shows under their name, and the
                    button becomes <strong>Change the prize</strong>.
                </p>
                <p class="mt-2 text-gray-600">
                    You can do this straight after each draw, or pick all your winners first and
                    fill in the prizes afterwards.
                </p>
                <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
                    <svg viewBox="0 0 560 316" class="w-full" role="img" aria-label="Naming the prize for a saved winner" style="font-family: ui-sans-serif, system-ui, sans-serif;">
                        <rect width="560" height="316" fill="#000000" />
                        <!-- a saved winner -->
                        <text x="24" y="26" font-size="8" font-weight="700" letter-spacing="1" fill="#74797a">WINNERS TONIGHT</text>
                        <rect x="24" y="34" width="512" height="86" rx="12" fill="#151515" stroke="#2e2e2e" />
                        <circle cx="46" cy="58" r="10" fill="#0d2419" />
                        <path d="M41.5 58 l3 3 l5.5 -6" stroke="#3dd68c" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round" />
                        <text x="66" y="55" font-size="11.5" font-weight="700" fill="#ffffff">Jordan Avery</text>
                        <text x="66" y="69" font-size="9" font-weight="600" fill="#16c3d9">Tap to say what they won</text>
                        <rect x="34" y="80" width="404" height="34" rx="11" fill="none" stroke="#16c3d9" stroke-width="1.5" opacity="0.5" />
                        <rect x="38" y="84" width="396" height="26" rx="8" fill="#16c3d9" />
                        <text x="236" y="101" text-anchor="middle" font-size="10.5" font-weight="700" fill="#00171c">Name the prize</text>
                        <rect x="446" y="84" width="76" height="26" rx="8" fill="none" stroke="#2e2e2e" />
                        <text x="484" y="101" text-anchor="middle" font-size="10.5" font-weight="600" fill="#f77d7d">Delete</text>
                        <!-- the prize panel -->
                        <rect x="60" y="136" width="440" height="164" rx="14" fill="#151515" stroke="#2e2e2e" />
                        <text x="80" y="158" font-size="8" font-weight="700" letter-spacing="1" fill="#74797a">WINNER</text>
                        <text x="80" y="176" font-size="14" font-weight="800" fill="#ffffff">Jordan Avery</text>
                        <text x="80" y="198" font-size="9.5" font-weight="700" fill="#abb0b2">What did they win?</text>
                        <rect x="80" y="206" width="400" height="34" rx="10" fill="#1f1f1f" stroke="#16c3d9" stroke-width="1.5" />
                        <text x="94" y="227" font-size="11.5" font-weight="600" fill="#ffffff">Double pass + cap</text>
                        <rect x="80" y="250" width="400" height="34" rx="11" fill="#16c3d9" />
                        <text x="280" y="271" text-anchor="middle" font-size="11.5" font-weight="800" fill="#00171c">Save the prize</text>
                    </svg>
                </div>
            </div>

            <!-- Remove a winner (in-draw only) -->
            <div v-if="showStep('remove')" class="rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full text-base font-bold" style="background-color: #16C3D9; color: #00171C;">{{ stepNumber('remove') }}</span>
                    <h3 class="text-lg font-bold text-gray-900">Remove a winner</h3>
                </div>
                <p class="mt-3 text-gray-600">
                    Drew someone by mistake, or they weren't in the room? On their row press the
                    red <strong>Delete</strong> button. A panel slides up: type
                    <strong>DELETE</strong>, press the red <strong>Delete</strong> button, and the
                    row is removed.
                </p>
                <p class="mt-2 text-gray-600">
                    They go back into the pool, so they can be drawn again. This can't be undone.
                    If you only need to change what they won, use
                    <strong>Change the prize</strong> instead.
                </p>
                <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
                    <svg viewBox="0 0 560 322" class="w-full" role="img" aria-label="Pressing Delete on a winner and typing DELETE to confirm" style="font-family: ui-sans-serif, system-ui, sans-serif;">
                        <rect width="560" height="322" fill="#000000" />
                        <!-- the row -->
                        <rect x="24" y="16" width="512" height="86" rx="12" fill="#151515" stroke="#2e2e2e" />
                        <circle cx="46" cy="40" r="10" fill="#0d2419" />
                        <path d="M41.5 40 l3 3 l5.5 -6" stroke="#3dd68c" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round" />
                        <text x="66" y="37" font-size="11.5" font-weight="700" fill="#ffffff">Jordan Avery</text>
                        <text x="66" y="51" font-size="9" fill="#abb0b2">Double pass + cap</text>
                        <rect x="38" y="66" width="396" height="26" rx="8" fill="#1f1f1f" stroke="#2e2e2e" />
                        <text x="236" y="83" text-anchor="middle" font-size="10.5" font-weight="600" fill="#ffffff">Change the prize</text>
                        <rect x="446" y="66" width="76" height="26" rx="8" fill="#2c1414" stroke="#f77d7d" stroke-width="2" />
                        <text x="484" y="83" text-anchor="middle" font-size="10.5" font-weight="700" fill="#f77d7d">Delete</text>
                        <!-- the confirm panel that slides up -->
                        <rect x="60" y="116" width="440" height="190" rx="14" fill="#151515" stroke="#2e2e2e" />
                        <text x="80" y="138" font-size="8" font-weight="700" letter-spacing="1" fill="#f77d7d">DELETE</text>
                        <text x="80" y="156" font-size="14" font-weight="800" fill="#ffffff">Jordan Avery</text>
                        <text x="80" y="172" font-size="9" fill="#abb0b2">This cannot be undone. They go back into the draw.</text>
                        <text x="80" y="194" font-size="9.5" font-weight="700" fill="#abb0b2">Type DELETE to confirm</text>
                        <rect x="80" y="202" width="400" height="34" rx="10" fill="#1f1f1f" stroke="#16c3d9" stroke-width="1.5" />
                        <text x="94" y="224" font-family="monospace" font-size="12" font-weight="700" letter-spacing="1.5" fill="#ffffff">DELETE</text>
                        <rect x="80" y="246" width="400" height="34" rx="11" fill="#f77d7d" />
                        <text x="280" y="267" text-anchor="middle" font-size="11.5" font-weight="800" fill="#2c0b0b">Delete</text>
                        <text x="280" y="297" text-anchor="middle" font-size="9.5" font-weight="600" fill="#abb0b2">Cancel</text>
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
