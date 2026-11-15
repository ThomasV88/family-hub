<script setup>
import { useId } from 'vue';
import { useI18n } from 'vue-i18n';

// Two-option segmented radiogroup, same pattern as ThemeSwitch. The value lives in the URL (?view), not on the client.
defineProps({
    view: { type: String, default: 'folders' },
});
const emit = defineEmits(['change']);
const { t } = useI18n();
const name = `health-view-${useId()}`;
const OPTIONS = ['folders', 'timeline'];
</script>

<template>
    <fieldset class="view-switch" data-testid="view-switch">
        <legend class="visually-hidden">{{ t('health.view.label') }}</legend>
        <label v-for="option in OPTIONS" :key="option" class="view-switch__option">
            <input type="radio" class="visually-hidden" :name="name" :value="option" :checked="view === option" @change="emit('change', option)" />
            <span>{{ t(`health.view.${option}`) }}</span>
        </label>
    </fieldset>
</template>

<style scoped>
.view-switch { display: inline-flex; gap: var(--space-1); margin: 0; padding: var(--space-1); border: 0; border-radius: var(--radius-md); background: var(--color-surface-2); }
.view-switch__option { display: inline-flex; align-items: center; min-height: var(--control-md); padding: 0 var(--space-3); border-radius: var(--radius-md); font-size: var(--text-sm); color: var(--color-text); cursor: pointer; }
.view-switch__option:has(input:checked) { background: var(--color-primary-soft); color: var(--color-primary); font-weight: 600; }
.view-switch__option:has(input:focus-visible) { outline: 2px solid var(--color-focus); outline-offset: 2px; }
@media (pointer: coarse) { .view-switch__option { min-height: var(--control-touch); } }
</style>
