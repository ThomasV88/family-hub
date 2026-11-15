<script setup>
defineProps({
    width: { type: String, default: '100%' },
    height: { type: String, default: '16px' },
});
</script>

<template>
    <span class="ui-skeleton" aria-hidden="true" :style="{ width, height }" />
</template>

<style scoped>
.ui-skeleton { display: block; background: var(--color-surface-2); border-radius: var(--radius-sm); animation: ui-skeleton-pulse 1.5s ease-in-out infinite; }
@keyframes ui-skeleton-pulse { 50% { opacity: 0.5; } }
@media (prefers-reduced-motion: reduce) {
    .ui-skeleton { animation: none; }
}
</style>
