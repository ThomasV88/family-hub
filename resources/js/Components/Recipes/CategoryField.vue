<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { X } from '@lucide/vue';
import UiButton from '../ui/UiButton.vue';
import UiField from '../ui/UiField.vue';
import UiInput from '../ui/UiInput.vue';
import { normalize } from '../../Shopping/catalogSearch.js';

// Up to `max` categories as chips. Suggestions are the starter set (localised labels, stored as the label of the current
// language) plus the categories already used in the family. Enter or a comma commits the typed text; a duplicate (ignoring
// case and accents) is refused.
const props = defineProps({
    modelValue: { type: Array, required: true },
    // Names already used by visible recipes.
    suggestions: { type: Array, default: () => [] },
    // Keys of the starter set (labels at recipes.category_starter.{key}).
    starter: { type: Array, default: () => [] },
    max: { type: Number, default: 3 },
    itemMax: { type: Number, default: 30 },
    error: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'edit']);
const { t } = useI18n();

const text = ref('');
const message = ref('');
const within = ref(false);
const input = ref(null);

const atMax = computed(() => props.modelValue.length >= props.max);
const options = computed(() => {
    const seen = new Set(props.modelValue.map(normalize));
    const out = [];
    for (const name of [...props.starter.map((k) => t(`recipes.category_starter.${k}`)), ...props.suggestions]) {
        const key = normalize(name);
        if (key === '' || seen.has(key)) continue;
        seen.add(key);
        out.push(name);
    }

    return out;
});
const shown = computed(() => {
    const needle = normalize(text.value);

    return options.value.filter((name) => needle === '' || normalize(name).includes(needle)).slice(0, 10);
});
const showSuggestions = computed(() => !props.disabled && !atMax.value && (within.value || text.value !== '') && shown.value.length > 0);

/** Adds one category; returns true when it was added. */
function commitOne(raw) {
    const name = String(raw).trim().replace(/\s+/g, ' ');
    if (name === '') return true;
    if (name.length > props.itemMax) { message.value = t('recipes.form.category_too_long', { n: props.itemMax }); return false; }
    if (props.modelValue.some((c) => normalize(c) === normalize(name))) { message.value = t('recipes.form.category_duplicate', { name }); return false; }
    if (props.modelValue.length >= props.max) { message.value = t('recipes.form.categories_max', { n: props.max }); return false; }
    message.value = '';
    emit('update:modelValue', [...props.modelValue, name]);
    emit('edit');

    return true;
}

function commitPending() {
    const pending = text.value;
    if (pending.trim() === '') { text.value = ''; return true; }
    const ok = commitOne(pending);
    if (ok) text.value = '';

    return ok;
}

function onInput(value) {
    message.value = '';
    if (!value.includes(',')) { text.value = value; return; }
    // A comma commits what came before it (pasted lists too); the rest stays in the box.
    const parts = value.split(',');
    const rest = parts.pop();
    for (const part of parts) if (!commitOne(part)) break;
    text.value = rest;
}

function onEnter(event) {
    if (event.isComposing) return;
    event.preventDefault();
    commitPending();
}

function pick(name) {
    if (commitOne(name)) { text.value = ''; input.value?.focus(); }
}

function remove(name) {
    message.value = '';
    emit('update:modelValue', props.modelValue.filter((c) => c !== name));
    emit('edit');
    input.value?.focus();
}

function onFocusOut(event) {
    if (!event.currentTarget.contains(event.relatedTarget)) within.value = false;
}

defineExpose({ commitPending });
</script>

<template>
    <div class="rec-cat" @focusin="within = true" @focusout="onFocusOut">
        <ul v-if="modelValue.length > 0" class="rec-cat__chips" :aria-label="t('recipes.form.categories')">
            <li v-for="name in modelValue" :key="name" class="rec-cat__chip" data-testid="category-chip">
                <span class="rec-cat__name">{{ name }}</span>
                <UiButton variant="ghost" size="sm" icon-only :icon="X" :label="t('recipes.form.category_remove', { name })" :disabled="disabled" @click="remove(name)" />
            </li>
        </ul>
        <UiField :label="t('recipes.form.category_add')" :error="error" :hint="atMax ? t('recipes.form.categories_max', { n: max }) : t('recipes.form.categories_hint', { n: max })">
            <UiInput
                ref="input" :model-value="text" autocomplete="off" :maxlength="itemMax + 1" :disabled="disabled || atMax" data-testid="category-input"
                @update:model-value="onInput" @keydown.enter="onEnter"
            />
        </UiField>
        <p class="rec-cat__message" role="status" aria-live="polite" data-testid="category-message">{{ message }}</p>
        <div v-if="showSuggestions" class="rec-cat__suggestions" role="group" :aria-label="t('recipes.form.category_suggestions')">
            <button v-for="name in shown" :key="name" type="button" class="rec-cat__suggestion" data-testid="category-suggestion" @mousedown.prevent @click="pick(name)">{{ name }}</button>
        </div>
    </div>
</template>

<style scoped>
.rec-cat { display: flex; flex-direction: column; gap: var(--space-2); }
.rec-cat :deep(.ui-field) { margin-bottom: 0; }
.rec-cat__chips { display: flex; flex-wrap: wrap; gap: var(--space-2); margin: 0; padding: 0; list-style: none; }
.rec-cat__chip { display: inline-flex; align-items: center; gap: var(--space-1); padding-inline-start: var(--space-3); background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: 999px; min-height: 44px; }
.rec-cat__name { overflow-wrap: anywhere; font-size: var(--text-sm); }
.rec-cat__message { margin: 0; min-height: 0; font-size: var(--text-sm); color: var(--color-danger); }
.rec-cat__message:empty { display: none; }
.rec-cat__suggestions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.rec-cat__suggestion { min-height: 44px; padding-inline: var(--space-3); background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border); border-radius: 999px; font: inherit; font-size: var(--text-sm); cursor: pointer; }
.rec-cat__suggestion:hover { background: var(--color-surface-2); }
</style>
