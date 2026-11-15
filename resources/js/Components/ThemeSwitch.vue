<script setup>
import { ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { THEME_OPTIONS, getThemePreference, setThemePreference } from '../theme.js';

const { t } = useI18n();
const current = ref(getThemePreference());
// Unique per instance so two switches on one page do not share a radio group.
const groupName = `fh-theme-switch-${useId()}`;

function choose(value) {
    current.value = value;
    setThemePreference(value);
}
</script>

<template>
    <fieldset class="theme-switch" data-testid="theme-switch">
        <legend class="visually-hidden">{{ t('theme.label') }}</legend>
        <label v-for="option in THEME_OPTIONS" :key="option" class="theme-switch__option">
            <input
                type="radio"
                class="visually-hidden"
                :name="groupName"
                :value="option"
                :checked="current === option"
                @change="choose(option)"
            />
            <span>{{ t(`theme.${option}`) }}</span>
        </label>
    </fieldset>
</template>

<style scoped>
.theme-switch {
    display: inline-flex;
    gap: var(--space-1);
    margin: 0;
    padding: var(--space-1);
    border: 0;
    border-radius: var(--radius-md);
    background: var(--color-surface-2);
}
.theme-switch__option {
    display: inline-flex;
    align-items: center;
    min-height: var(--control-md);
    padding: 0 var(--space-3);
    border: 1px solid transparent;
    border-radius: var(--radius-md);
    font-size: var(--text-sm);
    color: var(--color-text);
    cursor: pointer;
}
.theme-switch__option:has(input:checked) {
    background: var(--color-surface);
    border-color: var(--color-border-strong);
    color: var(--color-primary);
    font-weight: 600;
}
.theme-switch__option:has(input:focus-visible) {
    outline: 2px solid var(--color-focus);
    outline-offset: 2px;
}
@media (pointer: coarse) {
    .theme-switch__option { min-height: var(--control-touch); }
}
</style>
