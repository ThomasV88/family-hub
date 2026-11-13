<script setup>
defineOptions({ inheritAttrs: false });
defineProps({
    modelValue: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});
defineEmits(['update:modelValue']);
</script>

<template>
    <label class="ui-choice" :class="{ 'ui-choice--disabled': disabled }">
        <input
            v-bind="$attrs"
            type="checkbox"
            role="switch"
            class="ui-choice__input"
            :checked="modelValue"
            :disabled="disabled"
            @change="$emit('update:modelValue', $event.target.checked)"
        />
        <span class="ui-switch__track" aria-hidden="true"><span class="ui-switch__thumb" /></span>
        <span class="ui-choice__label"><slot /></span>
    </label>
</template>

<style scoped>
.ui-choice { position: relative; display: inline-flex; align-items: center; gap: var(--space-2); min-height: 28px; font-size: var(--text-base); line-height: 1.5; color: var(--color-text); cursor: pointer; }
.ui-choice--disabled { color: var(--color-text-muted); cursor: not-allowed; }
.ui-choice__input { position: absolute; inset-block-start: 50%; inset-inline-start: 0; width: 36px; height: 20px; margin: 0; transform: translateY(-50%); opacity: 0; cursor: inherit; }
.ui-switch__track { flex: none; position: relative; width: 36px; height: 20px; border: 1px solid var(--color-border-strong); border-radius: var(--radius-full); background: var(--color-surface-2); transition: background-color 150ms, border-color 150ms; }
.ui-switch__thumb { position: absolute; inset-block-start: 2px; inset-inline-start: 2px; width: 14px; height: 14px; border-radius: var(--radius-full); background: var(--color-text-muted); transition: transform 150ms, background-color 150ms; }
.ui-choice__input:checked + .ui-switch__track { background: var(--color-primary); border-color: var(--color-primary); }
.ui-choice__input:checked + .ui-switch__track .ui-switch__thumb { background: var(--color-on-primary); transform: translateX(16px); }
[dir='rtl'] .ui-choice__input:checked + .ui-switch__track .ui-switch__thumb { transform: translateX(-16px); }
.ui-choice:is(:hover, .is-hover) .ui-choice__input:not(:disabled):not(:checked) + .ui-switch__track { background: var(--color-border); }
.ui-choice__input:focus-visible + .ui-switch__track { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.ui-choice__input:disabled + .ui-switch__track { border-color: var(--color-border); }
.ui-choice__input:disabled:checked + .ui-switch__track { background: var(--color-border-strong); border-color: var(--color-border-strong); }
@media (pointer: coarse) {
    .ui-choice { min-height: var(--control-touch); }
    .ui-choice__input { width: 48px; height: var(--control-touch); inset-inline-start: -6px; }
}
@media (prefers-reduced-motion: reduce) {
    .ui-switch__track, .ui-switch__thumb { transition: none; }
}
</style>
