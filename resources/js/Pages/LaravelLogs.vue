<script setup>
import { computed } from 'vue';
import Swal from 'sweetalert2';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';

const props = defineProps({
    logContent: { type: String, default: '' },
    linesLimit: { type: Number, default: 1000 },
    totalLines: { type: Number, default: 0 },
    logPath: { type: String, default: '' },
});

const lineOptions = [500, 1000, 2000];

const summaryText = computed(() => {
    if (props.totalLines <= 0) return 'Log file is empty or missing.';
    return `File has ${props.totalLines.toLocaleString()} line(s). Showing last ${props.linesLimit}.`;
});

function setLines(n) {
    router.visit(route('laravelLogs.index', { lines: n }), { preserveState: false });
}

function refresh() {
    router.reload({ only: ['logContent', 'totalLines', 'linesLimit', 'logPath'] });
}

async function clearLogs() {
    const ok = await Swal.fire({
        title: 'Clear Laravel logs?',
        text: 'This will empty the log file. New entries will continue to be written. This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, clear logs',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626',
    }).then((r) => r.isConfirmed);
    if (!ok) return;
    try {
        await axios.post(route('laravelLogs.clear'));
        Swal.fire('Cleared', 'Log file has been cleared.', 'success');
        refresh();
    } catch (err) {
        const msg = err.response?.data?.message || err.message || 'Failed to clear logs.';
        Swal.fire('Error', msg, 'error');
    }
}
</script>

<template>
    <Head title="Laravel Logs" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Laravel Logs
            </h2>
        </template>

        <div class="p-2 pb-5 pt-4">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <p class="text-gray-600 mb-4">
                            View the last lines of the application log. Choose how many lines to load, then refresh or clear as needed.
                        </p>

                        <div class="flex flex-wrap items-center gap-4 mb-4">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-gray-700">View last</span>
                                <button
                                    v-for="n in lineOptions"
                                    :key="n"
                                    type="button"
                                    :class="[
                                        'rounded px-3 py-1.5 text-sm font-medium',
                                        linesLimit === n
                                            ? 'bg-gray-800 text-white'
                                            : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                                    ]"
                                    @click="setLines(n)"
                                >
                                    {{ n }}
                                </button>
                                <span class="text-sm text-gray-500">lines</span>
                            </div>
                            <button
                                type="button"
                                class="rounded border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                @click="refresh"
                            >
                                Refresh
                            </button>
                            <button
                                type="button"
                                class="rounded border border-red-300 bg-white px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50"
                                @click="clearLogs"
                            >
                                Clear logs
                            </button>
                        </div>

                        <p class="text-sm text-gray-500 mb-2">{{ summaryText }}</p>
                        <p v-if="logPath" class="text-xs text-gray-400 mb-2 truncate" :title="logPath">{{ logPath }}</p>

                        <div class="rounded border border-gray-200 bg-gray-900 text-gray-100 overflow-auto" style="max-height: 70vh;">
                            <pre class="p-4 text-xs font-mono whitespace-pre-wrap break-words min-h-full">{{ logContent || '(no content)' }}</pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
