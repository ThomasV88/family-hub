<script setup>
import { computed, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiDialog from '../Components/ui/UiDialog.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import UiSelect from '../Components/ui/UiSelect.vue';
import CatalogImage from './CatalogImage.vue';
import { httpHandlers } from './failure.js';
import { NAME_MAX } from './limits.js';

// New shopping list: name, store, responsible person. Posts to /shopping/lists; the server opens the new (empty) list.
const props = defineProps({
    open: { type: Boolean, default: false },
    // [{ id, name, logoVersion|null }]
    stores: { type: Array, default: () => [] },
    // [{ id, name, isChild }]
    candidates: { type: Array, default: () => [] },
    nameMax: { type: Number, default: NAME_MAX },
});
const emit = defineEmits(['update:open']);
const { t } = useI18n();
const page = usePage();

const form = useForm({ name: '', store_id: '', responsible_id: '' });
const nameInput = ref(null);
const httpError = ref('');
const initialFocus = () => nameInput.value?.$el?.querySelector?.('input') ?? null;

const me = computed(() => page.props.auth?.user?.id ?? null);
const defaultResponsible = () => String(props.candidates.find((c) => c.id === me.value)?.id ?? props.candidates[0]?.id ?? '');
const selectedStore = computed(() => props.stores.find((s) => String(s.id) === String(form.store_id)) ?? null);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.clearErrors();
        httpError.value = '';
        form.name = '';
        form.store_id = '';
        form.responsible_id = defaultResponsible();
    },
    { immediate: true },
);

const translate = (message) => (String(message).startsWith('shopping.') ? t(message) : String(message));
const errorOf = (key) => (form.errors?.[key] ? translate(form.errors[key]) : '');
const failed = computed(() => Boolean(errorOf('name') || errorOf('store_id') || errorOf('responsible_id') || httpError.value));
const close = () => emit('update:open', false);

function submit() {
    if (form.name.trim() === '' || form.processing) return;
    httpError.value = '';
    form
        .transform((data) => ({
            name: data.name.trim(),
            store_id: data.store_id === '' ? null : Number(data.store_id),
            responsible_id: data.responsible_id === '' ? null : Number(data.responsible_id),
        }))
        .post('/shopping/lists', {
            onSuccess: close,
            onError: () => initialFocus()?.focus(),
            ...httpHandlers((key) => { httpError.value = t(key); }),
        });
}
</script>

<template>
    <UiDialog :open="open" :title="t('shopping.create.title')" :initial-focus="initialFocus" @update:open="emit('update:open', $event)">
        <form id="create-list-form" class="shop-create" novalidate @submit.prevent="submit">
            <div aria-live="polite">
                <UiAlert v-if="failed" variant="danger" data-testid="create-failed">{{ errorOf('name') || errorOf('store_id') || errorOf('responsible_id') || httpError }}</UiAlert>
            </div>
            <UiField :label="t('shopping.create.name')" :error="errorOf('name')" required>
                <UiInput ref="nameInput" v-model="form.name" autocomplete="off" :maxlength="nameMax" :placeholder="t('shopping.create.name_placeholder')" :disabled="form.processing" />
            </UiField>
            <div class="shop-create__store">
                <UiField :label="t('shopping.create.store')" :error="errorOf('store_id')" class="shop-create__store-field">
                    <UiSelect v-model="form.store_id" :disabled="form.processing">
                        <option value="">{{ t('shopping.create.store_none') }}</option>
                        <option v-for="s in stores" :key="s.id" :value="String(s.id)">{{ s.name }}</option>
                    </UiSelect>
                </UiField>
                <CatalogImage v-if="selectedStore" kind="store" :id="selectedStore.id" :version="selectedStore.logoVersion" :size="40" />
            </div>
            <UiField :label="t('shopping.create.responsible')" :error="errorOf('responsible_id')">
                <UiSelect v-model="form.responsible_id" :disabled="form.processing">
                    <option v-for="c in candidates" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                </UiSelect>
            </UiField>
        </form>
        <template #footer>
            <UiButton variant="ghost" @click="close">{{ t('shopping.create.cancel') }}</UiButton>
            <UiButton type="submit" form="create-list-form" variant="primary" :disabled="form.name.trim() === '' || form.processing" :loading="form.processing">{{ t('shopping.create.submit') }}</UiButton>
        </template>
    </UiDialog>
</template>

<style scoped>
.shop-create { display: flex; flex-direction: column; gap: var(--space-4); }
.shop-create__store { display: flex; align-items: flex-end; gap: var(--space-3); }
.shop-create__store-field { flex: 1; min-width: 0; }
</style>
