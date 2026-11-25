<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
    },
    target: {
        type: String,
        default: null,
    },
});

const classes = computed(() =>
    props.active
        ? 'block w-full ps-3 pe-4 py-3 border-l-4 border-[#16C3D9] text-start text-base font-semibold text-[#0B7A8A] bg-[#ECFAFD] focus:outline-none transition duration-150 ease-in-out'
        : 'block w-full ps-3 pe-4 py-3 border-l-4 border-transparent text-start text-base font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-50 hover:border-gray-300 focus:outline-none focus:text-gray-900 focus:bg-gray-50 focus:border-gray-300 transition duration-150 ease-in-out',
);
</script>

<template>
    <Link v-if="!target" :href="href" :class="classes">
        <slot />
    </Link>
    <a v-else :href="href" :class="classes" :target="target">
        <slot />
    </a>
</template>
