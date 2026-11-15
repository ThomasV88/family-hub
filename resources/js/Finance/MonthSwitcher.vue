<script setup>
import { computed } from 'vue';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../Components/ui/UiButton.vue';
import { INTL_LOCALES } from '../i18n.js';
import { visitFinance } from './financeQuery.js';

const props = defineProps({
    month: { type: String, required: true },
    today: { type: String, required: true },
    disabled: { type: Boolean, default: false },
});
const { t, locale } = useI18n();

const currentMonth = computed(() => props.today.slice(0, 7));
const label = computed(() => {
    const [y, m] = props.month.split('-').map(Number);
    return new Intl.DateTimeFormat(INTL_LOCALES[locale.value] ?? locale.value, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(y, m - 1, 1)));
});

function shift(delta) {
    const [y, m] = props.month.split('-').map(Number);
    const d = new Date(Date.UTC(y, m - 1 + delta, 1));
    go(`${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`);
}
function go(month) {
    if (props.disabled) return;
    visitFinance({ month: month === currentMonth.value ? null : month }, { only: ['finance', 'transactionItems', 'transactions', 'filters', 'accounts', 'recurring', 'panelBill', 'panelRecurring', 'dayPanel', 'candidates'], reset: ['transactionItems'] });
}
</script>

<template>
    <nav class="month-switcher" :aria-label="t('finance.month.label')" :aria-disabled="disabled ? 'true' : undefined">
        <UiButton variant="ghost" icon-only :icon="ChevronLeft" :label="t('finance.month.prev')" :disabled="disabled" @click="shift(-1)" />
        <span class="month-switcher__label" aria-live="polite" data-testid="month-label">{{ label }}</span>
        <UiButton variant="ghost" icon-only :icon="ChevronRight" :label="t('finance.month.next')" :disabled="disabled" @click="shift(1)" />
        <UiButton v-if="month !== currentMonth" variant="ghost" size="sm" :disabled="disabled" @click="go(currentMonth)">{{ t('finance.month.current') }}</UiButton>
    </nav>
</template>

<style scoped>
.month-switcher { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }
.month-switcher__label { min-width: 12ch; text-align: center; font-size: var(--text-xl); font-weight: 600; line-height: 1.2; text-transform: capitalize; }
.month-switcher :deep(.ui-button--icon-only) { min-width: 44px; min-height: 44px; }
</style>
