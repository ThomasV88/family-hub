<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Trash2 } from '@lucide/vue';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiConfirmDialog from '../Components/ui/UiConfirmDialog.vue';
import UiDialog from '../Components/ui/UiDialog.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import UiSelect from '../Components/ui/UiSelect.vue';
import UiTag from '../Components/ui/UiTag.vue';
import { localeCode, unitLabel } from './units.js';

// Edit or remove one item of an open list. item: { id, name, quantity, unitId, note, catalogItemId }; units: [{ id, key, names }].
// The name is editable only for free-text items (no catalog item). Quantity is sent as typed (decimal comma allowed),
// the server normalises and validates it.
const props = defineProps({
    open: { type: Boolean, default: false },
    listId: { type: Number, required: true },
    item: { type: Object, default: null },
    units: { type: Array, default: () => [] },
    nameMax: { type: Number, default: 80 },
    noteMax: { type: Number, default: 200 },
});
const emit = defineEmits(['update:open']);
const { t, locale } = useI18n();

const form = useForm({ name: '', quantity: '', unit_id: '', note: '' });
const confirmOpen = ref(false);
const removing = ref(false);
const freeText = computed(() => props.item?.catalogItemId == null);

// "0.50" -> "0,5" (comma in Slovak and Polish), "2.00" -> "2".
function shown(quantity) {
    const n = Number(quantity);
    if (!Number.isFinite(n)) return String(quantity ?? '');
    const text = String(n);

    return localeCode(locale.value) === 'en' ? text : text.replace('.', ',');
}

watch(
    () => [props.open, props.item?.id],
    ([open]) => {
        if (!open || !props.item) return;
        form.clearErrors();
        form.name = props.item.name;
        form.quantity = shown(props.item.quantity);
        form.unit_id = props.item.unitId == null ? '' : String(props.item.unitId);
        form.note = props.item.note ?? '';
        confirmOpen.value = false;
        removing.value = false;
    },
    { immediate: true },
);

const unitOptions = computed(() => props.units.map((u) => ({ value: String(u.id), label: unitLabel(u, 1, t, locale.value) })));
const translate = (message) => (String(message).startsWith('shopping.') ? t(message) : String(message));
const errorOf = (key) => (form.errors?.[key] ? translate(form.errors[key]) : '');
const failed = computed(() => Object.keys(form.errors ?? {}).length > 0);
const close = () => emit('update:open', false);
const url = computed(() => `/shopping/lists/${props.listId}/items/${props.item?.id}`);
const quantityInput = ref(null);
const initialFocus = () => quantityInput.value?.$el?.querySelector?.('input') ?? null;

function submit() {
    if (!props.item || form.processing) return;
    form
        .transform((data) => {
            const body = {
                quantity: data.quantity,
                unit_id: data.unit_id === '' ? null : Number(data.unit_id),
                note: data.note.trim() === '' ? null : data.note.trim(),
            };
            if (freeText.value) body.name = data.name.trim();

            return body;
        })
        .put(url.value, { preserveScroll: true, onSuccess: close });
}

function remove() {
    removing.value = true;
    form.delete(url.value, {
        preserveScroll: true,
        onSuccess: () => { confirmOpen.value = false; close(); },
        onError: () => { confirmOpen.value = false; },
        onFinish: () => { removing.value = false; },
    });
}
</script>

<template>
    <UiDialog :open="open && !confirmOpen && !removing" :title="t('shopping.item.edit_title')" :initial-focus="initialFocus" @update:open="emit('update:open', $event)">
        <form v-if="item" id="item-edit-form" class="shop-edit" novalidate @submit.prevent="submit">
            <div aria-live="polite">
                <UiAlert v-if="failed" variant="danger" data-testid="item-edit-failed">{{ t('shopping.error.save_failed') }}</UiAlert>
            </div>
            <UiField v-if="freeText" :label="t('shopping.item.name')" :error="errorOf('name')" required>
                <UiInput v-model="form.name" autocomplete="off" :maxlength="nameMax" :disabled="form.processing" />
            </UiField>
            <p v-else class="shop-edit__name">{{ item.name }}</p>
            <UiTag v-if="freeText" variant="neutral" class="shop-edit__tag">{{ t('shopping.item.not_in_catalog') }}</UiTag>
            <div class="shop-edit__pair">
                <UiField :label="t('shopping.item.quantity')" :error="errorOf('quantity')" required class="shop-edit__quantity">
                    <UiInput ref="quantityInput" v-model="form.quantity" inputmode="decimal" autocomplete="off" :disabled="form.processing" />
                </UiField>
                <UiField :label="t('shopping.item.unit')" :error="errorOf('unit_id')" class="shop-edit__unit">
                    <UiSelect v-model="form.unit_id" :disabled="form.processing">
                        <option value="">{{ t('shopping.item.unit_none') }}</option>
                        <option v-for="o in unitOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </UiSelect>
                </UiField>
            </div>
            <UiField :label="t('shopping.item.note')" :error="errorOf('note')">
                <UiInput v-model="form.note" autocomplete="off" :maxlength="noteMax" :disabled="form.processing" />
            </UiField>
            <UiButton variant="ghost" :icon="Trash2" class="shop-edit__remove" :disabled="form.processing" @click="confirmOpen = true">{{ t('shopping.item.remove') }}</UiButton>
        </form>
        <template #footer>
            <UiButton variant="ghost" @click="close">{{ t('shopping.item.cancel') }}</UiButton>
            <UiButton type="submit" form="item-edit-form" variant="primary" :disabled="form.processing" :loading="form.processing && !removing">{{ t('shopping.item.save') }}</UiButton>
        </template>
    </UiDialog>
    <UiConfirmDialog
        :open="open && confirmOpen"
        :title="t('shopping.item.remove_title', { name: item?.name ?? '' })"
        :body="t('shopping.item.remove_body')"
        :confirm-label="t('shopping.item.remove_confirm')"
        :cancel-label="t('shopping.item.remove_cancel')"
        @update:open="(v) => { if (!v) confirmOpen = false; }"
        @confirm="remove"
    />
</template>

<style scoped>
.shop-edit { display: flex; flex-direction: column; gap: var(--space-4); }
.shop-edit__name { margin: 0; font-size: var(--text-base); font-weight: 600; overflow-wrap: anywhere; }
.shop-edit__tag { align-self: flex-start; }
.shop-edit__pair { display: flex; gap: var(--space-3); }
.shop-edit__quantity { flex: 1 1 40%; min-width: 0; }
.shop-edit__unit { flex: 1 1 60%; min-width: 0; }
.shop-edit__remove { align-self: flex-start; color: var(--color-danger); }
</style>
