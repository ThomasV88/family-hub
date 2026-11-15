<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiButton from '../Components/ui/UiButton.vue';
import UiEmptyState from '../Components/ui/UiEmptyState.vue';
import UiTabs from '../Components/ui/UiTabs.vue';
import ShellAdd from '../Wishlist/ShellAdd.vue';
import FinanceToolbar from './FinanceToolbar.vue';
import { financeUrl } from './financeQuery.js';

// Frame of every /finance page. Everything comes from `finance` (page props); there are no placeholders for
// accounts the server did not send, and a viewer with zero visible accounts gets the heading and an empty state only.
const props = defineProps({
    finance: { type: Object, required: true },
    // 'overview' | 'transactions' | 'recurring'
    current: { type: String, default: 'transactions' },
    showAddFab: { type: Boolean, default: false },
    monthDisabled: { type: Boolean, default: false },
});
const emit = defineEmits(['add']);
const { t } = useI18n();

const PATHS = { overview: '/finance', transactions: '/finance/transactions', recurring: '/finance/recurring' };
const hasAccounts = computed(() => (props.finance.accounts ?? []).length > 0);
const tabs = computed(() => ['overview', 'transactions', 'recurring'].map((key) => ({ key, label: t(`finance.tab.${key}`) })));

const query = computed(() => ({
    account: props.finance.selectedAccount,
    month: props.finance.month === props.finance.today?.slice(0, 7) ? undefined : props.finance.month,
}));
const linkQuery = computed(() => ({ account: props.finance.selectedAccount, month: query.value.month }));
const accountsHref = computed(() => financeUrl('/finance/accounts', linkQuery.value));
const categoriesHref = '/finance/categories';
const emptyAction = computed(() => '/settings/finance-accounts?acct=new');

function openTab(key) {
    if (typeof window !== 'undefined' && window.location.pathname === PATHS[key] && key === props.current) return;
    router.get(financeUrl(PATHS[key], query.value), {}, { preserveScroll: true });
}

// aria-busy while a GET visit (chip, month, filter, tab) is in flight; previous data stays dimmed.
const busy = ref(false);
const offs = [
    router.on?.('start', (event) => { if (event?.detail?.visit?.method === 'get') busy.value = true; }),
    router.on?.('finish', () => { busy.value = false; }),
].filter((off) => typeof off === 'function');
onBeforeUnmount(() => offs.forEach((off) => off()));
</script>

<template>
    <div class="fin-shell" data-testid="finance-shell">
        <ShellAdd :enabled="hasAccounts && finance.canEdit && showAddFab" :label="t('finance.add_tx')" @add="emit('add')" />
        <header class="fin-shell__header">
            <h1 class="fin-shell__title">{{ t('finance.title') }}</h1>
            <div v-if="hasAccounts" class="fin-shell__links">
                <UiButton variant="ghost" size="sm" :href="accountsHref">{{ t('finance.link.accounts') }}</UiButton>
                <UiButton variant="ghost" size="sm" :href="categoriesHref">{{ t('finance.link.categories') }}</UiButton>
            </div>
        </header>

        <UiEmptyState v-if="!hasAccounts" :heading-level="2" :title="finance.isAdmin ? t('finance.empty_account.title') : t('finance.empty_none.title')" :body="finance.isAdmin ? t('finance.empty_account.body') : t('finance.empty_none.body')">
            <template v-if="finance.isAdmin" #action>
                <UiButton variant="primary" :href="emptyAction">{{ t('finance.acc.add') }}</UiButton>
            </template>
        </UiEmptyState>

        <template v-else>
            <FinanceToolbar :finance="finance" :show-add-fab="showAddFab" :month-disabled="monthDisabled" @add="emit('add')">
                <template #actions><slot name="actions" /></template>
            </FinanceToolbar>
            <UiTabs :tabs="tabs" :model-value="current" :label="t('finance.title')" @update:model-value="openTab">
                <template v-for="tab in tabs" :key="tab.key" #[`panel-${tab.key}`]>
                    <div v-if="tab.key === current" :aria-busy="busy ? 'true' : undefined" :class="['fin-shell__content', { 'is-busy': busy }]">
                        <p v-if="!finance.canEdit" class="fin-shell__viewonly">{{ t('finance.viewonly') }}</p>
                        <slot />
                    </div>
                </template>
            </UiTabs>
        </template>
    </div>
</template>

<style scoped>
.fin-shell { display: flex; flex-direction: column; gap: var(--space-4); min-width: 0; }
.fin-shell__header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-2); }
.fin-shell__title { margin: 0; font-size: var(--text-xl); font-weight: 600; line-height: 1.2; }
.fin-shell__links { display: flex; flex-wrap: wrap; gap: var(--space-1); }
.fin-shell__viewonly { margin: 0 0 var(--space-3); font-size: var(--text-sm); color: var(--color-text-muted); }
.fin-shell__content { transition: opacity 150ms; }
.fin-shell__content.is-busy { opacity: 0.6; }
</style>
