<script setup>
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <button
        type="button"
        class="ui-chip"
        :class="{ 'is-on': modelValue }"
        :aria-pressed="modelValue ? 'true' : 'false'"
        :disabled="disabled"
        @click="emit('update:modelValue', !props.modelValue)"
    >
        <slot />
    </button>
</template>

<style scoped>
.ui-chip {
    display: inline-flex;
    align-items: center;
    min-height: var(--control-sm);
    padding: var(--space-1) var(--space-3);
    border: 1px solid var(--color-border-strong);
    border-radius: var(--radius-full);
    background: var(--color-surface);
    color: var(--color-text);
    font: inherit;
    font-size: var(--text-sm);
    font-weight: 600;
    cursor: pointer;
    transition: background-color 150ms, border-color 150ms;
}
@media (pointer: coarse) {
    .ui-chip { min-height: var(--control-touch); }
}
.ui-chip:is(:hover, .is-hover) { background: var(--color-surface-2); }
.ui-chip.is-on { background: var(--color-primary-soft); color: var(--color-primary); border-color: var(--color-primary); }
.ui-chip:is(:focus-visible, .is-focus) { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.ui-chip:disabled { opacity: 0.5; cursor: not-allowed; }
</style>
