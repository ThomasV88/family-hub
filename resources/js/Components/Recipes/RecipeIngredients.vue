<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import UiList from '../ui/UiList.vue';
import UiProgress from '../ui/UiProgress.vue';
import HelpTip from '../ui/HelpTip.vue';
import IngredientRow from './IngredientRow.vue';
import ServingsControl from './ServingsControl.vue';

// The "Ingredients" section of the detail: heading, servings control (only when the recipe has servings), progress and the rows.
const props = defineProps({
    rows: { type: Array, required: true }, // [{ ingredient, quantityText }]
    isChecked: { type: Function, required: true },
    servingsBase: { type: Number, default: null },
    servings: { type: Number, default: null },
    saveServings: { type: Function, default: null },
    cooking: { type: Boolean, default: false },
});
const emit = defineEmits(['toggle', 'update:servings']);
const { t } = useI18n();

const total = computed(() => props.rows.length);
const prepared = computed(() => props.rows.filter((r) => props.isChecked(r.ingredient.id)).length);
</script>

<template>
    <section class="ings" :aria-labelledby="'ings-heading'" data-testid="ingredients-section">
        <div class="ings__head">
            <h2 id="ings-heading" class="ings__title">{{ t('recipes.detail.ingredients') }}<HelpTip topic="recipes.progress" :label="t('recipes.detail.ingredients')" /></h2>
            <ServingsControl v-if="servingsBase !== null && servings !== null" class="ings__servings" :model-value="servings" :base="servingsBase" :save="saveServings" @update:model-value="(v) => emit('update:servings', v)" />
        </div>
        <p class="ings__progress" data-testid="ingredients-progress">{{ t('recipes.progress.prepared', { x: prepared, y: total }) }}</p>
        <UiProgress class="ings__bar" :value="prepared" :max="Math.max(1, total)" :label="t('recipes.progress.prepared', { x: prepared, y: total })" />
        <UiList :aria-label="t('recipes.detail.ingredients')">
            <IngredientRow v-for="row in rows" :key="row.ingredient.id" :ingredient="row.ingredient" :quantity-text="row.quantityText" :checked="isChecked(row.ingredient.id)" :cooking="cooking" @toggle="(v) => emit('toggle', row.ingredient.id, v)" />
        </UiList>
    </section>
</template>

<style scoped>
.ings { display: flex; flex-direction: column; gap: var(--space-2); }
.ings__head { display: flex; flex-direction: column; gap: var(--space-3); }
.ings__title { display: flex; align-items: center; gap: var(--space-2); margin: 0; font-size: var(--text-xl); font-weight: 600; line-height: 1.2; }
.ings__servings { position: sticky; inset-block-start: 0; z-index: 1; padding-block: var(--space-2); background: var(--color-bg); }
.ings__progress { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.ings__bar { height: 4px; }
</style>
