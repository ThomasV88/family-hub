<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppShell from '../../Layouts/AppShell.vue';
import BudgetList from '../../Finance/BudgetList.vue';
import BudgetPanel from '../../Finance/BudgetPanel.vue';
import FinanceShell from '../../Finance/FinanceShell.vue';
import StillToPayCard from '../../Finance/StillToPayCard.vue';
import SummaryCards from '../../Finance/SummaryCards.vue';
import BalanceChart from '../../Finance/charts/BalanceChart.vue';
import SpendingByCategoryChart from '../../Finance/charts/SpendingByCategoryChart.vue';
import { useI18n } from 'vue-i18n';
import { financeUrl, visitFinance } from '../../Finance/financeQuery.js';

// Overview tab. Every number is a server aggregate over the viewer's visible accounts, one block per currency;
// the page only lays them out. The budget panel is one partial visit (?budget).
const props = defineProps({
    finance: { type: Object, required: true },
    overview: { type: Object, required: true },
    panelBudget: { type: Object, default: null },
});
const { t } = useI18n();

const blocks = computed(() => props.overview.blocks ?? []);
const multi = computed(() => blocks.value.length > 1);
const level = computed(() => (multi.value ? 3 : 2));
// Block headings carry the currency, so the still-to-pay card must not repeat it.
const cardFinance = computed(() => ({ ...props.finance, currencies: [] }));

const closing = ref(false);
function openBudget(budget) {
    visitFinance({ budget }, { only: ['panelBudget'], onFinish: () => { closing.value = false; } });
}
function closePanel() {
    closing.value = true;
    openBudget(null);
}
watch(() => props.panelBudget, () => { closing.value = false; });
const panelOpen = computed(() => props.panelBudget !== null && !closing.value);

function openAdd() {
    router.get(financeUrl('/finance/transactions', { account: props.finance.selectedAccount, month: props.finance.month === props.finance.today?.slice(0, 7) ? undefined : props.finance.month, tx: 'new' }), {}, { preserveScroll: true });
}
</script>

<template>
    <AppShell current="finances">
        <div data-testid="finance-overview-page">
            <FinanceShell :finance="finance" current="overview" :show-add-fab="finance.canEdit" @add="openAdd">
                <div class="ov">
                    <section v-for="block in blocks" :key="block.currency" class="ov__block" :aria-labelledby="multi ? `ov-${block.currency}` : undefined" :data-currency="block.currency">
                        <h2 v-if="multi" :id="`ov-${block.currency}`" class="ov__currency">{{ t('finance.currency_heading', { code: block.currency }) }}</h2>
                        <SummaryCards :summary="block.summary" :currency="block.currency" />
                        <StillToPayCard v-if="overview.hasRecurring && block.stillToPay" :block="block.stillToPay" :finance="cardFinance" link-to-recurring />
                        <BudgetList :rows="block.budgets" :currency="block.currency" :categories="finance.categories" :can-edit="finance.canEdit" :heading-level="level" @open="openBudget" @add="openBudget('new')" />
                        <div class="ov__charts">
                            <SpendingByCategoryChart :data="block.categorySpending" :currency="block.currency" :month="finance.month" :categories="finance.categories" :heading-level="level" />
                            <BalanceChart :points="block.balanceSeries" :currency="block.currency" :heading-level="level" />
                        </div>
                    </section>
                </div>
            </FinanceShell>
            <BudgetPanel v-if="finance.canEdit" :open="panelOpen" :finance="finance" :budget="panelBudget" :blocks="blocks" @close="closePanel" />
        </div>
    </AppShell>
</template>

<style scoped>
.ov { display: flex; flex-direction: column; gap: var(--space-6); min-width: 0; }
.ov__block { display: flex; flex-direction: column; gap: var(--space-4); min-width: 0; }
.ov__currency { margin: 0; font-size: var(--text-xl); font-weight: 600; }
.ov__charts { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--space-4); container-type: inline-size; }
@container (min-width: 800px) { .ov__charts { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
