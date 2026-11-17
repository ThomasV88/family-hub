<script setup>
import { computed, onBeforeUnmount, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useShell } from '../Shell/shellState.js';

// The primary shopping action. On phone a bar fixed above the bottom navigation (64 px plus the safe area) shows
// "{n} items left" until everything is marked, then the action (slot). On wider screens it is a plain right-aligned row
// under the progress, with no fixed positioning.
const props = defineProps({ left: { type: Number, default: 0 } });
const { t } = useI18n();
const shell = useShell();
const phone = computed(() => Boolean(shell?.isPhone?.value));

// The bar is 64 px high and sits where toasts appear: lift the toasts above it while it is shown.
const LIFT = '--fh-bar-lift';
watch(phone, (on) => {
    if (on) document.documentElement.style.setProperty(LIFT, '64px');
    else document.documentElement.style.removeProperty(LIFT);
}, { immediate: true });
onBeforeUnmount(() => document.documentElement.style.removeProperty(LIFT));
</script>

<template>
    <div v-if="phone" class="sticky-bar" data-testid="sticky-bar">
        <p v-if="left > 0" class="sticky-bar__text" role="status">{{ t('shopping.progress.left', { n: left }, left) }}</p>
        <slot v-else />
    </div>
    <div v-else-if="$slots.default && left === 0" class="action-row" data-testid="action-row"><slot /></div>
</template>

<style scoped>
.sticky-bar {
    position: fixed; inset-inline: 0; z-index: 20;
    bottom: calc(var(--shell-bottombar) + env(safe-area-inset-bottom));
    display: flex; align-items: center; justify-content: center;
    min-height: 64px; padding: var(--space-2) var(--space-4);
    background: var(--color-surface); border-block-start: 1px solid var(--color-border);
}
.sticky-bar :deep(.ui-button) { width: 100%; }
.sticky-bar__text { margin: 0; font-weight: 600; }
.action-row { display: flex; justify-content: flex-end; }
</style>
