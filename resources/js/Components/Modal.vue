<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    maxWidth: {
        type: String,
        default: '2xl',
    },
    closeable: {
        type: Boolean,
        default: true,
    },
    // Shown in the header bar beside the close button. A #header slot replaces
    // it where a title alone is not enough.
    title: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['close']);
const dialog = ref();
const showSlot = ref(props.show);

watch(
    () => props.show,
    () => {
        if (props.show) {
            document.body.style.overflow = 'hidden';
            showSlot.value = true;

            dialog.value?.showModal();
        } else {
            document.body.style.overflow = '';

            setTimeout(() => {
                dialog.value?.close();
                showSlot.value = false;
            }, 200);
        }
    },
);

const close = () => {
    if (props.closeable) {
        emit('close');
    }
};

// A dialog closes one way: the X in its corner (or a Cancel button the dialog
// offers itself). A stray click on the backdrop or a reflexive Escape used to
// throw away a half-filled form, so neither does anything now. Escape still
// has to be swallowed, because a native <dialog> would close itself on it.
const closeOnEscape = (e) => {
    if (e.key === 'Escape' && props.show) {
        e.preventDefault();
    }
};

onMounted(() => document.addEventListener('keydown', closeOnEscape));

onUnmounted(() => {
    document.removeEventListener('keydown', closeOnEscape);

    document.body.style.overflow = '';
});

const maxWidthClass = computed(() => {
    return {
        sm: 'sm:max-w-sm',
        md: 'sm:max-w-md',
        lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
        // Wider panels, for forms with several columns and for the screenings
        // grid, which is a spreadsheet and needs the room.
        '3xl': 'sm:max-w-3xl',
        '4xl': 'sm:max-w-4xl',
        '5xl': 'sm:max-w-5xl',
        '6xl': 'sm:max-w-6xl',
        '7xl': 'sm:max-w-7xl',
    }[props.maxWidth];
});
</script>

<template>
    <dialog
        class="z-50 m-0 min-h-full min-w-full overflow-y-auto bg-transparent backdrop:bg-transparent"
        ref="dialog"
    >
        <div
            class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0"
            scroll-region
        >
            <Transition
                enter-active-class="ease-out duration-300"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="ease-in duration-200"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-show="show"
                    class="fixed inset-0 transform transition-all"
                >
                    <div
                        class="absolute inset-0 bg-gray-500 opacity-75"
                    />
                </div>
            </Transition>

            <Transition
                enter-active-class="ease-out duration-300"
                enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                enter-to-class="opacity-100 translate-y-0 sm:scale-100"
                leave-active-class="ease-in duration-200"
                leave-from-class="opacity-100 translate-y-0 sm:scale-100"
                leave-to-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            >
                <!-- Never taller than the window: the body scrolls between a
                     header and a button bar that both stay put, so Save is in
                     reach however long the form is. -->
                <div
                    v-show="show"
                    class="flex max-h-[calc(100vh-3rem)] transform flex-col overflow-hidden rounded-xl bg-white shadow-xl transition-all sm:mx-auto sm:w-full"
                    :class="maxWidthClass"
                >
                    <template v-if="showSlot">
                        <div class="flex shrink-0 items-start gap-3 border-b border-gray-200 px-6 py-4">
                            <div class="min-w-0 flex-1">
                                <slot name="header">
                                    <h2 class="text-lg font-extrabold tracking-tight text-gray-900">{{ title }}</h2>
                                </slot>
                            </div>
                            <button
                                type="button" @click="close" :disabled="!closeable"
                                class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700 disabled:opacity-40"
                                aria-label="Close"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
                            </button>
                        </div>

                        <div class="min-h-0 flex-1 overflow-y-auto">
                            <slot />
                        </div>

                        <div
                            v-if="$slots.footer"
                            class="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-gray-200 bg-white px-6 py-4"
                        >
                            <slot name="footer" />
                        </div>
                    </template>
                </div>
            </Transition>
        </div>
    </dialog>
</template>
