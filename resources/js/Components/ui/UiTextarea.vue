<script setup>
import { ref } from 'vue';
import { useFieldContext } from './useField.js';

defineOptions({ inheritAttrs: false });
defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
    rows: { type: Number, default: 3 },
});
defineEmits(['update:modelValue']);

const { id, describedBy, invalid } = useFieldContext();
const el = ref(null);
defineExpose({ focus: () => el.value?.focus() });
</script>

<template>
    <textarea
        :id="id"
        ref="el"
        v-bind="$attrs"
        class="ui-textarea"
        :class="{ 'ui-textarea--invalid': invalid }"
        :rows="Math.max(3, rows)"
        :value="modelValue"
        :placeholder="placeholder"
        :disabled="disabled"
        :readonly="readonly"
        :aria-describedby="describedBy"
        :aria-invalid="invalid ? 'true' : undefined"
        @input="$emit('update:modelValue', $event.target.value)"
    />
</template>

<style scoped>
.ui-textarea {
    display: block;
    width: 100%;
    min-height: calc(3 * 1.5em + 2 * var(--space-2));
    padding: var(--space-2) var(--space-3);
    border: 1px solid var(--color-border-strong);
    border-radius: var(--radius-md);
    background: var(--color-surface);
    color: var(--color-text);
    font: inherit;
    font-size: var(--text-base);
    line-height: 1.5;
    resize: vertical;
    transition: background-color 150ms, border-color 150ms;
}
.ui-textarea::placeholder { color: var(--color-text-muted); }
.ui-textarea:is(:hover, .is-hover):not(:disabled):not(:read-only) { background: var(--color-surface-2); }
.ui-textarea:is(:focus-visible, .is-focus) { outline: 2px solid var(--color-focus); outline-offset: 1px; }
.ui-textarea:read-only { background: var(--color-surface-2); }
.ui-textarea:disabled { background: var(--color-surface-2); color: var(--color-text-muted); cursor: not-allowed; }
.ui-textarea--invalid { border-color: var(--color-danger); }
</style>
