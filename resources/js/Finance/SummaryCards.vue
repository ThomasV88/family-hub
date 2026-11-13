<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertTriangle } from '@lucide/vue';
import UiCard from '../Components/ui/UiCard.vue';
import UiTag from '../Components/ui/UiTag.vue';
import MoneyAmount from './MoneyAmount.vue';

// Income, Spending and Balance at month end for one currency. All three amounts are server values.
const props = defineProps({
    // { incomeMinor, spendingMinor, balanceMinor }
    summary: { type: Object, required: true },
    currency: { type: String, required: true },
});
const { t } = useI18n();
const negative = computed(() => props.summary.balanceMinor.startsWith('-'));
</script>

<template>
    <div class="sum" data-testid="summary-cards">
        <UiCard>
            <p class="sum__label">{{ t('finance.summary.income') }}</p>
            <p class="sum__amount" data-testid="summary-income"><MoneyAmount :minor="summary.incomeMinor" :currency="currency" /></p>
        </UiCard>
        <UiCard>
            <p class="sum__label">{{ t('finance.summary.spending') }}</p>
            <p class="sum__amount" data-testid="summary-spending"><MoneyAmount :minor="summary.spendingMinor" :currency="currency" /></p>
        </UiCard>
        <UiCard>
            <p class="sum__label">{{ t('finance.summary.balance') }}</p>
            <p class="sum__amount" data-testid="summary-balance"><MoneyAmount :minor="summary.balanceMinor" :currency="currency" /></p>
            <UiTag v-if="negative" variant="warn" :icon="AlertTriangle">{{ t('finance.summary.below_zero') }}</UiTag>
        </UiCard>
    </div>
</template>

<style scoped>
.sum { display: grid; grid-template-columns: 1fr; gap: var(--space-3); container-type: inline-size; }
@container (min-width: 560px) { .sum { grid-template-columns: repeat(3, 1fr); } }
@media (min-width: 640px) { .sum { grid-template-columns: repeat(3, 1fr); } }
.sum__label { margin: 0; font-size: var(--text-sm); font-weight: 600; color: var(--color-text-muted); }
.sum__amount { margin: var(--space-1) 0; font-size: var(--text-2xl); font-weight: 600; line-height: 1.2; }
</style>
