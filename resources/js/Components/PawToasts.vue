<script setup>
import { toasts, dismissToast } from '@/stores/pawToast';

// Colours per kind: icon tint, icon background, border.
const look = {
    success: { tint: '#3dd68c', bg: '#0d2419', border: '#1d4d33' },
    warning: { tint: '#e8a341', bg: '#2a1f10', border: '#5a4420' },
    error:   { tint: '#f77d7d', bg: '#2c1414', border: '#5c2a2a' },
};
</script>

<template>
    <Teleport to="body">
        <div
            class="pointer-events-none fixed inset-x-0 top-0 z-[95] flex flex-col items-center gap-2 px-3 pt-[calc(10px+env(safe-area-inset-top))]"
            aria-live="polite"
        >
            <TransitionGroup
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="-translate-y-3 opacity-0"
                leave-active-class="transition duration-150 ease-in"
                leave-to-class="-translate-y-2 opacity-0"
            >
                <button
                    v-for="t in toasts" :key="t.id" type="button"
                    :role="t.type === 'error' ? 'alert' : 'status'"
                    class="pointer-events-auto flex w-full max-w-[520px] items-start gap-3 rounded-2xl border bg-[#151515] px-4 py-3 text-left shadow-[0_10px_30px_rgba(0,0,0,0.6)]"
                    :style="{ borderColor: look[t.type].border }"
                    @click="dismissToast(t.id)"
                >
                    <span
                        class="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-full"
                        :style="{ backgroundColor: look[t.type].bg, color: look[t.type].tint }"
                    >
                        <svg v-if="t.type === 'success'" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                        <svg v-else class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 7v6M12 17h.01" /></svg>
                    </span>
                    <span class="min-w-0 flex-1">
                        <b class="block text-[14.5px] font-bold leading-snug text-white">{{ t.title }}</b>
                        <span v-if="t.text" class="mt-0.5 block text-[12.5px] leading-snug text-[#abb0b2]">{{ t.text }}</span>
                    </span>
                    <span class="sr-only">Dismiss</span>
                </button>
            </TransitionGroup>
        </div>
    </Teleport>
</template>
