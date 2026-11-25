<script setup>
/**
 * The app's icons, drawn inline.
 *
 * These replace Font Awesome, which was a second CDN stylesheet loaded on every
 * page for about thirty glyphs. Drawn at 1em so an icon sizes with the text
 * beside it, and stroked in `currentColor` so it takes the colour of whatever
 * it sits in — the same two things the icon font gave us.
 *
 * `name` accepts the Font Awesome name with or without its `fa-` prefix, so a
 * value still stored as "fa-rotate" keeps working.
 */
import { computed } from 'vue';

const props = defineProps({
    name: { type: String, required: true },
    spin: { type: Boolean, default: false },
});

/** Inner SVG for each icon, on a 24x24 grid. */
const PATHS = {
    'arrow-left': '<path d="M19 12H5M11 18l-6-6 6-6"/>',
    ban: '<circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/>',
    'chart-simple': '<path d="M5 20v-6M12 20V4M19 20v-9"/>',
    check: '<path d="m5 13 4 4L19 7"/>',
    'chevron-down': '<path d="m6 9 6 6 6-6"/>',
    'circle-check': '<circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3.5 2"/>',
    'clock-rotate-left': '<path d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1"/><path d="M3 4v4.5h4.5"/><path d="M12 8v4.2l3 1.8"/>',
    copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/>',
    database: '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
    'envelope-circle-check': '<path d="M20 11V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h8"/><path d="m2.5 7.5 8.5 6 5-3.5"/><circle cx="18" cy="18" r="4"/><path d="m16.4 18 1.1 1.1 2.1-2.2"/>',
    'file-csv': '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 14h2M13 14h2M9 17h6"/>',
    'file-export': '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h6"/><path d="M14 3v5h5V9"/><path d="M14 17h7M18 14l3 3-3 3"/>',
    'file-shield': '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4"/><path d="M14 3v5h5"/><path d="M18 12.5 21.5 14v3c0 2-1.8 3.4-3.5 4-1.7-.6-3.5-2-3.5-4v-3z"/>',
    gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 14.5a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5v.2a2 2 0 0 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1h.2a2 2 0 0 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
    'hourglass-half': '<path d="M7 3h10M7 21h10"/><path d="M7 3v3.5c0 1.5 1.3 2.6 2.5 3.5L12 12l-2.5 2c-1.2.9-2.5 2-2.5 3.5V21M17 3v3.5c0 1.5-1.3 2.6-2.5 3.5L12 12l2.5 2c1.2.9 2.5 2 2.5 3.5V21"/><path d="M9 12h6"/>',
    'info-circle': '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><circle cx="12" cy="8" r=".6" fill="currentColor"/>',
    key: '<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8 2 2-2 2 2 2-2 2-2-2-2 2"/>',
    list: '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1" fill="currentColor"/><circle cx="3.5" cy="12" r="1" fill="currentColor"/><circle cx="3.5" cy="18" r="1" fill="currentColor"/>',
    'pen-to-square': '<path d="M11 4H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h13a2 2 0 0 0 2-2v-6"/><path d="M18.4 2.6a2 2 0 0 1 2.8 2.8L12.5 14l-3.5 1 1-3.5z"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    rotate: '<path d="M20 11A8 8 0 0 0 6.3 6.3L4 8.5M4 13a8 8 0 0 0 13.7 4.7L20 15.5"/><path d="M4 4v4.5h4.5M20 20v-4.5h-4.5"/>',
    'rotate-right': '<path d="M20.5 12a8.5 8.5 0 1 1-2.6-6.1"/><path d="M21 4v4.5h-4.5"/>',
    save: '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
    spinner: '<path d="M12 3v3.5M12 17.5V21M3 12h3.5M17.5 12H21M5.6 5.6l2.5 2.5M15.9 15.9l2.5 2.5M5.6 18.4l2.5-2.5M15.9 8.1l2.5-2.5" opacity=".9"/>',
    trash: '<path d="M4 7h16M10 11v6M14 11v6"/><path d="M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/><path d="M9 7V4h6v3"/>',
    'triangle-exclamation': '<path d="M10.3 4.3 2.6 17.6A2 2 0 0 0 4.3 20.6h15.4a2 2 0 0 0 1.7-3L13.7 4.3a2 2 0 0 0-3.4 0z"/><path d="M12 9.5v4"/><circle cx="12" cy="16.8" r=".7" fill="currentColor"/>',
    trophy: '<path d="M8 4h8v4a4 4 0 0 1-8 0V4z"/><path d="M8 5H5a3 3 0 0 0 3 3M16 5h3a3 3 0 0 1-3 3M10 12v3h4v-3M8 20h8"/>',
    users: '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M17 11.2a3 3 0 0 0 0-6M18 20a6 6 0 0 0-2-4.5"/>',
    xmark: '<path d="M6 6l12 12M18 6 6 18"/>',
    // A visible fallback beats a silent gap when a name is wrong.
    dot: '<circle cx="12" cy="12" r="3"/>',
};

/** Font Awesome names that are aliases of one another. */
const ALIASES = {
    add: 'plus',
    edit: 'pen-to-square',
    'exclamation-triangle': 'triangle-exclamation',
    'list-ul': 'list',
    random: 'rotate',
    times: 'xmark',
    'user-group': 'users',
};

const key = computed(() => {
    const raw = String(props.name ?? '').replace(/^fa-/, '');

    return ALIASES[raw] ?? raw;
});

const inner = computed(() => PATHS[key.value] ?? PATHS.dot);
</script>

<template>
    <svg
        class="inline-block h-[1em] w-[1em] shrink-0 align-[-0.125em]"
        :class="spin && 'animate-spin'"
        viewBox="0 0 24 24" fill="none" stroke="currentColor"
        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
        aria-hidden="true" focusable="false"
        v-html="inner"
    />
</template>
