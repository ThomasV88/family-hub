<script setup>
import { ref } from 'vue';
import { useFieldContext } from './useField.js';

defineOptions({ inheritAttrs: false });
defineProps({
    modelValue: { type: [String, Number], default: '' },
    type: { type: String, default: 'text' },
    placeholder: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
    autocomplete: { type: String, default: undefined },
    inputmode: { type: String, default: undefined },
    icon: { type: [Object, Function], default: null },
});
defineEmits(['update:modelValue']);

const { id, describedBy, invalid } = useFieldContext();
const el = ref(null);
defineExpose({ focus: () => el.value?.focus() });
</script>

<template>
    <span class="ui-input" :class="{ 'ui-input--icon': icon, 'ui-input--invalid': invalid }">
        <component :is="icon" v-if="icon" :size="20" :stroke-width="1.75" aria-hidden="true" class="ui-input__icon" />
        <input
            :id="id"
            ref="el"
            v-bind="$attrs"
            class="ui-control"
            :type="type"
            :value="modelValue"
            :placeholder="placeholder"
            :disabled="disabled"
            :readonly="readonly"
            :autocomplete="autocomplete"
            :inputmode="inputmode"
            :aria-describedby="describedBy"
            :aria-invalid="invalid ? 'true' : undefined"
            @input="$emit('update:modelValue', $event.target.value)"
        />
    </span>
</template>

<style scoped>
.ui-input { position: relative; display: block; }
.ui-input__icon { position: absolute; inset-inline-start: var(--space-3); top: 50%; transform: translateY(-50%); color: var(--color-text-muted); pointer-events: none; }
.ui-input--icon .ui-control { padding-inline-start: calc(var(--space-3) + 28px); }
.ui-control {
    display: block;
    width: 100%;
    height: var(--control-md);
    padding: 0 var(--space-3);
    border: 1px solid var(--color-border-strong);
    border-radius: var(--radius-md);
    background: var(--color-surface);
    color: var(--color-text);
    font: inherit;
    font-size: var(--text-base);
    line-height: 1.5;
    transition: background-color 150ms, border-color 150ms;
}
@media (pointer: coarse) {
    .ui-control { height: var(--control-touch); }
}
.ui-control::placeholder { color: var(--color-text-muted); }
.ui-control:is(:hover, .is-hover):not(:disabled):not(:read-only) { background: var(--color-surface-2); }
.ui-control:is(:focus-visible, .is-focus) { outline: 2px solid var(--color-focus); outline-offset: 1px; }
.ui-control:read-only { background: var(--color-surface-2); }
.ui-control:disabled { background: var(--color-surface-2); color: var(--color-text-muted); cursor: not-allowed; }
.ui-input--invalid .ui-control { border-color: var(--color-danger); }
</style>
