<script setup>
import { ref, computed, watch, onUnmounted } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Swal from 'sweetalert2';

const props = defineProps({
    driver: { type: String, default: 'mock' },
    isLive: { type: Boolean, default: false },
    sites: { type: Array, default: () => [] },
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
    router.get(route('sponsorDashboard.index'), {
        region: region.value || undefined,
        season: season.value || undefined,
        search: search.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

/** Drop the film scope and show everything again. */
function clearFilm() {
    region.value = '';
    season.value = '';
    router.get(route('sponsorDashboard.index'), {}, { preserveScroll: true });
}

watch([region, season], () => reload());

let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => reload(), 350);
});

// A film is not something the film sites model. Picking one here sets the
// region and season already recorded for that event, which is the same thing
// expressed the way WordPress stores it.
function filterByFilm(site) {
    const term = (tax, value) => props.taxonomies[tax]?.find(
        (t) => t.slug?.toLowerCase() === String(value).toLowerCase() || t.name?.toLowerCase() === String(value).toLowerCase(),
    );

    region.value = term('region', site.region)?.id ?? '';
    season.value = term('season', site.season)?.id ?? '';
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
        ? route('sponsorDashboard.partners.update', { group: group.id, index: editing.value.index })
        : route('sponsorDashboard.partners.store', { group: group.id });

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
    }).then((r) => {
        if (!r.isConfirmed) return;
        router.delete(route('sponsorDashboard.partners.destroy', { group: group.id, index: partner.index }), {
            preserveScroll: true,
            ...AFTER_PARTNER,
        });
    });
}

// -- groups -----------------------------------------------------------------

const showNewGroup = ref(false);
const showPageOrder = ref(false);
const newGroup = ref({ title: '', heading: '', status: 'draft', regions: [], seasons: [] });

function saveGroup() {
    busy.value = true;
    router.post(route('sponsorDashboard.groups.store'), newGroup.value, {
        preserveScroll: true,
        ...AFTER_PAGE,
        onSuccess: () => {
            newGroup.value = { title: '', heading: '', status: 'draft', regions: [], seasons: [] };
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

const stopStart = router.on('start', () => { loading.value = true; });
const stopFinish = router.on('finish', () => { loading.value = false; loadingWhat.value = 'Working…'; });
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
    router.post(route('sponsorDashboard.partners.reorder', { group: group.id }), { order }, {
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
    router.post(route('sponsorDashboard.pages.reorder', { page: page.id }), { order }, {
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
    }).then((r) => {
        if (!r.isConfirmed) return;
        busy.value = true;
        loadingWhat.value = showing ? `Taking it off ${page.title}…` : `Adding it to ${page.title}…`;
        router.post(route('sponsorDashboard.groups.place', { group: group.id }), {
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
    router.post(route('sponsorDashboard.groups.update', { group: group.id }), groupForm.value, {
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
    }).then((r) => {
        if (!r.isConfirmed) return;
        busy.value = true;
        router.post(route('sponsorDashboard.groups.update', { group: group.id }), {
            status: publishing ? 'publish' : 'draft',
        }, {
            preserveScroll: true,
            ...AFTER_PARTNER,
            onFinish: () => { busy.value = false; },
        });
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
    }).then((r) => {
        if (!r.isConfirmed) return;
        router.delete(route('sponsorDashboard.groups.destroy', { group: group.id }), { preserveScroll: true, ...AFTER_PAGE });
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
                <svg class="h-5 w-5 animate-spin text-indigo-600" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                </svg>
                <span class="text-sm font-medium text-gray-800">{{ loadingWhat }}</span>
            </div>
        </div>
    </Teleport>

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Partners</h2>
                <span
                    :class="isLive ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'"
                    class="rounded-full px-3 py-1 text-xs font-medium"
                >
                    {{ isLive ? 'Live — writes go to WordPress' : 'Mock — nothing reaches WordPress' }}
                </span>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-5 px-4 sm:px-6 lg:px-8">
                <div v-if="flash.success" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ flash.success }}
                </div>
                <div v-if="flash.error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ flash.error }}
                </div>

                <div v-if="loadError" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    <p class="font-medium">Could not read partners from the film site.</p>
                    <p class="mt-1">{{ loadError }}</p>
                </div>

                <!-- Arrived from a film. Say so plainly, including that a film is
                     not what the film sites actually tag partners by. -->
                <div v-if="film" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-indigo-200 bg-indigo-50/50 px-4 py-3">
                    <p class="text-sm text-indigo-900">
                        Showing partners for <strong>{{ film.event_name }}</strong> —
                        everything tagged <strong>{{ film.region }}</strong> / <strong>{{ film.season }}</strong>,
                        which is how the film site groups them.
                    </p>
                    <div class="flex items-center gap-3">
                        <Link :href="route('events.show', { event: film.event_id })" class="text-xs font-medium text-indigo-700 underline">
                            Back to the film
                        </Link>
                        <button type="button" @click="clearFilm" class="text-xs font-medium text-gray-600 underline">Show all partners</button>
                    </div>
                </div>

                <!-- How this maps to the site, stated rather than assumed. -->
                <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-600">
                    A partners page is built from <strong>groups</strong> — one per tier, per region, per season.
                    A partner is a logo and a link inside a group. Drag a partner to reorder it within its group,
                    or open <strong>Page order</strong> to change the order the groups appear down a page.
                    Films are not part of that model, so picking a film below just sets the region and season recorded for it.
                </div>

                <!-- Filters -->
                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <label class="block">
                            <span class="text-xs font-medium text-gray-700">Region</span>
                            <select v-model="region" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                <option value="">All regions</option>
                                <option v-for="t in taxonomies.region" :key="t.id" :value="t.id">{{ t.name }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-700">Season</span>
                            <select v-model="season" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                <option value="">All seasons</option>
                                <option v-for="t in taxonomies.season" :key="t.id" :value="t.id">{{ t.name }}</option>
                            </select>
                        </label>
                        <label class="block lg:col-span-2">
                            <span class="text-xs font-medium text-gray-700">Search groups</span>
                            <input v-model="search" type="search" placeholder="e.g. supporting" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                        </label>
                    </div>

                    <div v-if="sites.length" class="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3">
                        <span class="text-xs text-gray-500">Jump to a film:</span>
                        <button
                            v-for="s in sites" :key="s.id" type="button" @click="filterByFilm(s)"
                            class="rounded-full border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50"
                        >
                            {{ s.event_name }}
                            <span class="text-gray-400">{{ s.region }}/{{ s.season }}</span>
                        </button>
                    </div>
                </div>

                <!-- Page layout: which blocks a page shows, and in what order.
                     Separate from the group list below because this is the page's
                     running order, not a property of any one group. -->
                <div v-if="pages.length" class="rounded-lg border border-gray-200 bg-white">
                    <button type="button" @click="showPageOrder = !showPageOrder" class="flex w-full items-center justify-between p-4 text-left">
                        <span>
                            <span class="text-sm font-medium text-gray-900">Page order</span>
                            <span class="ml-2 text-xs text-gray-500">Drag to change the order blocks appear down each partners page</span>
                        </span>
                        <span class="text-xs text-gray-500">{{ showPageOrder ? 'Hide' : 'Show' }}</span>
                    </button>

                    <div v-if="showPageOrder" class="grid gap-4 border-t border-gray-100 p-4 sm:grid-cols-2">
                        <div v-for="pg in pages" :key="pg.id">
                            <p class="text-xs font-medium text-gray-700">{{ pg.title }}</p>
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
                                    <span class="cursor-grab select-none text-gray-300" title="Drag to reorder">⠿</span>
                                    <span class="w-4 text-gray-400">{{ i + 1 }}</span>
                                    <span class="truncate">
                                        {{ groupById(gid)?.heading || groupById(gid)?.title || `Group #${gid}` }}
                                    </span>
                                    <span v-if="groupById(gid)" class="ml-auto shrink-0 text-gray-400">
                                        {{ groupById(gid).partners.length }}
                                    </span>
                                </li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between">
                    <p class="text-sm text-gray-600">
                        {{ groups.length }} group{{ groups.length === 1 ? '' : 's' }},
                        {{ totalPartners }} partner{{ totalPartners === 1 ? '' : 's' }}
                    </p>
                    <button type="button" @click="showNewGroup = !showNewGroup" class="text-sm font-medium text-indigo-600 underline">
                        {{ showNewGroup ? 'Cancel' : 'New group' }}
                    </button>
                </div>

                <!-- New group -->
                <div v-if="showNewGroup" class="rounded-lg border border-indigo-200 bg-indigo-50/40 p-4">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <label class="block lg:col-span-2">
                            <span class="text-xs font-medium text-gray-700">Admin title</span>
                            <input v-model="newGroup.title" placeholder="WAFT AU – SUPPORTING PARTNERS 2026" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                        </label>
                        <label class="block lg:col-span-2">
                            <span class="text-xs font-medium text-gray-700">Heading shown on the page</span>
                            <input v-model="newGroup.heading" placeholder="SUPPORTING PARTNERS" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-700">Region</span>
                            <select v-model="newGroup.regions" multiple class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                <option v-for="t in taxonomies.region" :key="t.id" :value="t.id">{{ t.name }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-700">Season</span>
                            <select v-model="newGroup.seasons" multiple class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                <option v-for="t in taxonomies.season" :key="t.id" :value="t.id">{{ t.name }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-700">Status</span>
                            <select v-model="newGroup.status" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                <option value="draft">Draft</option>
                                <option value="publish">Published</option>
                            </select>
                        </label>
                        <div class="flex items-end">
                            <button type="button" :disabled="busy" @click="saveGroup" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                                {{ busy ? 'Saving…' : 'Create group' }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Groups -->
                <div v-for="group in groups" :key="group.id" class="rounded-lg border border-gray-200 bg-white">
                    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 p-4">
                        <div>
                            <p class="font-medium text-gray-900">{{ group.title }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">
                                Heading: <strong>{{ group.heading || '—' }}</strong>
                                · <span v-for="id in group.regions" :key="id">{{ termName('region', id) }} </span>
                                · <span v-for="id in group.seasons" :key="id">{{ termName('season', id) }} </span>
                                · {{ group.partners.length }} partner{{ group.partners.length === 1 ? '' : 's' }}
                            </p>
                            <p class="mt-1 text-xs">
                                <template v-if="pagesShowing(group).length">
                                    <span class="text-gray-500">Shows on:</span>
                                    <span v-for="pg in pagesShowing(group)" :key="pg.id" class="ml-1 rounded bg-emerald-50 px-1.5 py-0.5 font-medium text-emerald-800">
                                        {{ pg.title }} <span class="opacity-60">#{{ pg.group_ids.indexOf(group.id) + 1 }}</span>
                                    </span>
                                </template>
                                <span v-else class="rounded bg-amber-50 px-1.5 py-0.5 font-medium text-amber-800">
                                    Not on any page — nobody can see it yet
                                </span>
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <!-- The badge is the control: status is what changes most often. -->
                            <button
                                type="button" :disabled="busy" @click="toggleStatus(group)"
                                :class="group.status === 'publish' ? 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                                class="rounded-full px-2 py-0.5 text-[11px] font-medium disabled:opacity-50"
                                :title="group.status === 'publish' ? 'Click to unpublish' : 'Click to publish'"
                            >
                                {{ group.status === 'publish' ? 'Published' : 'Draft' }}
                            </button>
                            <a v-if="adminUrl(group)" :href="adminUrl(group)" target="_blank" class="text-xs text-indigo-600 underline">Open in WordPress</a>
                            <button type="button" @click="startEditGroup(group)" class="text-xs font-medium text-gray-700 underline">
                                {{ editGroupId === group.id ? 'Close' : 'Edit group' }}
                            </button>
                            <button type="button" @click="deleteGroup(group)" class="text-xs font-medium text-red-600 underline">Delete</button>
                            <button type="button" @click="startAdd(group)" class="rounded-md bg-gray-900 px-3 py-1.5 text-xs font-medium text-white">
                                {{ openGroup === group.id && !editing ? 'Close' : 'Add partner' }}
                            </button>
                        </div>
                    </div>

                    <!-- Edit the group itself: title, heading, where it belongs, status -->
                    <div v-if="editGroupId === group.id" class="border-b border-gray-200 bg-gray-50 p-4">
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <label class="block lg:col-span-2">
                                <span class="text-xs font-medium text-gray-700">Admin title</span>
                                <input v-model="groupForm.title" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                            </label>
                            <label class="block lg:col-span-2">
                                <span class="text-xs font-medium text-gray-700">Heading shown on the page</span>
                                <input v-model="groupForm.heading" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                            </label>
                            <label class="block">
                                <span class="text-xs font-medium text-gray-700">Region</span>
                                <select v-model="groupForm.regions" multiple class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                    <option v-for="t in taxonomies.region" :key="t.id" :value="t.id">{{ t.name }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-xs font-medium text-gray-700">Season</span>
                                <select v-model="groupForm.seasons" multiple class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                    <option v-for="t in taxonomies.season" :key="t.id" :value="t.id">{{ t.name }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-xs font-medium text-gray-700">Status</span>
                                <select v-model="groupForm.status" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                    <option value="draft">Draft</option>
                                    <option value="publish">Published</option>
                                </select>
                            </label>
                            <div class="flex items-end gap-3">
                                <button type="button" :disabled="busy" @click="saveGroupEdit(group)" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                                    {{ busy ? 'Saving…' : 'Save group' }}
                                </button>
                                <button type="button" @click="editGroupId = null" class="text-sm text-gray-600 underline">Cancel</button>
                            </div>
                        </div>

                        <div v-if="pages.length" class="mt-4 border-t border-gray-200 pt-3">
                            <p class="text-xs font-medium text-gray-700">Show this group on</p>
                            <p class="mt-0.5 text-[11px] text-gray-500">
                                Publishing only allows a group to be shown. A page has to list it before anyone sees it.
                            </p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button
                                    v-for="pg in pages" :key="pg.id" type="button" :disabled="loading"
                                    @click="togglePage(group, pg)"
                                    :class="pg.group_ids.includes(group.id)
                                        ? 'border-emerald-300 bg-emerald-50 text-emerald-800'
                                        : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50'"
                                    class="rounded-full border px-3 py-1 text-xs font-medium disabled:opacity-50"
                                >
                                    {{ pg.group_ids.includes(group.id) ? '✓ ' : '+ ' }}{{ pg.title }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div v-if="group.partners.length" class="divide-y divide-gray-100">
                        <div
                            v-for="p in group.partners" :key="p.index"
                            draggable="true"
                            @dragstart="dragStart('partner', group.id, p.index, $event)"
                            @dragend="dragEnd"
                            @dragover.prevent
                            @drop.prevent="dropPartner(group, p.index)"
                            :class="isDragging('partner', group.id) ? 'cursor-grabbing' : ''"
                            class="flex flex-wrap items-center gap-4 p-4 hover:bg-gray-50"
                        >
                            <span class="cursor-grab select-none text-gray-300" title="Drag to reorder">⠿</span>
                            <img v-if="p.logo_url" :src="p.logo_url" alt="" class="h-10 w-auto max-w-[140px] object-contain" />
                            <span v-else class="rounded bg-gray-100 px-2 py-1 text-[11px] text-gray-500">no logo</span>

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm text-gray-900">{{ p.text || '—' }}</p>
                                <a v-if="p.website_url" :href="p.website_url" target="_blank" class="truncate text-xs text-indigo-600 underline">{{ p.website_url }}</a>
                            </div>

                            <span class="text-[11px] text-gray-400">max {{ p.max_height }}px</span>

                            <div class="flex items-center gap-2">
                                <button type="button" @click="startEdit(group, p)" class="text-xs font-medium text-gray-700 underline">Edit</button>
                                <button type="button" @click="removePartner(group, p)" class="text-xs font-medium text-red-600 underline">Remove</button>
                            </div>
                        </div>
                    </div>
                    <p v-else class="px-4 py-6 text-center text-sm text-gray-400">No partners in this group yet.</p>

                    <!-- Add / edit a partner -->
                    <div v-if="openGroup === group.id" class="border-t border-gray-200 bg-gray-50 p-4">
                        <p class="mb-3 text-sm font-medium text-gray-900">
                            {{ editing ? 'Edit partner' : 'Add a partner to this group' }}
                        </p>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <label class="block">
                                <span class="text-xs font-medium text-gray-700">Logo</span>
                                <input type="file" accept="image/*" @change="pickLogo" class="mt-1 w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-200 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-gray-700" />
                                <span v-if="logoError" class="mt-1 block text-[11px] text-red-600">{{ logoError }}</span>
                                <span v-else-if="editing" class="mt-1 block text-[11px] text-gray-400">Leave empty to keep the current logo.</span>
                                <span v-else class="mt-1 block text-[11px] text-gray-400">JPG, PNG, GIF, SVG or WebP, up to {{ maxLogoMb }} MB.</span>
                            </label>
                            <label class="block">
                                <span class="text-xs font-medium text-gray-700">Website</span>
                                <input v-model="form.website_url" placeholder="https://" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                            </label>
                            <label class="block">
                                <span class="text-xs font-medium text-gray-700">Label <span class="text-gray-400">(optional)</span></span>
                                <input v-model="form.text" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                            </label>
                            <label class="block">
                                <span class="text-xs font-medium text-gray-700">Max height (px)</span>
                                <input v-model.number="form.max_height" type="number" min="20" max="600" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                            </label>
                        </div>

                        <div class="mt-3 flex items-center gap-3">
                            <img v-if="logoPreview" :src="logoPreview" alt="" class="h-10 w-auto max-w-[140px] object-contain" />
                            <button type="button" :disabled="busy" @click="savePartner(group)" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                                {{ busy ? 'Saving…' : (editing ? 'Save partner' : 'Add partner') }}
                            </button>
                            <button type="button" @click="resetForm(); openGroup = null" class="text-sm text-gray-600 underline">Cancel</button>
                        </div>
                    </div>
                </div>

                <p v-if="!groups.length && !loadError" class="rounded-lg border border-dashed border-gray-300 bg-white py-10 text-center text-sm text-gray-500">
                    No partner groups match these filters.
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
