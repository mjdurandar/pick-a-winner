<script setup>
import { ref, computed, watch, onUnmounted } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import Swal from 'sweetalert2';

const props = defineProps({
    driver: { type: String, default: 'mock' },
    isLive: { type: Boolean, default: false },
    // The tour sites this app can manage, and which one is on screen.
    connections: { type: Array, default: () => [] },
    site: { type: String, default: null },
    siteError: { type: String, default: null },
    films: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ region: null, season: null, search: null }) },
    taxonomies: { type: Object, default: () => ({ region: [], season: [] }) },
    groups: { type: Array, default: () => [] },
    loadError: { type: String, default: null },
    maxLogoKb: { type: Number, default: 4096 },
    siteUrl: { type: String, default: null },
    pages: { type: Array, default: () => [] },
    film: { type: Object, default: null },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

// The groups, pages and terms are read from the film site, which takes seconds,
// so the server sends the page without them and they arrive in a second
// request. Until they do the props are simply absent — which is the only way to
// tell "still loading" from "loaded, and there are none".
const loaded = computed(() => page.props.groups !== undefined);

// -- which film site --------------------------------------------------------
//
// Each tour is a separate WordPress with its own partners, its own media
// library and its own term ids, so every request has to say which one it means.
// Routes are built through r() rather than route() for exactly that reason: a
// call that forgot the site would write to whichever site the server picked,
// which is the one mistake this screen must not be able to make.

const connected = computed(() => props.connections.filter((c) => c.configured));
const currentSite = computed(() => props.site ?? '');
const siteLabel = computed(() => props.connections.find((c) => c.key === currentSite.value)?.label ?? '');

const r = (name, params = {}) => route(name, { ...params, site: currentSite.value });

function switchSite(key) {
    if (key === currentSite.value) return;

    // Region and season are deliberately dropped: the term ids belong to the
    // site being left and mean something else, or nothing, on the next one.
    router.get(route('sponsorDashboard.index'), { site: key }, { preserveScroll: true });
}

const termName = (tax, id) => props.taxonomies[tax]?.find((t) => t.id === id)?.name ?? `#${id}`;
const maxLogoMb = computed(() => (props.maxLogoKb / 1024).toFixed(1).replace(/\.0$/, ''));
const sizeLabel = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);

// A partner-group has no page of its own — the film site's partner-page
// templates pull it in, and its own URL 404s as a draft and redirects away when
// published. wp-admin is the only place to see one on its own, and it works
// whatever the status.
const adminUrl = (group) => props.siteUrl
    ? `${props.siteUrl}/wp-admin/post.php?post=${group.id}&action=edit`
    : null;

// -- filters ----------------------------------------------------------------

const region = ref(props.filters.region ?? '');
const season = ref(props.filters.season ?? '');
const search = ref(props.filters.search ?? '');

function reload() {
    loadingWhat.value = 'Loading partners…';
    router.get(route('sponsorDashboard.index'), {
        site: currentSite.value || undefined,
        region: region.value || undefined,
        season: season.value || undefined,
        search: search.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        // Only the groups change with a filter. Asking for just them skips the
        // read of every partners page, and keeps the lists already on screen.
        only: ['groups', 'filters', 'film', 'flash', 'errors', 'loadError'],
    });
}

/** Drop the film scope and show everything again. */
function clearFilm() {
    region.value = '';
    season.value = '';
    router.get(route('sponsorDashboard.index'), { site: currentSite.value || undefined }, { preserveScroll: true });
}

watch([region, season], () => reload());

let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => reload(), 350);
});

// A film is not something the film sites model. Picking one asks the server to
// translate it, because the answer is three things at once — which tour site it
// publishes to, and the region and season recorded for it — and only the server
// knows what those terms are called on that particular site.
function filterByFilm(film) {
    router.get(route('sponsorDashboard.index'), { film: film.event_id }, { preserveScroll: true });
}

const totalPartners = computed(() => props.groups.reduce((n, g) => n + g.partners.length, 0));

// -- adding a partner to a group --------------------------------------------

const openGroup = ref(null);          // group id whose add-form is showing
const editing = ref(null);            // { groupId, index } being edited
const form = ref({ website_url: '', text: '', max_height: 100 });
const logo = ref(null);
const logoPreview = ref(null);
const logoError = ref(null);
const busy = ref(false);

function resetForm() {
    form.value = { website_url: '', text: '', max_height: 100 };
    logo.value = null;
    logoPreview.value = null;
    logoError.value = null;
    editing.value = null;
}

function startAdd(group) {
    resetForm();
    openGroup.value = openGroup.value === group.id ? null : group.id;
}

function startEdit(group, partner) {
    resetForm();
    openGroup.value = group.id;
    editing.value = { groupId: group.id, index: partner.index };
    form.value = {
        website_url: partner.website_url ?? '',
        text: partner.text ?? '',
        max_height: partner.max_height ?? 100,
    };
    logoPreview.value = partner.logo_url ?? null;
}

function pickLogo(e) {
    const file = e.target.files[0] ?? null;
    logoError.value = null;

    // Refuse an oversized file on selection rather than after an upload the
    // server would only then reject.
    if (file && file.size > props.maxLogoKb * 1024) {
        logoError.value = `That image is ${sizeLabel(file.size)}. The limit is ${maxLogoMb.value} MB — try resizing or exporting it at a lower quality.`;
        e.target.value = '';
        logo.value = null;

        return;
    }

    logo.value = file;
    logoPreview.value = file ? URL.createObjectURL(file) : logoPreview.value;
}

function savePartner(group) {
    const data = {
        website_url: form.value.website_url,
        text: form.value.text,
        max_height: form.value.max_height ?? 100,
    };
    if (logo.value) data.logo = logo.value;

    const url = editing.value
        ? r('sponsorDashboard.partners.update', { group: group.id, index: editing.value.index })
        : r('sponsorDashboard.partners.store', { group: group.id });

    busy.value = true;
    router.post(url, data, {
        forceFormData: true,
        preserveScroll: true,
        ...AFTER_PARTNER,
        onSuccess: () => {
            resetForm();
            openGroup.value = null;
        },
        onError: (e) => {
            const first = Object.values(e ?? {}).find((m) => typeof m === 'string' && m);
            Swal.fire('Not saved', first || 'Check the highlighted fields.', 'error');
        },
        onFinish: () => { busy.value = false; },
    });
}

function removePartner(group, partner) {
    Swal.fire({
        title: 'Remove this partner?',
        text: props.isLive
            ? 'It comes off the live partners page. The logo stays in the WordPress media library.'
            : 'Mock store only — nothing is sent to WordPress.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Remove',
        confirmButtonColor: '#dc2626',
    }).then((res) => {
        if (!res.isConfirmed) return;
        router.delete(r('sponsorDashboard.partners.destroy', { group: group.id, index: partner.index }), {
            preserveScroll: true,
            ...AFTER_PARTNER,
        });
    });
}

// -- groups -----------------------------------------------------------------

const showNewGroup = ref(false);
const showPageOrder = ref(false);
const blankGroup = () => ({ title: '', heading: '', status: 'draft', regions: [], seasons: [] });
const newGroup = ref(blankGroup());

function openNewGroup() {
    newGroup.value = blankGroup();
    showNewGroup.value = true;
}

function saveGroup() {
    busy.value = true;
    router.post(r('sponsorDashboard.groups.store'), newGroup.value, {
        preserveScroll: true,
        ...AFTER_PAGE,
        onSuccess: () => {
            newGroup.value = blankGroup();
            showNewGroup.value = false;
        },
        onError: (e) => {
            const first = Object.values(e ?? {}).find((m) => typeof m === 'string' && m);
            Swal.fire('Not saved', first || 'Check the highlighted fields.', 'error');
        },
        onFinish: () => { busy.value = false; },
    });
}

// -- loading ----------------------------------------------------------------
// Saving a page runs the whole WordPress theme and can take several seconds,
// so silence would read as a dead button. Driven by Inertia's own events, which
// means it covers every request on this page rather than only the ones with a
// bespoke handler.

const loading = ref(false);
const loadingWhat = ref('Working…');

// The background fetch of the deferred props is not blocked on: the page says
// it is loading where the partners will appear, and the site picker stays usable.
const stopStart = router.on('start', (e) => {
    if (e.detail.visit.async) return;
    loading.value = true;
});
const stopFinish = router.on('finish', (e) => {
    if (e.detail.visit.async) return;
    loading.value = false;
    loadingWhat.value = 'Working…';
});
onUnmounted(() => { stopStart(); stopFinish(); });

/** Say what is happening, since a page save is slow enough to wonder about. */
function withMessage(message, run) {
    loadingWhat.value = message;
    run();
}

// -- ordering, by dragging --------------------------------------------------
// The repeater's order is the order the logos appear in, and a page's list is
// the order the tier blocks appear down it. So dragging here moves the real
// thing. Plain HTML5 drag events rather than a library: these are short
// single-column lists and a dependency would not earn its place.
//
// The list is reordered locally first so the row follows the cursor, then the
// whole order is sent. The server checks the submitted order still names
// exactly the rows it holds and refuses it otherwise, so a stale page cannot
// drop or duplicate a partner.

// Only ask for the props that changed. Each one is a round trip to WordPress
// taking a second or more, so a full reload after every click spends three of
// them to refresh one. `flash`, `errors` and `loadError` always come too, or
// the page would keep showing a stale message.
const AFTER_PARTNER = { only: ['groups', 'flash', 'errors', 'loadError'] };
const AFTER_PAGE = { only: ['groups', 'pages', 'flash', 'errors', 'loadError'] };

const dragging = ref(null);   // { kind: 'partner'|'group', ownerId, from }

function dragStart(kind, ownerId, from, event) {
    dragging.value = { kind, ownerId, from };
    event.dataTransfer.effectAllowed = 'move';
    // Firefox will not start a drag without something set here.
    event.dataTransfer.setData('text/plain', String(from));
}

function dragEnd() {
    dragging.value = null;
}

/** True when the thing being dragged belongs to this list. */
const isDragging = (kind, ownerId) => dragging.value?.kind === kind && dragging.value?.ownerId === ownerId;

function moved(list, from, to) {
    const next = [...list];
    next.splice(to, 0, next.splice(from, 1)[0]);

    return next;
}

function dropPartner(group, to) {
    if (!isDragging('partner', group.id)) return;

    const from = dragging.value.from;
    dragging.value = null;
    if (from === to) return;

    const order = moved(group.partners.map((p) => p.index), from, to);
    group.partners = moved(group.partners, from, to);   // follow the cursor now

    loadingWhat.value = 'Saving partner order…';
    router.post(r('sponsorDashboard.partners.reorder', { group: group.id }), { order }, {
        preserveScroll: true,
        ...AFTER_PARTNER,
    });
}

function dropGroup(page, to) {
    if (!isDragging('group', page.id)) return;

    const from = dragging.value.from;
    dragging.value = null;
    if (from === to) return;

    const order = moved(page.group_ids, from, to);
    page.group_ids = order;

    loadingWhat.value = `Saving the order of ${page.title}…`;
    router.post(r('sponsorDashboard.pages.reorder', { page: page.id }), { order }, {
        preserveScroll: true,
        ...AFTER_PAGE,
    });
}

const groupById = (id) => props.groups.find((g) => g.id === id) ?? null;

// -- which pages show a group -----------------------------------------------
// A published group is only *allowed* to be shown. What puts it in front of
// anyone is a partners page listing it, so the screen says that outright
// rather than leaving it to be discovered by publishing and seeing nothing.

const pagesShowing = (group) => props.pages.filter((pg) => pg.group_ids.includes(group.id));

function togglePage(group, page) {
    const showing = page.group_ids.includes(group.id);

    Swal.fire({
        title: showing ? `Take it off ${page.title}?` : `Show it on ${page.title}?`,
        text: showing
            ? 'It comes off that page. The group and its partners are kept.'
            : 'The page starts showing this group straight away.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: showing ? 'Take it off' : 'Show it',
    }).then((res) => {
        if (!res.isConfirmed) return;
        busy.value = true;
        loadingWhat.value = showing ? `Taking it off ${page.title}…` : `Adding it to ${page.title}…`;
        router.post(r('sponsorDashboard.groups.place', { group: group.id }), {
            page_id: page.id,
            show: showing ? 0 : 1,
        }, {
            preserveScroll: true,
            ...AFTER_PAGE,
            onFinish: () => { busy.value = false; },
        });
    });
}

// -- editing an existing group ----------------------------------------------

const editGroupId = ref(null);
const groupForm = ref({ title: '', heading: '', status: 'draft', regions: [], seasons: [] });

function startEditGroup(group) {
    resetForm();
    openGroup.value = null;
    editGroupId.value = editGroupId.value === group.id ? null : group.id;
    groupForm.value = {
        title: group.title,
        heading: group.heading,
        status: group.status,
        regions: [...group.regions],
        seasons: [...group.seasons],
    };
}

function saveGroupEdit(group) {
    busy.value = true;
    router.post(r('sponsorDashboard.groups.update', { group: group.id }), groupForm.value, {
        preserveScroll: true,
        ...AFTER_PARTNER,
        onSuccess: () => { editGroupId.value = null; },
        onError: (e) => {
            const first = Object.values(e ?? {}).find((m) => typeof m === 'string' && m);
            Swal.fire('Not saved', first || 'Check the highlighted fields.', 'error');
        },
        onFinish: () => { busy.value = false; },
    });
}

/**
 * Publish or unpublish in one click.
 *
 * Status is the field anyone actually changes day to day — a group is drafted,
 * filled, then published — so it does not belong buried in an edit form.
 */
function toggleStatus(group) {
    const publishing = group.status !== 'publish';

    Swal.fire({
        title: publishing ? 'Publish this group?' : 'Unpublish this group?',
        text: publishing
            ? 'It appears on the partners page straight away.'
            : 'It comes off the partners page. Nothing in it is deleted.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: publishing ? 'Publish' : 'Unpublish',
    }).then((res) => {
        if (!res.isConfirmed) return;
        busy.value = true;
        router.post(r('sponsorDashboard.groups.update', { group: group.id }), {
            status: publishing ? 'publish' : 'draft',
        }, {
            preserveScroll: true,
            ...AFTER_PARTNER,
            onFinish: () => { busy.value = false; },
        });
    });
}

/* -- which group is on screen -------------------------------------------- */
// Fly Fishing has over a hundred groups, which will not fit down one page. So
// the left side picks and the right side shows. The choice follows the data:
// if the chosen group is filtered away or deleted, the first one left takes
// over, and the panel is never pointed at nothing.

const selectedId = ref(null);

const selected = computed(() =>
    props.groups.find((g) => g.id === selectedId.value) ?? props.groups[0] ?? null);

watch(() => props.groups, (groups) => {
    if (!groups.some((g) => g.id === selectedId.value)) {
        selectedId.value = groups[0]?.id ?? null;
    }
}, { immediate: true });

function selectGroup(group) {
    selectedId.value = group.id;
    resetForm();
    openGroup.value = null;
    editGroupId.value = null;
}

/* -- reordering without a mouse ------------------------------------------ */
// HTML5 drag events never fire on a touch screen, so every drag is also a pair
// of buttons. Both end in the same request.

function movePartner(group, from, delta) {
    const to = from + delta;
    if (to < 0 || to >= group.partners.length) return;

    const order = moved(group.partners.map((p) => p.index), from, to);
    group.partners = moved(group.partners, from, to);   // follow the tap now

    loadingWhat.value = 'Saving partner order…';
    router.post(r('sponsorDashboard.partners.reorder', { group: group.id }), { order }, {
        preserveScroll: true,
        ...AFTER_PARTNER,
    });
}

function moveGroupOnPage(pg, from, delta) {
    const to = from + delta;
    if (to < 0 || to >= pg.group_ids.length) return;

    const order = moved(pg.group_ids, from, to);
    pg.group_ids = order;

    loadingWhat.value = `Saving the order of ${pg.title}…`;
    router.post(r('sponsorDashboard.pages.reorder', { page: pg.id }), { order }, {
        preserveScroll: true,
        ...AFTER_PAGE,
    });
}

function deleteGroup(group) {
    Swal.fire({
        title: 'Delete this group?',
        text: props.isLive
            ? `"${group.title}" and its ${group.partners.length} partner row(s) go to the WordPress trash, where they can be restored.`
            : 'Mock store only — nothing is sent to WordPress.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Delete',
        confirmButtonColor: '#dc2626',
    }).then((res) => {
        if (!res.isConfirmed) return;
        router.delete(r('sponsorDashboard.groups.destroy', { group: group.id }), { preserveScroll: true, ...AFTER_PAGE });
    });
}
</script>

<template>
    <Head title="Partners" />

    <!-- Every write here goes over the network to WordPress, and a page save
         takes seconds. Block the screen while it runs so nothing is clicked
         twice and nobody wonders whether it worked. -->
    <Teleport to="body">
        <div v-if="loading" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/30">
            <div class="flex items-center gap-3 rounded-lg bg-white px-5 py-4 shadow-lg">
                <svg class="h-5 w-5 animate-spin text-[#0B7A8A]" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                </svg>
                <span class="text-sm font-medium text-gray-800">{{ loadingWhat }}</span>
            </div>
        </div>
    </Teleport>

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-end gap-4">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-xs font-semibold text-gray-500">
                        <span v-if="siteLabel">{{ siteLabel }} · </span>
                        <template v-if="loaded">
                            {{ groups.length }} group{{ groups.length === 1 ? '' : 's' }},
                            {{ totalPartners }} partner{{ totalPartners === 1 ? '' : 's' }}
                        </template>
                        <template v-else-if="!siteError">loading…</template>
                    </p>
                    <h2 class="truncate text-2xl font-extrabold tracking-tight text-gray-900">Partners</h2>
                </div>

                <!-- Three states, not two: unconfigured is neither live nor mock,
                     and calling it "mock" would suggest work is being saved. -->
                <span
                    class="rounded-full px-3 py-1 text-xs font-bold"
                    :class="{
                        'bg-emerald-50 text-emerald-800': isLive,
                        'bg-amber-50 text-amber-800': !isLive && driver === 'mock',
                        'bg-red-50 text-red-800': driver === 'unconfigured',
                    }"
                >
                    <template v-if="isLive">Live — writes go to WordPress</template>
                    <template v-else-if="driver === 'mock'">Mock — nothing reaches WordPress</template>
                    <template v-else>Not connected to a film site</template>
                </span>

                <button
                    v-if="loaded && !loadError && !siteError"
                    type="button" @click="openNewGroup"
                    class="h-10 rounded-lg bg-[#16C3D9] px-4 text-sm font-bold text-[#06242A] hover:brightness-105"
                >
                    New group
                </button>
            </div>
        </template>

        <div class="mx-auto max-w-full space-y-4 px-4 py-6 sm:px-6 lg:px-8">
            <div v-if="flash.success" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                {{ flash.success }}
            </div>
            <div v-if="flash.error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ flash.error }}
            </div>

            <!-- Which tour's site is being edited. Each is a separate WordPress
                 with its own partners and its own media library, so this is the
                 single most important thing on the screen: everything below
                 belongs to whichever one is selected. -->
            <!-- A dropdown rather than a row of buttons: there is one of these
                 per tour, and the row would not survive many more of them. -->
            <div v-if="connections.length > 1" class="flex flex-wrap items-end gap-x-4 gap-y-2 rounded-xl bg-white p-4 shadow-card">
                <label class="block w-full sm:w-80">
                    <span class="mb-1 block text-xs font-bold text-gray-600">Film site</span>
                    <select
                        :value="currentSite" @change="switchSite($event.target.value)"
                        class="h-10 w-full rounded-lg border-gray-200 text-sm font-semibold text-gray-900 focus:border-[#16C3D9] focus:ring-[#16C3D9]"
                    >
                        <option v-if="!currentSite" value="" disabled>Choose a film site</option>
                        <option v-for="c in connections" :key="c.key" :value="c.key" :disabled="!c.configured">
                            {{ c.label }}{{ c.configured ? '' : ' — not connected' }}
                        </option>
                    </select>
                </label>
                <p class="min-w-0 flex-1 pb-2.5 text-xs text-gray-500">
                    Everything below belongs to this site.
                    <span v-if="connected.length < connections.length">
                        A site shows as not connected until its address and application password are set on the server.
                    </span>
                </p>
            </div>

            <div v-if="siteError" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <p class="font-semibold">{{ siteError }}</p>
                <p class="mt-1">
                    Partners live on the tour websites, not in this app, so there is nothing to show until a site's
                    address and application password are set on the server. No partner data is affected.
                </p>
            </div>

            <div v-if="loadError" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <p class="font-semibold">
                    {{ driver === 'unconfigured' ? 'This app is not connected to a film site.' : 'Could not read partners from the film site.' }}
                </p>
                <p class="mt-1">{{ loadError }}</p>
                <p v-if="driver === 'unconfigured'" class="mt-2 text-xs">
                    Partners are stored on the film site, so nothing can be shown or saved until the connection is set
                    up. No partner data is lost.
                </p>
            </div>

            <!-- Arrived from a film. Say so plainly, including that a film is
                 not what the film sites actually tag partners by. -->
            <div v-if="film" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-[#BFEAF2] bg-[#ECFAFD] px-4 py-3">
                <p class="text-sm text-[#0B5563]">
                    Showing partners for <strong>{{ film.event_name }}</strong> — everything tagged
                    <strong>{{ film.region }}</strong> / <strong>{{ film.season }}</strong>, which is how the film site
                    groups them.
                </p>
                <div class="flex items-center gap-3">
                    <Link :href="route('events.show', { event: film.event_id })" class="text-xs font-semibold text-[#0B7A8A] underline">Back to the film</Link>
                    <button type="button" @click="clearFilm" class="text-xs font-semibold text-gray-600 underline">Show all partners</button>
                </div>
            </div>

            <!-- ============================================================
                 Groups on the left, the chosen one on the right.
                 ============================================================ -->
            <div
                v-if="!loaded && !siteError"
                class="flex flex-col items-center justify-center gap-3 rounded-xl bg-white px-6 py-20 text-center shadow-card"
                role="status" aria-live="polite"
            >
                <svg class="h-9 w-9 animate-spin text-[#16C3D9]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                </svg>
                <p class="text-sm font-bold text-gray-900">
                    Loading partners<span v-if="siteLabel"> from {{ siteLabel }}</span>…
                </p>
                <p class="text-xs text-gray-500">They are read live from the film site, so this can take a few seconds.</p>
            </div>

            <div v-if="loaded && !loadError && !siteError" class="grid gap-4 lg:grid-cols-[330px_minmax(0,1fr)]">

                <!-- ---------------------------------------- the list -- -->
                <div class="overflow-hidden rounded-xl bg-white shadow-card">
                    <div class="space-y-2 border-b border-gray-100 p-3">
                        <label class="flex h-10 items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3">
                            <svg class="h-4 w-4 shrink-0 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                            <span class="sr-only">Search groups</span>
                            <input v-model="search" type="search" placeholder="Search groups" class="w-full border-0 bg-transparent p-0 text-sm focus:ring-0" />
                        </label>
                        <div class="grid grid-cols-2 gap-2">
                            <select v-model="region" class="h-9 w-full rounded-lg border-gray-200 text-xs focus:border-[#16C3D9] focus:ring-[#16C3D9]" aria-label="Region">
                                <option value="">All regions</option>
                                <option v-for="t in taxonomies.region" :key="t.id" :value="t.id">{{ t.name }}</option>
                            </select>
                            <select v-model="season" class="h-9 w-full rounded-lg border-gray-200 text-xs focus:border-[#16C3D9] focus:ring-[#16C3D9]" aria-label="Season">
                                <option value="">All seasons</option>
                                <option v-for="t in taxonomies.season" :key="t.id" :value="t.id">{{ t.name }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="max-h-[340px] overflow-y-auto lg:max-h-[min(70vh,780px)]">
                        <button
                            v-for="group in groups" :key="group.id" type="button"
                            @click="selectGroup(group)"
                            class="grid w-full gap-0.5 border-b border-l-[3px] border-gray-100 px-3 py-2.5 text-left last:border-b-0"
                            :class="selected && group.id === selected.id
                                ? 'border-l-[#16C3D9] bg-[#ECFAFD]'
                                : 'border-l-transparent hover:bg-gray-50'"
                        >
                            <span class="flex items-center gap-2">
                                <span
                                    class="h-1.5 w-1.5 shrink-0 rounded-full"
                                    :class="group.status === 'publish' ? 'bg-emerald-500' : 'bg-gray-300'"
                                    :title="group.status === 'publish' ? 'Published' : 'Draft'"
                                ></span>
                                <span class="min-w-0 flex-1 truncate text-[13px] font-bold text-gray-900">{{ group.heading || group.title }}</span>
                                <span class="shrink-0 rounded border border-gray-200 bg-gray-50 px-1.5 text-[11px] tabular-nums text-gray-600">{{ group.partners.length }}</span>
                            </span>
                            <span class="truncate text-[11px] text-gray-400">{{ group.title }}</span>
                            <span class="truncate text-[11px]">
                                <span v-if="pagesShowing(group).length" class="text-gray-500">
                                    on {{ pagesShowing(group).length }} page{{ pagesShowing(group).length === 1 ? '' : 's' }}
                                </span>
                                <span v-else class="font-semibold text-amber-700">not on a page</span>
                            </span>
                        </button>

                        <p v-if="!groups.length" class="px-4 py-12 text-center text-sm text-gray-400">
                            No partner groups match these filters.
                        </p>
                    </div>
                </div>

                <!-- -------------------------------------- the group -- -->
                <div v-if="selected" class="space-y-4">
                    <div class="rounded-xl bg-white shadow-card">
                        <div class="grid gap-3 p-4">
                            <div class="min-w-0">
                                <h3 class="text-xl font-extrabold tracking-tight text-gray-900">{{ selected.heading || selected.title }}</h3>
                                <p class="break-words font-mono text-xs text-gray-400">{{ selected.title }}</p>
                            </div>

                            <div class="flex flex-wrap gap-1.5">
                                <span v-for="id in selected.regions" :key="`r${id}`" class="rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs font-medium text-gray-600">{{ termName('region', id) }}</span>
                                <span v-for="id in selected.seasons" :key="`s${id}`" class="rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs font-medium text-gray-600">{{ termName('season', id) }}</span>
                                <span class="rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs font-medium text-gray-600">
                                    {{ selected.partners.length }} partner{{ selected.partners.length === 1 ? '' : 's' }}
                                </span>
                                <span
                                    v-for="pg in pagesShowing(selected)" :key="pg.id"
                                    class="rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-bold text-emerald-800"
                                >{{ pg.title }} · #{{ pg.group_ids.indexOf(selected.id) + 1 }}</span>
                                <span v-if="!pagesShowing(selected).length" class="rounded-md bg-amber-50 px-2 py-0.5 text-xs font-bold text-amber-800">
                                    Not on any page — nobody can see it
                                </span>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <!-- The badge is the control: status is what changes most often. -->
                                <button
                                    type="button" :disabled="busy" @click="toggleStatus(selected)"
                                    class="h-10 rounded-lg border px-4 text-sm font-bold disabled:opacity-50"
                                    :class="selected.status === 'publish'
                                        ? 'border-emerald-300 bg-emerald-50 text-emerald-800 hover:bg-emerald-100'
                                        : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'"
                                    :title="selected.status === 'publish' ? 'Click to unpublish' : 'Click to publish'"
                                >
                                    {{ selected.status === 'publish' ? 'Published' : 'Draft' }}
                                </button>
                                <button type="button" @click="startAdd(selected)" class="h-10 rounded-lg bg-[#16C3D9] px-4 text-sm font-bold text-[#06242A] hover:brightness-105">
                                    {{ openGroup === selected.id && !editing ? 'Close' : '+ Add partner' }}
                                </button>
                                <button type="button" @click="startEditGroup(selected)" class="h-10 rounded-lg border border-gray-200 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                    {{ editGroupId === selected.id ? 'Close' : 'Edit group' }}
                                </button>
                                <a v-if="adminUrl(selected)" :href="adminUrl(selected)" target="_blank" class="flex h-10 items-center rounded-lg border border-gray-200 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">Open in WordPress</a>
                                <button type="button" @click="deleteGroup(selected)" class="h-10 rounded-lg border border-gray-200 px-4 text-sm font-semibold text-red-700 hover:border-red-300 hover:bg-red-50">Delete</button>
                            </div>
                        </div>

                        <!-- Edit the group itself -->
                        <div v-if="editGroupId === selected.id" class="border-t border-gray-200 bg-gray-50 p-4">
                            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <label class="block lg:col-span-2">
                                    <span class="text-xs font-bold text-gray-600">Admin title</span>
                                    <input v-model="groupForm.title" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" />
                                </label>
                                <label class="block lg:col-span-2">
                                    <span class="text-xs font-bold text-gray-600">Heading shown on the page</span>
                                    <input v-model="groupForm.heading" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" />
                                </label>
                                <label class="block">
                                    <span class="text-xs font-bold text-gray-600">Region</span>
                                    <select v-model="groupForm.regions" multiple class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]">
                                        <option v-for="t in taxonomies.region" :key="t.id" :value="t.id">{{ t.name }}</option>
                                    </select>
                                </label>
                                <label class="block">
                                    <span class="text-xs font-bold text-gray-600">Season</span>
                                    <select v-model="groupForm.seasons" multiple class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]">
                                        <option v-for="t in taxonomies.season" :key="t.id" :value="t.id">{{ t.name }}</option>
                                    </select>
                                </label>
                                <label class="block">
                                    <span class="text-xs font-bold text-gray-600">Status</span>
                                    <select v-model="groupForm.status" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]">
                                        <option value="draft">Draft</option>
                                        <option value="publish">Published</option>
                                    </select>
                                </label>
                                <div class="flex items-end gap-3">
                                    <button type="button" :disabled="busy" @click="saveGroupEdit(selected)" class="h-10 rounded-lg bg-[#16C3D9] px-4 text-sm font-bold text-[#06242A] disabled:opacity-50">
                                        {{ busy ? 'Saving…' : 'Save group' }}
                                    </button>
                                    <button type="button" @click="editGroupId = null" class="text-sm text-gray-600 underline">Cancel</button>
                                </div>
                            </div>

                            <div v-if="pages.length" class="mt-4 border-t border-gray-200 pt-3">
                                <p class="text-xs font-bold text-gray-700">Show this group on</p>
                                <p class="mt-0.5 text-[11px] text-gray-500">
                                    Publishing only allows a group to be shown. A page has to list it before anyone sees it.
                                </p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <button
                                        v-for="pg in pages" :key="pg.id" type="button" :disabled="loading"
                                        @click="togglePage(selected, pg)"
                                        class="h-9 rounded-full border px-4 text-xs font-semibold disabled:opacity-50"
                                        :class="pg.group_ids.includes(selected.id)
                                            ? 'border-emerald-300 bg-emerald-50 text-emerald-800'
                                            : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'"
                                    >
                                        {{ pg.group_ids.includes(selected.id) ? '✓ ' : '+ ' }}{{ pg.title }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Add / edit a partner -->
                        <div v-if="openGroup === selected.id" class="border-t border-gray-200 bg-gray-50 p-4">
                            <p class="mb-3 text-sm font-bold text-gray-900">
                                {{ editing ? 'Edit partner' : 'Add a partner to this group' }}
                            </p>
                            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <label class="block">
                                    <span class="text-xs font-bold text-gray-600">Logo</span>
                                    <input type="file" accept="image/*" @change="pickLogo" class="mt-1 w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-200 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-gray-700" />
                                    <span v-if="logoError" class="mt-1 block text-[11px] font-semibold text-red-700">{{ logoError }}</span>
                                    <span v-else-if="editing" class="mt-1 block text-[11px] text-gray-400">Leave empty to keep the current logo.</span>
                                    <span v-else class="mt-1 block text-[11px] text-gray-400">JPG, PNG, GIF, SVG or WebP, up to {{ maxLogoMb }} MB.</span>
                                </label>
                                <label class="block">
                                    <span class="text-xs font-bold text-gray-600">Website</span>
                                    <input v-model="form.website_url" placeholder="https://" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" />
                                </label>
                                <label class="block">
                                    <span class="text-xs font-bold text-gray-600">Label <span class="font-normal text-gray-400">(optional)</span></span>
                                    <input v-model="form.text" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" />
                                </label>
                                <label class="block">
                                    <span class="text-xs font-bold text-gray-600">Max height (px)</span>
                                    <input v-model.number="form.max_height" type="number" min="20" max="600" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" />
                                </label>
                            </div>
                            <div class="mt-3 flex flex-wrap items-center gap-3">
                                <img v-if="logoPreview" :src="logoPreview" alt="" class="h-10 w-auto max-w-[140px] object-contain" />
                                <button type="button" :disabled="busy" @click="savePartner(selected)" class="h-10 rounded-lg bg-[#16C3D9] px-4 text-sm font-bold text-[#06242A] disabled:opacity-50">
                                    {{ busy ? 'Saving…' : (editing ? 'Save partner' : 'Add partner') }}
                                </button>
                                <button type="button" @click="resetForm(); openGroup = null" class="text-sm text-gray-600 underline">Cancel</button>
                            </div>
                        </div>
                    </div>

                    <!-- Partners in this group -->
                    <div class="overflow-hidden rounded-xl bg-white shadow-card">
                        <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-4 py-3">
                            <h4 class="text-xs font-bold uppercase tracking-wider text-gray-500">Partners</h4>
                            <span class="text-xs text-gray-400">Top to bottom is the order they appear on the page.</span>
                        </div>

                        <div v-if="selected.partners.length" class="divide-y divide-gray-100">
                            <div
                                v-for="(p, i) in selected.partners" :key="p.index"
                                draggable="true"
                                @dragstart="dragStart('partner', selected.id, p.index, $event)"
                                @dragend="dragEnd"
                                @dragover.prevent
                                @drop.prevent="dropPartner(selected, p.index)"
                                class="flex flex-wrap items-center gap-3 px-4 py-3 hover:bg-gray-50"
                                :class="isDragging('partner', selected.id) ? 'cursor-grabbing' : ''"
                            >
                                <!-- Drag works with a mouse; the arrows work everywhere. -->
                                <span class="grid shrink-0 gap-1">
                                    <button type="button" :disabled="i === 0" @click="movePartner(selected, i, -1)" class="grid h-5 w-7 place-items-center rounded border border-gray-200 text-[9px] text-gray-500 disabled:opacity-30 hover:enabled:border-[#16C3D9] hover:enabled:text-[#0B7A8A]" aria-label="Move up">▲</button>
                                    <button type="button" :disabled="i === selected.partners.length - 1" @click="movePartner(selected, i, 1)" class="grid h-5 w-7 place-items-center rounded border border-gray-200 text-[9px] text-gray-500 disabled:opacity-30 hover:enabled:border-[#16C3D9] hover:enabled:text-[#0B7A8A]" aria-label="Move down">▼</button>
                                </span>

                                <img v-if="p.logo_url" :src="p.logo_url" alt="" class="h-10 w-auto max-w-[140px] shrink-0 object-contain" />
                                <span v-else class="shrink-0 rounded bg-gray-100 px-2 py-1 text-[11px] text-gray-500">no logo</span>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold text-gray-900">{{ p.text || '—' }}</p>
                                    <a v-if="p.website_url" :href="p.website_url" target="_blank" class="block truncate font-mono text-xs text-[#0B7A8A] underline">{{ p.website_url }}</a>
                                </div>

                                <span class="shrink-0 text-[11px] text-gray-400">max {{ p.max_height }}px</span>

                                <div class="flex shrink-0 gap-2">
                                    <button type="button" @click="startEdit(selected, p)" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">Edit</button>
                                    <button type="button" @click="removePartner(selected, p)" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-red-700 hover:border-red-300 hover:bg-red-50">Remove</button>
                                </div>
                            </div>
                        </div>

                        <p v-else class="px-4 py-10 text-center text-sm text-gray-400">
                            No partners in this group yet. Use <strong>Add partner</strong> above.
                        </p>
                    </div>
                </div>

                <div v-else class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-16 text-center text-sm text-gray-400">
                    No partner groups match these filters.
                </div>
            </div>

            <!-- Page layout: which blocks a page shows, and in what order.
                 Separate from the group list because this is the page's running
                 order, not a property of any one group. -->
            <div v-if="pages.length && !loadError && !siteError" class="overflow-hidden rounded-xl bg-white shadow-card">
                <button type="button" @click="showPageOrder = !showPageOrder" class="flex w-full items-center justify-between gap-3 p-4 text-left">
                    <span>
                        <span class="text-sm font-bold text-gray-900">Page order</span>
                        <span class="ml-2 text-xs text-gray-500">The order blocks appear down each partners page</span>
                    </span>
                    <span class="text-xs font-semibold text-gray-500">{{ showPageOrder ? 'Hide' : 'Show' }}</span>
                </button>

                <div v-if="showPageOrder" class="grid gap-4 border-t border-gray-100 p-4 sm:grid-cols-2 xl:grid-cols-3">
                    <div v-for="pg in pages" :key="pg.id">
                        <p class="text-xs font-bold text-gray-700">{{ pg.title }}</p>
                        <p v-if="!pg.group_ids.length" class="mt-1 text-xs text-gray-400">No groups on this page.</p>
                        <ol v-else class="mt-2 space-y-1">
                            <li
                                v-for="(gid, i) in pg.group_ids" :key="gid"
                                draggable="true"
                                @dragstart="dragStart('group', pg.id, i, $event)"
                                @dragend="dragEnd"
                                @dragover.prevent
                                @drop.prevent="dropGroup(pg, i)"
                                class="flex items-center gap-2 rounded-md border border-gray-200 bg-gray-50 px-2 py-1.5 text-xs text-gray-800 hover:bg-gray-100"
                            >
                                <span class="w-4 shrink-0 text-gray-400">{{ i + 1 }}</span>
                                <span class="min-w-0 flex-1 truncate">
                                    {{ groupById(gid)?.heading || groupById(gid)?.title || `Group #${gid}` }}
                                </span>
                                <span v-if="groupById(gid)" class="shrink-0 text-gray-400">{{ groupById(gid).partners.length }}</span>
                                <span class="flex shrink-0 gap-1">
                                    <button type="button" :disabled="i === 0" @click="moveGroupOnPage(pg, i, -1)" class="grid h-5 w-6 place-items-center rounded border border-gray-200 bg-white text-[9px] text-gray-500 disabled:opacity-30 hover:enabled:border-[#16C3D9] hover:enabled:text-[#0B7A8A]" aria-label="Move up">▲</button>
                                    <button type="button" :disabled="i === pg.group_ids.length - 1" @click="moveGroupOnPage(pg, i, 1)" class="grid h-5 w-6 place-items-center rounded border border-gray-200 bg-white text-[9px] text-gray-500 disabled:opacity-30 hover:enabled:border-[#16C3D9] hover:enabled:text-[#0B7A8A]" aria-label="Move down">▼</button>
                                </span>
                            </li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- How this maps to the site, stated rather than assumed. Last,
                 because it is read once and then never again. -->
            <details class="rounded-xl bg-white shadow-card p-4">
                <summary class="cursor-pointer text-sm font-semibold text-gray-700">How partners pages are put together</summary>
                <p class="mt-2 text-sm text-gray-600">
                    A partners page is built from <strong>groups</strong> — one per tier, per region, per season. A
                    partner is a logo and a link inside a group. Use the arrows to reorder a partner within its group,
                    or open <strong>Page order</strong> to change the order the groups appear down a page. Films are not
                    part of that model, so picking a film just opens its tour's site at the region and season recorded
                    for it.
                </p>
                <div v-if="films.length" class="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3">
                    <span class="text-xs text-gray-500">Jump to a film:</span>
                    <button
                        v-for="f in films" :key="f.id" type="button" @click="filterByFilm(f)"
                        class="rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                    >
                        {{ f.event_name }} <span class="text-gray-400">{{ f.region }}/{{ f.season }}</span>
                    </button>
                </div>
            </details>
        </div>
        <!-- New group -->
        <Modal :show="showNewGroup" max-width="2xl" title="New group" @close="showNewGroup = false">
            <form id="new-group-form" @submit.prevent="saveGroup" class="grid gap-4 p-6 sm:grid-cols-2">
                <p v-if="siteLabel" class="text-sm text-gray-600 sm:col-span-2">
                    This group is created on <strong class="text-gray-900">{{ siteLabel }}</strong>.
                </p>
                <label class="block sm:col-span-2">
                    <span class="text-xs font-bold text-gray-600">Admin title</span>
                    <input v-model="newGroup.title" required placeholder="WAFT AU – SUPPORTING PARTNERS 2026" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" />
                    <span class="mt-1 block text-xs text-gray-500">Only seen in WordPress, to tell groups apart.</span>
                </label>
                <label class="block sm:col-span-2">
                    <span class="text-xs font-bold text-gray-600">Heading shown on the page</span>
                    <input v-model="newGroup.heading" placeholder="SUPPORTING PARTNERS" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]" />
                </label>
                <label class="block">
                    <span class="text-xs font-bold text-gray-600">Region</span>
                    <select v-model="newGroup.regions" multiple class="mt-1 h-32 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]">
                        <option v-for="t in taxonomies.region" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-bold text-gray-600">Season</span>
                    <select v-model="newGroup.seasons" multiple class="mt-1 h-32 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]">
                        <option v-for="t in taxonomies.season" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                </label>
                <p class="-mt-2 text-xs text-gray-500 sm:col-span-2">Hold Cmd (Ctrl on Windows) to pick more than one region or season.</p>
                <label class="block">
                    <span class="text-xs font-bold text-gray-600">Status</span>
                    <select v-model="newGroup.status" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]">
                        <option value="draft">Draft</option>
                        <option value="publish">Published</option>
                    </select>
                </label>
            </form>

            <template #footer>
                <button type="button" @click="showNewGroup = false" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" form="new-group-form" :disabled="busy" class="rounded-lg bg-[#16C3D9] px-4 py-2 text-sm font-bold text-[#06242A] hover:brightness-105 disabled:opacity-50">
                    {{ busy ? 'Creating…' : 'Create group' }}
                </button>
            </template>
        </Modal>
    </AuthenticatedLayout>
</template>
