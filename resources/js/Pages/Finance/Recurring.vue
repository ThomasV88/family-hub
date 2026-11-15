<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import AppShell from '../../Layouts/AppShell.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiChip from '../../Components/ui/UiChip.vue';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import BillPanel from '../../Finance/BillPanel.vue';
import DayPanel from '../../Finance/DayPanel.vue';
import FinanceShell from '../../Finance/FinanceShell.vue';
import PaymentCalendar from '../../Finance/PaymentCalendar.vue';
import RecurringList from '../../Finance/RecurringList.vue';
import RecurringPanel from '../../Finance/RecurringPanel.vue';
import StillToPayCard from '../../Finance/StillToPayCard.vue';
import { financeUrl, visitFinance } from '../../Finance/financeQuery.js';

// Recurring tab. All data is page props; opening or closing a panel is one partial visit (?day, ?bill, ?match, ?rec).
const props = defineProps({
    finance: { type: Object, required: true },
    recurring: { type: Object, required: true },
    panelBill: { type: Object, default: null },
    panelRecurring: { type: Object, default: null },
    dayPanel: { type: Object, default: null },
    candidates: { type: Array, default: null },
});
const { t } = useI18n();

const STORAGE_KEY = 'fh-finance-recurring-view';
const VIEWS = ['calendar', 'list'];
function storedView() {
    try {
        const value = typeof window !== 'undefined' ? window.localStorage.getItem(STORAGE_KEY) : null;
        return VIEWS.includes(value) ? value : 'calendar';
    } catch {
        return 'calendar';
    }
}
const view = ref(storedView());
function setView(value) {
    view.value = value;
    try { window.localStorage.setItem(STORAGE_KEY, value); } catch { /* preference only */ }
}
function onViewKey(event, index) {
    const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key];
    if (step === undefined) return;
    event.preventDefault();
    const next = VIEWS[(index + step + VIEWS.length) % VIEWS.length];
    setView(next);
    event.currentTarget.parentElement?.querySelectorAll('button')[VIEWS.indexOf(next)]?.focus();
}

const PANEL_ONLY = ['dayPanel', 'panelBill', 'panelRecurring', 'candidates'];
const CLEARED = { day: null, bill: null, rec: null, match: null };
const closing = ref(false);
function go(params, extra = {}) {
    visitFinance({ ...CLEARED, ...params }, { only: PANEL_ONLY, onFinish: () => { closing.value = false; }, ...extra });
}
const openDay = (day) => go({ day });
const openBill = (bill) => go({ bill });
const startMatch = () => go({ bill: `${props.panelBill.recurringId}.${props.panelBill.dueDate}`, match: '1' });
const backFromMatch = () => go({ bill: `${props.panelBill.recurringId}.${props.panelBill.dueDate}` });
const openRec = (rec) => go({ rec });
function closePanels() {
    closing.value = true;
    go({});
}
function openAdd() {
    router.get(financeUrl('/finance/transactions', { account: props.finance.selectedAccount, month: props.finance.month === props.finance.today?.slice(0, 7) ? undefined : props.finance.month, tx: 'new' }), {}, { preserveScroll: true });
}
function shiftMonth(delta) {
    const [y, m] = props.finance.month.split('-').map(Number);
    const d = new Date(Date.UTC(y, m - 1 + delta, 1));
    const month = `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`;
    visitFinance({ ...CLEARED, month: month === props.finance.today.slice(0, 7) ? null : month }, { only: ['finance', 'recurring', ...PANEL_ONLY] });
}

watch(() => [props.dayPanel, props.panelBill, props.panelRecurring], () => { closing.value = false; });
const dayOpen = computed(() => props.dayPanel !== null && !closing.value);
const billOpen = computed(() => props.panelBill !== null && !closing.value);
const recOpen = computed(() => props.panelRecurring !== null && !closing.value);
const hasItems = computed(() => (props.recurring.items ?? []).length > 0);
const selectedDay = computed(() => props.dayPanel?.date ?? null);
const selectedKey = computed(() => props.panelBill?.key ?? null);
</script>

<template>
    <AppShell current="finances">
        <div class="rec-page" data-testid="finance-recurring-page">
            <div class="rec-page__main">
            <FinanceShell :finance="finance" current="recurring" :month-disabled="view === 'list'" @add="openAdd">
                <div class="rec-page__stack">
                    <template v-if="hasItems">
                        <div class="rec-page__cards">
                            <StillToPayCard v-for="block in recurring.stillToPay" :key="block.currency" :block="block" :finance="finance" />
                        </div>

                        <div class="rec-page__controls">
                            <div class="rec-page__view" role="radiogroup" :aria-label="t('finance.view.label')">
                                <UiChip
                                    v-for="(option, index) in VIEWS"
                                    :key="option"
                                    :model-value="view === option"
                                    :tabindex="view === option ? 0 : -1"
                                    @update:model-value="setView(option)"
                                    @keydown="onViewKey($event, index)"
                                >{{ t(`finance.view.${option}`) }}</UiChip>
                            </div>
                            <UiButton v-if="finance.canEdit" variant="secondary" :icon="Plus" class="rec-page__add" @click="openRec('new')">{{ t('finance.rec.add') }}</UiButton>
                        </div>

                        <PaymentCalendar v-if="view === 'calendar'" :month="finance.month" :today="finance.today" :days="recurring.calendar" :selected="selectedDay" @open="openDay" @month="shiftMonth" />
                        <RecurringList v-else :overdue="recurring.overdue" :next30="recurring.next30" :selected-key="selectedKey" @open="openBill" />
                    </template>
                    <UiEmptyState v-else :heading-level="2" :title="t('finance.rec.empty_title')" :body="t('finance.rec.empty_body')">
                        <template v-if="finance.canEdit" #action>
                            <UiButton variant="primary" :icon="Plus" @click="openRec('new')">{{ t('finance.rec.add') }}</UiButton>
                        </template>
                    </UiEmptyState>
                </div>
            </FinanceShell>
            </div>

            <DayPanel :open="dayOpen" :day="dayPanel" @close="closePanels" @open-bill="openBill" />
            <BillPanel :open="billOpen" :finance="finance" :bill="panelBill" :candidates="candidates" @close="closePanels" @match="startMatch" @match-back="backFromMatch" @edit="openRec" />
            <RecurringPanel v-if="finance.canEdit" :open="recOpen" :finance="finance" :item="panelRecurring" @close="closePanels" />
        </div>
    </AppShell>
</template>

<style scoped>
.rec-page { display: flex; align-items: flex-start; gap: var(--space-4); }
.rec-page__main { flex: 1; min-width: 0; }
.rec-page__stack { display: flex; flex-direction: column; gap: var(--space-4); min-width: 0; }
.rec-page__cards { display: flex; flex-direction: column; gap: var(--space-3); }
.rec-page__controls { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3); }
.rec-page__view { display: flex; gap: var(--space-2); }
.rec-page__add { min-height: 44px; }
@media (max-width: 480px) { .rec-page__add { width: 100%; } }
</style>
