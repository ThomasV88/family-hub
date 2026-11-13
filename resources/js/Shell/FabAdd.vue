<script setup>
import { Plus } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { useShell } from './shellState.js';

const { t } = useI18n();
const shell = useShell();
</script>

<template>
    <button type="button" class="fab" :aria-label="t('nav.add_task')" @click="shell.triggerAdd($event.currentTarget)">
        <Plus :size="24" :stroke-width="1.75" aria-hidden="true" />
    </button>
</template>

<style scoped>
.fab {
    position: fixed;
    inset-inline-end: var(--space-4);
    /* sits above the bottom bar (one label line plus icon) and the safe area */
    inset-block-end: calc(var(--shell-bottombar) + var(--space-4) + env(safe-area-inset-bottom));
    z-index: 21;
    display: grid;
    place-items: center;
    width: var(--shell-fab);
    height: var(--shell-fab);
    border: 0;
    border-radius: var(--radius-full);
    background: var(--color-primary);
    color: var(--color-on-primary);
    box-shadow: var(--shadow-md);
    cursor: pointer;
}
.fab:hover { background: var(--color-primary-hover); }
</style>
