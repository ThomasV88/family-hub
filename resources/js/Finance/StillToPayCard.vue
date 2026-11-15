<script setup>
import { useI18n } from 'vue-i18n';
import UiButton from '../Components/ui/UiButton.vue';
import UiCard from '../Components/ui/UiCard.vue';
import UiTag from '../Components/ui/UiTag.vue';
import { AlertCircle } from '@lucide/vue';
import { INTL_LOCALES } from '../i18n.js';
import MoneyAmount from './MoneyAmount.vue';
import { formatDay } from './labels.js';
import { financeUrl } from './financeQuery.js';

// Still-to-pay card for one currency block of the server's `recurring.stillToPay`. Renders nothing without a block;
// the parent omits it entirely when the viewer has no visible recurring items.
const props = defineProps({
    // { currency, sumMinor, count, overdueCount, next: Occ[] } | null
    block: { type: Object, default: null },
    linkToRecurring: { type: Boolean, default: false },
    finance: { type: Object, required: true },
});
const { t, locale } = useI18n();
const intl = () => INTL_LOCALES[locale.value] ?? locale.value;
const href = () => financeUrl('/finance/recurring', { account: props.finance.selectedAccount, month: props.finance.month === props.finance.today?.slice(0, 7) ? undefined : props.finance.month });
</script>

<template>
    <UiCard v-if="block" :title="t('finance.still.title')" data-testid="still-to-pay">
        <p v-if="(finance.currencies ?? []).length > 1" class="still__currency">{{ t('finance.currency_heading', { code: block.currency }) }}</p>
        <template v-if="block.count > 0">
            <p class="still__sum"><MoneyAmount :minor="block.sumMinor" :currency="block.currency" /></p>
            <p class="still__meta">
                <span>{{ t('finance.still.count', { n: block.count }, block.count) }}</span>
                <UiTag v-if="block.overdueCount > 0" variant="danger" :icon="AlertCircle">{{ t('finance.still.overdue', { n: block.overdueCount }) }}</UiTag>
            </p>
            <ul v-if="(block.next ?? []).length" class="still__next">
                <li v-for="occ in block.next" :key="occ.key">
                    <span class="still__name">{{ occ.name }}</span>
                    <time :datetime="occ.dueDate" class="still__date">{{ formatDay(occ.dueDate, intl(), { day: 'numeric', month: 'short' }) }}</time>
                    <MoneyAmount :minor="occ.expectedMinor" :currency="occ.currency" />
                </li>
            </ul>
        </template>
        <p v-else class="still__none">{{ t('finance.still.none') }}</p>
        <UiButton v-if="linkToRecurring" variant="ghost" size="sm" :href="href()">{{ t('finance.still.open') }}</UiButton>
    </UiCard>
</template>

<style scoped>
.still__currency { margin: 0 0 var(--space-1); font-size: var(--text-sm); font-weight: 600; color: var(--color-text-muted); }
.still__sum { margin: 0; font-size: var(--text-2xl); font-weight: 600; line-height: 1.2; }
.still__meta { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); margin: var(--space-1) 0 var(--space-2); color: var(--color-text-muted); font-size: var(--text-sm); }
.still__none { margin: 0 0 var(--space-2); color: var(--color-text-muted); }
.still__next { display: flex; flex-direction: column; gap: var(--space-1); margin: 0 0 var(--space-2); padding: 0; list-style: none; font-size: var(--text-sm); }
.still__next li { display: flex; align-items: baseline; gap: var(--space-2); }
.still__name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.still__date { color: var(--color-text-muted); }
</style>
