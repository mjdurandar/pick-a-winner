<script setup>
import { onMounted, onUnmounted } from 'vue';

/**
 * The shell for the Pick a Winner screens as they were before the redesign
 * (Pages/Legacy). Those screens are built on Bootstrap and Font Awesome, which
 * the rest of the app no longer loads, so this layout makes sure they are
 * there and takes the stylesheets away again on the way out: left behind,
 * Bootstrap would restyle every Tailwind page that came after.
 *
 * On a normal page load app.blade.php has already put the same tags in the
 * head (see there), so a host never sees an unstyled flash. This only has work
 * to do when someone arrives from inside the app without a full reload.
 *
 * Delete this file, Pages/Legacy and Components/Legacy once the new screens
 * are live everywhere and config('pick_a_winner.new_ui') is gone.
 */
const STYLES = [
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
];
const SCRIPT = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js';

onMounted(() => {
    // Bootstrap has to come before the app's own stylesheet, as it always did,
    // so that Tailwind wins where the two disagree. The fonts link sits just
    // above that spot.
    const fonts = document.head.querySelector('link[href*="fonts.bunny.net/css"]');
    let after = fonts;

    STYLES.forEach((href) => {
        if (document.head.querySelector(`link[data-legacy-paw][href="${href}"]`)) return;
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        link.dataset.legacyPaw = '';
        if (after) {
            after.insertAdjacentElement('afterend', link);
        } else {
            document.head.prepend(link);
        }
        after = link;
    });

    if (!window.bootstrap && !document.querySelector(`script[src="${SCRIPT}"]`)) {
        const script = document.createElement('script');
        script.src = SCRIPT;
        script.dataset.legacyPaw = '';
        document.body.appendChild(script);
    }
});

onUnmounted(() => {
    // Going from one old screen to the next mounts this layout again straight
    // away, so wait a moment and only clean up if no old screen is showing.
    setTimeout(() => {
        if (document.querySelector('[data-legacy-paw-root]')) return;
        document.head.querySelectorAll('link[data-legacy-paw]').forEach((el) => el.remove());
    }, 50);
});
</script>

<template>
    <div class="min-h-screen" style="background-color: #151515;" data-legacy-paw-root>
        <main>
            <slot />
        </main>
    </div>
</template>
