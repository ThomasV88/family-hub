<script setup>
defineProps({
    variant: { type: String, default: 'neutral', validator: (v) => ['neutral', 'warn', 'danger', 'success'].includes(v) },
    icon: { type: [Object, Function], default: null },
});
</script>

<template>
    <span :class="['ui-tag', `ui-tag--${variant}`]">
        <component :is="icon" v-if="icon" :size="16" :stroke-width="1.75" aria-hidden="true" />
        <slot />
    </span>
</template>

<style scoped>
.ui-tag {
    display: inline-flex;
    align-items: center;
    gap: var(--space-1);
    padding: var(--space-1) var(--space-2);
    border-radius: var(--radius-full);
    font-size: var(--text-sm);
    font-weight: 600;
    line-height: 1.25;
    white-space: nowrap;
    background: var(--color-surface-2);
    color: var(--color-text-muted);
}
.ui-tag--warn { background: var(--color-warn-bg); color: var(--color-warn-text); }
.ui-tag--danger { color: var(--color-danger); }
.ui-tag--success { color: var(--color-success); }
</style>
