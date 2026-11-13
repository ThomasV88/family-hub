<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Unlink2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../Components/ui/UiButton.vue';
import UiDetailPanel from '../Components/ui/UiDetailPanel.vue';
import UiList from '../Components/ui/UiList.vue';
import UiListRow from '../Components/ui/UiListRow.vue';
import { INTL_LOCALES } from '../i18n.js';
import MoneyAmount from './MoneyAmount.vue';
import StatusTag from './StatusTag.vue';
import TransactionForm from './TransactionForm.vue';
import { categoryLabel, formatDay } from './labels.js';
import { formatMinor } from './money.js';

// One payment (occurrence) of a recurring expense: expected vs actual, record a payment, pick or drop the matching
// transaction. Status, difference and candidates are server values; nothing is computed here.
const props = defineProps({
    open: { type: Boolean, default: false },
    finance: { type: Object, required: true },
    // Occ | null
    bill: { type: Object, default: null },
    // null, or the ranked unmatched transactions when ?match=1
    candidates: { type: Array, default: null },
});
const emit = defineEmits(['close', 'match', 'match-back', 'edit']);
const { t, locale } = useI18n();

const intl = computed(() => INTL_LOCALES[locale.value] ?? locale.value);
const unpaid = computed(() => props.bill?.status === 'upcoming' || props.bill?.status === 'overdue');
const matching = computed(() => props.candidates !== null && unpaid.value);
const recording = ref(false);
const form = ref(null);
const busy = ref(false);
watch(() => props.bill?.key, () => { recording.value = false; });
watch(() => props.open, (open) => { if (!open) recording.value = false; });

const category = computed(() => (props.finance.categories ?? []).find((c) => c.id === props.bill?.categoryId) ?? null);
const date = (iso) => formatDay(iso, intl.value, { dateStyle: 'long' });
const base = computed(() => (props.bill ? `/finance/recurring/${props.bill.recurringId}/bills/${props.bill.dueDate}` : ''));
const abs = (minor) => String(minor).replace(/^-/, '');

const prefill = computed(() => (props.bill ? {
    kind: 'expense',
    amountMinor: props.bill.expectedMinor,
    currency: props.bill.currency,
    bookedOn: props.bill.dueDate,
    description: props.bill.name,
    categoryId: props.bill.categoryId,
    accountId: props.bill.accountId,
    accountName: props.bill.accountName,
} : null));

const differently = computed(() => {
    const m = props.bill?.match;
    if (!m || props.bill.status !== 'paid_differently') return '';
    return t('finance.status.paid_different_detail', {
        actual: formatMinor(abs(m.amountMinor), props.bill.currency, locale.value),
        date: date(m.bookedOn),
        expected: formatMinor(props.bill.expectedMinor, props.bill.currency, locale.value),
    });
});

const options = { preserveScroll: true, onStart: () => { busy.value = true; }, onFinish: () => { busy.value = false; } };
const useCandidate = (id) => router.post(`${base.value}/match`, { transaction_id: id }, options);
const unmatch = () => router.delete(`${base.value}/match`, options);
const startRecord = () => { recording.value = true; };
const submitRecord = () => form.value?.save();
</script>

<template>
    <UiDetailPanel :open="open && bill !== null" :title="t('finance.bill.panel')" @update:open="(v) => (v ? null : emit('close'))">
        <template v-if="bill">
            <div class="bill__head">
                <h3 class="bill__name">{{ bill.name }}</h3>
                <StatusTag :status="bill.status" />
            </div>

            <!-- Candidate list -->
            <section v-if="matching" data-testid="bill-candidates">
                <h3 class="bill__sub">{{ t('finance.bill.pick_title') }}</h3>
                <UiList v-if="candidates.length">
                    <UiListRow v-for="c in candidates" :key="c.id" class="bill__cand">
                        <span class="bill__cand-title">{{ c.description }}</span>
                        <template #meta>
                            <span class="bill__cand-meta"><time :datetime="c.bookedOn">{{ date(c.bookedOn) }}</time>, {{ c.accountName }}</span>
                            <MoneyAmount :minor="c.amountMinor" :currency="bill.currency" signed />
                        </template>
                        <template #trailing>
                            <UiButton variant="secondary" size="sm" :disabled="busy" @click="useCandidate(c.id)">{{ t('finance.bill.pick_use') }}</UiButton>
                        </template>
                    </UiListRow>
                </UiList>
                <div v-else class="bill__none">
                    <p>{{ t('finance.bill.pick_empty') }}</p>
                    <UiButton variant="secondary" @click="emit('match-back'); startRecord()">{{ t('finance.bill.record') }}</UiButton>
                </div>
            </section>

            <!-- Inline record form -->
            <TransactionForm
                v-else-if="recording"
                :key="bill.key"
                ref="form"
                mode="record"
                :finance="finance"
                :initial="prefill"
                :submit-url="`${base}/record`"
                method="post"
                :locked-fields="['account']"
            />

            <!-- Details -->
            <template v-else>
                <dl class="bill__dl">
                    <div><dt>{{ t('finance.bill.expected') }}</dt><dd><MoneyAmount :minor="bill.expectedMinor" :currency="bill.currency" /></dd></div>
                    <div><dt>{{ t('finance.field.date') }}</dt><dd><time :datetime="bill.dueDate">{{ date(bill.dueDate) }}</time></dd></div>
                    <div><dt>{{ t('finance.field.account') }}</dt><dd>{{ bill.accountName }}</dd></div>
                    <div><dt>{{ t('finance.field.category') }}</dt><dd>{{ categoryLabel(category, t) }}</dd></div>
                </dl>
                <section v-if="bill.match" class="bill__match" data-testid="bill-match">
                    <h3 class="bill__sub">{{ t('finance.bill.matched') }}</h3>
                    <p class="bill__match-desc">{{ bill.match.description }}</p>
                    <p class="bill__match-meta">
                        <time :datetime="bill.match.bookedOn">{{ date(bill.match.bookedOn) }}</time>, {{ bill.match.accountName }},
                        <MoneyAmount :minor="bill.match.amountMinor" :currency="bill.currency" signed />
                    </p>
                    <p v-if="differently" class="bill__diff">{{ differently }}</p>
                    <UiButton variant="ghost" size="sm" :href="`/finance/transactions?tx=${bill.match.transactionId}`">{{ t('finance.bill.open_tx') }}</UiButton>
                </section>
            </template>
        </template>

        <template #footer>
            <template v-if="bill">
                <UiButton v-if="finance.canEdit && !matching && !recording" variant="ghost" size="sm" class="bill__edit" @click="emit('edit', bill.recurringId)">{{ t('finance.bill.edit_rec') }}</UiButton>
                <UiButton v-if="matching" variant="secondary" @click="emit('match-back')">{{ t('finance.back') }}</UiButton>
                <UiButton v-else-if="recording" variant="secondary" @click="recording = false">{{ t('finance.back') }}</UiButton>
                <UiButton variant="secondary" @click="emit('close')">{{ t('finance.close') }}</UiButton>
                <template v-if="finance.canEdit">
                    <UiButton v-if="unpaid && !matching && !recording" variant="secondary" @click="emit('match')">{{ t('finance.bill.match') }}</UiButton>
                    <UiButton v-if="unpaid && !matching && !recording" variant="primary" @click="startRecord">{{ t('finance.bill.record') }}</UiButton>
                    <UiButton v-if="recording" variant="primary" @click="submitRecord">{{ t('finance.bill.record') }}</UiButton>
                    <UiButton v-if="!unpaid && bill.match" variant="ghost" :icon="Unlink2" :loading="busy" @click="unmatch">{{ t('finance.bill.unmatch') }}</UiButton>
                </template>
            </template>
        </template>
    </UiDetailPanel>
</template>

<style scoped>
.bill__head { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); margin-bottom: var(--space-3); }
.bill__name { margin: 0; font-size: var(--text-lg); font-weight: 600; overflow-wrap: anywhere; }
.bill__sub { margin: 0 0 var(--space-2); font-size: var(--text-base); font-weight: 600; }
.bill__dl { display: flex; flex-direction: column; gap: var(--space-3); margin: 0 0 var(--space-4); }
.bill__dl dt { font-size: var(--text-sm); font-weight: 600; }
.bill__dl dd { margin: 0; overflow-wrap: anywhere; }
.bill__match { padding: var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.bill__match p { margin: 0 0 var(--space-1); overflow-wrap: anywhere; }
.bill__match-meta { color: var(--color-text-muted); font-size: var(--text-sm); }
.bill__diff { font-weight: 600; }
.bill__cand { min-height: 56px; }
.bill__cand-title { white-space: normal; overflow-wrap: anywhere; }
.bill__cand-meta { margin-inline-end: var(--space-2); }
.bill__none { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-2); color: var(--color-text-muted); }
.bill__none p { margin: 0; }
.bill__edit { margin-inline-end: auto; }
</style>
