<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Copy, Ellipsis, ListPlus, Trash2 } from '@lucide/vue';
import AppShell from '../../Layouts/AppShell.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiConfirmDialog from '../../Components/ui/UiConfirmDialog.vue';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import UiList from '../../Components/ui/UiList.vue';
import UiMenu from '../../Components/ui/UiMenu.vue';
import CopyListDialog from '../../Shopping/CopyListDialog.vue';
import ItemEditDialog from '../../Shopping/ItemEditDialog.vue';
import ListHeader from '../../Shopping/ListHeader.vue';
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
});
const { t } = useI18n();

const unitById = computed(() => Object.fromEntries(props.units.map((u) => [u.id, u])));
const canEdit = computed(() => props.abilities.editItems === true && props.list.status === 'open');
const archived = computed(() => props.list.status === 'done');
const locked = computed(() => props.list.status === 'locked');

const editOpen = ref(false);
const editTarget = ref(null);
const copyOpen = ref(false);
const deleteOpen = ref(false);

function editItem(item) {
    if (!canEdit.value) return;
    editTarget.value = item;
    editOpen.value = true;
}

const menuItems = computed(() => {
    const entries = [];
    if (props.abilities.copy) entries.push({ type: 'item', key: 'copy', label: t('shopping.overview.copy'), icon: Copy });
    if (props.abilities.delete) entries.push({ type: 'item', key: 'delete', label: t('shopping.detail.delete_list'), icon: Trash2 });

    return entries;
});

function onMenu({ key }) {
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
                <UiMenu v-if="menuItems.length > 0" :label="t('shopping.detail.more')" :icon="Ellipsis" :items="menuItems" @select="onMenu" />
            </ListHeader>

            <UiAlert v-if="archived" variant="info" data-testid="archived-banner">{{ t('shopping.detail.archived') }}</UiAlert>
            <UiAlert v-else-if="locked" variant="info" data-testid="locked-banner">{{ t('shopping.detail.locked_note') }}</UiAlert>

            <UiEmptyState v-if="items.length === 0" :heading-level="2" :icon="ListPlus" :title="t('shopping.detail.empty_title')" :body="canEdit ? t('shopping.detail.empty_body') : ''" />
            <UiList v-else :aria-label="t('shopping.detail.items_label')">
                <ShoppingItemRow v-for="item in items" :key="item.id" :item="item" :unit="unitById[item.unitId] ?? null" :interactive="canEdit" @edit="editItem" />
            </UiList>
        </div>

        <ItemEditDialog v-if="canEdit" v-model:open="editOpen" :list-id="list.id" :item="editTarget" :units="units" />
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
