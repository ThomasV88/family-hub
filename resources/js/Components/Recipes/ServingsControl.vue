<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Minus, Plus, RotateCcw } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../ui/UiButton.vue';
import UiInput from '../ui/UiInput.vue';
import UiTag from '../ui/UiTag.vue';
import HelpTip from '../ui/HelpTip.vue';

// Display-only servings recalculation. The chosen number is v-model (the page rescales the quantities from it) and is saved
// per member through `save(servings|null)` 600 ms after the last change (null when equal to the recipe's own servings).
const MIN = 1;
const LIMIT = 99;
const SAVE_DELAY_MS = 600;
const props = defineProps({
    modelValue: { type: Number, required: true },
    base: { type: Number, required: true },
    save: { type: Function, default: null },
});
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();

// A recipe whose own servings are above the usual limit must still be able to return to them with the stepper.
const MAX = computed(() => Math.max(LIMIT, props.base));
const draft = ref(String(props.modelValue));
// Shown (not only announced) when a typed number was pulled into range, so the number on screen is never a silent surprise.
const clampNote = ref('');
const scaled = computed(() => props.modelValue !== props.base);
const announcement = ref('');
let timer = null;

watch(() => props.modelValue, (v) => { if (Number(draft.value) !== v) draft.value = String(v); });

const clamp = (n) => Math.min(MAX.value, Math.max(MIN, n));

function flush() {
    if (timer === null) return;
    clearTimeout(timer);
    timer = null;
    props.save?.(props.modelValue === props.base ? null : props.modelValue);
}
function queueSave() {
    if (timer !== null) clearTimeout(timer);
    timer = setTimeout(flush, SAVE_DELAY_MS);
}
function set(next) {
    const rounded = Math.round(next);
    const n = clamp(rounded);
    clampNote.value = n !== rounded ? t('recipes.servings.clamped', { min: MIN, max: MAX.value }) : '';
    draft.value = String(n);
    if (n === props.modelValue) return;
    emit('update:modelValue', n);
    announcement.value = t('recipes.servings.updated', { n }, n);
    queueSave();
}
function onInput(value) {
    draft.value = value;
    clampNote.value = '';
    // Typing: apply a valid whole number at once, wait on anything else (empty, partial) until blur.
    if (/^\d{1,2}$/.test(value.trim()) && Number(value) >= MIN && Number(value) <= MAX.value) set(Number(value));
}
function onBlur() {
    const n = Number(draft.value);
    set(Number.isFinite(n) && draft.value.trim() !== '' ? n : props.modelValue);
}

onBeforeUnmount(flush);
</script>

<template>
    <div class="servings" role="group" :aria-label="t('recipes.servings.label')" data-testid="servings-control">
        <span class="servings__label">{{ t('recipes.servings.label') }}<HelpTip topic="recipes.servings" :label="t('recipes.servings.label')" /></span>
        <div class="servings__stepper">
            <UiButton variant="secondary" :icon="Minus" icon-only :label="t('recipes.servings.fewer')" :disabled="modelValue <= MIN" data-testid="servings-minus" @click="set(modelValue - 1)" />
            <span class="servings__field"><UiInput :model-value="draft" inputmode="numeric" :aria-label="t('recipes.servings.input')" data-testid="servings-input" @update:model-value="onInput" @blur="onBlur" /></span>
            <UiButton variant="secondary" :icon="Plus" icon-only :label="t('recipes.servings.more')" :disabled="modelValue >= MAX" data-testid="servings-plus" @click="set(modelValue + 1)" />
        </div>
        <template v-if="scaled">
            <UiTag data-testid="servings-scaled">{{ t('recipes.servings.scaled') }}</UiTag>
            <UiButton variant="ghost" :icon="RotateCcw" data-testid="servings-reset" @click="set(base)">{{ t('recipes.servings.reset', { n: base }) }}</UiButton>
        </template>
        <p v-if="clampNote" class="servings__note" role="status" data-testid="servings-clamped">{{ clampNote }}</p>
        <span class="visually-hidden" role="status" aria-live="polite">{{ announcement }}</span>
    </div>
</template>

<style scoped>
.servings { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2) var(--space-3); }
.servings__label { display: inline-flex; align-items: center; gap: var(--space-1); font-weight: 600; }
.servings__stepper { display: inline-flex; align-items: center; gap: var(--space-2); }
.servings__field { display: block; width: 64px; }
.servings__note { flex-basis: 100%; margin: 0; color: var(--color-text-muted); font-size: var(--text-sm); }
.servings__stepper :deep(.ui-button) { min-width: var(--control-touch); min-height: var(--control-touch); }
.servings__field :deep(input) { text-align: center; }
</style>
