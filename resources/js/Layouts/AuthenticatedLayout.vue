<script setup>
/**
 * The app shell: one top navigation bar, as it has always been.
 *
 * What changes is what you can reach from it. Eleven destinations used to be
 * findable only by clicking your own name, and their badges were down there
 * with them — so nothing told you seven sheet changes were waiting. They now
 * sit in a grouped Tools menu whose counts show on the bar itself.
 *
 * The slot API is unchanged — `#header` and the default slot — so every page
 * written against this layout keeps working untouched.
 */
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';

const showingNavigationDropdown = ref(false);

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
const userRole = computed(() => user.value?.role);
const isAdmin = computed(() => userRole.value === 'admin');
const isStaff = computed(() => userRole.value === 'admin' || userRole.value === 'host');

// Whether this account owns the master sheet sync. Decided on the server (see
// User::canManageMasterSheet) so the address lives in one place and the nav
// cannot disagree with what the routes actually allow.
const canManageMasterSheet = computed(() => page.props.canManageMasterSheet === true);

// Film site checks whose last run failed or found errors (admins only).
const filmSiteAlerts = computed(() => page.props.filmSiteAlerts ?? 0);

// Master sheet tabs holding changes nobody has accepted yet.
const sheetReview = computed(() => page.props.sheetReview ?? null);
const reviewCount = computed(() => sheetReview.value?.count ?? 0);

// Removals are called out separately from edits: one is a batch to approve, the
// other is a question about deleting a location and its attendees.
const removedCount = computed(() => sheetReview.value?.missing ?? 0);

const reviewSummary = computed(() => {
    const tabs = sheetReview.value?.tabs ?? [];
    if (!tabs.length) return '';

    return tabs
        .map((t) => {
            const bits = [];
            if (t.pending) bits.push(`${t.pending} change${t.pending === 1 ? '' : 's'}`);
            if (t.missing) bits.push(`${t.missing} removed from the sheet`);

            return `${t.tab_name} (${bits.join(', ')})`;
        })
        .join(', ');
});

/**
 * Everything behind the Tools menu, grouped.
 *
 * Only routes that need no parameters belong here — a film's sign-up forms and
 * its brand report are reached from the film, because they cannot be named
 * without one.
 */
const toolGroups = computed(() => [
    {
        title: 'Mailchimp',
        items: [
            { label: 'MC Sync', route: 'sheetSync.index', show: canManageMasterSheet.value, badge: reviewCount.value, tone: 'warn' },
            { label: 'MC Import', route: 'mailchimpImport.index', show: isAdmin.value },
            { label: 'MC Dashboard', route: 'mcDashboard.index', show: isStaff.value },
            { label: 'Resubscribes', route: 'newsletterResubscribes.index', show: isAdmin.value },
        ],
    },
    {
        title: 'Logs and report',
        items: [
            { label: 'Film Site Check', route: 'filmSiteChecks.index', show: isAdmin.value, badge: filmSiteAlerts.value, tone: 'bad' },
            { label: 'Activity Logs', route: 'admin.exportLogs.index', show: isAdmin.value },
            { label: 'Laravel Logs', route: 'laravelLogs.index', show: isStaff.value },
            { label: 'Weekly Report', route: 'weekly-report', show: isStaff.value },
        ],
    },
    {
        title: 'Admin',
        items: [
            { label: 'Users', route: 'users.index', show: isAdmin.value },
            { label: 'Profile', route: 'profile.edit', show: true },
        ],
    },
].map((g) => ({ ...g, items: g.items.filter((i) => i.show) })).filter((g) => g.items.length));

/** What the Tools button itself has to announce. */
const toolAlerts = computed(() => reviewCount.value + filmSiteAlerts.value);

// Ziggy throws on a name it does not know, which would take the whole shell
// down rather than one link. A missing route simply yields no href.
const href = (name) => {
    try {
        return route(name);
    } catch (e) {
        return null;
    }
};

const isCurrent = (name) => {
    try {
        return route().current(name);
    } catch (e) {
        return false;
    }
};
</script>

<template>
    <div>
        <div class="min-h-screen bg-white">
            <nav class="border-b border-gray-200 bg-white">
                <!-- Primary Navigation Menu -->
                <div class="mx-auto max-w-full px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between">
                        <div class="flex min-w-0">
                            <!-- Logo -->
                            <div class="flex shrink-0 items-center">
                                <Link :href="href('dashboard') ?? '/'">
                                    <ApplicationLogo class="block h-9 w-auto fill-current text-gray-800" />
                                </Link>
                            </div>

                            <!-- Navigation Links -->
                            <div class="hidden space-x-7 sm:-my-px sm:ms-10 sm:flex">
                                <NavLink
                                    v-if="isAdmin"
                                    :href="href('dashboard') ?? '#'"
                                    :active="isCurrent('dashboard')"
                                >
                                    Dashboard
                                </NavLink>
                                <NavLink
                                    v-if="isStaff"
                                    :href="href('events.index') ?? '#'"
                                    :active="isCurrent('events.index')"
                                >
                                    Films
                                </NavLink>
                                <NavLink
                                    v-if="isStaff"
                                    :href="href('films.index') ?? '#'"
                                    :active="isCurrent('films.index')"
                                >
                                    Websites
                                </NavLink>
                                <NavLink
                                    v-if="isAdmin"
                                    :href="href('sponsorDashboard.index') ?? '#'"
                                    :active="isCurrent('sponsorDashboard.index')"
                                >
                                    Partners
                                </NavLink>
                                <NavLink
                                    v-if="isStaff"
                                    :href="href('pickawinner.index') ?? '#'"
                                    :active="isCurrent('pickawinner.index')"
                                    target="_blank"
                                >
                                    Pick a Winner
                                </NavLink>
                            </div>
                        </div>

                        <div class="hidden sm:ms-6 sm:flex sm:items-center sm:gap-3">
                            <!-- Tools: everything that used to hide under the user's name -->
                            <Dropdown v-if="toolGroups.length" align="right" width="auto" content-classes="bg-white">
                                <template #trigger>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-semibold leading-4 transition duration-150 ease-in-out focus:outline-none"
                                        :class="toolAlerts > 0
                                            ? 'border-[#16C3D9] bg-[#ECFAFD] text-[#0B7A8A] hover:bg-[#DDF4F9]'
                                            : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50 hover:text-gray-900'"
                                    >
                                        Tools
                                        <span
                                            v-if="toolAlerts > 0"
                                            class="inline-flex min-w-[18px] items-center justify-center rounded-full bg-[#C2410C] px-1.5 text-[11px] font-bold text-white"
                                        >{{ toolAlerts }}</span>
                                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                        </svg>
                                    </button>
                                </template>

                                <template #content>
                                    <div class="grid gap-5 p-5" :class="toolGroups.length >= 4 ? 'sm:grid-cols-4' : 'sm:grid-cols-3'">
                                        <div v-for="group in toolGroups" :key="group.title">
                                            <p class="px-2 pb-1 text-[10px] font-bold uppercase tracking-[0.1em] text-gray-400">
                                                {{ group.title }}
                                            </p>
                                            <Link
                                                v-for="item in group.items" :key="item.route"
                                                :href="href(item.route) ?? '#'"
                                                class="flex items-center gap-2 rounded-lg px-2 py-2 text-sm font-medium"
                                                :class="isCurrent(item.route)
                                                    ? 'bg-[#ECFAFD] text-[#0B7A8A]'
                                                    : 'text-gray-800 hover:bg-gray-50'"
                                            >
                                                {{ item.label }}
                                                <span
                                                    v-if="item.badge"
                                                    class="ms-auto shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold"
                                                    :class="item.tone === 'bad'
                                                        ? 'bg-red-100 text-red-700'
                                                        : 'bg-amber-100 text-amber-800'"
                                                >{{ item.badge }}</span>
                                            </Link>
                                        </div>
                                    </div>

                                    <p
                                        v-if="reviewCount > 0 && reviewSummary"
                                        class="border-t border-gray-100 bg-gray-50 px-5 py-3 text-xs text-gray-600"
                                    >
                                        Waiting on the master sheet: {{ reviewSummary }}.
                                        <span v-if="removedCount" class="font-semibold text-red-700">
                                            {{ removedCount }} asked about separately before anything is removed.
                                        </span>
                                    </p>
                                </template>
                            </Dropdown>

                            <!-- Settings Dropdown -->
                            <Dropdown align="right" width="56">
                                <template #trigger>
                                    <span class="inline-flex rounded-md">
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-2 rounded-md border border-transparent bg-white px-2 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-900 focus:outline-none"
                                        >
                                            <span class="grid h-7 w-7 place-items-center rounded-full bg-gray-900 text-[11px] font-bold text-white">
                                                {{ (user?.name ?? '?').split(' ').filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase() }}
                                            </span>
                                            {{ user?.name }}
                                            <svg class="-me-0.5 h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                            </svg>
                                        </button>
                                    </span>
                                </template>

                                <template #content>
                                    <DropdownLink :href="href('profile.edit') ?? '#'">Profile</DropdownLink>
                                    <DropdownLink :href="href('logout') ?? '#'" method="post" as="button">Log Out</DropdownLink>
                                </template>
                            </Dropdown>
                        </div>

                        <!-- Hamburger -->
                        <div class="-me-2 flex items-center sm:hidden">
                            <button
                                @click="showingNavigationDropdown = !showingNavigationDropdown"
                                class="relative inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none"
                            >
                                <span
                                    v-if="toolAlerts > 0 && !showingNavigationDropdown"
                                    class="absolute right-1 top-1 grid h-[15px] min-w-[15px] place-items-center rounded-full bg-[#C2410C] px-1 text-[9px] font-bold text-white"
                                >{{ toolAlerts }}</span>
                                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                    <path
                                        :class="{ hidden: showingNavigationDropdown, 'inline-flex': !showingNavigationDropdown }"
                                        stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{ hidden: !showingNavigationDropdown, 'inline-flex': showingNavigationDropdown }"
                                        stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Responsive Navigation Menu -->
                <div
                    :class="{ block: showingNavigationDropdown, hidden: !showingNavigationDropdown }"
                    class="sm:hidden"
                >
                    <div class="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink v-if="isAdmin" :href="href('dashboard') ?? '#'" :active="isCurrent('dashboard')">Dashboard</ResponsiveNavLink>
                        <ResponsiveNavLink v-if="isStaff" :href="href('events.index') ?? '#'" :active="isCurrent('events.index')">Films</ResponsiveNavLink>
                        <ResponsiveNavLink v-if="isStaff" :href="href('films.index') ?? '#'" :active="isCurrent('films.index')">Websites</ResponsiveNavLink>
                        <ResponsiveNavLink v-if="isAdmin" :href="href('sponsorDashboard.index') ?? '#'" :active="isCurrent('sponsorDashboard.index')">Partners</ResponsiveNavLink>
                        <ResponsiveNavLink v-if="isStaff" :href="href('pickawinner.index') ?? '#'" :active="isCurrent('pickawinner.index')" target="_blank">Pick a Winner</ResponsiveNavLink>
                    </div>

                    <div v-for="group in toolGroups" :key="group.title" class="border-t border-gray-200 pb-2 pt-3">
                        <p class="px-4 pb-1 text-[10px] font-bold uppercase tracking-[0.1em] text-gray-400">{{ group.title }}</p>
                        <ResponsiveNavLink
                            v-for="item in group.items" :key="item.route"
                            :href="href(item.route) ?? '#'"
                            :active="isCurrent(item.route)"
                        >
                            {{ item.label }}
                            <span
                                v-if="item.badge"
                                class="ms-2 rounded-full px-2 py-0.5 text-[11px] font-bold"
                                :class="item.tone === 'bad' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800'"
                            >{{ item.badge }}</span>
                        </ResponsiveNavLink>
                    </div>

                    <div class="border-t border-gray-200 pb-3 pt-4">
                        <div class="px-4">
                            <div class="text-base font-medium text-gray-800">{{ user?.name }}</div>
                            <div class="text-sm font-medium capitalize text-gray-500">{{ userRole }}</div>
                        </div>
                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink :href="href('logout') ?? '#'" method="post" as="button">Log Out</ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Page Heading -->
            <header v-if="$slots.header" class="bg-white shadow">
                <div class="mx-auto max-w-full px-4 py-5 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
