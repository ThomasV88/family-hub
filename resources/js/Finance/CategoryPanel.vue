<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiDetailPanel from '../Components/ui/UiDetailPanel.vue';
import UiDialog from '../Components/ui/UiDialog.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import UiRadio from '../Components/ui/UiRadio.vue';
import UiSelect from '../Components/ui/UiSelect.vue';
import { categoryLabel } from './labels.js';

// Family category panel. `category` is { mode: 'new' } or { id, kind, defaultKey, name }. Names and kinds are
// family-wide and non-sensitive; nothing here knows how many rows use a category (the server never sends it).
const props = defineProps({
    category: { type: Object, required: true },
    // finance.categories: every family category, used for the "Move to" choices.
    categories: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);
const { t, locale } = useI18n();

const isNew = computed(() => props.category.mode === 'new');
const form = useForm({
    name: isNew.value ? '' : (props.category.name ?? ''),
    kind: isNew.value ? 'expense' : props.category.kind,
});
// A default category that was never renamed shows its translated name as the starting text.
if (!isNew.value && !props.category.name) form.name = categoryLabel(props.category, t);

const failed = ref(false);
const localError = ref('');
const tr = (key) => (key ? t(key) : '');
const nameError = computed(() => tr(localError.value || form.errors?.name));

function submit() {
    failed.value = false;
    localError.value = form.name.trim() === '' ? 'finance.cat.err_name' : '';
    if (localError.value) return;
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onError: (errors) => { failed.value = !errors?.name; },
        onHttpException: () => { failed.value = true; },
        onNetworkError: () => { failed.value = true; },
    };
    if (isNew.value) form.post('/finance/categories', options);
    else form.patch(`/finance/categories/${props.category.id}`, options);
}

const collator = computed(() => new Intl.Collator(locale.value));
const targets = computed(() => props.categories
    .filter((c) => !isNew.value && c.kind === props.category.kind && c.id !== props.category.id)
    .map((c) => ({ value: c.id, label: categoryLabel(c, t) }))
    .sort((a, b) => collator.value.compare(a.label, b.label)));

const deleteOpen = ref(false);
const moveTo = ref('');
const moveError = ref('');
const deleting = ref(false);
const keep = ref(null);
const shownName = computed(() => (isNew.value ? '' : categoryLabel(props.category, t)));

function openDelete() {
    moveTo.value = '';
    moveError.value = '';
    deleteOpen.value = true;
}
function confirmDelete() {
    if (moveTo.value === '') return;
    deleting.value = true;
    router.delete(`/finance/categories/${props.category.id}`, {
        data: { move_to: moveTo.value },
        preserveScroll: true,
        onSuccess: () => { deleteOpen.value = false; emit('close'); },
        onError: (errors) => { moveError.value = tr(errors?.move_to ?? 'finance.cat.err_move'); },
        onFinish: () => { deleting.value = false; },
    });
}
</script>

<template>
    <UiDetailPanel :open="true" :title="isNew ? t('finance.cat.panel_new') : t('finance.cat.panel_edit')" @update:open="(open) => !open && emit('close')">
        <form id="finance-category-form" class="cat-panel" novalidate @submit.prevent="submit">
            <UiAlert v-if="failed" variant="danger" live>{{ t('finance.cat.err_save') }}</UiAlert>
            <UiField :label="t('finance.cat.field_name')" :error="nameError" required>
                <UiInput v-model="form.name" maxlength="40" autocomplete="off" />
            </UiField>
            <fieldset class="cat-panel__type">
                <legend class="cat-panel__legend">{{ t('finance.cat.field_type') }}</legend>
                <div class="cat-panel__choices" role="radiogroup" :aria-label="t('finance.cat.field_type')">
                    <UiRadio v-model="form.kind" name="category-kind" value="expense" :disabled="!isNew">{{ t('finance.kind.expense') }}</UiRadio>
                    <UiRadio v-model="form.kind" name="category-kind" value="income" :disabled="!isNew">{{ t('finance.kind.income') }}</UiRadio>
                </div>
            </fieldset>
        </form>
        <template #footer>
            <UiButton v-if="!isNew" variant="ghost" :icon="Trash2" class="cat-panel__delete" @click="openDelete">{{ t('finance.cat.delete') }}</UiButton>
            <UiButton variant="secondary" @click="emit('close')">{{ t('finance.close') }}</UiButton>
            <UiButton type="submit" form="finance-category-form" variant="primary" :loading="form.processing" :disabled="form.processing">{{ t('finance.cat.save') }}</UiButton>
        </template>
    </UiDetailPanel>

    <UiDialog v-model:open="deleteOpen" :title="t('finance.cat.delete_title')" :initial-focus="() => keep">
        <p class="cat-panel__body">{{ t('finance.cat.delete_body', { name: shownName }) }}</p>
        <UiField :label="t('finance.cat.move_to')" :error="moveError" required>
            <UiSelect v-model="moveTo">
                <option value="" disabled>{{ t('finance.cat.move_to') }}</option>
                <option v-for="target in targets" :key="target.value" :value="target.value">{{ target.label }}</option>
            </UiSelect>
        </UiField>
        <template #footer>
            <UiButton ref="keep" variant="secondary" @click="deleteOpen = false">{{ t('finance.cat.keep') }}</UiButton>
            <UiButton variant="danger" :disabled="moveTo === '' || deleting" :loading="deleting" @click="confirmDelete">{{ t('finance.cat.delete') }}</UiButton>
        </template>
    </UiDialog>
</template>

<style scoped>
.cat-panel { display: flex; flex-direction: column; gap: var(--space-4); }
.cat-panel__type { margin: 0; padding: 0; border: 0; min-width: 0; display: flex; flex-direction: column; gap: var(--space-2); }
.cat-panel__legend { padding: 0; font-size: var(--text-base); font-weight: 600; }
.cat-panel__choices { display: flex; flex-wrap: wrap; gap: var(--space-4); }
.cat-panel__delete { margin-inline-end: auto; color: var(--color-danger); }
.cat-panel__body { margin: 0 0 var(--space-4); font-size: var(--text-base); }
</style>
