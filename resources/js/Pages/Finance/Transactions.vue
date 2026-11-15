<script setup>
import { computed, ref, watch } from 'vue';
import AppShell from '../../Layouts/AppShell.vue';
import FinanceShell from '../../Finance/FinanceShell.vue';
import TransactionFilters from '../../Finance/TransactionFilters.vue';
import TransactionList from '../../Finance/TransactionList.vue';
import TransactionPanel from '../../Finance/TransactionPanel.vue';
import { visitFinance } from '../../Finance/financeQuery.js';

// Transactions tab. All data is page props; opening or closing the panel is one partial visit for ?tx=.
const props = defineProps({
    finance: { type: Object, required: true },
    filters: { type: Object, required: true },
    transactions: { type: Object, required: true },
    transactionItems: { type: Array, default: () => [] },
    // null | { mode: 'new' } | Panel (server-validated; mirrors ?tx= in the URL)
    panelTransaction: { type: Object, default: null },
});
const closing = ref(false);
const panelOpen = computed(() => props.panelTransaction !== null && !closing.value);
watch(() => props.panelTransaction, (value) => { if (value === null) closing.value = false; });
const selectedId = computed(() => (props.panelTransaction && props.panelTransaction.mode !== 'new' ? props.panelTransaction.id : null));

const panel = ref(null);
const filtersRef = ref(null);
const guarded = (fn) => (panel.value ? panel.value.guard(fn) : fn());

function navigate(tx = null) {
    visitFinance(tx === null ? {} : { tx }, {
        only: ['panelTransaction'],
        onFinish: () => { if (tx === null) closing.value = false; },
    });
}
const openTx = (id) => guarded(() => navigate(id));
const openNew = () => guarded(() => navigate('new'));
function closePanel() {
    closing.value = true;
    navigate();
}
</script>

<template>
    <AppShell current="finances">
        <div class="fin-page" data-testid="finance-transactions-page">
            <div class="fin-page__main">
                <FinanceShell :finance="finance" current="transactions" show-add-fab @add="openNew">
                    <div class="fin-page__stack">
                        <TransactionFilters ref="filtersRef" :filters="filters" :categories="finance.categories" />
                        <TransactionList :items="transactionItems" :transactions="transactions" :filters="filters" :finance="finance" :selected-id="selectedId" @open="openTx" @add="openNew" @clear="filtersRef?.clearAll()" />
                    </div>
                </FinanceShell>
            </div>
            <TransactionPanel v-if="finance.accounts.length > 0" ref="panel" :open="panelOpen" :finance="finance" :transaction="panelTransaction" @close="closePanel" />
        </div>
    </AppShell>
</template>

<style scoped>
.fin-page { display: flex; align-items: flex-start; gap: var(--space-4); }
.fin-page__main { flex: 1; min-width: 0; }
.fin-page__stack { display: flex; flex-direction: column; gap: var(--space-4); }
</style>
