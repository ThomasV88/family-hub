<script setup>
import { useI18n } from 'vue-i18n';
import { Plus, X } from '@lucide/vue';
import StyleSection from '../StyleSection.vue';
import UiButton from '../../Components/ui/UiButton.vue';

const { t } = useI18n();
const variants = ['primary', 'secondary', 'ghost', 'danger'];
const states = [
    { key: 'default', cls: '', props: {} },
    { key: 'hover', cls: 'is-hover', props: {} },
    { key: 'active', cls: 'is-active', props: {} },
    { key: 'focus', cls: 'is-focus', props: {} },
    { key: 'disabled', cls: '', props: { disabled: true } },
    { key: 'loading', cls: '', props: { loading: true } },
];
</script>

<template>
    <StyleSection id="buttons" title-key="style.section.buttons">
        <div class="buttons__scroll">
            <table class="buttons__grid">
                <thead>
                    <tr>
                        <td />
                        <th v-for="state in states" :key="state.key" scope="col">{{ t(`style.state.${state.key}`) }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="variant in variants" :key="variant">
                        <th scope="row">{{ t(`style.variant.${variant}`) }}</th>
                        <td v-for="state in states" :key="state.key">
                            <UiButton :variant="variant" :class="state.cls" v-bind="state.props">{{ t('ui.save_changes') }}</UiButton>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="buttons__row">
            <UiButton variant="primary" :icon="Plus">{{ t('ui.save_changes') }}</UiButton>
            <UiButton variant="secondary" icon-only :icon="X" :label="t('ui.close')" />
            <UiButton variant="secondary" size="sm">{{ t('ui.save_changes') }}</UiButton>
            <div class="buttons__narrow">
                <UiButton variant="primary">{{ t('confirm.delete_task.title') }} {{ t('style.demo.dialog_body') }}</UiButton>
            </div>
        </div>
    </StyleSection>
</template>

<style scoped>
.buttons__scroll { overflow-x: auto; }
.buttons__grid { border-collapse: separate; border-spacing: var(--space-3); font-size: var(--text-sm); }
.buttons__grid th { font-weight: 600; text-align: left; white-space: nowrap; }
.buttons__row { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-4); margin-top: var(--space-4); }
.buttons__narrow { max-width: 160px; }
</style>
