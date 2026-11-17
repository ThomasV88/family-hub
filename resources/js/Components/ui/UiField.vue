<script setup>
import { computed, useId } from 'vue';
import { CircleAlert } from '@lucide/vue';
import { provideField } from './useField.js';
import HelpTip from './HelpTip.vue';

const props = defineProps({
    label: { type: String, required: true },
    hint: { type: String, default: '' },
    error: { type: String, default: '' },
    id: { type: String, default: '' },
    required: { type: Boolean, default: false },
    labelVisuallyHidden: { type: Boolean, default: false },
    // Contextual help topic (registry key): a small green "?" after the label. Ignored when the label is visually hidden.
    // The `label-after` slot puts something (a tag) next to the label, also ignored when the label is visually hidden.
    help: { type: String, default: '' },
});

const generated = useId();
const controlId = computed(() => props.id || `f-${generated}`);
const hintId = computed(() => `${controlId.value}-hint`);
const errorId = computed(() => `${controlId.value}-error`);
const describedBy = computed(() => {
    const ids = [];
    if (props.hint) ids.push(hintId.value);
    if (props.error) ids.push(errorId.value);
    return ids.length ? ids.join(' ') : undefined;
});
provideField({ id: controlId, describedBy, invalid: computed(() => Boolean(props.error)) });
</script>

<template>
    <div class="ui-field">
        <div v-if="(help || $slots['label-after']) && !labelVisuallyHidden" class="ui-field__head">
            <label :for="controlId" class="ui-field__label">
                {{ label }}<span v-if="required" class="ui-field__required" aria-hidden="true"> *</span>
            </label>
            <HelpTip v-if="help" :topic="help" :label="label" />
            <slot name="label-after" />
        </div>
        <label v-else :for="controlId" class="ui-field__label" :class="{ 'visually-hidden': labelVisuallyHidden }">
            {{ label }}<span v-if="required" class="ui-field__required" aria-hidden="true"> *</span>
        </label>
        <slot />
        <p v-if="hint" :id="hintId" class="ui-field__hint">{{ hint }}</p>
        <p v-if="error" :id="errorId" class="ui-field__error" aria-live="polite">
            <CircleAlert :size="16" :stroke-width="1.75" aria-hidden="true" />
            <span>{{ error }}</span>
        </p>
    </div>
</template>

<style scoped>
.ui-field { display: flex; flex-direction: column; gap: var(--space-1); margin-bottom: var(--space-4); }
.ui-field__head { display: flex; align-items: center; gap: var(--space-2); min-width: 0; }
.ui-field__label { font-size: var(--text-sm); font-weight: 600; line-height: 1.4; color: var(--color-text); }
.ui-field__hint { margin: 0; font-size: var(--text-sm); font-weight: 400; line-height: 1.4; color: var(--color-text-muted); }
.ui-field__error { display: flex; align-items: flex-start; gap: var(--space-1); margin: 0; font-size: var(--text-sm); font-weight: 400; line-height: 1.4; color: var(--color-danger); }
.ui-field__error svg { flex: none; margin-top: 2px; }
</style>
