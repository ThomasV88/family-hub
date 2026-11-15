<script setup>
import { nextTick, ref } from 'vue';
import { Plus, X } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../Components/ui/UiButton.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import { isHttpUrl } from './money.js';

// Controlled repeater of { url, label } rows (max 5). Blank rows are ignored on save by the parent.
const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    max: { type: Number, default: 5 },
    // Messages from the server by row index, shown until the row is edited.
    serverErrors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();

const touched = ref({});
const root = ref(null);
const atMax = () => props.modelValue.length >= props.max;

function update(index, patch) {
    emit('update:modelValue', props.modelValue.map((row, i) => (i === index ? { ...row, ...patch } : row)));
}
function add() {
    if (atMax()) return;
    emit('update:modelValue', [...props.modelValue, { url: '', label: '' }]);
    nextTick(() => {
        const inputs = root.value?.querySelectorAll('input[type="url"]');
        inputs?.[inputs.length - 1]?.focus();
    });
}
function remove(index) {
    emit('update:modelValue', props.modelValue.filter((_, i) => i !== index));
    touched.value = {};
}
function rowError(index) {
    const row = props.modelValue[index];
    if (touched.value[index] && row.url.trim() !== '' && !isHttpUrl(row.url)) return t('wishlist.error.url_invalid');
    return props.serverErrors[index] ?? '';
}
const touch = (index) => { touched.value = { ...touched.value, [index]: true }; };

// Parent calls this on save: marks every row touched and reports whether all non-blank URLs are valid.
function validateAll() {
    touched.value = Object.fromEntries(props.modelValue.map((_, i) => [i, true]));
    return props.modelValue.every((row) => row.url.trim() === '' || isHttpUrl(row.url));
}
defineExpose({ validateAll });
</script>

<template>
    <fieldset ref="root" class="links">
        <legend class="links__legend">{{ t('wishlist.field.links') }}</legend>
        <div v-for="(row, index) in modelValue" :key="index" class="links__row">
            <div class="links__fields">
                <UiField :label="`${t('wishlist.field.link_url')} ${index + 1}`" :error="rowError(index)">
                    <UiInput :model-value="row.url" type="url" inputmode="url" placeholder="https://" autocomplete="off" @update:model-value="update(index, { url: $event })" @blur="touch(index)" />
                </UiField>
                <UiField :label="`${t('wishlist.field.link_label')} ${index + 1}`">
                    <UiInput :model-value="row.label" :placeholder="t('wishlist.field.link_label_ph')" autocomplete="off" @update:model-value="update(index, { label: $event })" />
                </UiField>
            </div>
            <UiButton variant="ghost" icon-only :icon="X" :label="t('wishlist.field.link_remove', { n: index + 1 })" class="links__remove" @click="remove(index)" />
        </div>
        <UiButton variant="secondary" :icon="Plus" :disabled="modelValue.length >= max" @click="add">{{ t('wishlist.field.link_add') }}</UiButton>
        <p v-if="modelValue.length >= max" class="links__hint">{{ t('wishlist.field.link_max') }}</p>
    </fieldset>
</template>

<style scoped>
.links { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-3); min-width: 0; margin: 0 0 var(--space-4); padding: 0; border: 0; }
.links__legend { padding: 0; margin-bottom: var(--space-2); font-size: var(--text-sm); font-weight: 600; line-height: 1.4; }
.links__row { display: flex; align-items: flex-start; gap: var(--space-2); width: 100%; }
.links__fields { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: var(--space-2); }
.links__remove { flex: none; margin-block-start: var(--space-6); }
.links__hint { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
</style>
