<script setup>
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { ChevronDown, Search, X } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../Components/ui/UiButton.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import UiSelect from '../Components/ui/UiSelect.vue';
import { useFrameWidth } from '../Shell/shellState.js';
import { categoryLabel } from './labels.js';
import { visitFinance } from './financeQuery.js';

// Search (debounced 300 ms) and Type/Category/Source filters. Every change is one Inertia visit that resets the
// merged list; the account filter is the shared chip row and the date range is the month switcher.
const props = defineProps({
    filters: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
});
const { t } = useI18n();

const ONLY = ['transactionItems', 'transactions', 'filters'];
const root = ref(null);
const width = useFrameWidth(root);
const inline = computed(() => width.value >= 640);
const disclosure = ref(false);
const panelId = `fin-filters-${useId()}`;

const q = ref(props.filters.q ?? '');
watch(() => props.filters.q, (value) => { if (value !== q.value) q.value = value ?? ''; });
let timer = null;
function onSearch(value) {
    q.value = value;
    clearTimeout(timer);
    timer = setTimeout(() => apply({ q: value.trim() }), 300);
}
onBeforeUnmount(() => clearTimeout(timer));

function apply(params) {
    visitFinance(params, { only: ONLY, reset: ['transactionItems'] });
}

const typeOptions = computed(() => [
    { value: 'all', label: t('finance.tx.source_all') },
    { value: 'income', label: t('finance.kind.income') },
    { value: 'expense', label: t('finance.kind.expense') },
    { value: 'transfer', label: t('finance.kind.transfer') },
]);
const categoryOptions = computed(() => [
    { value: 'all', label: t('finance.tx.filter_all_categories') },
    ...props.categories.map((c) => ({ value: c.id, label: categoryLabel(c, t) })),
    { value: 'none', label: t('finance.tx.uncategorized') },
]);
const sourceOptions = computed(() => [
    { value: 'all', label: t('finance.tx.source_all') },
    { value: 'manual', label: t('finance.tx.source_manual') },
    { value: 'import', label: t('finance.tx.source_import') },
]);

const active = computed(() => {
    const list = [];
    if (props.filters.type !== 'all') list.push({ key: 'type', name: t(`finance.kind.${props.filters.type}`) });
    if (props.filters.category !== 'all') {
        const c = props.categories.find((x) => x.id === props.filters.category);
        list.push({ key: 'category', name: props.filters.category === 'none' ? t('finance.tx.uncategorized') : categoryLabel(c, t) });
    }
    if (props.filters.source !== 'all') list.push({ key: 'source', name: t(`finance.tx.source_${props.filters.source}`) });
    return list;
});
const anyActive = computed(() => active.value.length > 0 || (props.filters.q ?? '') !== '');
const remove = (key) => apply({ [key]: 'all' });
function clearAll() {
    clearTimeout(timer);
    q.value = '';
    apply({ q: '', type: 'all', category: 'all', source: 'all' });
}
defineExpose({ clearAll });
</script>

<template>
    <div ref="root" class="tx-filters" data-testid="transaction-filters">
        <div class="tx-filters__top">
            <UiField :label="t('finance.tx.search')" label-visually-hidden class="tx-filters__search">
                <UiInput :model-value="q" :icon="Search" type="search" autocomplete="off" :placeholder="t('finance.tx.search')" @update:model-value="onSearch" />
            </UiField>
            <button
                v-if="!inline"
                type="button"
                class="tx-filters__toggle"
                :aria-expanded="disclosure ? 'true' : 'false'"
                :aria-controls="panelId"
                @click="disclosure = !disclosure"
            >
                {{ active.length ? t('finance.tx.filters_active', { n: active.length }) : t('finance.tx.filters') }}
                <ChevronDown :size="20" :stroke-width="1.75" aria-hidden="true" :class="{ 'is-open': disclosure }" />
            </button>
        </div>

        <div v-show="inline || disclosure" :id="panelId" class="tx-filters__selects">
            <UiField :label="t('finance.tx.filter_type')">
                <UiSelect :model-value="filters.type" :options="typeOptions" @update:model-value="(v) => apply({ type: v })" />
            </UiField>
            <UiField :label="t('finance.tx.filter_category')">
                <UiSelect :model-value="filters.category" :options="categoryOptions" @update:model-value="(v) => apply({ category: v })" />
            </UiField>
            <UiField :label="t('finance.tx.filter_source')">
                <UiSelect :model-value="filters.source" :options="sourceOptions" @update:model-value="(v) => apply({ source: v })" />
            </UiField>
        </div>

        <div v-if="anyActive" class="tx-filters__active">
            <button v-for="chip in active" :key="chip.key" type="button" class="tx-filters__chip" :aria-label="t('finance.tx.filter_remove', { name: chip.name })" @click="remove(chip.key)">
                <span>{{ chip.name }}</span>
                <X :size="16" :stroke-width="1.75" aria-hidden="true" />
            </button>
            <UiButton variant="ghost" size="sm" @click="clearAll">{{ t('finance.tx.clear_filters') }}</UiButton>
        </div>
    </div>
</template>

<style scoped>
.tx-filters { display: flex; flex-direction: column; gap: var(--space-3); min-width: 0; }
.tx-filters__top { display: flex; flex-wrap: wrap; align-items: flex-start; gap: var(--space-2); }
.tx-filters__search { flex: 1 1 220px; min-width: 0; margin-bottom: 0; }
.tx-filters__toggle {
    display: inline-flex; align-items: center; gap: var(--space-1); min-height: 44px; padding: 0 var(--space-3);
    background: var(--color-surface); border: 1px solid var(--color-border-strong); border-radius: var(--radius-md);
    color: var(--color-text); font: inherit; font-size: var(--text-sm); font-weight: 600; cursor: pointer;
}
.tx-filters__toggle:focus-visible, .tx-filters__chip:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.tx-filters__toggle .is-open { transform: rotate(180deg); }
.tx-filters__selects { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: var(--space-3); }
.tx-filters__selects :deep(.ui-field) { margin-bottom: 0; }
.tx-filters__active { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }
.tx-filters__chip {
    display: inline-flex; align-items: center; gap: var(--space-1); min-height: 44px; padding: 0 var(--space-3);
    background: var(--color-surface); border: 1px solid var(--color-border-strong); border-radius: var(--radius-full);
    color: var(--color-text); font: inherit; font-size: var(--text-sm); font-weight: 600; cursor: pointer;
}
</style>
