<script setup>
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiCard from '../../Components/ui/UiCard.vue';
import UiChip from '../../Components/ui/UiChip.vue';
import UiTable from '../../Components/ui/UiTable.vue';
import { INTL_LOCALES } from '../../i18n.js';
import { formatDay } from '../labels.js';
import { formatMinor } from '../money.js';
import ChartTableToggle from './ChartTableToggle.vue';
import { niceTicks } from './niceTicks.js';

// Month-end balance line for one currency. Labels always come from formatMinor on the server's integer strings;
// Number() is used only to place marks in pixels (values are bounded by 1e13, so it is exact).
const props = defineProps({
    // [{ month: 'YYYY-MM', balanceMinor }] oldest first, up to 12
    points: { type: Array, default: () => [] },
    currency: { type: String, required: true },
    headingLevel: { type: Number, default: 2 },
    height: { type: Number, default: 200 },
});
const { t, locale } = useI18n();
const intl = () => INTL_LOCALES[locale.value] ?? locale.value;
const money = (minor) => formatMinor(minor, props.currency, locale.value);
const headingId = `bal-chart-${useId()}`;

const range = ref(6);
const asTable = ref(false);
const shown = computed(() => props.points.slice(-range.value));
const enough = computed(() => props.points.length >= 2);

const wrap = ref(null);
const width = ref(0);
let observer = null;
// The chart element is replaced when switching to the table and back, so observe whichever element is current.
watch(wrap, (el) => {
    observer?.disconnect();
    observer = null;
    if (!el) return;
    width.value = el.clientWidth || 0;
    if (typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver((entries) => { width.value = Math.floor(entries[0]?.contentRect?.width || el.clientWidth || 0); });
        observer.observe(el);
    }
}, { flush: 'post' });
onBeforeUnmount(() => observer?.disconnect());

const W = computed(() => (width.value > 0 ? width.value : 600));
const H = computed(() => props.height);
const PAD = 16;
const CHAR = 8;

const values = computed(() => shown.value.map((p) => Number(p.balanceMinor)));
const ticks = computed(() => (values.value.length ? niceTicks(Math.min(...values.value), Math.max(...values.value), 3) : []));
const lowest = computed(() => ticks.value[0] ?? 0);
const highest = computed(() => ticks.value[ticks.value.length - 1] ?? 1);
const left = computed(() => PAD + Math.max(1, ...ticks.value.map((v) => money(String(v)).length)) * CHAR);
const plot = computed(() => ({ x0: left.value, x1: Math.max(left.value + 1, W.value - PAD), y0: PAD, y1: H.value - PAD - 20 }));
const xOf = (i) => {
    const n = shown.value.length;
    return n <= 1 ? plot.value.x0 : plot.value.x0 + (i * (plot.value.x1 - plot.value.x0)) / (n - 1);
};
const yOf = (v) => {
    const span = highest.value - lowest.value || 1;
    return plot.value.y1 - ((v - lowest.value) * (plot.value.y1 - plot.value.y0)) / span;
};

const crossesZero = computed(() => values.value.length > 0 && Math.min(...values.value) < 0 && Math.max(...values.value) > 0);
// Thin the grid labels rather than rounding them: with crowded ticks the middle label goes.
const crowded = computed(() => ticks.value.length > 2 && Math.abs(yOf(ticks.value[1]) - yOf(ticks.value[0])) < 18);
const gridLines = computed(() => ticks.value.map((v, i) => ({
    v,
    y: yOf(v),
    label: money(String(v)),
    showLabel: !(crowded.value && i > 0 && i < ticks.value.length - 1) && !(crossesZero.value && v === 0),
})));
const polyline = computed(() => values.value.map((v, i) => `${xOf(i)},${yOf(v)}`).join(' '));

const compact = computed(() => W.value < 400);
const xLabels = computed(() => {
    const n = shown.value.length;
    const every = compact.value ? (range.value === 6 ? 2 : 3) : 1;
    return shown.value.map((p, i) => ({ i, text: formatDay(`${p.month}-01`, intl(), { month: 'short' }), show: (n - 1 - i) % every === 0 }));
});

const marks = computed(() => {
    const v = values.value;
    if (!v.length) return [];
    const last = v.length - 1;
    const lo = v.indexOf(Math.min(...v));
    const hi = v.indexOf(Math.max(...v));
    const mid = (Math.min(...v) + Math.max(...v)) / 2;
    return [...new Set([last, lo, hi])].map((i) => {
        const text = money(shown.value[i].balanceMinor);
        const half = (text.length * CHAR) / 2;
        const x = Math.min(Math.max(xOf(i), PAD + half), W.value - PAD - half);
        const below = v[i] < mid;
        return { i, text, x, y: below ? yOf(v[i]) + 20 : yOf(v[i]) - 10 };
    });
});

const monthLong = (month) => formatDay(`${month}-01`, intl(), { month: 'long', year: 'numeric' });
const ariaLabel = computed(() => t('finance.chart.aria_balance', {
    n: shown.value.length,
    month: monthLong(shown.value[shown.value.length - 1]?.month ?? ''),
    amount: money(shown.value[shown.value.length - 1]?.balanceMinor ?? '0'),
}));

const tableColumns = computed(() => [
    { key: 'month', label: t('finance.chart.col_month') },
    { key: 'balance', label: t('finance.chart.col_balance'), numeric: true },
    { key: 'change', label: t('finance.chart.col_change'), numeric: true },
]);
const tableRows = computed(() => {
    const offset = props.points.length - shown.value.length;
    return shown.value.map((p, i) => {
        const prev = props.points[offset + i - 1];
        let change = null;
        if (prev) {
            const delta = BigInt(p.balanceMinor) - BigInt(prev.balanceMinor);
            change = { text: formatMinor(String(delta), props.currency, locale.value, { signed: true }), dir: delta > 0n ? 'up' : delta < 0n ? 'down' : null };
        }
        return { id: p.month, month: monthLong(p.month), balance: money(p.balanceMinor), change };
    });
});
</script>

<template>
    <UiCard :title="t('finance.chart.balance')" :heading-level="headingLevel" :title-id="headingId" data-testid="balance-chart">
        <template v-if="enough" #action>
            <ChartTableToggle v-model="asTable" />
        </template>
        <p v-if="!enough" class="bal-chart__empty">{{ t('finance.chart.empty_balance') }}</p>
        <template v-else>
            <div class="bal-chart__range" role="radiogroup" :aria-labelledby="headingId">
                <UiChip :model-value="range === 6" @update:model-value="range = 6">{{ t('finance.chart.range_6') }}</UiChip>
                <UiChip :model-value="range === 12" @update:model-value="range = 12">{{ t('finance.chart.range_12') }}</UiChip>
            </div>
            <UiTable v-if="asTable" :caption="t('finance.chart.balance')" caption-hidden :columns="tableColumns" :rows="tableRows">
                <template #cell-change="{ row }">
                    <template v-if="row.change">{{ row.change.text }}<span v-if="row.change.dir" class="visually-hidden"> {{ t(`finance.chart.${row.change.dir}`) }}</span></template>
                    <template v-else>&mdash;</template>
                </template>
            </UiTable>
            <div v-else ref="wrap" class="bal-chart__wrap">
                <svg class="bal-chart__svg" role="img" :aria-label="ariaLabel" :viewBox="`0 0 ${W} ${H}`" :width="W" :height="H" focusable="false">
                    <g class="bal-chart__grid">
                        <template v-for="line in gridLines" :key="line.v">
                            <line v-if="!(crossesZero && line.v === 0)" :x1="plot.x0" :x2="plot.x1" :y1="line.y" :y2="line.y" class="bal-chart__gridline" />
                            <text v-if="line.showLabel" :x="plot.x0 - 6" :y="line.y + 5" text-anchor="end" class="bal-chart__axis">{{ line.label }}</text>
                        </template>
                    </g>
                    <g v-if="crossesZero">
                        <line :x1="plot.x0" :x2="plot.x1" :y1="yOf(0)" :y2="yOf(0)" class="bal-chart__zero" stroke-dasharray="4 4" />
                        <text :x="plot.x0 - 6" :y="yOf(0) + 5" text-anchor="end" class="bal-chart__axis">0</text>
                    </g>
                    <polyline :points="polyline" class="bal-chart__line" fill="none" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                    <circle v-for="(v, i) in values" :key="`m${i}`" :cx="xOf(i)" :cy="yOf(v)" r="4" class="bal-chart__dot" stroke-width="2" />
                    <g class="bal-chart__xaxis">
                        <template v-for="label in xLabels" :key="label.i">
                            <text v-if="label.show" :x="xOf(label.i)" :y="H - PAD + 2" text-anchor="middle" class="bal-chart__axis">{{ label.text }}</text>
                        </template>
                    </g>
                    <text v-for="mark in marks" :key="`l${mark.i}`" :x="mark.x" :y="mark.y" text-anchor="middle" class="bal-chart__value">{{ mark.text }}</text>
                </svg>
            </div>
        </template>
    </UiCard>
</template>

<style scoped>
.bal-chart__empty { margin: 0; color: var(--color-text-muted); font-size: var(--text-sm); }
.bal-chart__range { display: flex; gap: var(--space-2); margin-bottom: var(--space-3); }
.bal-chart__wrap { width: 100%; min-width: 0; }
.bal-chart__svg { display: block; max-width: 100%; overflow: visible; }
.bal-chart__gridline { stroke: var(--color-border); stroke-width: 1; }
.bal-chart__zero { stroke: var(--color-border-strong); stroke-width: 1; }
.bal-chart__line { stroke: var(--color-primary); }
.bal-chart__dot { fill: var(--color-primary); stroke: var(--color-surface); }
.bal-chart__axis { fill: var(--color-text-muted); font-size: 14px; font-variant-numeric: tabular-nums; }
.bal-chart__value { fill: var(--color-text); font-size: 14px; font-weight: 600; font-variant-numeric: tabular-nums; paint-order: stroke; stroke: var(--color-surface); stroke-width: 3px; }
.visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
@media (prefers-reduced-motion: no-preference) {
    .bal-chart__svg { animation: bal-fade 150ms ease-out; }
}
@keyframes bal-fade { from { opacity: 0; } to { opacity: 1; } }
@media (forced-colors: active) {
    .bal-chart__line { stroke: currentColor; }
    .bal-chart__dot { fill: currentColor; }
    .bal-chart__gridline, .bal-chart__zero { stroke: currentColor; }
}
</style>
