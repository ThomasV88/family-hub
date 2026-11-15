<script setup>
defineOptions({ inheritAttrs: false });
defineProps({
    modelValue: { type: [String, Number, Boolean], default: '' },
    value: { type: [String, Number, Boolean], required: true },
    name: { type: String, default: undefined },
    disabled: { type: Boolean, default: false },
});
defineEmits(['update:modelValue']);
</script>

<template>
    <label class="ui-choice" :class="{ 'ui-choice--disabled': disabled }">
        <input
            v-bind="$attrs"
            type="radio"
            class="ui-choice__input"
            :name="name"
            :value="value"
            :checked="modelValue === value"
            :disabled="disabled"
            @change="$emit('update:modelValue', value)"
        />
        <span class="ui-choice__box" aria-hidden="true"><span class="ui-choice__dot" /></span>
        <span class="ui-choice__label"><slot /></span>
    </label>
</template>

<style scoped>
.ui-choice { position: relative; display: inline-flex; align-items: center; gap: var(--space-2); min-height: 28px; font-size: var(--text-base); line-height: 1.5; color: var(--color-text); cursor: pointer; }
.ui-choice--disabled { color: var(--color-text-muted); cursor: not-allowed; }
.ui-choice__input { position: absolute; inset-block-start: 50%; inset-inline-start: 0; width: 20px; height: 20px; margin: 0; transform: translateY(-50%); opacity: 0; cursor: inherit; }
.ui-choice__box { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border: 1px solid var(--color-border-strong); border-radius: var(--radius-full); background: var(--color-surface); transition: background-color 150ms, border-color 150ms; }
.ui-choice__dot { width: 8px; height: 8px; border-radius: var(--radius-full); background: var(--color-on-primary); opacity: 0; }
.ui-choice__input:checked + .ui-choice__box { background: var(--color-primary); border-color: var(--color-primary); }
.ui-choice__input:checked + .ui-choice__box .ui-choice__dot { opacity: 1; }
.ui-choice:is(:hover, .is-hover) .ui-choice__input:not(:disabled):not(:checked) + .ui-choice__box { background: var(--color-surface-2); }
.ui-choice__input:focus-visible + .ui-choice__box { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.ui-choice__input:disabled + .ui-choice__box { background: var(--color-surface-2); border-color: var(--color-border); }
.ui-choice__input:disabled:checked + .ui-choice__box { background: var(--color-border-strong); border-color: var(--color-border-strong); }
@media (pointer: coarse) {
    .ui-choice { min-height: var(--control-touch); }
    .ui-choice__input { width: var(--control-touch); height: var(--control-touch); inset-inline-start: -12px; }
}
@media (prefers-reduced-motion: reduce) {
    .ui-choice__box { transition: none; }
}
</style>
