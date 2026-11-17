<script setup>
import { nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowDown, ArrowUp, Plus, Trash2 } from '@lucide/vue';
import UiButton from '../ui/UiButton.vue';
import UiField from '../ui/UiField.vue';
import UiTextarea from '../ui/UiTextarea.vue';
import { useToast } from '../ui/useToast.js';

// The steps of the recipe form. Rows are { key, id, body }; numbers are display-only and follow the order. The parent owns the
// rows (v-model); `errors` is { [row key]: message } already translated.
const props = defineProps({
    modelValue: { type: Array, required: true },
    limits: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    listError: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'edit']);
const { t } = useI18n();
const { show: showToast } = useToast();

const root = ref(null);
const addButton = ref(null);
let serial = 0;
const newKey = () => `s${Date.now().toString(36)}-${serial++}`;
const atCap = () => props.modelValue.length >= props.limits.stepsMax;

function grow(el) {
    if (!el || !el.scrollHeight) return;
    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight}px`;
}
const growAll = () => root.value?.querySelectorAll('textarea').forEach(grow);
onMounted(growAll);
watch(() => props.modelValue.length, () => nextTick(growAll));

const focusRow = (key) => nextTick(() => root.value?.querySelector(`[data-row="${key}"] textarea`)?.focus());

function update(index, value) {
    emit('update:modelValue', props.modelValue.map((row, i) => (i === index ? { ...row, body: value } : row)));
    emit('edit', { key: props.modelValue[index].key });
}

function add() {
    if (atCap() || props.disabled) return;
    const row = { key: newKey(), id: null, body: '' };
    emit('update:modelValue', [...props.modelValue, row]);
    focusRow(row.key);
}

function move(index, by) {
    const to = index + by;
    if (to < 0 || to >= props.modelValue.length) return;
    const next = [...props.modelValue];
    [next[index], next[to]] = [next[to], next[index]];
    emit('update:modelValue', next);
    // The row moved in the DOM, so keep the focus on the same button.
    const label = by < 0 ? 'up' : 'down';
    nextTick(() => root.value?.querySelector(`[data-row="${next[to].key}"] [data-move="${label}"]`)?.focus());
}

function remove(index) {
    const row = props.modelValue[index];
    const next = props.modelValue.filter((_, i) => i !== index);
    emit('update:modelValue', next);
    if (next.length > 0) focusRow(next[Math.min(index, next.length - 1)].key);
    else nextTick(() => addButton.value?.$el?.focus());
    if (row.body.trim() === '') return;
    showToast(t('recipes.toast.step_removed'), {
        duration: 8000,
        action: {
            label: t('recipes.toast.undo'),
            onSelect: () => {
                const current = props.modelValue;
                if (current.some((r) => r.key === row.key) || atCap()) return;
                const restored = [...current];
                restored.splice(Math.min(index, restored.length), 0, row);
                emit('update:modelValue', restored);
                focusRow(row.key);
            },
        },
    });
}
</script>

<template>
    <div ref="root" class="rec-steps">
        <ol class="rec-steps__list">
            <li v-for="(row, index) in modelValue" :key="row.key" class="rec-steps__row" :data-row="row.key">
                <span class="rec-steps__number" aria-hidden="true">{{ index + 1 }}</span>
                <UiField :label="t('recipes.step.label', { n: index + 1 })" :error="errors[row.key] ?? ''" required class="rec-steps__field">
                    <UiTextarea
                        :model-value="row.body" :rows="2" :maxlength="limits.stepMax" :disabled="disabled" data-field="step"
                        @update:model-value="update(index, $event)"
                        @input="(e) => grow(e.target)"
                    />
                </UiField>
                <div class="rec-steps__actions">
                    <UiButton variant="ghost" icon-only :icon="ArrowUp" :label="t('recipes.step.move_up', { n: index + 1 })" :disabled="disabled || index === 0" data-move="up" @click="move(index, -1)" />
                    <UiButton variant="ghost" icon-only :icon="ArrowDown" :label="t('recipes.step.move_down', { n: index + 1 })" :disabled="disabled || index === modelValue.length - 1" data-move="down" @click="move(index, 1)" />
                    <UiButton variant="ghost" icon-only :icon="Trash2" :label="t('recipes.step.remove', { n: index + 1 })" :disabled="disabled" data-testid="remove-step" @click="remove(index)" />
                </div>
            </li>
        </ol>
        <p v-if="listError" class="rec-steps__error" aria-live="polite" data-invalid="true" tabindex="-1" data-testid="steps-error">{{ listError }}</p>
        <UiButton ref="addButton" variant="secondary" :icon="Plus" :disabled="disabled || modelValue.length >= limits.stepsMax" data-testid="add-step" @click="add">{{ t('recipes.step.add') }}</UiButton>
    </div>
</template>

<style scoped>
.rec-steps { display: flex; flex-direction: column; gap: var(--space-3); }
.rec-steps__list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--space-3); }
.rec-steps__row { display: flex; align-items: flex-start; gap: var(--space-2); }
.rec-steps__number { flex: none; width: 28px; height: 28px; margin-block-start: 28px; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; background: var(--color-surface-2); font-size: var(--text-sm); font-weight: 600; }
.rec-steps__field { flex: 1 1 auto; min-width: 0; margin-bottom: 0; }
.rec-steps__field :deep(textarea) { resize: none; overflow: hidden; }
.rec-steps__actions { flex: none; display: flex; flex-wrap: wrap; width: 44px; gap: var(--space-1); margin-block-start: 22px; }
.rec-steps__error { margin: 0; font-size: var(--text-sm); color: var(--color-danger); }
</style>
