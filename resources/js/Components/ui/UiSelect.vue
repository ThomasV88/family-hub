<script setup>
import { ref } from 'vue';
import { ChevronDown } from '@lucide/vue';
import { useFieldContext } from './useField.js';

defineOptions({ inheritAttrs: false });
defineProps({
    modelValue: { type: [String, Number], default: '' },
    options: { type: Array, default: () => [] },
    disabled: { type: Boolean, default: false },
});
defineEmits(['update:modelValue']);

const { id, describedBy, invalid } = useFieldContext();
const el = ref(null);
defineExpose({ focus: () => el.value?.focus() });
</script>

<template>
    <span class="ui-select" :class="{ 'ui-select--invalid': invalid }">
        <select
            :id="id"
            ref="el"
            v-bind="$attrs"
            class="ui-select__control"
            :value="modelValue"
            :disabled="disabled"
            :aria-describedby="describedBy"
            :aria-invalid="invalid ? 'true' : undefined"
            @change="$emit('update:modelValue', $event.target.value)"
        >
            <slot>
                <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
            </slot>
        </select>
        <ChevronDown :size="20" :stroke-width="1.75" aria-hidden="true" class="ui-select__chevron" />
    </span>
</template>

<style scoped>
.ui-select { position: relative; display: block; }
.ui-select__control {
    display: block;
    width: 100%;
    height: var(--control-md);
    padding: 0 calc(var(--space-3) + 28px) 0 var(--space-3);
    border: 1px solid var(--color-border-strong);
    border-radius: var(--radius-md);
    background: var(--color-surface);
    color: var(--color-text);
    font: inherit;
    font-size: var(--text-base);
    appearance: none;
    transition: background-color 150ms, border-color 150ms;
}
@media (pointer: coarse) {
    .ui-select__control { height: var(--control-touch); }
}
.ui-select__control:is(:hover, .is-hover):not(:disabled) { background: var(--color-surface-2); }
.ui-select__control:is(:focus-visible, .is-focus) { outline: 2px solid var(--color-focus); outline-offset: 1px; }
.ui-select__control:disabled { background: var(--color-surface-2); color: var(--color-text-muted); cursor: not-allowed; }
.ui-select--invalid .ui-select__control { border-color: var(--color-danger); }
.ui-select__chevron { position: absolute; inset-inline-end: var(--space-3); top: 50%; transform: translateY(-50%); color: var(--color-text-muted); pointer-events: none; }
</style>
