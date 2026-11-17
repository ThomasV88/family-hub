<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Copy, Ellipsis, ListPlus, Mail, Plus, Settings, Trash2 } from '@lucide/vue';
import AppShell from '../../Layouts/AppShell.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiConfirmDialog from '../../Components/ui/UiConfirmDialog.vue';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import UiList from '../../Components/ui/UiList.vue';
import UiMenu from '../../Components/ui/UiMenu.vue';
import CopyListDialog from '../../Shopping/CopyListDialog.vue';
import EmailListDialog from '../../Shopping/EmailListDialog.vue';
import ItemEditDialog from '../../Shopping/ItemEditDialog.vue';
import ItemPickerDialog from '../../Shopping/ItemPickerDialog.vue';
import ListHeader from '../../Shopping/ListHeader.vue';
import ListSettingsPanel from '../../Shopping/ListSettingsPanel.vue';
import ShoppingItemRow from '../../Shopping/ShoppingItemRow.vue';

// List detail. The server decides what a person may do (`abilities`); the page only renders controls that are allowed.
// Plan mode (open list): items can be added, edited and removed. Locked and finished lists render read-only.
const props = defineProps({
    list: { type: Object, required: true },
    items: { type: Array, default: () => [] },
    abilities: { type: Object, default: () => ({}) },
    members: { type: Object, default: () => ({ contributors: [], viewers: [] }) },
    units: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    catalog: { type: Array, default: null },
    candidates: { type: Object, default: null },
    responsiblePushEnabled: { type: Boolean, default: null },
});
const { t } = useI18n();
const page = usePage();

const unitById = computed(() => Object.fromEntries(props.units.map((u) => [u.id, u])));
const canEdit = computed(() => props.abilities.editItems === true && props.list.status === 'open');
const archived = computed(() => props.list.status === 'done');
const locked = computed(() => props.list.status === 'locked');

const pickerOpen = ref(false);
const editOpen = ref(false);
const editTarget = ref(null);
const copyOpen = ref(false);
const deleteOpen = ref(false);
const settingsOpen = ref(false);
const mailOpen = ref(false);

// Catalog items already pending on the list (the server raises their quantity instead of adding a second row).
const onListIds = computed(() => props.items.filter((i) => i.catalogItemId != null && i.state === 'pending').map((i) => i.catalogItemId));

// Creating a list flashes open_picker once: an empty list opens the picker straight away for people who may add items.
onMounted(() => {
    if (page.props.flash?.data?.open_picker && canEdit.value && props.items.length === 0) pickerOpen.value = true;
});

function editItem(item) {
    if (!canEdit.value) return;
    editTarget.value = item;
    editOpen.value = true;
}

const takeover = computed(() => (props.list.responsibleFallback?.previousName ? t('shopping.panel.takeover', { name: props.list.responsibleFallback?.previousName }) : t('shopping.panel.takeover_unknown')));
const menuItems = computed(() => {
    const entries = [{ type: 'item', key: 'settings', label: t('shopping.panel.title'), icon: Settings }];
    if (props.abilities.mail) entries.push({ type: 'item', key: 'mail', label: t('shopping.mail.title'), icon: Mail });
    if (props.abilities.copy) entries.push({ type: 'item', key: 'copy', label: t('shopping.overview.copy'), icon: Copy });
    if (props.abilities.delete) entries.push({ type: 'item', key: 'delete', label: t('shopping.detail.delete_list'), icon: Trash2 });

    return entries;
});

function onMenu({ key }) {
    if (key === 'settings') settingsOpen.value = true;
    if (key === 'mail') mailOpen.value = true;
    if (key === 'copy') copyOpen.value = true;
    if (key === 'delete') deleteOpen.value = true;
}

function deleteList() {
    router.delete(`/shopping/lists/${props.list.id}`);
}
</script>

<template>
    <Head :title="list.name" />
    <AppShell current="shopping">
        <div class="shop-show" data-testid="shopping-list-page">
            <ListHeader :list="list">
                <UiButton v-if="canEdit" variant="primary" :icon="Plus" data-testid="add-items" @click="pickerOpen = true">{{ t('shopping.detail.add_items') }}</UiButton>
                <UiMenu v-if="menuItems.length > 0" :label="t('shopping.detail.more')" :icon="Ellipsis" :items="menuItems" @select="onMenu" />
            </ListHeader>

            <UiAlert v-if="list.responsibleFallback" variant="info" data-testid="takeover-alert">{{ takeover }}</UiAlert>
            <UiAlert v-if="archived" variant="info" data-testid="archived-banner">{{ t('shopping.detail.archived') }}</UiAlert>
            <UiAlert v-else-if="locked" variant="info" data-testid="locked-banner">{{ t('shopping.detail.locked_note') }}</UiAlert>

            <UiEmptyState v-if="items.length === 0" :heading-level="2" :icon="ListPlus" :title="t('shopping.detail.empty_title')" :body="canEdit ? t('shopping.detail.empty_body') : ''">
                <template v-if="canEdit" #action>
                    <UiButton variant="primary" :icon="Plus" @click="pickerOpen = true">{{ t('shopping.detail.add_items') }}</UiButton>
                </template>
            </UiEmptyState>
            <UiList v-else :aria-label="t('shopping.detail.items_label')">
                <ShoppingItemRow v-for="item in items" :key="item.id" :item="item" :unit="unitById[item.unitId] ?? null" :interactive="canEdit" @edit="editItem" />
            </UiList>
        </div>

        <ItemPickerDialog v-if="canEdit" v-model:open="pickerOpen" :list-id="list.id" :catalog="catalog" :on-list-ids="onListIds" :units="units" :can-manage-catalog="abilities.manageCatalog === true" />
        <ItemEditDialog v-if="canEdit" v-model:open="editOpen" :list-id="list.id" :item="editTarget" :units="units" />
        <ListSettingsPanel v-model:open="settingsOpen" :list="list" :abilities="abilities" :members="members" :stores="stores" :candidates="candidates" :responsible-push-enabled="responsiblePushEnabled" />
        <EmailListDialog v-if="abilities.mail" v-model:open="mailOpen" :list="{ id: list.id, name: list.name }" />
        <CopyListDialog v-if="abilities.copy" v-model:open="copyOpen" :list="{ id: list.id, name: list.name }" />
        <UiConfirmDialog
            v-if="abilities.delete"
            v-model:open="deleteOpen"
            :title="t('shopping.delete.title', { name: list.name })"
            :body="t('shopping.delete.body')"
            :confirm-label="t('shopping.delete.confirm')"
            :cancel-label="t('shopping.delete.cancel')"
            @confirm="deleteList"
        />
    </AppShell>
</template>

<style scoped>
.shop-show { display: flex; flex-direction: column; gap: var(--space-4); max-width: 840px; margin-inline: auto; width: 100%; }
</style>
