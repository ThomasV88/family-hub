<script setup>
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowDown, ArrowUp, Ellipsis, Plus, Trash2 } from '@lucide/vue';
import UiAlert from '../ui/UiAlert.vue';
import UiButton from '../ui/UiButton.vue';
import UiField from '../ui/UiField.vue';
import UiInput from '../ui/UiInput.vue';
import UiMenu from '../ui/UiMenu.vue';
import UiSelect from '../ui/UiSelect.vue';
import { useToast } from '../ui/useToast.js';
import { unitLabel } from '../../Shopping/units.js';
import { parseIngredientLines } from './ingredientPaste.js';

// The ingredient lines of the recipe form. Rows are { key, id, quantity (text), unitId ('' or id as text), name, note }; the
// parent owns them (v-model) and every change here emits a new array. `errors` is { [row key]: { name, quantity, unit_id, note } }
// already translated; `edit` tells the parent which field the member changed so it can clear that error.
const props = defineProps({
    modelValue: { type: Array, required: true },
    units: { type: Array, default: () => [] },
    limits: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    listError: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'edit']);
const { t, locale } = useI18n();
const { show: showToast } = useToast();

const root = ref(null);
const addButton = ref(null);
let serial = 0;
const newKey = () => `n${Date.now().toString(36)}-${serial++}`;
const blankRow = () => ({ key: newKey(), id: null, quantity: '', unitId: '', name: '', note: '' });
const isBlank = (row) => row.name.trim() === '' && row.quantity.trim() === '' && row.note.trim() === '';
const atCap = computed(() => props.modelValue.length >= props.limits.ingredientsMax);

const unitOptions = computed(() => props.units.map((u) => ({ value: String(u.id), label: unitLabel(u, 1, t, locale.value) })));
const errorOf = (row, field) => props.errors?.[row.key]?.[field] ?? '';

function focusRow(key, field = 'name') {
    nextTick(() => root.value?.querySelector(`[data-row="${key}"] [data-field="${field}"]`)?.focus());
}

function update(index, field, value) {
    emit('update:modelValue', props.modelValue.map((row, i) => (i === index ? { ...row, [field]: value } : row)));
    emit('edit', { key: props.modelValue[index].key, field });
    if (field === 'name' && offer.value?.key === props.modelValue[index].key) offer.value = null;
}

function add(afterIndex = props.modelValue.length - 1) {
    if (atCap.value || props.disabled) return;
    const row = blankRow();
    const next = [...props.modelValue];
    next.splice(afterIndex + 1, 0, row);
    emit('update:modelValue', next);
    focusRow(row.key);
}

function move(index, by) {
    const to = index + by;
    if (to < 0 || to >= props.modelValue.length) return;
    const next = [...props.modelValue];
    [next[index], next[to]] = [next[to], next[index]];
    emit('update:modelValue', next);
}

function remove(index) {
    const row = props.modelValue[index];
    const next = props.modelValue.filter((_, i) => i !== index);
    emit('update:modelValue', next);
    if (offer.value?.key === row.key) offer.value = null;
    if (isBlank(row)) {
        if (next.length > 0) focusRow(next[Math.min(index, next.length - 1)].key);
        else nextTick(() => addButton.value?.$el?.focus());

        return;
    }
    showToast(t('recipes.toast.ingredient_removed'), {
        duration: 8000,
        action: {
            label: t('recipes.toast.undo'),
            onSelect: () => {
                const current = props.modelValue;
                if (current.some((r) => r.key === row.key) || current.length >= props.limits.ingredientsMax) return;
                const restored = [...current];
                restored.splice(Math.min(index, restored.length), 0, row);
                emit('update:modelValue', restored);
                focusRow(row.key);
            },
        },
    });
    if (next.length > 0) focusRow(next[Math.min(index, next.length - 1)].key);
    else nextTick(() => addButton.value?.$el?.focus());
}

function onMenu(index, { key }) {
    if (key === 'up') move(index, -1);
    else if (key === 'down') move(index, 1);
}
const menuItems = (index) => [
    ...(index > 0 ? [{ type: 'item', key: 'up', label: t('recipes.ingredient.move_up'), icon: ArrowUp }] : []),
    ...(index < props.modelValue.length - 1 ? [{ type: 'item', key: 'down', label: t('recipes.ingredient.move_down'), icon: ArrowDown }] : []),
];

function onEnter(index, event) {
    if (event.isComposing) return;
    event.preventDefault();
    add(index);
}

// Pasting several lines into a name: the text goes in as one line (a text input cannot hold line breaks) and the member is
// offered to split it into one ingredient per line.
const offer = ref(null);
function onPaste(index, event) {
    const text = event.clipboardData?.getData('text') ?? '';
    const parsed = parseIngredientLines(text, { units: props.units, t, locale: locale.value, nameMax: props.limits.ingredientNameMax });
    if (parsed.length < 2) return;
    event.preventDefault();
    const row = props.modelValue[index];
    const input = event.target;
    const start = input.selectionStart ?? row.name.length;
    const end = input.selectionEnd ?? row.name.length;
    const prefix = row.name.slice(0, start);
    const suffix = row.name.slice(end);
    const joined = text.split(/\r\n|\r|\n/).map((l) => l.trim()).filter(Boolean).join(', ');
    update(index, 'name', `${prefix}${joined}${suffix}`.slice(0, props.limits.ingredientNameMax));
    offer.value = { key: row.key, prefix, suffix, parsed };
}

function split() {
    const current = offer.value;
    if (!current) return;
    const index = props.modelValue.findIndex((r) => r.key === current.key);
    offer.value = null;
    if (index === -1) return;
    const row = props.modelValue[index];
    const keep = `${current.prefix}${current.suffix}`.trim();
    const free = props.limits.ingredientsMax - props.modelValue.length + (keep === '' ? 1 : 0);
    const lines = current.parsed.slice(0, Math.max(free, 0));
    const made = lines.map((p) => ({ key: newKey(), id: null, quantity: p.quantity === null ? '' : String(p.quantity), unitId: p.unitId === null ? '' : String(p.unitId), name: p.name, note: '' }));
    const next = [...props.modelValue];
    if (keep === '') {
        // The row held only the pasted text: the first line takes its place (and its id), the rest follow.
        if (made.length === 0) return;
        made[0] = { ...made[0], key: row.key, id: row.id, note: row.note };
        next.splice(index, 1, ...made);
    } else {
        next[index] = { ...row, name: keep };
        next.splice(index + 1, 0, ...made);
    }
    emit('update:modelValue', next);
    const focusKey = made[made.length - 1]?.key ?? row.key;
    focusRow(focusKey);
}
</script>

<template>
    <div ref="root" class="rec-ing">
        <div v-if="offer" aria-live="polite">
            <UiAlert variant="info" class="rec-ing__offer" data-testid="split-offer">
                {{ t('recipes.ingredient.split_offer', { n: offer.parsed.length }) }}
                <template #actions>
                    <UiButton variant="secondary" size="sm" data-testid="split-button" @click="split">{{ t('recipes.ingredient.split') }}</UiButton>
                    <UiButton variant="ghost" size="sm" @click="offer = null">{{ t('recipes.ingredient.split_dismiss') }}</UiButton>
                </template>
            </UiAlert>
        </div>
        <ol class="rec-ing__list">
            <li v-for="(row, index) in modelValue" :key="row.key" class="rec-ing__row" :data-row="row.key">
                <div class="rec-ing__fields">
                    <UiField :label="t('recipes.ingredient.quantity')" :error="errorOf(row, 'quantity')" class="rec-ing__quantity">
                        <UiInput
                            :model-value="row.quantity" inputmode="decimal" autocomplete="off" data-field="quantity" :disabled="disabled"
                            @update:model-value="update(index, 'quantity', $event)"
                        />
                    </UiField>
                    <UiField :label="t('recipes.ingredient.unit')" :error="errorOf(row, 'unit_id')" class="rec-ing__unit">
                        <UiSelect :model-value="row.unitId" data-field="unit" :disabled="disabled" @update:model-value="update(index, 'unitId', $event)">
                            <option value="">{{ t('recipes.ingredient.unit_none') }}</option>
                            <option v-for="o in unitOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                        </UiSelect>
                    </UiField>
                    <UiField :label="t('recipes.ingredient.name')" :error="errorOf(row, 'name')" required class="rec-ing__name">
                        <UiInput
                            :model-value="row.name" autocomplete="off" :maxlength="limits.ingredientNameMax" data-field="name" :disabled="disabled"
                            @update:model-value="update(index, 'name', $event)"
                            @keydown.enter="onEnter(index, $event)"
                            @paste="onPaste(index, $event)"
                        />
                    </UiField>
                    <UiField :label="t('recipes.ingredient.note')" :error="errorOf(row, 'note')" class="rec-ing__note">
                        <UiInput
                            :model-value="row.note" :placeholder="t('recipes.ingredient.note_placeholder')" autocomplete="off" :maxlength="limits.ingredientNoteMax" data-field="note" :disabled="disabled"
                            @update:model-value="update(index, 'note', $event)"
                        />
                    </UiField>
                </div>
                <div class="rec-ing__actions">
                    <UiMenu
                        v-if="modelValue.length > 1"
                        :label="t('recipes.ingredient.actions', { n: index + 1 })" :icon="Ellipsis" icon-only trigger-variant="ghost"
                        :items="menuItems(index)" @select="onMenu(index, $event)"
                    />
                    <UiButton
                        variant="ghost" icon-only :icon="Trash2" :label="t('recipes.ingredient.remove', { n: index + 1 })" :disabled="disabled"
                        data-testid="remove-ingredient" @click="remove(index)"
                    />
                </div>
            </li>
        </ol>
        <p v-if="listError" class="rec-ing__error" aria-live="polite" data-invalid="true" tabindex="-1" data-testid="ingredients-error">{{ listError }}</p>
        <UiButton ref="addButton" variant="secondary" :icon="Plus" :disabled="disabled || atCap" data-testid="add-ingredient" @click="add()">{{ t('recipes.ingredient.add') }}</UiButton>
        <p v-if="atCap" class="rec-ing__cap">{{ t('recipes.ingredient.cap', { n: limits.ingredientsMax }) }}</p>
    </div>
</template>

<style scoped>
.rec-ing { container-type: inline-size; display: flex; flex-direction: column; gap: var(--space-3); }
.rec-ing__list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--space-3); }
.rec-ing__row { display: flex; align-items: flex-start; gap: var(--space-2); padding: var(--space-3); background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.rec-ing__fields { flex: 1 1 auto; min-width: 0; display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 0 var(--space-3); }
.rec-ing__fields :deep(.ui-field) { margin-bottom: var(--space-3); }
.rec-ing__name, .rec-ing__note { grid-column: 1 / -1; }
.rec-ing__actions { flex: none; display: flex; flex-direction: column; gap: var(--space-1); }
.rec-ing__error, .rec-ing__cap { margin: 0; font-size: var(--text-sm); }
.rec-ing__error { color: var(--color-danger); }
.rec-ing__cap { color: var(--color-text-muted); }
.rec-ing__offer { margin-block-end: var(--space-1); }
/* One line per ingredient once the list has room: quantity, unit, name, note. */
@container (min-width: 640px) {
    .rec-ing__row { align-items: flex-start; }
    .rec-ing__fields { grid-template-columns: 96px 150px minmax(0, 2fr) minmax(0, 1.4fr); }
    .rec-ing__name, .rec-ing__note { grid-column: auto; }
    .rec-ing__actions { flex-direction: row; }
    .rec-ing__fields :deep(.ui-field) { margin-bottom: 0; }
}
</style>
