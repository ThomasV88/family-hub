<script setup>
import { nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';

// Two-option segmented radiogroup for the stacked review layout (phone, iPad portrait). One Tab stop, arrows move
// inside. The value is owned by the page (it also lives in ?pane=); nothing is kept here.
const props = defineProps({
    modelValue: { type: String, default: 'entries' },
});
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();

const OPTIONS = [
    { value: 'document', label: 'health.ai.review.pane_document' },
    { value: 'entries', label: 'health.ai.review.pane_suggestions' },
];
const group = ref(null);

function pick(value) {
    if (value !== props.modelValue) emit('update:modelValue', value);
}
async function onKey(event) {
    const forward = ['ArrowRight', 'ArrowDown'].includes(event.key);
    const back = ['ArrowLeft', 'ArrowUp'].includes(event.key);
    if (!forward && !back) return;
    event.preventDefault();
    const index = OPTIONS.findIndex((o) => o.value === props.modelValue);
    const next = OPTIONS[(index + (forward ? 1 : OPTIONS.length - 1)) % OPTIONS.length];
    pick(next.value);
    await nextTick();
    group.value?.querySelector(`[data-value="${next.value}"]`)?.focus();
}
</script>

<template>
    <div ref="group" class="pane-toggle" role="radiogroup" :aria-label="t('health.ai.review.pane_toggle_label')" data-testid="pane-toggle" @keydown="onKey">
        <button
            v-for="option in OPTIONS"
            :key="option.value"
            type="button"
            role="radio"
            class="pane-toggle__option"
            :aria-checked="modelValue === option.value ? 'true' : 'false'"
            :tabindex="modelValue === option.value ? 0 : -1"
            :data-value="option.value"
            @click="pick(option.value)"
        >{{ t(option.label) }}</button>
    </div>
</template>

<style scoped>
.pane-toggle { display: inline-flex; gap: var(--space-1); padding: var(--space-1); border-radius: var(--radius-md); background: var(--color-surface-2); max-width: 100%; }
.pane-toggle__option { min-height: var(--control-touch); padding: 0 var(--space-4); border: 0; border-radius: var(--radius-md); background: transparent; font: inherit; font-size: var(--text-sm); color: var(--color-text); cursor: pointer; overflow-wrap: anywhere; }
.pane-toggle__option[aria-checked='true'] { background: var(--color-primary-soft); color: var(--color-primary); font-weight: 600; }
.pane-toggle__option:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
</style>
