<script setup>
import { computed, ref } from 'vue';
import { Plus, Upload } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiEmptyState from '../Components/ui/UiEmptyState.vue';
import { INTL_LOCALES } from '../i18n.js';
import { useFrameWidth } from '../Shell/shellState.js';
import TransactionRow from './TransactionRow.vue';
import { formatDay } from './labels.js';
import { financeUrl, visitFinance } from './financeQuery.js';

// Paged list grouped by day. The server returns one page of at most 50 rows; "Show more" asks for the next page
// as a partial reload that the server's merge prop appends to.
const props = defineProps({
    items: { type: Array, default: () => [] },
    transactions: { type: Object, required: true },
    filters: { type: Object, required: true },
    finance: { type: Object, required: true },
    selectedId: { type: String, default: null },
});
const emit = defineEmits(['open', 'add', 'clear']);
const { t, locale } = useI18n();

const root = ref(null);
const width = useFrameWidth(root);
const layout = computed(() => (width.value >= 640 ? 'wide' : 'narrow'));
const intl = computed(() => INTL_LOCALES[locale.value] ?? locale.value);
const showAccount = computed(() => props.finance.selectedAccount === 'all');

const groups = computed(() => {
    const out = [];
    for (const row of props.items) {
        const last = out[out.length - 1];
        if (last && last.date === row.bookedOn) last.rows.push(row);
        else out.push({ date: row.bookedOn, rows: [row] });
    }
    return out.map((g) => ({ ...g, label: formatDay(g.date, intl.value) }));
});

const filtered = computed(() => {
    const f = props.filters;
    return f.q !== '' || f.type !== 'all' || f.category !== 'all' || f.source !== 'all';
});
const total = computed(() => props.transactions.total ?? props.items.length);

const loadingMore = ref(false);
const moreError = ref(false);
function showMore() {
    loadingMore.value = true;
    moreError.value = false;
    visitFinance({ page: (props.transactions.page ?? 1) + 1 }, {
        only: ['transactionItems', 'transactions'],
        preserveUrl: true,
        onError: () => { moreError.value = true; },
        onHttpException: () => { moreError.value = true; },
        onNetworkError: () => { moreError.value = true; },
        onFinish: () => { loadingMore.value = false; },
    });
}
const importHref = computed(() => financeUrl('/finance/import', { account: props.finance.selectedAccount }));
</script>

<template>
    <div ref="root" class="tx-list" data-testid="transaction-list">
        <UiEmptyState v-if="items.length === 0 && filtered" :heading-level="2" :title="t('finance.tx.empty_filtered_title')" :body="t('finance.tx.empty_filtered_body')">
            <template #action>
                <UiButton variant="secondary" @click="emit('clear')">{{ t('finance.tx.clear_filters') }}</UiButton>
            </template>
        </UiEmptyState>
        <UiEmptyState v-else-if="items.length === 0" :heading-level="2" :title="t('finance.tx.empty_title')" :body="t('finance.tx.empty_body')">
            <template v-if="finance.canEdit" #action>
                <div class="tx-list__empty-actions">
                    <UiButton variant="primary" :icon="Plus" @click="emit('add')">{{ t('finance.add_tx') }}</UiButton>
                    <UiButton variant="secondary" :icon="Upload" :href="importHref">{{ t('finance.import') }}</UiButton>
                </div>
            </template>
        </UiEmptyState>

        <template v-else>
            <p class="tx-list__count" aria-live="polite" data-testid="tx-count">{{ t('finance.tx.count', { n: total }, total) }}</p>

            <table v-if="layout === 'wide'" class="tx-table">
                <thead>
                    <tr>
                        <th scope="col">{{ t('finance.tx.col_description') }}</th>
                        <th scope="col">{{ t('finance.tx.col_category') }}</th>
                        <th v-if="showAccount" scope="col">{{ t('finance.tx.col_account') }}</th>
                        <th scope="col" class="is-end">{{ t('finance.tx.col_amount') }}</th>
                    </tr>
                </thead>
                <tbody v-for="group in groups" :key="group.date">
                    <tr class="tx-table__day">
                        <th scope="colgroup" :colspan="showAccount ? 4 : 3"><h3 class="tx-list__day">{{ group.label }}</h3></th>
                    </tr>
                    <TransactionRow v-for="row in group.rows" :key="row.id" :row="row" :categories="finance.categories" layout="wide" :show-account="showAccount" :selected="row.id === selectedId" @open="emit('open', $event)" />
                </tbody>
            </table>
            <div v-else>
                <section v-for="group in groups" :key="group.date" class="tx-list__group">
                    <h3 class="tx-list__day">{{ group.label }}</h3>
                    <ul class="tx-list__items">
                        <TransactionRow v-for="row in group.rows" :key="row.id" :row="row" :categories="finance.categories" layout="narrow" :show-account="showAccount" :selected="row.id === selectedId" @open="emit('open', $event)" />
                    </ul>
                </section>
            </div>

            <UiAlert v-if="moreError" variant="danger" live>{{ t('finance.tx.more_error') }}</UiAlert>
            <UiButton v-if="transactions.hasMore" variant="secondary" :loading="loadingMore" class="tx-list__more" @click="showMore">{{ t('finance.tx.more') }}</UiButton>
        </template>
    </div>
</template>

<style scoped>
.tx-list { display: flex; flex-direction: column; gap: var(--space-3); min-width: 0; }
.tx-list__count { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.tx-list__day { margin: 0; font-size: var(--text-sm); font-weight: 600; line-height: 1.4; color: var(--color-text-muted); }
.tx-list__group { display: flex; flex-direction: column; gap: var(--space-1); margin-bottom: var(--space-3); }
.tx-list__items { margin: 0; padding: 0; list-style: none; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-md); overflow: hidden; }
.tx-list__empty-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: var(--space-2); }
.tx-list__more { align-self: flex-start; min-height: 44px; }
.tx-table { width: 100%; border-collapse: collapse; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: var(--text-base); line-height: 1.5; }
.tx-table thead th { position: sticky; top: 0; padding: var(--space-2) var(--space-3); background: var(--color-surface-2); font-size: var(--text-sm); font-weight: 600; text-align: start; white-space: nowrap; }
.tx-table thead th.is-end { text-align: end; }
.tx-table__day th { padding: var(--space-3) var(--space-3) var(--space-1); text-align: start; background: var(--color-surface); border-top: 1px solid var(--color-border); }
</style>
