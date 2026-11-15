<script setup>
import { I18nT, useI18n } from 'vue-i18n';
import AppShell from '../../Layouts/AppShell.vue';
import FinanceShell from '../../Finance/FinanceShell.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiList from '../../Components/ui/UiList.vue';
import MoneyAmount from '../../Finance/MoneyAmount.vue';
import { accountTypeIcon } from '../../Finance/labels.js';

// Member view of the accounts: visible accounts and today's balance, rows are not interactive.
defineProps({
    finance: { type: Object, required: true },
    accounts: { type: Array, default: () => [] },
});
const { t } = useI18n();
</script>

<template>
    <AppShell current="finances">
        <FinanceShell :finance="finance" current="overview">
            <section class="fin-accounts" data-testid="finance-accounts">
                <div class="fin-accounts__head">
                    <h2 class="fin-accounts__title">{{ t('finance.acc.title') }}</h2>
                    <UiButton v-if="finance.isAdmin" variant="secondary" size="sm" href="/settings/finance-accounts">{{ t('finance.acc.manage') }}</UiButton>
                </div>
                <UiList>
                    <li v-for="account in accounts" :key="account.id" class="fin-accounts__row">
                        <component :is="accountTypeIcon(account.type)" :size="20" :stroke-width="1.75" aria-hidden="true" class="fin-accounts__icon" />
                        <div class="fin-accounts__main">
                            <span class="fin-accounts__name">{{ account.name }}</span>
                            <span class="fin-accounts__type">{{ t(`finance.acc.type_${account.type}`) }}</span>
                        </div>
                        <I18nT keypath="finance.acc.balance" tag="span" class="fin-accounts__balance">
                            <template #amount><MoneyAmount :minor="account.balanceMinor" :currency="account.currency" /></template>
                        </I18nT>
                    </li>
                </UiList>
            </section>
        </FinanceShell>
    </AppShell>
</template>

<style scoped>
.fin-accounts { display: flex; flex-direction: column; gap: var(--space-3); }
.fin-accounts__head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-2); }
.fin-accounts__title { margin: 0; font-size: var(--text-xl); font-weight: 600; line-height: 1.2; }
.fin-accounts__row { display: flex; align-items: center; gap: var(--space-3); min-height: 64px; padding-block: var(--space-3); }
.fin-accounts__icon { flex: none; color: var(--color-text-muted); }
.fin-accounts__main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.fin-accounts__name { font-weight: 600; overflow-wrap: anywhere; }
.fin-accounts__type { font-size: var(--text-sm); color: var(--color-text-muted); }
.fin-accounts__balance { flex: none; max-width: 50%; text-align: end; font-weight: 600; }
</style>
