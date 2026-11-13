<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiChip from '../Components/ui/UiChip.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import UiSelect from '../Components/ui/UiSelect.vue';
import UiTextarea from '../Components/ui/UiTextarea.vue';
import { categoryLabel } from './labels.js';
import { exponentOf, parseMoney, toInputString } from './money.js';

// Reusable transaction form: the transaction panel uses it for new and edit, the bill panel (05-14) for 'record'.
// Edit payloads never carry account_id, kind, from_account_id or to_account_id: they are immutable server-side.
const props = defineProps({
    finance: { type: Object, required: true },
    // Row/Panel from the server in edit mode, an optional prefill in new/record mode.
    initial: { type: Object, default: null },
    // 'new' | 'edit' | 'record'
    mode: { type: String, default: 'new' },
    submitUrl: { type: String, required: true },
    method: { type: String, default: 'post' },
    // Field names ('account', 'from', 'to', 'amount', 'date', 'description', 'category') shown read-only.
    lockedFields: { type: Array, default: () => [] },
});
const emit = defineEmits(['saved', 'dirty-change']);
const { t, locale } = useI18n();

const KINDS = ['expense', 'income', 'transfer'];
const editing = computed(() => props.mode === 'edit');
const accounts = computed(() => props.finance.accounts ?? []);
const accountById = (id) => accounts.value.find((a) => a.id === id) ?? null;

function defaultAccount() {
    if (props.initial?.accountId && accountById(props.initial.accountId)) return props.initial.accountId;
    if (props.finance.selectedAccount !== 'all' && accountById(props.finance.selectedAccount)) return props.finance.selectedAccount;
    return accounts.value[0]?.id ?? '';
}

function initialState() {
    const i = props.initial;
    const kind = i?.kind ?? 'expense';
    const account = defaultAccount();
    const currency = i?.currency ?? accountById(account)?.currency ?? 'EUR';
    let amount = '';
    if (i?.amountMinor !== undefined && i?.amountMinor !== null) {
        const raw = String(i.amountMinor);
        amount = toInputString(raw.startsWith('-') ? raw.slice(1) : raw, exponentOf(currency), locale.value);
    } else if (typeof i?.amount === 'string') {
        amount = i.amount;
    }
    const base = {
        kind: KINDS.includes(kind) ? kind : 'expense',
        amount,
        booked_on: i?.bookedOn ?? props.finance.today,
        description: i?.description ?? '',
        category_id: i?.categoryId ?? '',
        account_id: account,
        from_id: i?.transfer?.fromId ?? account,
        to_id: i?.transfer?.toId ?? (accounts.value.find((a) => a.id !== account)?.id ?? ''),
        note: i?.note ?? '',
    };
    return base;
}

const form = ref(initialState());
const snapshot = ref(JSON.stringify(form.value));
const errors = ref({});
const serverError = ref(false);
const processing = ref(false);
const body = ref(null);
const amountField = ref(null);
const kindRoot = ref(null);

const isTransfer = computed(() => form.value.kind === 'transfer');
const sourceAccount = computed(() => accountById(isTransfer.value && !editing.value ? form.value.from_id : form.value.account_id));
const currency = computed(() => (editing.value && props.initial?.currency ? props.initial.currency : sourceAccount.value?.currency ?? 'EUR'));
const exponent = computed(() => exponentOf(currency.value));
const dirty = computed(() => JSON.stringify(form.value) !== snapshot.value);

const amountMinor = computed(() => parseMoney(form.value.amount, exponent.value));
const amountError = computed(() => (form.value.amount.trim() !== '' && amountMinor.value === null ? t('finance.error.amount', { digits: exponent.value }) : ''));
const transferSame = computed(() => isTransfer.value && !editing.value && form.value.from_id !== '' && form.value.from_id === form.value.to_id);
const transferCurrency = computed(() => {
    if (!isTransfer.value || editing.value || transferSame.value) return false;
    const from = accountById(form.value.from_id);
    const to = accountById(form.value.to_id);
    return Boolean(from && to && from.currency !== to.currency);
});
const descriptionRequired = computed(() => !isTransfer.value);
const descriptionOk = computed(() => form.value.description.length <= 160 && (!descriptionRequired.value || form.value.description.trim() !== ''));
const accountsOk = computed(() => {
    if (editing.value) return true;
    if (isTransfer.value) return form.value.from_id !== '' && form.value.to_id !== '' && !transferSame.value && !transferCurrency.value;
    return form.value.account_id !== '';
});
const valid = computed(() => amountMinor.value !== null && /^\d{4}-\d{2}-\d{2}$/.test(form.value.booked_on) && descriptionOk.value && accountsOk.value && form.value.note.length <= 1000);
const canSave = computed(() => valid.value && (!editing.value || dirty.value) && !processing.value);

watch(dirty, (value) => emit('dirty-change', value));

const kindOptions = computed(() => KINDS.map((k) => ({ value: k, label: t(`finance.kind.${k}`) })));
const accountOptions = computed(() => accounts.value.map((a) => ({ value: a.id, label: a.name })));
const categoryOptions = computed(() => [
    { value: '', label: t('finance.tx.uncategorized') },
    ...(props.finance.categories ?? [])
        .filter((c) => c.kind === form.value.kind)
        .map((c) => ({ value: c.id, label: categoryLabel(c, t) })),
]);
const accountName = (id) => accountById(id)?.name ?? props.initial?.accountName ?? '';

function setKind(kind) {
    if (editing.value || props.mode === 'record' || form.value.kind === kind) return;
    form.value.kind = kind;
    form.value.category_id = '';
    if (kind === 'transfer' && (form.value.to_id === '' || form.value.to_id === form.value.from_id)) {
        form.value.to_id = accounts.value.find((a) => a.id !== form.value.from_id)?.id ?? '';
    }
}
function onKindKey(event, index) {
    const rtl = document.documentElement.dir === 'rtl';
    const step = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1, ArrowDown: 1, ArrowUp: -1 }[event.key];
    let target = null;
    if (step !== undefined) target = Math.min(KINDS.length - 1, Math.max(0, index + step));
    else if (event.key === 'Home') target = 0;
    else if (event.key === 'End') target = KINDS.length - 1;
    if (target === null) return;
    event.preventDefault();
    setKind(KINDS[target]);
    nextTick(() => kindRoot.value?.querySelectorAll('button')[target]?.focus());
}
// Keeping the account in step: a transfer's from account follows the single account select.
watch(() => form.value.account_id, (id) => { if (!isTransfer.value && !form.value.from_id) form.value.from_id = id; });

function payload() {
    const f = form.value;
    const common = {
        amount: f.amount.trim(),
        booked_on: f.booked_on,
        description: f.description.trim(),
        note: f.note.trim() === '' ? null : f.note,
    };
    if (editing.value) {
        return { ...common, ...(isTransfer.value ? {} : { category_id: f.category_id === '' ? null : f.category_id }) };
    }
    if (isTransfer.value) return { kind: 'transfer', ...common, from_account_id: f.from_id, to_account_id: f.to_id };
    return { kind: f.kind, ...common, category_id: f.category_id === '' ? null : f.category_id, account_id: f.account_id };
}

const FIELD_OF = { amount: 'amount', booked_on: 'date', description: 'description', category_id: 'category', account_id: 'account', from_account_id: 'from', to_account_id: 'to', note: 'note' };
function message(key) {
    return typeof key === 'string' && key.startsWith('finance.error.') ? t(key, { digits: exponent.value }) : t('finance.error.tx_save');
}
const focusFirstInvalid = () => nextTick(() => body.value?.querySelector('[aria-invalid="true"]')?.focus());

function localErrors() {
    const next = {};
    if (amountMinor.value === null) next.amount = t('finance.error.amount', { digits: exponent.value });
    if (!/^\d{4}-\d{2}-\d{2}$/.test(form.value.booked_on)) next.date = t('finance.error.date');
    if (!descriptionOk.value) next.description = t('finance.error.description');
    if (transferSame.value) next.to = t('finance.error.transfer_same');
    else if (transferCurrency.value) next.to = t('finance.error.transfer_currency');
    else if (!accountsOk.value) next[isTransfer.value ? 'to' : 'account'] = t('finance.error.account');
    return next;
}

function save() {
    const local = localErrors();
    errors.value = local;
    if (Object.keys(local).length) {
        focusFirstInvalid();
        return;
    }
    if (!canSave.value) return;
    const sent = JSON.stringify(form.value);
    const options = {
        preserveScroll: true,
        onStart: () => { processing.value = true; serverError.value = false; },
        onSuccess: () => { snapshot.value = sent; emit('dirty-change', false); emit('saved'); },
        onError: (errs) => {
            const mapped = {};
            let unknown = false;
            for (const [key, value] of Object.entries(errs ?? {})) {
                if (FIELD_OF[key]) mapped[FIELD_OF[key]] = message(value);
                else unknown = true;
            }
            errors.value = mapped;
            serverError.value = unknown || Object.keys(mapped).length === 0;
            focusFirstInvalid();
        },
        onHttpException: () => { serverError.value = true; },
        onNetworkError: () => { serverError.value = true; },
        onFinish: () => { processing.value = false; },
    };
    if (props.method === 'patch') router.patch(props.submitUrl, payload(), options);
    else router.post(props.submitUrl, payload(), options);
}

const onEnter = (event) => { if (event.target?.tagName === 'INPUT') event.preventDefault(); };
const focusAmount = () => amountField.value?.focus();
function reset() {
    form.value = initialState();
    snapshot.value = JSON.stringify(form.value);
    errors.value = {};
    serverError.value = false;
}
// A new server state for the same transaction (after a save) refreshes the form unless the member is mid-edit.
watch(() => props.initial, () => { if (!dirty.value) reset(); });

defineExpose({ save, canSave, dirty, processing, focusAmount, reset });
</script>

<template>
    <div ref="body" data-testid="transaction-form">
        <form novalidate @submit.prevent="save" @keydown.enter="onEnter">
            <UiAlert v-if="serverError" variant="danger" live class="tx-form__alert">{{ t('finance.error.tx_save') }}</UiAlert>

            <div class="tx-form__fields">
                <div v-if="mode === 'new' || editing" class="tx-form__block">
                    <span class="tx-form__label" aria-hidden="true">{{ t('finance.field.kind') }}</span>
                    <div v-if="!editing" ref="kindRoot" class="tx-form__kinds" role="radiogroup" :aria-label="t('finance.field.kind')">
                        <UiChip
                            v-for="(option, index) in kindOptions"
                            :key="option.value"
                            :model-value="form.kind === option.value"
                            :tabindex="form.kind === option.value ? 0 : -1"
                            @update:model-value="setKind(option.value)"
                            @keydown="onKindKey($event, index)"
                        >{{ option.label }}</UiChip>
                    </div>
                    <p v-else class="tx-form__static" data-testid="kind-static">{{ t(`finance.kind.${form.kind}`) }}</p>
                </div>

                <UiField :label="t('finance.field.amount')" required :error="errors.amount || amountError">
                    <div class="tx-form__amount">
                        <UiInput ref="amountField" v-model="form.amount" inputmode="decimal" autocomplete="off" :readonly="lockedFields.includes('amount')" />
                        <span class="tx-form__suffix" data-testid="currency-suffix">{{ currency }}</span>
                    </div>
                </UiField>

                <UiField :label="t('finance.field.date')" required :error="errors.date">
                    <UiInput v-model="form.booked_on" type="date" :readonly="lockedFields.includes('date')" />
                </UiField>

                <UiField :label="t('finance.field.description')" :required="descriptionRequired" :error="errors.description">
                    <UiInput v-model="form.description" maxlength="160" autocomplete="off" :readonly="lockedFields.includes('description')" />
                </UiField>

                <UiField v-if="!isTransfer" :label="t('finance.field.category')" :error="errors.category">
                    <UiSelect v-model="form.category_id" :options="categoryOptions" :disabled="lockedFields.includes('category')" />
                </UiField>

                <template v-if="isTransfer && !editing">
                    <UiField :label="t('finance.field.from')" required :error="errors.from">
                        <UiSelect v-model="form.from_id" :options="accountOptions" :disabled="lockedFields.includes('from')" />
                    </UiField>
                    <UiField :label="t('finance.field.to')" required :error="errors.to || (transferSame ? t('finance.error.transfer_same') : transferCurrency ? t('finance.error.transfer_currency') : '')">
                        <UiSelect v-model="form.to_id" :options="accountOptions" :disabled="lockedFields.includes('to')" />
                    </UiField>
                </template>
                <div v-else-if="isTransfer && editing" class="tx-form__block">
                    <span class="tx-form__label" aria-hidden="true">{{ t('finance.field.account') }}</span>
                    <p class="tx-form__static" data-testid="account-static">{{ t('finance.kind.transfer_between', { from: initial?.transfer?.fromName ?? '', to: initial?.transfer?.toName ?? '' }) }}</p>
                </div>
                <UiField v-else-if="!editing" :label="t('finance.field.account')" required :error="errors.account">
                    <UiSelect v-model="form.account_id" :options="accountOptions" :disabled="lockedFields.includes('account')" />
                </UiField>
                <div v-else class="tx-form__block">
                    <span class="tx-form__label" aria-hidden="true">{{ t('finance.field.account') }}</span>
                    <p class="tx-form__static" data-testid="account-static">{{ accountName(form.account_id) }}</p>
                </div>

                <UiField :label="t('finance.field.note')" :error="errors.note">
                    <UiTextarea v-model="form.note" :rows="3" maxlength="1000" />
                </UiField>
            </div>
        </form>
    </div>
</template>

<style scoped>
.tx-form__alert { margin-bottom: var(--space-4); }
.tx-form__fields { display: flex; flex-direction: column; }
.tx-form__block { display: flex; flex-direction: column; gap: var(--space-1); margin-bottom: var(--space-4); }
.tx-form__label { font-size: var(--text-sm); font-weight: 600; line-height: 1.4; }
.tx-form__kinds { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.tx-form__static { margin: 0; overflow-wrap: anywhere; }
.tx-form__amount { display: flex; align-items: center; gap: var(--space-2); }
.tx-form__amount :deep(.ui-input) { flex: 1; min-width: 0; }
.tx-form__suffix { flex: none; font-weight: 600; color: var(--color-text-muted); }
</style>
