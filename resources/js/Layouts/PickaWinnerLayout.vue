<script setup>
import { Head } from '@inertiajs/vue3';
import PawToasts from '@/Components/PawToasts.vue';
</script>

<template>
    <div class="min-h-screen" style="background-color: #151515;">
        <main>
            <slot />
        </main>

        <!-- "Saved", "deleted" and the like, for every Pick a Winner page. -->
        <PawToasts />
    </div>
</template> 