<script setup>
import { computed } from 'vue';
import Swal from 'sweetalert2';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    oauthConfigured: { type: Boolean, default: false },
    redirectUri: { type: String, default: '' },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});

const needsReconnect = computed(() => props.accounts.filter((a) => a.needs_reconnect));

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString();
}

function connect(account) {
    // Inertia follows the 409 the controller returns with a full page visit to
    // Mailchimp's login.
    router.post(route('mailchimp.integration.connect'), { account: account.key });
}

async function disconnect(account) {
    const ok = await Swal.fire({
        title: `Disconnect ${account.label}?`,
        text: 'The token will be revoked with Mailchimp and removed from this app. Imports for this account will stop working until it is reconnected.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, disconnect',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626',
    }).then((r) => r.isConfirmed);

    if (!ok) return;

    router.delete(route('mailchimp.integration.disconnect', { connection: account.connection.id }), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Mailchimp Integration" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Mailchimp Integration
            </h2>
        </template>

        <div class="p-2 pb-5 pt-4">
            <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
                <div v-if="flash.success" class="mb-4 rounded-md bg-green-50 p-4">
                    <p class="text-sm text-green-700">{{ flash.success }}</p>
                </div>
                <div v-if="flash.error" class="mb-4 rounded-md bg-red-50 p-4">
                    <p class="text-sm text-red-700">{{ flash.error }}</p>
                </div>

                <!-- Surfaced whenever Mailchimp rejected the token mid-operation. -->
                <div
                    v-for="account in needsReconnect"
                    :key="`banner-${account.key}`"
                    class="mb-4 rounded-md border border-amber-300 bg-amber-50 p-4"
                >
                    <p class="text-sm font-semibold text-amber-900">
                        {{ account.label }}: reconnect required
                    </p>
                    <p class="mt-1 text-sm text-amber-800">
                        Mailchimp rejected the stored token, so imports for this account are paused.
                        Reconnect to resume.
                    </p>
                </div>

                <div v-if="!oauthConfigured" class="mb-4 rounded-md border border-gray-300 bg-gray-50 p-4">
                    <p class="text-sm font-semibold text-gray-900">OAuth is not configured yet</p>
                    <p class="mt-1 text-sm text-gray-700">
                        Register an app in Mailchimp under Account → Extras → Registered Apps, then set
                        <code class="rounded bg-gray-200 px-1">MAILCHIMP_OAUTH_CLIENT_ID</code> and
                        <code class="rounded bg-gray-200 px-1">MAILCHIMP_OAUTH_CLIENT_SECRET</code> in the environment.
                    </p>
                    <p class="mt-2 text-sm text-gray-700">
                        Redirect URI to register:
                        <code class="rounded bg-gray-200 px-1 break-all">{{ redirectUri }}</code>
                    </p>
                </div>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <p class="mb-6 text-gray-600">
                            Connect a Mailchimp account to import contacts from a CSV. You sign in on
                            Mailchimp's own site — this app never sees your Mailchimp password, and the
                            access token it receives is encrypted and used only on the server.
                        </p>

                        <div class="divide-y divide-gray-200">
                            <div
                                v-for="account in accounts"
                                :key="account.key"
                                class="flex flex-wrap items-start justify-between gap-4 py-5 first:pt-0 last:pb-0"
                            >
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-semibold text-gray-900">{{ account.label }}</span>
                                        <span
                                            :class="[
                                                'rounded-full px-2 py-0.5 text-xs font-medium',
                                                !account.connected
                                                    ? 'bg-gray-100 text-gray-600'
                                                    : account.needs_reconnect
                                                        ? 'bg-amber-100 text-amber-800'
                                                        : 'bg-green-100 text-green-800',
                                            ]"
                                        >
                                            {{ !account.connected ? (account.credential_source === 'api_key' ? 'API key' : 'Not connected') : account.needs_reconnect ? 'Reconnect required' : 'Connected (OAuth)' }}
                                        </span>
                                    </div>

                                    <p
                                        v-if="!account.connected && account.credential_source === 'api_key'"
                                        class="mt-2 text-sm text-gray-600"
                                    >
                                        Using the API key configured on the server
                                        (<span class="font-mono">{{ account.credential_datacenter }}</span>).
                                        Imports work without connecting, but the key is shared server
                                        configuration — connecting records who granted access and when.
                                    </p>

                                    <dl v-if="account.connected" class="mt-2 space-y-1 text-sm text-gray-600">
                                        <div class="flex gap-2">
                                            <dt class="w-32 text-gray-500">Account</dt>
                                            <dd>{{ account.connection.mailchimp_account_name || '—' }}</dd>
                                        </div>
                                        <div class="flex gap-2">
                                            <dt class="w-32 text-gray-500">Datacenter</dt>
                                            <dd>{{ account.connection.datacenter }}</dd>
                                        </div>
                                        <div class="flex gap-2">
                                            <dt class="w-32 text-gray-500">Connected by</dt>
                                            <dd>{{ account.connection.connected_by?.name || '—' }}</dd>
                                        </div>
                                        <div class="flex gap-2">
                                            <dt class="w-32 text-gray-500">Connected at</dt>
                                            <dd>{{ formatDate(account.connection.connected_at) }}</dd>
                                        </div>
                                    </dl>

                                    <p
                                        v-else-if="!account.credential_source"
                                        class="mt-2 text-sm text-gray-500"
                                    >
                                        No credentials for this account — imports are unavailable until it is
                                        connected or an API key is configured.
                                    </p>
                                </div>

                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        :disabled="!oauthConfigured"
                                        class="rounded bg-gray-800 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-700 disabled:cursor-not-allowed disabled:bg-gray-300"
                                        @click="connect(account)"
                                    >
                                        {{ account.connected ? 'Reconnect' : 'Connect' }}
                                    </button>
                                    <button
                                        v-if="account.connected"
                                        type="button"
                                        class="rounded border border-red-300 bg-white px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50"
                                        @click="disconnect(account)"
                                    >
                                        Disconnect
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
