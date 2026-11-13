<script setup>
import { computed, useId } from 'vue';
import { CircleAlert } from '@lucide/vue';
import { provideField } from './useField.js';

const props = defineProps({
    label: { type: String, required: true },
    hint: { type: String, default: '' },
    error: { type: String, default: '' },
    id: { type: String, default: '' },
    required: { type: Boolean, default: false },
    labelVisuallyHidden: { type: Boolean, default: false },
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
        <label :for="controlId" class="ui-field__label" :class="{ 'visually-hidden': labelVisuallyHidden }">
            {{ label }}<span v-if="required" class="ui-field__required" aria-hidden="true"> *</span>
        </label>
        <slot />
        <p v-if="hint" :id="hintId" class="ui-field__hint">{{ hint }}</p>
        <p v-if="error" :id="errorId" class="ui-field__error">
            <CircleAlert :size="16" :stroke-width="1.75" aria-hidden="true" />
            <span>{{ error }}</span>
        </p>
    </div>
</template>

<style scoped>
.ui-field { display: flex; flex-direction: column; gap: var(--space-1); margin-bottom: var(--space-4); }
.ui-field__label { font-size: var(--text-sm); font-weight: 600; line-height: 1.4; color: var(--color-text); }
.ui-field__hint { margin: 0; font-size: var(--text-sm); font-weight: 400; line-height: 1.4; color: var(--color-text-muted); }
.ui-field__error { display: flex; align-items: flex-start; gap: var(--space-1); margin: 0; font-size: var(--text-sm); font-weight: 400; line-height: 1.4; color: var(--color-danger); }
.ui-field__error svg { flex: none; margin-top: 2px; }
</style>
