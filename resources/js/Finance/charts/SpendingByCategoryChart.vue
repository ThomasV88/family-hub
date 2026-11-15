<script setup>
import { computed, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import UiCard from '../../Components/ui/UiCard.vue';
import UiTable from '../../Components/ui/UiTable.vue';
import { INTL_LOCALES } from '../../i18n.js';
import { categoryLabel, formatDay } from '../labels.js';
import { formatMinor } from '../money.js';
import ChartTableToggle from './ChartTableToggle.vue';

// Spending by category for one currency. Everything (amounts, percents, the Other bucket, the totals) comes from the
// server as integer strings; the only arithmetic here is the bar length, which is pixel geometry.
const props = defineProps({
    // { totalMinor, top: [{categoryId, amountMinor, percent}], other: {amountMinor, percent}|null, all: [...] }
    data: { type: Object, required: true },
    currency: { type: String, required: true },
    month: { type: String, required: true },
    categories: { type: Array, default: () => [] },
    headingLevel: { type: Number, default: 2 },
});
const { t, locale } = useI18n();
const asTable = ref(false);
const headingId = `cat-chart-${useId()}`;

const money = (minor) => formatMinor(minor, props.currency, locale.value);
const nameOf = (categoryId) => categoryLabel(props.categories.find((c) => c.id === categoryId) ?? null, t);
const monthLabel = computed(() => formatDay(`${props.month}-01`, INTL_LOCALES[locale.value] ?? locale.value, { month: 'long', year: 'numeric' }));

const rows = computed(() => {
    const list = (props.data.top ?? []).map((item) => ({ key: String(item.categoryId ?? 'none'), name: nameOf(item.categoryId), minor: item.amountMinor, percent: item.percent, other: false }));
    if (props.data.other) list.push({ key: 'other', name: t('finance.chart.other'), minor: props.data.other.amountMinor, percent: props.data.other.percent, other: true });
    return list;
});
const empty = computed(() => rows.value.length === 0 || !/[1-9]/.test(props.data.totalMinor ?? '0'));

// Bar width as a percentage of the longest row (exact BigInt ratio, three decimals).
const widest = computed(() => rows.value.reduce((max, r) => (BigInt(r.minor) > max ? BigInt(r.minor) : max), 0n));
function share(minor) {
    const value = BigInt(minor);
    if (value <= 0n || widest.value <= 0n) return 0;
    return Number((value * 10000000n) / widest.value) / 100000;
}

const ariaLabel = computed(() => t('finance.chart.aria_category', {
    month: monthLabel.value,
    category: rows.value[0]?.name ?? '',
    amount: rows.value[0] ? money(rows.value[0].minor) : '',
}));

const tableColumns = computed(() => [
    { key: 'name', label: t('finance.chart.col_category') },
    { key: 'spent', label: t('finance.chart.col_spent'), numeric: true },
    { key: 'share', label: t('finance.chart.col_share'), numeric: true },
]);
const tableRows = computed(() => [
    ...(props.data.all ?? []).map((item) => ({ id: String(item.categoryId ?? 'none'), name: nameOf(item.categoryId), spent: money(item.amountMinor), share: `${item.percent}%` })),
    { id: 'total', name: t('finance.chart.col_total'), spent: money(props.data.totalMinor), share: '100%' },
]);
</script>

<template>
    <UiCard :title="t('finance.chart.category')" :heading-level="headingLevel" :title-id="headingId" data-testid="category-chart">
        <template v-if="!empty" #action>
            <ChartTableToggle v-model="asTable" />
        </template>
        <p v-if="empty" class="cat-chart__empty">{{ t('finance.chart.empty_category') }}</p>
        <UiTable v-else-if="asTable" :caption="t('finance.chart.category')" caption-hidden :columns="tableColumns" :rows="tableRows" />
        <div v-else role="img" :aria-label="ariaLabel"><ul class="cat-chart">
            <li v-for="row in rows" :key="row.key" class="cat-chart__row">
                <div class="cat-chart__line">
                    <span class="cat-chart__name">{{ row.name }}</span>
                    <span class="cat-chart__value">{{ money(row.minor) }} <span class="cat-chart__percent">{{ row.percent }}%</span></span>
                </div>
                <svg class="cat-chart__bar" role="presentation" aria-hidden="true" width="100%" height="12" focusable="false">
                    <rect class="cat-chart__track" x="0" y="0" width="100%" height="12" rx="6" />
                    <rect :class="['cat-chart__fill', { 'is-other': row.other }]" x="0" y="0" height="12" rx="6" :width="`${share(row.minor)}%`" :style="share(row.minor) > 0 ? { width: `max(2px, ${share(row.minor)}%)` } : null" />
                </svg>
            </li>
        </ul></div>
    </UiCard>
</template>

<style scoped>
.cat-chart { display: flex; flex-direction: column; gap: var(--space-3); margin: 0; padding: 0; list-style: none; }
.cat-chart__line { display: flex; flex-wrap: wrap; justify-content: space-between; gap: var(--space-1) var(--space-3); font-size: var(--text-sm); }
.cat-chart__name { font-weight: 600; min-width: 0; overflow-wrap: anywhere; }
.cat-chart__value { font-variant-numeric: tabular-nums; color: var(--color-text); }
.cat-chart__percent { color: var(--color-text-muted); }
.cat-chart__bar { display: block; margin-top: var(--space-1); }
.cat-chart__track { fill: var(--color-surface-2); }
.cat-chart__fill { fill: var(--color-primary); }
.cat-chart__fill.is-other { fill: var(--color-border-strong); }
.cat-chart__empty { margin: 0; color: var(--color-text-muted); font-size: var(--text-sm); }
@media (prefers-reduced-motion: no-preference) {
    .cat-chart__fill { animation: cat-fade 150ms ease-out; }
}
@keyframes cat-fade { from { opacity: 0; } to { opacity: 1; } }
@media (forced-colors: active) {
    .cat-chart__fill { fill: currentColor; }
    .cat-chart__track { fill: none; stroke: currentColor; }
}
</style>
