<script setup>
import { computed, nextTick, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiConfirmDialog from '../../Components/ui/UiConfirmDialog.vue';
import UiDialog from '../../Components/ui/UiDialog.vue';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiInput from '../../Components/ui/UiInput.vue';
import UiList from '../../Components/ui/UiList.vue';
import UiListRow from '../../Components/ui/UiListRow.vue';
import HelpTip from '../../Components/ui/HelpTip.vue';
import CatalogImage from '../CatalogImage.vue';
import ImageUploadField from '../ImageUploadField.vue';

// Settings > Shopping, tab "Stores": the shared list of shops, each with a logo.
const props = defineProps({
    // [{ id, name, logoVersion|null, usedOn }]
    items: { type: Array, default: () => [] },
    nameMax: { type: Number, default: 60 },
});
const { t } = useI18n();

const open = ref(false);
const editing = ref(null);
const savedId = ref(null);
const file = ref(null);
const removeImage = ref(false);
const imageError = ref('');
const uploading = ref(false);
const toDelete = ref(null);
const confirmOpen = ref(false);
const deleteFailed = ref(false);
const nameInput = ref(null);
const form = useForm({ name: '' });

const initialFocus = () => nameInput.value?.$el?.querySelector?.('input') ?? null;

const translate = (message) => (message === 'shopping.settings.err_duplicate' ? t('shopping.settings.err_duplicate_store') : String(message).startsWith('shopping.') ? t(message) : String(message));
const nameError = computed(() => (form.errors?.name ? translate(form.errors.name) : ''));
const failed = computed(() => Boolean(nameError.value));
const targetId = computed(() => editing.value?.id ?? savedId.value);
const shownVersion = computed(() => (editing.value ? editing.value.logoVersion : null));

function openDialog(item = null) {
    editing.value = item;
    savedId.value = null;
    file.value = null;
    removeImage.value = false;
    imageError.value = '';
    form.clearErrors();
    form.name = item?.name ?? '';
    open.value = true;
}
const close = () => { open.value = false; };

function imageRequest(id) {
    const base = { preserveScroll: true, onError: (errors) => { imageError.value = translate(errors?.image ?? 'shopping.image.err_write'); savedId.value = id; }, onFinish: () => { uploading.value = false; } };
    uploading.value = true;
    if (file.value) {
        router.post(`/shopping/stores/${id}/image`, { image: file.value }, { ...base, forceFormData: true, onSuccess: close });
    } else {
        router.delete(`/shopping/stores/${id}/image`, { ...base, onSuccess: close });
    }
}

async function afterSave() {
    await nextTick();
    const clean = form.name.trim().replace(/\s+/g, ' ');
    const id = targetId.value ?? props.items.find((i) => i.name === clean)?.id ?? null;
    if (id !== null && (file.value || (removeImage.value && editing.value))) imageRequest(id);
    else close();
}

function submit() {
    if (form.name.trim() === '' || form.processing || uploading.value) return;
    imageError.value = '';
    // A retry after the item was saved but its picture failed only repeats the picture.
    if (savedId.value !== null) {
        imageRequest(savedId.value);
        return;
    }
    const opts = {
        preserveScroll: true,
        onSuccess: afterSave,
        onError: () => nextTick(() => initialFocus()?.focus()),
    };
    if (editing.value) form.put(`/settings/shopping/stores/${editing.value.id}`, opts);
    else form.post('/settings/shopping/stores', opts);
}

function askDelete(item) {
    toDelete.value = item;
    deleteFailed.value = false;
    confirmOpen.value = true;
}
function doDelete() {
    const item = toDelete.value;
    if (!item) return;
    router.delete(`/settings/shopping/stores/${item.id}`, {
        preserveScroll: true,
        onError: () => { deleteFailed.value = true; },
    });
}
</script>

<template>
    <div class="shop-tab">
        <div class="shop-tab__head">
            <h2 class="shop-tab__title">{{ t('shopping.settings.tab_stores') }}</h2>
            <HelpTip topic="shopping.store" :label="t('shopping.settings.tab_stores')" />
        </div>
        <UiAlert v-if="deleteFailed" variant="danger" live class="shop-tab__alert">{{ t('shopping.settings.save_failed') }}</UiAlert>
        <template v-if="items.length === 0">
            <UiEmptyState :title="t('shopping.settings.stores.empty_title')" :body="t('shopping.settings.stores.empty_body')">
                <template #action>
                    <UiButton variant="primary" :icon="Plus" @click="openDialog()">{{ t('shopping.settings.add_store') }}</UiButton>
                </template>
            </UiEmptyState>
        </template>
        <template v-else>
            <div class="shop-tab__toolbar">
                <UiButton variant="primary" :icon="Plus" class="shop-tab__add" @click="openDialog()">{{ t('shopping.settings.add_store') }}</UiButton>
            </div>
            <UiList>
                <UiListRow v-for="item in items" :key="item.id" class="shop-row" data-testid="store-row">
                    <template #leading><CatalogImage kind="store" :id="item.id" :version="item.logoVersion" :size="48" /></template>
                    {{ item.name }}
                    <template #meta>{{ t('shopping.settings.used_on', { n: item.usedOn }, item.usedOn) }}</template>
                    <template #trailing>
                        <UiButton variant="ghost" icon-only :icon="Pencil" :label="t('shopping.settings.edit', { name: item.name })" @click="openDialog(item)" />
                        <UiButton variant="ghost" icon-only :icon="Trash2" :label="t('shopping.settings.delete', { name: item.name })" @click="askDelete(item)" />
                    </template>
                </UiListRow>
            </UiList>
        </template>

        <UiDialog :open="open" :title="editing ? t('shopping.settings.stores.edit_title') : t('shopping.settings.stores.add_title')" :initial-focus="initialFocus" @update:open="open = $event">
            <form id="store-form" class="shop-form" novalidate @submit.prevent="submit">
                <div aria-live="polite">
                    <UiAlert v-if="failed" variant="danger" data-testid="save-failed">{{ t('shopping.settings.save_failed') }}</UiAlert>
                </div>
                <ImageUploadField
                    v-model:file="file"
                    v-model:remove="removeImage"
                    kind="store"
                    :id="targetId"
                    :version="shownVersion"
                    :label="t('shopping.settings.stores.logo')"
                    :hint="t('shopping.image.hint_logo')"
                    :max-bytes="2 * 1024 * 1024"
                    :error="imageError"
                    :busy="uploading"
                />
                <UiField :label="t('shopping.settings.stores.name')" :error="nameError" required>
                    <UiInput ref="nameInput" v-model="form.name" autocomplete="off" :maxlength="nameMax" :disabled="form.processing" />
                </UiField>
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="close">{{ t('shopping.settings.cancel') }}</UiButton>
                <UiButton type="submit" form="store-form" variant="primary" :disabled="form.name.trim() === '' || form.processing || uploading" :loading="form.processing || uploading">{{ t('shopping.settings.stores.save') }}</UiButton>
            </template>
        </UiDialog>

        <UiConfirmDialog
            v-model:open="confirmOpen"
            :title="t('shopping.settings.stores.delete_title', { name: toDelete?.name ?? '' })"
            :body="toDelete && toDelete.usedOn > 0 ? t('shopping.settings.stores.delete_body_used', { n: toDelete.usedOn }, toDelete.usedOn) : t('shopping.settings.stores.delete_body_free')"
            :confirm-label="t('shopping.settings.stores.delete_confirm')"
            :cancel-label="t('shopping.settings.stores.delete_cancel')"
            @confirm="doDelete"
        />
    </div>
</template>

<style scoped>
.shop-tab__head { display: flex; align-items: center; gap: var(--space-2); margin-block-end: var(--space-3); }
.shop-tab__title { margin: 0; font-size: var(--text-lg); font-weight: 600; }
.shop-tab__alert { margin-block-end: var(--space-3); }
.shop-tab__toolbar { display: flex; flex-wrap: wrap; align-items: flex-start; gap: var(--space-3); margin-block-end: var(--space-3); }
.shop-tab__add { flex: none; }
.shop-row { min-height: 64px; }
.shop-row :deep(.ui-row__title) { overflow-wrap: anywhere; }
.shop-form { display: flex; flex-direction: column; }
</style>
