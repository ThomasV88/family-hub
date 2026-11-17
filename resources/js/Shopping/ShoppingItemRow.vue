<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Ban, Check } from '@lucide/vue';
import UiListRow from '../Components/ui/UiListRow.vue';
import UiTag from '../Components/ui/UiTag.vue';
import CatalogImage from './CatalogImage.vue';
import { formatQuantity } from './units.js';

// One item of a list. item: { id, name, quantity, unitId, note, state, catalogItemId, imageVersion }; unit: { key, names } or null.
// interactive: a tap opens the edit dialog (open list, allowed person). Otherwise the row is read-only and shows its state:
// Bought as an accent check, Unavailable as a warning tag with an icon (never colour alone, never struck through).
const props = defineProps({
    item: { type: Object, required: true },
    unit: { type: Object, default: null },
    interactive: { type: Boolean, default: false },
});
const emit = defineEmits(['edit']);
const { t, locale } = useI18n();

const quantity = computed(() => formatQuantity(props.item.quantity, props.unit, t, locale.value));
const bought = computed(() => props.item.state === 'bought');
const unavailable = computed(() => props.item.state === 'unavailable');
</script>

<template>
    <UiListRow
        :as="interactive ? 'button' : 'static'"
        :title="interactive ? t('shopping.detail.edit_item', { name: item.name }) : ''"
        :class="['shop-item', { 'shop-item--done': bought || unavailable }]"
        data-testid="item-row"
        @select="emit('edit', item)"
    >
        <template #leading><CatalogImage kind="catalog" :id="item.catalogItemId" :version="item.imageVersion" :size="48" /></template>
        <span class="shop-item__name">{{ item.name }}</span>
        <template v-if="item.note" #meta><span class="shop-item__note">{{ item.note }}</span></template>
        <template #trailing>
            <span v-if="quantity" class="shop-item__quantity" data-testid="item-quantity">{{ quantity }}</span>
            <span v-if="bought" class="shop-item__bought"><Check :size="18" :stroke-width="2" aria-hidden="true" />{{ t('shopping.detail.state_bought') }}</span>
            <UiTag v-else-if="unavailable" variant="warn" :icon="Ban">{{ t('shopping.detail.state_unavailable') }}</UiTag>
        </template>
    </UiListRow>
</template>

<style scoped>
.shop-item { min-height: 56px; }
.shop-item :deep(.ui-row__title) { overflow: visible; white-space: normal; overflow-wrap: anywhere; }
.shop-item :deep(.ui-row__meta) { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.shop-item :deep(.ui-row__trailing) { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: var(--space-2); }
.shop-item--done .shop-item__name { color: var(--color-text-muted); }
.shop-item__quantity { font-size: var(--text-sm); font-weight: 600; white-space: nowrap; }
.shop-item__bought { display: inline-flex; align-items: center; gap: var(--space-1); color: var(--color-primary); font-size: var(--text-sm); font-weight: 600; }
.shop-item__note { overflow-wrap: anywhere; }
</style>
