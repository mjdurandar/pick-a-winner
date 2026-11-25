<script setup>
/**
 * Mailchimp auto-sync settings for one event.
 *
 * Lifted out of the screenings page, where it was a dialog holding a form with
 * a row per sign-up answer. The settings live behind the mailchimp.autosync.*
 * endpoints rather than in page props, so this screen loads them itself.
 */
import { ref, computed, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import Swal from 'sweetalert2';
import axios from 'axios';

const props = defineProps({
    event: Object,
    fave_sport_options: { type: Array, default: () => [] },
});

const loading = ref(true);
const saving = ref(false);
const loadError = ref(null);

const settings = ref({
    auto_sync: true, // Compulsory — the backend forces it on, it is never a choice
    default_list_id: '',
    default_tags: '',
    enabled_locations: [],
    film_tour: '',
    mailchimp_account: 'anz',
    interest_tag_map: {},
    event_id: props.event.id,
});

const availableLists = ref([]);
const availableAccounts = ref([]);

// Auto-sync always writes to the account's WIN APP audience, so the audience is
// never picked. Matched by name here only to label it and to follow an account
// change; the backend forces the id from config on both read and save.
const findWinAppList = (lists) => (lists || []).find((l) => (l.name || '').trim().toUpperCase() === 'WIN APP') || null;

const winAppList = computed(() => {
    const byId = availableLists.value.find((l) => l.id === settings.value.default_list_id);

    return byId || findWinAppList(availableLists.value);
});

// The account is decided by the event's country on the server, never chosen here.
const currentAccount = computed(() => availableAccounts.value?.[settings.value.mailchimp_account] || null);

const accountLabel = computed(() =>
    currentAccount.value?.name || (settings.value.mailchimp_account === 'usa' ? 'USA' : 'ANZ'));

/**
 * The tag each "Favorite adventure sport?" answer gets when nobody has chosen
 * one. Matched by lowercase prefix so an option like
 * "Snow Sports (Skiing, Snowboarding)" still maps to INT - SNOWSPORTS. Kept in
 * sync with the PHP fallback in MailchimpService::mapFaveSportToInterestTags.
 */
const DEFAULT_INTEREST_TAG_MAP = [
    { prefix: 'snow sports', tag: 'INT - SNOWSPORTS' },
    { prefix: 'climbing', tag: 'INT - CLIMBING' },
    { prefix: 'running', tag: 'INT - RUNNING' },
    { prefix: 'trail sports', tag: 'INT - TRAILSPORTS, INT - RUNNING' },
    { prefix: 'skate sports', tag: 'INT - SKATEBOARDING' },
    { prefix: 'cycling', tag: 'INT - MTB' },
    { prefix: 'water sports', tag: 'INT - WATERSPORTS' },
    { prefix: 'outdoor', tag: 'INT - OUTDOOR' },
    { prefix: 'aerial', tag: 'INT - OUTDOOR, INT - ENVIRONMENT' },
    { prefix: 'extreme', tag: 'INT - OUTDOOR, INT - ENVIRONMENT' },
    { prefix: 'other', tag: 'INT - ALL' },
];

const defaultInterestTagFor = (option) => {
    const v = typeof option === 'string' ? option.trim().toLowerCase() : '';
    if (!v) return '';

    for (const { prefix, tag } of DEFAULT_INTEREST_TAG_MAP) {
        if (v.startsWith(prefix)) return tag;
    }

    return '';
};

async function load() {
    loading.value = true;
    loadError.value = null;

    try {
        const response = await axios.get(route('mailchimp.autosync.settings'), {
            params: { event_id: props.event.id },
        });
        const { settings: saved, available_lists, available_accounts } = response.data;

        const savedMap = (saved && typeof saved.interest_tag_map === 'object' && saved.interest_tag_map !== null)
            ? saved.interest_tag_map
            : {};
        const seededMap = {};
        (props.fave_sport_options || []).forEach((opt) => {
            const value = typeof savedMap[opt] === 'string' ? savedMap[opt].trim() : '';
            seededMap[opt] = value !== '' ? value : defaultInterestTagFor(opt);
        });

        settings.value = {
            ...saved,
            film_tour: saved.film_tour,
            default_tags: Array.isArray(saved.default_tags) ? saved.default_tags.join(', ') : '',
            mailchimp_account: saved.mailchimp_account || 'anz',
            interest_tag_map: seededMap,
            event_id: props.event.id,
        };
        availableLists.value = available_lists;
        availableAccounts.value = available_accounts;
    } catch (error) {
        console.error('Failed to fetch Mailchimp settings:', error);
        loadError.value = 'Could not load the Mailchimp settings for this event.';
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;

    try {
        const rawMap = settings.value.interest_tag_map || {};
        const cleanedMap = {};
        Object.keys(rawMap).forEach((key) => {
            const value = typeof rawMap[key] === 'string' ? rawMap[key].trim() : '';
            if (value !== '') {
                cleanedMap[key] = value;
            }
        });

        await axios.post(route('mailchimp.autosync.update'), {
            ...settings.value,
            film_tour: settings.value.film_tour,
            default_tags: settings.value.default_tags.split(',').map((t) => t.trim()).filter((t) => t),
            enabled_locations: Array.isArray(settings.value.enabled_locations) ? settings.value.enabled_locations : [],
            mailchimp_account: settings.value.mailchimp_account,
            interest_tag_map: cleanedMap,
            event_id: props.event.id,
        });

        Swal.fire('Saved', 'Mailchimp auto-sync settings updated.', 'success');
    } catch (error) {
        console.error('Failed to save Mailchimp settings:', error);
        Swal.fire('Error', 'Failed to save Mailchimp settings', 'error');
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <Head :title="`Mailchimp sync — ${event.event_name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1">
                    <Link :href="route('location.locationpage', { eventId: event.id })" class="text-xs font-semibold text-[#0B7A8A]">
                        ‹ {{ event.event_name }}
                    </Link>
                    <h2 class="truncate text-2xl font-extrabold tracking-tight text-gray-900">Mailchimp sync</h2>
                </div>
                <button
                    type="button" @click="save" :disabled="loading || saving || !!loadError"
                    class="h-10 rounded-lg bg-[#16C3D9] px-4 text-sm font-bold text-[#06242A] hover:brightness-105 disabled:opacity-50"
                >
                    {{ saving ? 'Saving…' : 'Save settings' }}
                </button>
            </div>
        </template>

        <div class="mx-auto max-w-full px-4 py-6 sm:px-6 lg:px-8">
            <div v-if="loading" class="rounded-xl bg-white shadow-card px-4 py-16 text-center text-sm text-gray-500">
                <Icon name="spinner" spin class="mb-2 text-2xl text-[#0B7A8A]" />
                <p>Reading the settings from Mailchimp…</p>
            </div>

            <div v-else-if="loadError" class="rounded-xl border border-red-200 bg-red-50 px-4 py-4 text-sm text-red-800">
                <p class="font-semibold">{{ loadError }}</p>
                <p class="mt-1">Nothing has been changed. Check the Mailchimp API configuration and try again.</p>
                <button type="button" @click="load" class="mt-3 h-9 rounded-lg border border-red-300 bg-white px-4 text-sm font-semibold text-red-800 hover:bg-red-100">
                    Try again
                </button>
            </div>

            <div v-else class="space-y-4">
                <!-- What is fixed, and why -->
                <section class="rounded-xl bg-white shadow-card">
                    <div class="border-b border-gray-100 px-5 py-3">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-gray-500">Decided for you</h3>
                        <p class="mt-0.5 text-xs text-gray-500">Neither of these is a choice — they are shown so you can check them.</p>
                    </div>

                    <dl class="grid gap-4 p-5 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-semibold text-gray-700">Mailchimp account</dt>
                            <dd class="mt-1 rounded-lg bg-gray-50 px-3 py-2 text-sm font-semibold text-gray-900">{{ accountLabel }}</dd>
                            <p class="mt-1 text-xs text-gray-500">
                                Set by this event's country ({{ event.event_country || 'not set' }}) — USA and Canada
                                sync through the USA account, everywhere else through ANZ.
                            </p>
                            <p v-if="currentAccount && !currentAccount.enabled" class="mt-1 flex items-start gap-1.5 text-xs font-semibold text-amber-700">
                                <Icon name="triangle-exclamation" class="mt-0.5" />
                                This account is not configured, so auto-sync cannot reach it.
                            </p>
                        </div>

                        <div>
                            <dt class="text-sm font-semibold text-gray-700">Audience</dt>
                            <dd class="mt-1 rounded-lg bg-gray-50 px-3 py-2 text-sm font-semibold text-gray-900">
                                {{ winAppList ? `${winAppList.name} (${winAppList.stats.member_count} members)` : 'WIN APP' }}
                            </dd>
                            <p class="mt-1 text-xs text-gray-500">
                                Auto-sync always writes to this account's WIN APP audience — nothing to set up.
                            </p>
                            <p v-if="!winAppList" class="mt-1 flex items-start gap-1.5 text-xs font-semibold text-amber-700">
                                <Icon name="triangle-exclamation" class="mt-0.5" />
                                No WIN APP audience was found on this account. Check the API configuration.
                            </p>
                        </div>

                        <div class="sm:col-span-2">
                            <dt class="text-sm font-semibold text-gray-700">Auto-sync</dt>
                            <dd class="mt-1 flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-bold text-emerald-800">
                                    <Icon name="check" /> Always on
                                </span>
                                <span class="text-sm text-gray-500">Every screening in this event syncs automatically.</span>
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- Tags -->
                <section class="rounded-xl bg-white shadow-card">
                    <div class="border-b border-gray-100 px-5 py-3">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-gray-500">Tags</h3>
                        <p class="mt-0.5 text-xs text-gray-500">What every contact from this event is tagged with.</p>
                    </div>

                    <div class="grid gap-4 p-5">
                        <label class="block">
                            <span class="block text-sm font-semibold text-gray-700">Film tour code</span>
                            <input
                                v-model="settings.film_tour" type="text" required
                                placeholder="e.g. WM, RUNNATION"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9] sm:max-w-sm"
                            />
                            <span class="mt-1 block text-xs text-gray-500">
                                Used in the SOURCE tag — WM for Warren Miller, RUNNATION, and so on.
                            </span>
                        </label>

                        <label class="block">
                            <span class="block text-sm font-semibold text-gray-700">Default tags</span>
                            <input
                                v-model="settings.default_tags" type="text"
                                placeholder="e.g. 2026, FILM TOUR - WARREN MILLER"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]"
                            />
                            <span class="mt-1 block text-xs text-gray-500">
                                Separated by commas. Every subscriber from this event gets all of them.
                            </span>
                        </label>
                    </div>
                </section>

                <!-- Interest tags -->
                <section class="rounded-xl bg-white shadow-card">
                    <div class="border-b border-gray-100 px-5 py-3">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-gray-500">Interest tags</h3>
                        <p class="mt-0.5 text-xs text-gray-500">
                            One tag per answer to "Favorite adventure sport?". Leave a row empty to tag nothing for that answer.
                        </p>
                    </div>

                    <p v-if="!fave_sport_options.length" class="px-5 py-8 text-center text-sm text-gray-400">
                        The sign-up form for this event has no "Favorite adventure sport?" question, so there are no
                        answers to map.
                    </p>

                    <div v-else class="divide-y divide-gray-100">
                        <label
                            v-for="option in fave_sport_options" :key="option"
                            class="flex flex-wrap items-center gap-3 px-5 py-3"
                        >
                            <span class="min-w-0 flex-1 break-words text-sm text-gray-800">{{ option }}</span>
                            <input
                                v-model="settings.interest_tag_map[option]" type="text"
                                placeholder="e.g. INT - SNOWSPORTS"
                                class="w-full max-w-xs rounded-lg border-gray-300 text-sm focus:border-[#16C3D9] focus:ring-[#16C3D9]"
                            />
                        </label>
                    </div>
                </section>

                <div class="flex flex-wrap justify-end gap-2">
                    <Link
                        :href="route('location.locationpage', { eventId: event.id })"
                        class="flex h-10 items-center rounded-lg border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                    >Back to screenings</Link>
                    <button
                        type="button" @click="save" :disabled="saving"
                        class="h-10 rounded-lg bg-[#16C3D9] px-4 text-sm font-bold text-[#06242A] hover:brightness-105 disabled:opacity-50"
                    >
                        {{ saving ? 'Saving…' : 'Save settings' }}
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
