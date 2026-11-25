<script setup>
/**
 * Host access to a draw: film → screening → that screening's password.
 *
 * Same three things the old selection modal asked for, and the same server
 * calls — two dropdowns and a password field became three screens, because
 * this is opened on a phone in a venue foyer and a long <select> on a phone
 * is a spinning wheel of 150 venues.
 */
import { ref, computed, watch } from 'vue';
import { useForm, Head } from '@inertiajs/vue3';
import PickaWinnerLayout from '@/Layouts/PickaWinnerLayout.vue';
import axios from 'axios';

const props = defineProps({
    events: Array,
    locations: Array,
});

const step = ref('film');          // film | screening | password
const locations = ref([]);
const loading = ref(false);
const search = ref('');
const showHelp = ref(false);
const pwBox = ref(null);
// Hidden as it is typed, because a host is often standing beside the audience.
// The eye shows it for anyone who wants to check what they entered.
const showPassword = ref(false);

function togglePassword() {
    showPassword.value = !showPassword.value;
    // Tapping the eye takes focus off the field; hand it back so typing carries on.
    requestAnimationFrame(() => pwBox.value?.focus());
}

// The password is verified server-side — it never reaches this page.
const form = useForm({
    event_id: '',
    location_id: '',
    password: '',
});

const selectedEvent = computed(() => props.events.find((e) => e.id === form.event_id) ?? null);
const selectedLocation = computed(() => locations.value.find((l) => l.id === form.location_id) ?? null);

/* ------------------------------------------------------------ formatting -- */
// Dates and times are formatted from the stored strings, never through Date(),
// so a screening never drifts a day either side of midnight.
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
    'August', 'September', 'October', 'November', 'December'];
const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

function formatDate(dateString) {
    if (!dateString) return '';
    if (String(dateString).includes(',')) return dateString;

    const m = String(dateString).trim().match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
    if (!m) return dateString;

    const [, y, mo, d] = m;
    const month = MONTHS[parseInt(mo, 10) - 1];
    if (!month) return dateString;

    // Weekday from a UTC date built out of the parts — no local-time shift.
    const weekday = DAYS[new Date(Date.UTC(+y, +mo - 1, +d)).getUTCDay()];

    return `${weekday} ${parseInt(d, 10)} ${month} ${y}`;
}

function formatTime(timeString) {
    if (!timeString) return '';
    const t = String(timeString).trim();
    if (!t || t.toUpperCase() === 'TBA') return 'TBA';
    if (t.includes('AM') || t.includes('PM')) return t.replace(/\s+/g, '');
    if (!t.includes(':')) return t;

    const parts = t.split(':');
    let h = parseInt(parts[0], 10) || 0;
    const min = parseInt(parts[1], 10) || 0;
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;

    return `${h}:${String(min).padStart(2, '0')}${ampm}`;
}

function sortKey(l) {
    const t = String(l.time ?? '').trim();
    const parts = t.split(':');

    return `${l.date ?? ''}T${String(parseInt(parts[0], 10) || 0).padStart(2, '0')}:${String(parseInt(parts[1], 10) || 0).padStart(2, '0')}`;
}

/**
 * Screenings for the chosen film, grouped under the night they run.
 *
 * Which screenings arrive here is the server's call, not this page's:
 * getLocations returns the last seven days onwards unless the event has
 * show_all_locations_draw set, in which case it returns the lot.
 *
 * The ordering is the one the old screen used — nights in date order, and
 * inside a night the venues alphabetically, then by time. A host scanning for
 * their venue is looking for a name, not for 7:30.
 */
const nights = computed(() => {
    const q = search.value.trim().toLowerCase();
    const rows = locations.value
        .filter((l) => (q ? `${l.name ?? ''} ${l.address ?? ''}`.toLowerCase().includes(q) : true))
        .slice()
        .sort((a, b) => String(a.date ?? '').localeCompare(String(b.date ?? '')));

    const out = [];
    rows.forEach((l) => {
        const key = l.date ?? '';
        let night = out.find((n) => n.key === key);
        if (!night) {
            night = { key, label: formatDate(l.date), list: [] };
            out.push(night);
        }
        night.list.push(l);
    });

    out.forEach((night) => night.list.sort((a, b) =>
        String(a.name ?? '').localeCompare(String(b.name ?? ''))
        || sortKey(a).localeCompare(sortKey(b))));

    return out;
});

/* ----------------------------------------------------------------- steps -- */

async function pickFilm(event) {
    form.event_id = event.id;
    form.location_id = '';
    form.password = '';
    search.value = '';
    step.value = 'screening';
    loading.value = true;

    try {
        const { data } = await axios.get(`/api/events/${event.id}/locations`);
        locations.value = Array.isArray(data) ? data : [];
    } catch (e) {
        locations.value = [];
    } finally {
        loading.value = false;
    }
}

function pickLocation(location) {
    form.location_id = location.id;
    form.password = '';
    showPassword.value = false;
    form.clearErrors();
    step.value = 'password';
    // Focus without summoning the keyboard over the whole screen on tap.
    requestAnimationFrame(() => pwBox.value?.focus());
}

function back() {
    form.clearErrors();
    if (step.value === 'password') {
        form.password = '';
        showPassword.value = false;
        step.value = 'screening';
    } else if (step.value === 'screening') {
        locations.value = [];
        form.event_id = '';
        step.value = 'film';
    }
}

function submit() {
    if (!form.password.trim() || form.processing) return;
    form.post(route('picka-winner.verify'), { preserveScroll: true });
}

/**
 * The host instruction guide for the chosen film. It asks for its own
 * password before showing anything, so opening it is not an unlock.
 */
function openInstructions() {
    if (!selectedEvent.value) return;
    window.open(
        route('pickawinner.hostguide', { event_uuid: selectedEvent.value.event_uuid }),
        '_blank',
        'noopener'
    );
}

// Re-asking for the password after a failure should not keep the wrong one.
watch(() => form.errors.password, (msg) => {
    if (msg) {
        form.password = '';
        requestAnimationFrame(() => pwBox.value?.focus());
    }
});
</script>

<template>
    <Head title="Pick a Winner" />

    <PickaWinnerLayout>
        <div class="mx-auto flex min-h-screen w-full max-w-[440px] flex-col">
            <!-- ------------------------------------------------ header -- -->
            <header class="flex min-h-[56px] items-center gap-2.5 border-b border-[#2e2e2e] bg-[#151515] px-[max(18px,env(safe-area-inset-left))] py-2.5">
                <button
                    v-if="step !== 'film'" type="button" @click="back"
                    class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border border-[#2e2e2e] bg-[#1f1f1f] text-[#abb0b2] hover:text-white"
                    aria-label="Back"
                >
                    <svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="m15 6-6 6 6 6" /></svg>
                </button>

                <div class="min-w-0 flex-1 leading-tight">
                    <template v-if="step === 'film'">
                        <span class="block truncate text-sm font-bold text-white">Pick a Winner</span>
                        <span class="block truncate text-[11.5px] text-[#74797a]">Adventure Entertainment</span>
                    </template>
                    <template v-else-if="step === 'screening'">
                        <span class="block truncate text-sm font-bold text-white">{{ selectedEvent?.event_name }}</span>
                        <span class="block truncate text-[11.5px] text-[#74797a]">Choose your screening</span>
                    </template>
                    <template v-else>
                        <span class="block truncate text-sm font-bold text-white">{{ selectedLocation?.name }}</span>
                        <span class="block truncate text-[11.5px] text-[#74797a]">{{ formatDate(selectedLocation?.date) }}</span>
                    </template>
                </div>

                <img src="/favicon/AE-Favicon.png" alt="Adventure Entertainment" class="h-9 w-9 shrink-0 rounded-lg" />
            </header>

            <main class="flex-1 px-[max(18px,env(safe-area-inset-left))] pb-[calc(26px+env(safe-area-inset-bottom))] pt-5">
                <!-- progress -->
                <div class="mb-4 flex gap-1.5">
                    <i class="h-[3px] flex-1 rounded-full" :class="step === 'film' ? 'bg-[#16c3d9]' : 'bg-[#16c3d9]/45'"></i>
                    <i class="h-[3px] flex-1 rounded-full" :class="step === 'screening' ? 'bg-[#16c3d9]' : step === 'password' ? 'bg-[#16c3d9]/45' : 'bg-[#2e2e2e]'"></i>
                    <i class="h-[3px] flex-1 rounded-full" :class="step === 'password' ? 'bg-[#16c3d9]' : 'bg-[#2e2e2e]'"></i>
                </div>

                <!-- ------------------------------------- step 1: film -- -->
                <section v-if="step === 'film'" class="grid gap-3.5">
                    <div>
                        <p class="text-[11.5px] font-bold uppercase tracking-[0.12em] text-[#74797a]">Step 1 of 3</p>
                        <h1 class="text-[clamp(27px,8vw,34px)] font-black leading-[1.06] tracking-[-0.03em] text-white">
                            Which film are you hosting?
                        </h1>
                    </div>

                    <button
                        v-for="event in events" :key="event.id" type="button"
                        @click="pickFilm(event)"
                        class="flex min-h-[68px] w-full items-center gap-3 rounded-2xl border border-[#2e2e2e] bg-[#151515] px-4 py-3.5 text-left hover:border-[#74797a] hover:bg-[#1f1f1f]"
                    >
                        <span class="min-w-0 flex-1">
                            <b class="block text-[15.5px] font-bold tracking-[-0.01em] text-white">{{ event.event_name }}</b>
                            <span class="text-[12.5px] text-[#abb0b2]">Tap to see its screenings</span>
                        </span>
                        <svg class="h-[17px] w-[17px] shrink-0 text-[#74797a]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round"><path d="m9 6 6 6-6 6" /></svg>
                    </button>

                    <p v-if="!events.length" class="py-8 text-center text-[13px] text-[#74797a]">
                        No films are running at the moment. Ask the tour coordinator to enable yours.
                    </p>
                    <p v-else class="text-[12.5px] text-[#74797a]">
                        Only films that are running show up here. Your screening's password comes next.
                    </p>
                </section>

                <!-- -------------------------------- step 2: screening -- -->
                <section v-else-if="step === 'screening'" class="grid gap-3.5">
                    <div>
                        <p class="text-[11.5px] font-bold uppercase tracking-[0.12em] text-[#74797a]">Step 2 of 3</p>
                        <h1 class="text-[clamp(27px,8vw,34px)] font-black leading-[1.06] tracking-[-0.03em] text-white">
                            Which screening?
                        </h1>
                    </div>

                    <div v-if="loading" class="py-10 text-center text-[13px] text-[#74797a]">
                        <svg class="mx-auto mb-3 h-6 w-6 animate-spin text-[#16c3d9]" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-80" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                        </svg>
                        Loading your screenings…
                    </div>

                    <template v-else>
                        <div v-if="locations.length > 6" class="relative">
                            <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[#74797a]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                            <input
                                v-model="search" type="search" placeholder="Venue or town…" autocomplete="off"
                                class="min-h-[52px] w-full rounded-xl border-[#2e2e2e] bg-[#1f1f1f] px-3.5 py-3 ps-10 text-[15px] text-white placeholder:text-[#74797a] focus:border-[#16c3d9] focus:ring-0"
                            />
                        </div>

                        <div v-for="night in nights" :key="night.key">
                            <p class="pb-2 pt-1 text-[11.5px] font-bold uppercase tracking-[0.1em] text-[#74797a]">
                                {{ night.label }}
                            </p>
                            <button
                                v-for="loc in night.list" :key="loc.id" type="button"
                                @click="pickLocation(loc)"
                                class="mb-2.5 flex min-h-[68px] w-full items-center gap-3 rounded-2xl border bg-[#151515] px-4 py-3.5 text-left hover:border-[#74797a] hover:bg-[#1f1f1f]"
                                :class="formatTime(loc.time) === 'TBA' ? 'border-dashed border-[#2e2e2e]' : 'border-[#2e2e2e]'"
                            >
                                <span class="min-w-0 flex-1">
                                    <b class="block text-[15.5px] font-bold tracking-[-0.01em] text-white">{{ loc.name }}</b>
                                    <span class="text-[12.5px] text-[#abb0b2]">
                                        {{ formatTime(loc.time) === 'TBA' ? 'Time not set yet' : formatTime(loc.time) }}
                                    </span>
                                </span>
                                <svg class="h-[17px] w-[17px] shrink-0 text-[#74797a]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round"><path d="m9 6 6 6-6 6" /></svg>
                            </button>
                        </div>

                        <p v-if="!nights.length" class="py-8 text-center text-[13px] text-[#74797a]">
                            {{ search ? 'No screening matches that.' : 'This film has no screenings yet.' }}
                        </p>
                    </template>
                </section>

                <!-- --------------------------------- step 3: password -- -->
                <section v-else class="grid gap-3.5">
                    <div>
                        <p class="text-[11.5px] font-bold uppercase tracking-[0.12em] text-[#74797a]">Step 3 of 3</p>
                        <h1 class="text-[clamp(27px,8vw,34px)] font-black leading-[1.06] tracking-[-0.03em] text-white">
                            Unlock this draw
                        </h1>
                        <p class="mt-1 text-[15px] text-[#abb0b2]">
                            The password for {{ selectedLocation?.name }} on {{ formatDate(selectedLocation?.date) }}. It is in your host pack.
                        </p>
                    </div>

                    <form @submit.prevent="submit" class="grid gap-2.5">
                        <!-- Padded the same on both sides so the text stays centred
                             with the eye sitting on the right. -->
                        <div class="relative">
                            <input
                                ref="pwBox" v-model="form.password" :type="showPassword ? 'text' : 'password'" inputmode="text"
                                autocapitalize="characters" autocomplete="off" spellcheck="false"
                                placeholder="PASSWORD" aria-label="Screening password"
                                class="min-h-[68px] w-full rounded-2xl border-2 bg-[#151515] px-16 py-4 text-center font-mono text-2xl tracking-[0.14em] text-white placeholder:tracking-normal placeholder:text-[#55595a] focus:ring-0"
                                :class="[
                                    form.errors.password ? 'border-[#f77d7d] focus:border-[#f77d7d]' : 'border-[#2e2e2e] focus:border-[#16c3d9]',
                                    showPassword ? 'uppercase' : '',
                                ]"
                            />
                            <button
                                type="button" @click="togglePassword"
                                :aria-label="showPassword ? 'Hide password' : 'Show password'" :aria-pressed="showPassword"
                                class="absolute right-2 top-1/2 grid h-12 w-12 -translate-y-1/2 place-items-center rounded-xl text-[#abb0b2] hover:bg-[#1f1f1f] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#16c3d9]"
                            >
                                <!-- Open eye: tap to show. Struck-through eye: showing, tap to hide. -->
                                <svg v-if="!showPassword" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z" /><circle cx="12" cy="12" r="3" />
                                </svg>
                                <svg v-else class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M10.6 5.1A10.5 10.5 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.5 6.6C3.6 8.5 2 12 2 12s3.6 7 10 7a10 10 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2M3 3l18 18" />
                                </svg>
                            </button>
                        </div>

                        <p v-if="form.errors.password" class="text-center text-[13.5px] font-semibold text-[#f77d7d]">
                            {{ form.errors.password }}
                        </p>

                        <button
                            type="submit" :disabled="!form.password.trim() || form.processing"
                            class="min-h-[68px] w-full rounded-2xl bg-[#16c3d9] text-lg font-extrabold text-[#00171c] disabled:opacity-40"
                        >
                            {{ form.processing ? 'Unlocking…' : 'Unlock the draw' }}
                        </button>
                    </form>

                    <button
                        type="button" @click="showHelp = !showHelp"
                        class="min-h-[46px] w-full rounded-xl text-[14px] font-semibold text-[#abb0b2] hover:bg-[#1f1f1f] hover:text-white"
                    >
                        I do not have the password
                    </button>

                    <div v-if="showHelp" class="rounded-2xl border border-[#2e2e2e] bg-[#151515] p-4">
                        <p class="text-[14px] text-[#abb0b2]">
                            Every screening has its own password, and it is in the host pack emailed before the night.
                        </p>
                        <p class="mt-2 text-[12.5px] text-[#74797a]">
                            If you cannot find it, the tour coordinator can read it out or reset it. Nobody can open a
                            draw without it — that is what stops the wrong person drawing winners for your night.
                        </p>
                        <button
                            type="button" @click="openInstructions"
                            class="mt-3 min-h-[44px] w-full rounded-xl border border-[#2e2e2e] bg-[#1f1f1f] px-4 text-[13.5px] font-semibold text-white hover:bg-[#2b2b2b]"
                        >
                            Open the host guide
                        </button>
                    </div>
                </section>
            </main>
        </div>
    </PickaWinnerLayout>
</template>
