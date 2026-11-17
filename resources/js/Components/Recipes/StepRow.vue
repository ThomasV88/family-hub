<script setup>
import { Check } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiCheckbox from '../ui/UiCheckbox.vue';

// One step of the detail with the member's own "done" tick. No forced order. The step text is interpolated, never HTML.
defineProps({
    step: { type: Object, required: true },
    number: { type: Number, required: true },
    checked: { type: Boolean, default: false },
    cooking: { type: Boolean, default: false },
});
const emit = defineEmits(['toggle']);
const { t } = useI18n();
</script>

<template>
    <li :class="['step-row', { 'step-row--on': checked, 'step-row--cooking': cooking }]" data-testid="step-row">
        <UiCheckbox :model-value="checked" :aria-label="t('recipes.progress.done_label', { n: number })" @update:model-value="(v) => emit('toggle', v)">
            <span class="step-row__badge" aria-hidden="true"><Check v-if="checked" :size="16" :stroke-width="2.5" /><template v-else>{{ number }}</template></span>
            <span class="step-row__text">{{ step.body }}</span>
        </UiCheckbox>
    </li>
</template>

<style scoped>
.step-row { display: flex; background: transparent; }
.step-row--on { background: var(--color-surface-2); }
.step-row :deep(.ui-choice) { flex: 1; align-items: flex-start; min-height: 56px; padding: var(--space-4) var(--space-3); gap: var(--space-3); }
.step-row--cooking :deep(.ui-choice) { min-height: 64px; }
.step-row :deep(.ui-choice__label) { display: flex; flex: 1; align-items: flex-start; gap: var(--space-3); min-width: 0; }
.step-row__badge { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: var(--radius-full); background: var(--color-surface-2); font-size: var(--text-sm); font-weight: 600; }
.step-row--on .step-row__badge { background: var(--color-primary); color: var(--color-on-primary); }
.step-row__text { font-size: var(--text-base); line-height: 1.5; white-space: pre-line; overflow-wrap: anywhere; }
</style>
