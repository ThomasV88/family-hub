<script setup>
import { AlertCircle, AlertTriangle, Plus } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../Components/ui/UiButton.vue';
import UiCard from '../Components/ui/UiCard.vue';
import UiEmptyState from '../Components/ui/UiEmptyState.vue';
import UiProgress from '../Components/ui/UiProgress.vue';
import UiTag from '../Components/ui/UiTag.vue';
import { categoryLabel } from './labels.js';
import { formatMinor } from './money.js';

// Budget rows of one currency. spent, percent, state and the over amount are server values: nothing is computed here.
const props = defineProps({
    // [{ id, categoryId, limitMinor, spentMinor, percent, state, overMinor }]
    rows: { type: Array, default: () => [] },
    currency: { type: String, required: true },
    categories: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
    headingLevel: { type: Number, default: 2 },
});
const emit = defineEmits(['open', 'add']);
const { t, locale } = useI18n();

const nameOf = (id) => categoryLabel(props.categories.find((c) => c.id === id) ?? null, t);
const money = (minor) => formatMinor(minor, props.currency, locale.value);
const summary = (row) => t('finance.budgets.of', { spent: money(row.spentMinor), budget: money(row.limitMinor) });
</script>

<template>
    <UiCard :title="t('finance.budgets.title')" :heading-level="headingLevel" data-testid="budget-list">
        <template v-if="canEdit && rows.length" #action>
            <UiButton variant="secondary" size="sm" :icon="Plus" @click="emit('add')">{{ t('finance.budgets.add') }}</UiButton>
        </template>
        <UiEmptyState v-if="!rows.length" :heading-level="3" :title="t('finance.budgets.empty_title')" :body="t('finance.budgets.empty_body')">
            <template v-if="canEdit" #action>
                <UiButton variant="primary" :icon="Plus" @click="emit('add')">{{ t('finance.budgets.add') }}</UiButton>
            </template>
        </UiEmptyState>
        <template v-else>
            <ul class="bud">
                <li v-for="row in rows" :key="row.id">
                    <component :is="canEdit ? 'button' : 'div'" :type="canEdit ? 'button' : undefined" :class="['bud__row', { 'is-button': canEdit, 'is-over': row.state === 'over' }]" data-testid="budget-row" @click="canEdit && emit('open', row.id)">
                        <span class="bud__line">
                            <span class="bud__name">{{ nameOf(row.categoryId) }}</span>
                            <span class="bud__of">{{ summary(row) }}</span>
                        </span>
                        <UiProgress :value="row.percent" :label="nameOf(row.categoryId)" :aria-valuetext="summary(row)" class="bud__bar" />
                        <span class="bud__meta">
                            <span class="bud__percent">{{ t('finance.budgets.percent', { n: row.percent }) }}</span>
                            <UiTag v-if="row.state === 'over'" variant="danger" :icon="AlertCircle">{{ t('finance.budgets.over', { amount: money(row.overMinor) }) }}</UiTag>
                            <UiTag v-else-if="row.state === 'near'" variant="warn" :icon="AlertTriangle">{{ t('finance.budgets.near') }}</UiTag>
                        </span>
                    </component>
                </li>
            </ul>
            <p class="bud__hint">{{ t('finance.budgets.carry') }}</p>
        </template>
    </UiCard>
</template>

<style scoped>
.bud { display: flex; flex-direction: column; gap: var(--space-2); margin: 0; padding: 0; list-style: none; }
.bud__row { display: flex; flex-direction: column; gap: var(--space-2); width: 100%; min-height: 56px; padding: var(--space-2); border: 0; border-radius: var(--radius-md); background: none; color: inherit; font: inherit; text-align: start; }
.bud__row.is-button { cursor: pointer; }
.bud__row.is-button:hover { background: var(--color-surface-2); }
.bud__row.is-button:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.bud__line { display: flex; flex-wrap: wrap; justify-content: space-between; gap: var(--space-1) var(--space-3); }
.bud__name { font-weight: 600; min-width: 0; overflow-wrap: anywhere; }
.bud__of { font-size: var(--text-sm); font-variant-numeric: tabular-nums; }
.bud__meta { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); font-size: var(--text-sm); color: var(--color-text-muted); }
.is-over .bud__bar :deep(.ui-progress__fill) { background: var(--color-danger); }
.bud__hint { margin: var(--space-3) 0 0; font-size: var(--text-sm); color: var(--color-text-muted); }
</style>
