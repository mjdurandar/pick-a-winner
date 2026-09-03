<script setup>
import { ref, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link } from '@inertiajs/vue3';

const showingNavigationDropdown = ref(false);

// ✅ Get user role from Inertia
const page = usePage();
const user = computed(() => page.props.auth.user || null);
const userRole = computed(() => user.value?.role); 

// Master sheet tabs holding changes nobody has accepted yet. Shared from the
// server on every page, and null for anyone who is not an admin.
// Whether this account owns the master sheet sync. Decided on the server (see
// User::canManageMasterSheet) so the address lives in one place and the nav
// cannot disagree with what the routes actually allow.
const canManageMasterSheet = computed(() => page.props.canManageMasterSheet === true);
const canManageSms = computed(() => page.props.canManageSms === true);

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

</script>

<template>
    <div>
        <div class="min-h-screen bg-gray-100">
            <nav
                class="border-b border-gray-100 bg-white"
            >
                <!-- Primary Navigation Menu -->
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between">
                        <div class="flex">
                            <!-- Logo -->
                            <div class="flex shrink-0 items-center">
                                <Link :href="route('dashboard')">
                                    <ApplicationLogo
                                        class="block h-9 w-auto fill-current text-gray-800"
                                    />
                                </Link>
                            </div>

                            <!-- Navigation Links -->
                            <div
                                class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex"
                            >
                                <NavLink
                                    v-if="userRole === 'admin'"
                                    :href="route('dashboard')"
                                    :active="route().current('dashboard')"
                                >
                                    Dashboard
                                </NavLink>
                                <NavLink
                                    v-if="userRole === 'admin' || userRole === 'host'"
                                    :href="route('events.index')"
                                    :active="route().current('events.index')"
                                >
                                    Films
                                </NavLink>
                                <NavLink
                                    v-if="userRole === 'admin' || userRole === 'host'"
                                    :href="route('films.index')"
                                    :active="route().current('films.index')"
                                >
                                    Brands
                                </NavLink>
                                <!-- <NavLink
                                    v-if="userRole === 'admin' || userRole === 'host'"
                                    :href="route('mastersheet.index')"
                                    :active="route().current('mastersheet.index')"
                                >
                                    Master Sheet
                                </NavLink> -->
                                <NavLink
                                    v-if="userRole === 'admin' || userRole === 'host'"
                                    :href="route('pickawinner.index')"
                                    :active="route().current('pickawinner.index')"
                                    target="_blank"
                                >
                                    Pick a Winner
                                </NavLink>
                                <NavLink
                                    v-if="canManageSms"
                                    :href="route('sms.index')"
                                    :active="route().current('sms.index')"
                                >
                                    SMS
                                </NavLink>
                                <!-- <NavLink
                                    v-if="userRole === 'admin' || userRole === 'host'"
                                    :href="route('location.index')"
                                    :active="route().current('location.index')"
                                >
                                    Locations
                                </NavLink> -->
                                <NavLink
                                    v-if="userRole === 'admin' || userRole === 'host'"
                                    :href="route('weekly-report')"
                                    :active="route().current('weekly-report')"
                                >
                                    Report
                                </NavLink>
                            </div>
                        </div>

                        <div class="hidden sm:ms-6 sm:flex sm:items-center">
                            <!-- Settings Dropdown -->
                            <div class="relative ms-3">
                                <Dropdown align="right" width="48">
                                    <template #trigger>
                                        <span class="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none"
                                            >
                                                {{ $page.props.auth.user.name }}

                                                <svg
                                                    class="-me-0.5 ms-2 h-4 w-4"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fill-rule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clip-rule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>

                                    <template #content>
                                        <DropdownLink
                                            :href="route('profile.edit')"
                                        >
                                            Profile
                                        </DropdownLink>
                                        <DropdownLink
                                            v-if="userRole === 'admin' || userRole === 'host'"
                                            :href="route('mcDashboard.index')"
                                        >
                                            MC Dashboard
                                        </DropdownLink>
                                        <DropdownLink
                                            v-if="userRole === 'admin' || userRole === 'host'"
                                            :href="route('laravelLogs.index')"
                                        >
                                            Laravel Logs
                                        </DropdownLink>
                                        <DropdownLink
                                            v-if="userRole === 'admin'"
                                            :href="route('mailchimpImport.index')"
                                        >
                                            MC Import
                                        </DropdownLink>
                                        <DropdownLink
                                            v-if="canManageMasterSheet"
                                            :href="route('sheetSync.index')"
                                        >
                                            MC Sync
                                            <span
                                                v-if="reviewCount > 0"
                                                class="ms-1 inline-flex items-center rounded-full bg-amber-100 px-1.5 text-xs font-medium text-amber-800"
                                            >
                                                {{ reviewCount }}
                                            </span>
                                        </DropdownLink>
                                        <DropdownLink
                                            v-if="userRole === 'admin'"
                                            :href="route('admin.exportLogs.index')"
                                        >
                                            Activity Logs
                                        </DropdownLink>
                                        <DropdownLink
                                            v-if="userRole === 'admin'"
                                            :href="route('users.index')"
                                        >
                                            Users
                                        </DropdownLink>
                                        <DropdownLink
                                            :href="route('logout')"
                                            method="post"
                                            as="button"
                                        >
                                            Log Out
                                        </DropdownLink>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>

                        <!-- Hamburger -->
                        <div class="-me-2 flex items-center sm:hidden">
                            <button
                                @click="
                                    showingNavigationDropdown =
                                        !showingNavigationDropdown
                                "
                                class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none"
                            >
                                <svg
                                    class="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        :class="{
                                            hidden: showingNavigationDropdown,
                                            'inline-flex':
                                                !showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{
                                            hidden: !showingNavigationDropdown,
                                            'inline-flex':
                                                showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Responsive Navigation Menu -->
                <div
                    :class="{
                        block: showingNavigationDropdown,
                        hidden: !showingNavigationDropdown,
                    }"
                    class="sm:hidden"
                >
                    <div class="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink
                                v-if="userRole === 'admin'"
                                    :href="route('dashboard')"
                                    :active="route().current('dashboard')"
                                >
                                    Dashboard
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                                v-if="userRole === 'admin' || userRole === 'host'"
                                    :href="route('events.index')"
                                    :active="route().current('events.index')"
                                >
                                    Films
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                                v-if="userRole === 'admin' || userRole === 'host'"
                                    :href="route('films.index')"
                                    :active="route().current('films.index')"
                                >
                                    Brands
                        </ResponsiveNavLink>
                        <!-- <ResponsiveNavLink
                                v-if="userRole === 'admin' || userRole === 'host'"
                                    :href="route('mastersheet.index')"
                                    :active="route().current('mastersheet.index')"
                                >
                                    Master Sheet
                        </ResponsiveNavLink> -->
                        <ResponsiveNavLink
                                v-if="userRole === 'admin' || userRole === 'host'"
                                    :href="route('pickawinner.index')"
                                    :active="route().current('pickawinner.index')"
                                    target="_blank"
                                >
                                    Pick a Winner
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            v-if="canManageSms"
                            :href="route('sms.index')"
                            :active="route().current('sms.index')"
                        >
                            SMS
                        </ResponsiveNavLink>
                        <!-- <ResponsiveNavLink
                                v-if="userRole === 'admin'"
                                    :href="route('location.index')"
                                    :active="route().current('location.index')"
                                >
                                    Locations
                        </ResponsiveNavLink> -->
                        <ResponsiveNavLink
                                v-if="userRole === 'admin' || userRole === 'host'"
                                    :href="route('weekly-report')"
                                    :active="route().current('weekly-report')"
                                >
                                    Weekly Report
                        </ResponsiveNavLink>
                    </div>

                    <!-- Responsive Settings Options -->
                    <div
                        class="border-t border-gray-200 pb-1 pt-4"
                    >
                        <div class="px-4">
                            <div
                                class="text-base font-medium text-gray-800"
                            >
                                {{ $page.props.auth.user.name }}
                            </div>
                            <div class="text-sm font-medium text-gray-500">
                                {{ $page.props.auth.user.email }}
                            </div>
                        </div>

                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink :href="route('profile.edit')">
                                Profile
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                v-if="userRole === 'admin' || userRole === 'host'"
                                :href="route('mcDashboard.index')"
                            >
                                MC Dashboard
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                v-if="userRole === 'admin' || userRole === 'host'"
                                :href="route('laravelLogs.index')"
                            >
                                Laravel Logs
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                v-if="canManageMasterSheet"
                                :href="route('sheetSync.index')"
                            >
                                MC Sync
                                <span
                                    v-if="reviewCount > 0"
                                    class="ms-1 inline-flex items-center rounded-full bg-amber-100 px-1.5 text-xs font-medium text-amber-800"
                                >
                                    {{ reviewCount }}
                                </span>
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                v-if="userRole === 'admin'"
                                :href="route('admin.exportLogs.index')"
                            >
                                Activity Logs
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                v-if="userRole === 'admin'"
                                :href="route('users.index')"
                            >
                                Users
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                :href="route('logout')"
                                method="post"
                                as="button"
                            >
                                Log Out
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Page Heading -->
            <header
                class="bg-white shadow"
                v-if="$slots.header"
            >
                <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Master sheet changes awaiting review -->
            <div v-if="reviewCount > 0" class="bg-amber-50 border-b border-amber-200">
                <div class="mx-auto max-w-7xl px-4 py-3 sm:px-6 lg:px-8 flex flex-wrap items-center gap-3">
                    <span class="flex h-2.5 w-2.5 shrink-0 rounded-full bg-amber-500"></span>
                    <p class="text-sm text-amber-900 flex-1 min-w-0">
                        <strong>{{ reviewCount }} film{{ reviewCount === 1 ? '' : 's' }} updated in the master sheet</strong>
                        and {{ reviewCount === 1 ? 'needs' : 'need' }} review<template v-if="removedCount > 0">,
                        including <strong>{{ removedCount }} screening{{ removedCount === 1 ? '' : 's' }} removed from the sheet</strong></template>
                        &mdash;
                        <span class="text-amber-800">{{ reviewSummary }}</span>
                    </p>
                    <Link
                        :href="route('sheetSync.index')"
                        class="shrink-0 rounded-md bg-amber-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-700"
                    >
                        Review &amp; approve
                    </Link>
                </div>
            </div>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
