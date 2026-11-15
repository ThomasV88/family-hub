<script setup>
import { computed } from 'vue';
import { ArrowDownLeft, ArrowLeftRight, ArrowUpRight } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiTag from '../Components/ui/UiTag.vue';
import { INTL_LOCALES } from '../i18n.js';
import MoneyAmount from './MoneyAmount.vue';
import { categoryLabel, formatDay } from './labels.js';
import { formatMinor } from './money.js';

// One transaction as a table row (wide) or a two-line list item (narrow). Text interpolation only, never HTML.
const props = defineProps({
    row: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    // 'wide' | 'narrow'
    layout: { type: String, default: 'narrow' },
    showAccount: { type: Boolean, default: true },
    selected: { type: Boolean, default: false },
});
const emit = defineEmits(['open']);
const { t, locale } = useI18n();

const ICONS = { income: ArrowDownLeft, expense: ArrowUpRight, transfer: ArrowLeftRight };
const icon = computed(() => ICONS[props.row.kind] ?? ArrowUpRight);
const signed = computed(() => props.row.kind !== 'transfer');
const intl = computed(() => INTL_LOCALES[locale.value] ?? locale.value);
const kindLabel = computed(() => t(`finance.kind.${props.row.kind}`));
const category = computed(() => props.categories.find((c) => c.id === props.row.categoryId) ?? null);
const categoryText = computed(() => (props.row.kind === 'transfer' ? '' : categoryLabel(category.value, t)));
const transferText = computed(() => (props.row.transfer ? t('finance.kind.transfer_between', { from: props.row.transfer.fromName, to: props.row.transfer.toName }) : ''));
const ariaLabel = computed(() => t('finance.tx.row_label', {
    description: props.row.description,
    kind: kindLabel.value,
    amount: formatMinor(props.row.amountMinor, props.row.currency, locale.value, { signed: signed.value }),
    date: formatDay(props.row.bookedOn, intl.value, { dateStyle: 'long' }),
}));
const open = () => emit('open', props.row.id);
</script>

<template>
    <tr v-if="layout === 'wide'" class="tx-row tx-row--wide" :class="{ 'is-selected': selected }">
        <td class="tx-row__desc">
            <button type="button" class="tx-row__button" :aria-label="ariaLabel" :aria-current="selected ? 'true' : undefined" @click="open">
                <component :is="icon" :size="20" :stroke-width="1.75" aria-hidden="true" class="tx-row__icon" />
                <span class="tx-row__text">
                    <span class="tx-row__description">{{ row.description }}</span>
                    <span v-if="transferText" class="tx-row__meta">{{ transferText }}</span>
                </span>
                <UiTag v-if="row.source === 'import'">{{ t('finance.tx.imported') }}</UiTag>
            </button>
        </td>
        <td>{{ categoryText }}</td>
        <td v-if="showAccount">{{ row.accountName }}</td>
        <td class="tx-row__amount"><MoneyAmount :minor="row.amountMinor" :currency="row.currency" :signed="signed" /></td>
    </tr>
    <li v-else class="tx-row tx-row--narrow" :class="{ 'is-selected': selected }">
        <button type="button" class="tx-row__button tx-row__button--stack" :aria-label="ariaLabel" :aria-current="selected ? 'true' : undefined" @click="open">
            <span class="tx-row__line">
                <component :is="icon" :size="20" :stroke-width="1.75" aria-hidden="true" class="tx-row__icon" />
                <span class="tx-row__description tx-row__description--clamp">{{ row.description }}</span>
                <span class="tx-row__amount"><MoneyAmount :minor="row.amountMinor" :currency="row.currency" :signed="signed" /></span>
            </span>
            <span class="tx-row__meta tx-row__meta--wrap">
                <span v-if="transferText">{{ transferText }}</span>
                <span v-else-if="categoryText">{{ categoryText }}</span>
                <span v-if="showAccount && !transferText">{{ row.accountName }}</span>
                <UiTag v-if="row.source === 'import'">{{ t('finance.tx.imported') }}</UiTag>
            </span>
        </button>
    </li>
</template>

<style scoped>
.tx-row__button {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    width: 100%;
    min-height: 56px;
    padding: var(--space-2) 0;
    background: none;
    border: 0;
    color: var(--color-text);
    font: inherit;
    text-align: start;
    cursor: pointer;
}
.tx-row__button--stack { flex-direction: column; align-items: stretch; justify-content: center; gap: var(--space-1); padding: var(--space-3) var(--space-3); }
.tx-row__button:focus-visible { outline: 2px solid var(--color-focus); outline-offset: -2px; }
.tx-row--narrow:hover, .tx-row--narrow.is-selected, .tx-row--wide.is-selected { background: var(--color-surface-2); }
.tx-row__line { display: flex; align-items: flex-start; gap: var(--space-2); }
.tx-row__icon { flex: none; margin-top: 2px; color: var(--color-text-muted); }
.tx-row__text { display: flex; flex-direction: column; min-width: 0; }
.tx-row__description { flex: 1; min-width: 0; overflow-wrap: anywhere; }
.tx-row__description--clamp { display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.tx-row__amount { flex: none; font-weight: 600; text-align: end; white-space: nowrap; }
.tx-row__meta { font-size: var(--text-sm); font-weight: 400; color: var(--color-text-muted); }
.tx-row__meta--wrap { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-1) var(--space-2); padding-inline-start: calc(20px + var(--space-2)); }
.tx-row--wide td { padding: var(--space-1) var(--space-3); border-top: 1px solid var(--color-border); vertical-align: middle; }
.tx-row--wide td.tx-row__amount { text-align: end; }
.tx-row--wide:hover { background: var(--color-surface-2); }
.tx-row--narrow + .tx-row--narrow { border-top: 1px solid var(--color-border); }
</style>
