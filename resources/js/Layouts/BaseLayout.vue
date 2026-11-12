<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const appName = computed(() => page.props.app?.name ?? 'Family Hub');
const isStaging = computed(() => page.props.app?.env === 'staging');
</script>

<template>
    <div class="layout">
        <div v-if="isStaging" class="staging-banner" data-testid="staging-banner">
            Staging - synthetic data only
        </div>
        <header class="layout__header">{{ appName }}</header>
        <main class="layout__main">
            <slot />
        </main>
    </div>
</template>
