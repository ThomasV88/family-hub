<script setup>
import { computed } from 'vue';
import { LoaderCircle } from '@lucide/vue';

const props = defineProps({
    variant: { type: String, default: 'secondary', validator: (v) => ['primary', 'secondary', 'ghost', 'danger'].includes(v) },
    size: { type: String, default: 'md', validator: (v) => ['md', 'sm'].includes(v) },
    type: { type: String, default: 'button' },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    icon: { type: [Object, Function], default: null },
    iconOnly: { type: Boolean, default: false },
    label: { type: String, default: '' },
    href: { type: String, default: '' },
});
const emit = defineEmits(['click']);

if (props.iconOnly && !props.label && import.meta.env?.DEV !== false) {
    console.warn('[UiButton] icon-only button needs a "label" prop for its accessible name.');
}

const inactive = computed(() => props.disabled || props.loading);
const classes = computed(() => [
    'ui-button',
    `ui-button--${props.variant}`,
    `ui-button--${props.size}`,
    { 'ui-button--icon-only': props.iconOnly, 'ui-button--loading': props.loading },
]);
const ariaLabel = computed(() => (props.iconOnly && props.label ? props.label : undefined));

function onClick(event) {
    if (inactive.value) {
        event.preventDefault();
        return;
    }
    emit('click', event);
}
</script>

<template>
    <component
        :is="href ? 'a' : 'button'"
        :class="classes"
        :type="href ? undefined : type"
        :href="href && !inactive ? href : undefined"
        :disabled="href ? undefined : inactive"
        :aria-disabled="href && inactive ? 'true' : undefined"
        :aria-busy="loading ? 'true' : undefined"
        :aria-label="ariaLabel"
        @click="onClick"
    >
        <component :is="icon" v-if="icon" :size="20" :stroke-width="1.75" aria-hidden="true" class="ui-button__icon" />
        <span v-if="!iconOnly" class="ui-button__label"><slot /></span>
        <LoaderCircle v-if="loading" :size="20" :stroke-width="1.75" aria-hidden="true" class="ui-button__spinner" />
    </component>
</template>

<style scoped>
.ui-button {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
    min-height: var(--control-md);
    padding: var(--space-2) var(--space-4);
    border: 1px solid transparent;
    border-radius: var(--radius-md);
    font: inherit;
    font-size: var(--text-sm);
    font-weight: 600;
    line-height: 1.25;
    text-align: center;
    text-decoration: none;
    cursor: pointer;
    transition: background-color 150ms, border-color 150ms, color 150ms;
}
.ui-button--sm { padding-inline: var(--space-3); }
@media (pointer: fine) {
    .ui-button--sm { min-height: var(--control-sm); }
}
@media (pointer: coarse) {
    .ui-button { min-height: var(--control-touch); }
}
.ui-button--icon-only { padding-inline: var(--space-2); aspect-ratio: 1; }
.ui-button--primary { background: var(--color-primary); color: var(--color-on-primary); }
.ui-button--primary:is(:hover, .is-hover, :active, .is-active) { background: var(--color-primary-hover); }
.ui-button--secondary { background: var(--color-surface); color: var(--color-text); border-color: var(--color-border-strong); }
.ui-button--secondary:is(:hover, .is-hover) { background: var(--color-surface-2); }
.ui-button--secondary:is(:active, .is-active) { background: var(--color-border); }
.ui-button--ghost { background: transparent; color: var(--color-text); }
.ui-button--ghost:is(:hover, .is-hover) { background: var(--color-surface-2); }
.ui-button--ghost:is(:active, .is-active) { background: var(--color-border); }
.ui-button--danger { background: var(--color-danger-fill); color: var(--color-on-primary); }
.ui-button--danger:is(:hover, .is-hover, :active, .is-active) { filter: brightness(0.92); }
.ui-button:is(:focus-visible, .is-focus) { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.ui-button:disabled,
.ui-button[aria-disabled='true'] { opacity: 0.5; cursor: not-allowed; }
.ui-button--loading { cursor: progress; }
.ui-button--loading .ui-button__label { visibility: hidden; }
.ui-button__spinner {
    position: absolute;
    inset: 0;
    margin: auto;
    animation: ui-button-spin 1s linear infinite;
}
@keyframes ui-button-spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) {
    .ui-button__spinner { animation: none; }
}
</style>
