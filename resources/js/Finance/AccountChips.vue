<script setup>
import { useI18n } from 'vue-i18n';
import UiChip from '../Components/ui/UiChip.vue';
import { accountTypeIcon } from './labels.js';
import { visitFinance } from './financeQuery.js';

// Only accounts present in `accounts` get a chip: nothing is drawn for anything the server did not send.
const props = defineProps({
    accounts: { type: Array, default: () => [] },
    selected: { type: String, default: 'all' },
});
const { t } = useI18n();

function choose(id) {
    if (id === props.selected) return;
    visitFinance({ account: id }, { only: ['finance', 'transactionItems', 'transactions', 'filters', 'accounts', 'panelTransaction'], reset: ['transactionItems'] });
}
</script>

<template>
    <div class="account-chips" role="group" :aria-label="t('finance.accounts.chips')">
        <UiChip :model-value="selected === 'all'" @update:model-value="choose('all')">{{ t('finance.accounts.all') }}</UiChip>
        <UiChip v-for="account in accounts" :key="account.id" :model-value="selected === account.id" @update:model-value="choose(account.id)">
            <component :is="accountTypeIcon(account.type)" :size="20" :stroke-width="1.75" aria-hidden="true" />
            <span class="account-chips__name">{{ account.name }}</span>
        </UiChip>
    </div>
</template>

<style scoped>
.account-chips { display: flex; flex-wrap: wrap; gap: var(--space-2); min-width: 0; }
.account-chips :deep(.ui-chip) { gap: var(--space-1); max-width: 100%; }
.account-chips__name { overflow-wrap: anywhere; text-align: start; }
</style>
