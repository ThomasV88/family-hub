<script setup>
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import UiChip from '../ui/UiChip.vue';
import UiField from '../ui/UiField.vue';
import UiInput from '../ui/UiInput.vue';
import UiRadio from '../ui/UiRadio.vue';
import UiSelect from '../ui/UiSelect.vue';
import UiSwitch from '../ui/UiSwitch.vue';
import { normaliseFilters } from './recipeFilters.js';

// The filter controls (category chips, author, ingredient, show, favourites). The same component fills the inline row on a wide
// container and the bottom sheet on a phone. It only edits the filter object; the page applies it and the server filters.
const props = defineProps({
    modelValue: { type: Object, required: true },
    options: { type: Object, default: () => ({ categories: [], authors: [] }) },
});
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();
const uid = useId();

const f = computed(() => normaliseFilters(props.modelValue));
const set = (patch) => emit('update:modelValue', { ...f.value, ...patch });
const toggleCategory = (name, on) => set({ categories: on ? [...f.value.categories, name] : f.value.categories.filter((c) => c !== name) });
const authors = computed(() => [{ value: '', label: t('recipes.filter.author_everyone') }, ...(props.options.authors ?? []).map((a) => ({ value: String(a.id), label: a.name }))]);
const SHOW = [['all', 'recipes.filter.show_all'], ['mine', 'recipes.filter.show_mine'], ['shared', 'recipes.filter.show_shared']];
</script>

<template>
    <div class="recipe-filters" data-testid="recipe-filters">
        <fieldset v-if="(options.categories ?? []).length > 0" class="recipe-filters__group">
            <legend class="recipe-filters__legend">{{ t('recipes.filter.category') }}</legend>
            <div class="recipe-filters__chips">
                <UiChip v-for="c in options.categories" :key="c.key" :model-value="f.categories.includes(c.name)" @update:model-value="toggleCategory(c.name, $event)">{{ c.name }}</UiChip>
            </div>
        </fieldset>
        <div class="recipe-filters__row">
            <UiField :label="t('recipes.filter.author')" :id="`${uid}-author`">
                <UiSelect :model-value="f.author === null ? '' : String(f.author)" :options="authors" @update:model-value="set({ author: $event === '' ? null : Number($event) })" />
            </UiField>
            <UiField :label="t('recipes.filter.ingredient')" :id="`${uid}-ingredient`">
                <UiInput :model-value="f.ingredient" autocomplete="off" @update:model-value="set({ ingredient: $event })" />
            </UiField>
        </div>
        <fieldset class="recipe-filters__group">
            <legend class="recipe-filters__legend">{{ t('recipes.filter.show') }}</legend>
            <div class="recipe-filters__radios">
                <UiRadio v-for="[value, key] in SHOW" :key="value" :name="`${uid}-show`" :value="value" :model-value="f.show" @update:model-value="set({ show: $event })">{{ t(key) }}</UiRadio>
            </div>
        </fieldset>
        <UiSwitch :model-value="f.favourites" @update:model-value="set({ favourites: $event })">{{ t('recipes.filter.favourites_only') }}</UiSwitch>
    </div>
</template>

<style scoped>
.recipe-filters { display: flex; flex-direction: column; gap: var(--space-4); min-width: 0; }
.recipe-filters__group { margin: 0; padding: 0; border: 0; min-width: 0; }
.recipe-filters__legend { padding: 0; margin-block-end: var(--space-2); font-size: var(--text-sm); font-weight: 600; }
.recipe-filters__chips { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.recipe-filters__radios { display: flex; flex-wrap: wrap; gap: var(--space-2) var(--space-4); }
.recipe-filters__row { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr)); gap: var(--space-3); }
</style>
