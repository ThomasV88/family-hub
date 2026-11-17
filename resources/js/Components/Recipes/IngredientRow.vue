<script setup>
import { useI18n } from 'vue-i18n';
import UiCheckbox from '../ui/UiCheckbox.vue';

// One ingredient of the detail with the member's own "prepared" tick. The whole row is the label, so the hit area is the row.
// A ticked row only changes colour: it never reorders and is never struck through. Text is interpolated, never rendered as HTML.
defineProps({
    ingredient: { type: Object, required: true },
    quantityText: { type: String, default: '' },
    checked: { type: Boolean, default: false },
    cooking: { type: Boolean, default: false },
});
const emit = defineEmits(['toggle']);
const { t } = useI18n();
</script>

<template>
    <li :class="['ing-row', { 'ing-row--on': checked, 'ing-row--cooking': cooking }]" data-testid="ingredient-row">
        <UiCheckbox :model-value="checked" :aria-label="t('recipes.progress.prepared_label', { name: ingredient.name })" @update:model-value="(v) => emit('toggle', v)">
            <span class="ing-row__qty" data-testid="ingredient-quantity">{{ quantityText }}</span>
            <span class="ing-row__text">
                <span class="ing-row__name">{{ ingredient.name }}</span>
                <span v-if="ingredient.note" class="ing-row__note">{{ ingredient.note }}</span>
            </span>
        </UiCheckbox>
    </li>
</template>

<style scoped>
.ing-row { display: flex; background: transparent; }
.ing-row--on { background: var(--color-surface-2); }
.ing-row :deep(.ui-choice) { flex: 1; align-items: center; min-height: 56px; padding: var(--space-2) var(--space-3); gap: var(--space-3); }
.ing-row--cooking :deep(.ui-choice) { min-height: 64px; padding-block: var(--space-4); }
.ing-row :deep(.ui-choice__label) { display: flex; flex: 1; align-items: baseline; gap: var(--space-3); min-width: 0; }
.ing-row__qty { flex: none; min-width: 96px; font-size: var(--text-sm); font-weight: 600; text-align: end; }
.ing-row__text { display: flex; flex-direction: column; min-width: 0; overflow-wrap: anywhere; }
.ing-row__name { font-size: var(--text-base); }
.ing-row__note { font-size: var(--text-sm); color: var(--color-text-muted); }
@container (max-width: 359px) {
    .ing-row :deep(.ui-choice__label) { flex-direction: column; gap: 0; }
    .ing-row__qty { min-width: 0; text-align: start; }
}
</style>
