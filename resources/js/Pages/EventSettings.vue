<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Swal from 'sweetalert2';

const props = defineProps({
    event: { type: Object, required: true },
    films: { type: Array, default: () => [] },
    counts: { type: Object, default: () => ({}) },
    partners: { type: Array, default: () => [] },
    defaultCoordinatorPhone: { type: String, default: '' },
    maxImageKb: { type: Number, default: 2048 },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

// A plain object rather than useForm: this posts multipart (logo/banner) via
// router.post with _method=patch, so useForm's helpers buy little here.
const form = ref({
    event_name: props.event.event_name ?? '',
    film_id: props.event.film_id ?? null,
    event_year: props.event.event_year ?? '',
    event_date: props.event.event_date ?? '',
    event_end_date: props.event.event_end_date ?? '',
    event_country: props.event.event_country ?? '',
    instructions_password: props.event.instructions_password ?? '',
    event_coordinator: props.event.event_coordinator ?? '',
    event_coordinator_email: props.event.event_coordinator_email ?? '',
    event_coordinator_phone: props.event.event_coordinator_phone ?? '',
    is_enabled: !!props.event.is_enabled,
    show_all_locations: !!props.event.show_all_locations,
    show_all_locations_draw: !!props.event.show_all_locations_draw,
});

const original = JSON.stringify(form.value);
const newLogo = ref(null);
const newBanner = ref(null);
const logoPreview = ref(null);
const bannerPreview = ref(null);
const errors = ref({});
const saving = ref(false);

// Caught here as well as on the server, so an oversized image is refused the
// moment it is chosen rather than after a slow upload that then fails.
const fileErrors = ref({ logo: null, banner: null });
const maxImageMb = computed(() => (props.maxImageKb / 1024).toFixed(1).replace(/\.0$/, ''));
const sizeLabel = (bytes) => bytes >= 1048576
    ? `${(bytes / 1048576).toFixed(1)} MB`
    : `${Math.max(1, Math.round(bytes / 1024))} KB`;

const dirty = computed(() => JSON.stringify(form.value) !== original || !!newLogo.value || !!newBanner.value);

function pickFile(e, which) {
    const file = e.target.files[0] ?? null;
    fileErrors.value[which] = null;

    if (file && file.size > props.maxImageKb * 1024) {
        fileErrors.value[which] = `That image is ${sizeLabel(file.size)}. The limit is ${maxImageMb.value} MB — try resizing or exporting it at a lower quality.`;
        e.target.value = '';
        if (which === 'logo') { newLogo.value = null; logoPreview.value = null; }
        else { newBanner.value = null; bannerPreview.value = null; }

        return;
    }

    const preview = file ? URL.createObjectURL(file) : null;

    if (which === 'logo') { newLogo.value = file; logoPreview.value = preview; }
    else { newBanner.value = file; bannerPreview.value = preview; }
}

function save() {
    errors.value = {};

    const data = new FormData();
    Object.entries(form.value).forEach(([k, v]) => {
        if (typeof v === 'boolean') data.append(k, v ? 1 : 0);
        else if (v !== null && v !== '') data.append(k, v);
    });
    // Sent even when empty: blank means "no end date", so sign-ups stay open.
    if (form.value.event_end_date === '') data.append('event_end_date', '');
    if (newLogo.value) data.append('event_logo', newLogo.value);
    if (newBanner.value) data.append('event_banner', newBanner.value);
    data.append('_method', 'patch');

    router.post(route('events.update', props.event.id), data, {
        forceFormData: true,
        preserveScroll: true,
        onStart: () => { saving.value = true; },
        onFinish: () => { saving.value = false; },
        onError: (e) => {
            errors.value = e;
            // Name the first problem: the offending field can be well below the fold
            // on a page this wide, so "check the highlighted fields" alone is no help.
            const first = Object.values(e ?? {}).find((m) => typeof m === 'string' && m);
            Swal.fire('Not saved', first || 'Check the highlighted fields.', 'error');
        },
        onSuccess: () => Swal.fire({ icon: 'success', title: 'Saved', timer: 1300, showConfirmButton: false }),
    });
}

const n = (v) => (v ?? 0).toLocaleString();

const partnersByTier = computed(() => {
    const out = {};
    for (const p of props.partners) (out[p.tier || 'No tier'] ??= []).push(p);
    return out;
});

const countries = ['AUSTRALIA & NEW ZEALAND', 'USA & CANADA', 'USA', 'Canada', 'United Kingdom', 'Europe'];
</script>

<template>
    <Head :title="event.event_name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <Link :href="route('events.index')" class="text-sm text-gray-500 hover:text-gray-700">&larr; Films</Link>
                    <h2 class="mt-1 truncate text-xl font-semibold leading-tight text-gray-800">{{ event.event_name }}</h2>
                </div>
                <div class="flex items-center gap-3">
                    <span v-if="dirty" class="text-xs text-amber-700">Unsaved changes</span>
                    <span v-else-if="event.is_enabled" class="rounded-md bg-green-50 px-3 py-1 text-xs font-medium text-green-800">Live</span>
                    <span v-else class="rounded-md bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600">Hidden</span>
                    <button type="button" @click="save" :disabled="saving || !dirty"
                            class="rounded-md bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700 disabled:opacity-40">
                        {{ saving ? 'Saving…' : 'Save changes' }}
                    </button>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-full space-y-4 px-4 sm:px-6 lg:px-8">

                <div v-if="flash.success" class="rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">{{ flash.success }}</div>
                <div v-if="flash.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">{{ flash.error }}</div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div v-for="s in [
                        { label: 'Screenings', value: counts.locations },
                        { label: 'Prizes', value: counts.prizes },
                        { label: 'Winners drawn', value: counts.winners },
                    ]" :key="s.label" class="rounded-lg bg-gray-50 p-4">
                        <p class="text-xs text-gray-500">{{ s.label }}</p>
                        <p class="mt-0.5 text-2xl font-semibold text-gray-900">{{ n(s.value) }}</p>
                    </div>
                </div>

                <section class="rounded-lg bg-white p-5 shadow-sm">
                    <h3 class="text-base font-semibold text-gray-900">Event details</h3>
                    <p class="mt-1 text-sm text-gray-600">What the event is and when it runs.</p>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                        <label class="text-xs text-gray-600">
                            Event name
                            <input v-model="form.event_name" type="text" maxlength="26" class="mt-1 w-full rounded-md border-gray-300 text-sm" :class="{ 'border-red-400': errors.event_name }" />
                            <span v-if="errors.event_name" class="mt-1 block text-xs text-red-700">{{ errors.event_name }}</span>
                        </label>
                        <label class="text-xs text-gray-600">
                            Film <span class="text-red-600">*</span>
                            <select v-model="form.film_id" class="mt-1 w-full rounded-md border-gray-300 text-sm" :class="{ 'border-red-400': errors.film_id }">
                                <option :value="null" disabled>Choose a film</option>
                                <option v-for="f in films" :key="f.id" :value="f.id">{{ f.name }}</option>
                            </select>
                            <span v-if="errors.film_id" class="mt-1 block text-xs text-red-700">{{ errors.film_id }}</span>
                        </label>
                        <label class="text-xs text-gray-600">
                            Event year
                            <input v-model="form.event_year" type="number" class="mt-1 w-full rounded-md border-gray-300 text-sm" :class="{ 'border-red-400': errors.event_year }" />
                        </label>

                        <label class="text-xs text-gray-600">
                            Start date
                            <input v-model="form.event_date" type="date" class="mt-1 w-full rounded-md border-gray-300 text-sm" :class="{ 'border-red-400': errors.event_date }" />
                        </label>
                        <label class="text-xs text-gray-600">
                            End date
                            <input v-model="form.event_end_date" type="date" class="mt-1 w-full rounded-md border-gray-300 text-sm" :class="{ 'border-red-400': errors.event_end_date }" />
                            <span class="mt-1 block text-[11px] text-gray-400">Last day people can sign up. Blank keeps it open.</span>
                            <span v-if="errors.event_end_date" class="block text-xs text-red-700">{{ errors.event_end_date }}</span>
                        </label>
                        <label class="text-xs text-gray-600">
                            Country
                            <select v-model="form.event_country" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                <option v-for="c in countries" :key="c" :value="c">{{ c }}</option>
                            </select>
                        </label>
                    </div>
                </section>

                <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
                    <section class="rounded-lg bg-white p-5 shadow-sm">
                        <h3 class="text-base font-semibold text-gray-900">Host and coordinator</h3>
                        <p class="mt-1 text-sm text-gray-600">Shown on the shared host guide.</p>

                        <div class="mt-4 space-y-3">
                            <label class="block text-xs text-gray-600">
                                Host guide password
                                <input v-model="form.instructions_password" type="text" class="mt-1 w-full rounded-md border-gray-300 text-sm" placeholder="Auto-generated if left blank" />
                                <span class="mt-1 block text-[11px] text-gray-400">Password hosts enter to open the guide.</span>
                            </label>
                            <label class="block text-xs text-gray-600">
                                Coordinator name
                                <input v-model="form.event_coordinator" type="text" class="mt-1 w-full rounded-md border-gray-300 text-sm" :class="{ 'border-red-400': errors.event_coordinator }" />
                            </label>
                            <label class="block text-xs text-gray-600">
                                Coordinator email
                                <input v-model="form.event_coordinator_email" type="email" class="mt-1 w-full rounded-md border-gray-300 text-sm" :class="{ 'border-red-400': errors.event_coordinator_email }" />
                                <span v-if="errors.event_coordinator_email" class="mt-1 block text-xs text-red-700">{{ errors.event_coordinator_email }}</span>
                            </label>
                            <label class="block text-xs text-gray-600">
                                Coordinator phone
                                <input v-model="form.event_coordinator_phone" type="text" class="mt-1 w-full rounded-md border-gray-300 text-sm" :placeholder="defaultCoordinatorPhone" />
                                <span class="mt-1 block text-[11px] text-gray-400">Blank uses the default support number.</span>
                            </label>
                        </div>
                    </section>

                    <section class="rounded-lg bg-white p-5 shadow-sm">
                        <h3 class="text-base font-semibold text-gray-900">Branding</h3>
                        <p class="mt-1 text-sm text-gray-600">Leave a field empty to keep the current image.</p>

                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <p class="text-xs text-gray-600">Event logo</p>
                                <div class="mt-1 flex h-24 items-center justify-center overflow-hidden rounded-md border border-dashed border-gray-300 bg-gray-50">
                                    <img v-if="logoPreview || event.event_logo_url" :src="logoPreview || event.event_logo_url" alt="Event logo" class="max-h-full max-w-full object-contain" />
                                    <span v-else class="text-xs text-gray-400">no logo set</span>
                                </div>
                                <input type="file" accept="image/*" @change="pickFile($event, 'logo')" class="mt-2 w-full text-xs" />
                                <span v-if="fileErrors.logo" class="mt-1 block text-xs text-red-700">{{ fileErrors.logo }}</span>
                                <span v-else-if="errors.event_logo" class="mt-1 block text-xs text-red-700">{{ errors.event_logo }}</span>
                                <span v-else-if="logoPreview" class="mt-1 block text-[11px] text-amber-700">New logo — not saved until you press Save.</span>
                                <span v-else class="mt-1 block text-[11px] text-gray-400">JPG, PNG or GIF, up to {{ maxImageMb }} MB.</span>
                            </div>
                            <div>
                                <p class="text-xs text-gray-600">Event banner</p>
                                <div class="mt-1 flex h-24 items-center justify-center overflow-hidden rounded-md border border-dashed border-gray-300 bg-gray-50">
                                    <img v-if="bannerPreview || event.event_banner_url" :src="bannerPreview || event.event_banner_url" alt="Event banner" class="max-h-full max-w-full object-contain" />
                                    <span v-else class="text-xs text-gray-400">no banner set</span>
                                </div>
                                <input type="file" accept="image/*" @change="pickFile($event, 'banner')" class="mt-2 w-full text-xs" />
                                <span v-if="fileErrors.banner" class="mt-1 block text-xs text-red-700">{{ fileErrors.banner }}</span>
                                <span v-else-if="errors.event_banner" class="mt-1 block text-xs text-red-700">{{ errors.event_banner }}</span>
                                <span v-else-if="bannerPreview" class="mt-1 block text-[11px] text-amber-700">New banner — not saved until you press Save.</span>
                                <span v-else class="mt-1 block text-[11px] text-gray-400">JPG, PNG or GIF, up to {{ maxImageMb }} MB.</span>
                            </div>
                        </div>
                    </section>

                <section class="rounded-lg bg-white p-5 shadow-sm">
                    <h3 class="text-base font-semibold text-gray-900">Visibility</h3>
                    <p class="mt-1 text-sm text-gray-600">Where this event and its locations appear.</p>

                    <div class="mt-4 space-y-3">
                        <label class="flex items-start gap-3 text-sm">
                            <input v-model="form.is_enabled" type="checkbox" class="mt-1 rounded border-gray-300" />
                            <span>
                                Show in the Pick a Winner dropdown
                                <span class="mt-0.5 block text-xs text-gray-500">Controls the event list, not the locations. When off, this event is hidden from the Pick a Winner login and no one can open its draw. Does not affect the sign-up form.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-3 text-sm">
                            <input v-model="form.show_all_locations" type="checkbox" class="mt-1 rounded border-gray-300" />
                            <span>
                                Show all locations on the sign-up form
                                <span class="mt-0.5 block text-xs text-gray-500">Includes finished locations. When off, the sign-up form only lists locations from 2 days ago to 3 weeks ahead.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-3 text-sm">
                            <input v-model="form.show_all_locations_draw" type="checkbox" class="mt-1 rounded border-gray-300" />
                            <span>
                                Show all locations on the Pick a Winner login
                                <span class="mt-0.5 block text-xs text-gray-500">Includes finished locations. When off, the draw only lists locations from 7 days ago onwards.</span>
                            </span>
                        </label>
                    </div>
                </section>
                </div>

                <!--
                    Partners: hidden until sponsor upload to the film sites is live.
                    The data is already supplied by EventsController::show, so this
                    only needs uncommenting once that ships.

                                <section class="rounded-lg bg-white p-5 shadow-sm">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h3 class="text-base font-semibold text-gray-900">Partners</h3>
                                            <p class="mt-1 text-sm text-gray-600">Sponsors for this event. Edited on the sponsor dashboard, so there is only one place they change.</p>
                                        </div>
                                        <Link :href="route('sponsorDashboard.index')"
                                              class="shrink-0 rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                            Manage partners
                                        </Link>
                                    </div>

                                    <p v-if="!partners.length" class="mt-4 text-sm text-gray-500">No partners recorded for this event.</p>

                                    <div v-for="(group, tier) in partnersByTier" :key="tier" class="mt-4">
                                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ tier }}</p>
                                        <ul class="mt-2 divide-y divide-gray-100">
                                            <li v-for="p in group" :key="p.id" class="flex flex-wrap items-center gap-3 py-2">
                                                <img v-if="p.logo_url" :src="p.logo_url" :alt="p.name" class="h-8 w-16 shrink-0 object-contain" />
                                                <span v-else class="flex h-8 w-16 shrink-0 items-center justify-center rounded border border-dashed border-gray-300 text-[10px] text-gray-400">logo</span>
                                                <p class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900">{{ p.name }}</p>
                                                <a v-if="p.website_url" :href="p.website_url" target="_blank" rel="noopener" class="max-w-[180px] truncate text-xs text-teal-700 underline">{{ p.website_url }}</a>
                                                <span :class="['rounded px-2 py-0.5 text-xs font-medium', p.is_published ? 'bg-green-50 text-green-800' : 'bg-gray-100 text-gray-600']">
                                                    {{ p.is_published ? 'Published' : 'Hidden' }}
                                                </span>
                                            </li>
                                        </ul>
                                    </div>
                                </section>
                -->

                <section class="rounded-lg bg-white p-5 shadow-sm">
                    <h3 class="text-base font-semibold text-gray-900">Reports and tools</h3>
                    <p class="mt-1 text-sm text-gray-600">Everything for this event, in one place.</p>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <Link :href="route('location.index', { event: event.id })" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Locations</Link>
                        <Link :href="route('mastersheet.index')" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Master sheet</Link>
                        <a :href="route('signup.embed', { event_uuid: event.event_uuid })" target="_blank" rel="noopener" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Sign-up form</a>
                        <a :href="route('pickawinner.hostguide', { event_uuid: event.event_uuid })" target="_blank" rel="noopener" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Host guide</a>
                        <Link :href="route('newsletterResubscribes.index', { event: event.id })" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Newsletter report</Link>
                    </div>
                </section>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
