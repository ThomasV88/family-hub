<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiConfirmDialog from '../Components/ui/UiConfirmDialog.vue';
import UiDetailPanel from '../Components/ui/UiDetailPanel.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import UiSelect from '../Components/ui/UiSelect.vue';
import RecurrenceFields from '../Todo/RecurrenceFields.vue';
import { emptyRepeat, intervalValid, patternFromRepeat, repeatFromTask } from '../Todo/recurrenceState.js';
import { categoryLabel } from './labels.js';
import { exponentOf, parseMoney, toInputString } from './money.js';

// New or edit form for one recurring expense (schedule mode only). The account is chosen on create and read-only
// afterwards: the edit payload never carries account_id or kind (immutable server-side).
const props = defineProps({
    open: { type: Boolean, default: false },
    finance: { type: Object, required: true },
    // { mode: 'new' } | item | null
    item: { type: Object, default: null },
});
const emit = defineEmits(['close']);
const { t, te, locale } = useI18n();

const WINDOWS = [1, 2, 3, 5, 7, 10, 14];
const creating = computed(() => !props.item || props.item.mode === 'new');
const accounts = computed(() => props.finance.accounts ?? []);
const accountById = (id) => accounts.value.find((a) => a.id === id) ?? null;

function initial() {
    const i = creating.value ? null : props.item;
    const account = i?.accountId ?? (accountById(props.finance.selectedAccount) ? props.finance.selectedAccount : accounts.value[0]?.id ?? '');
    const currency = accountById(account)?.currency ?? i?.currency ?? 'EUR';
    const exp = exponentOf(currency);
    return {
        name: i?.name ?? '',
        amount: i ? toInputString(i.expectedMinor, exp, locale.value) : '',
        category_id: i?.categoryId ?? '',
        account_id: account,
        starts_on: i?.startsOn ?? props.finance.today,
        repeat: i ? repeatFromTask(i.pattern, 'schedule') : { ...emptyRepeat(), kind: 'monthly' },
        ends_on: i?.endsOn ?? '',
        text_hint: i?.textHint ?? '',
        tolerance: toInputString(i?.toleranceMinor ?? '0', exp, locale.value),
        window_days: String(i?.windowDays ?? 5),
    };
}
const form = ref(initial());
const errors = ref({});
const serverError = ref(false);
const processing = ref(false);
const deleteOpen = ref(false);
const body = ref(null);
watch(() => [props.item?.id ?? props.item?.mode ?? null, props.open], () => {
    form.value = initial();
    errors.value = {};
    serverError.value = false;
});

const currency = computed(() => (creating.value ? accountById(form.value.account_id)?.currency : props.item?.currency) ?? 'EUR');
const exponent = computed(() => exponentOf(currency.value));
const accountName = computed(() => accountById(form.value.account_id)?.name ?? '');
const accountOptions = computed(() => accounts.value.map((a) => ({ value: a.id, label: a.name })));
const categoryOptions = computed(() => [
    { value: '', label: t('finance.tx.uncategorized') },
    ...(props.finance.categories ?? []).filter((c) => c.kind === 'expense').map((c) => ({ value: c.id, label: categoryLabel(c, t) })),
]);
const windowOptions = WINDOWS.map((n) => ({ value: String(n), label: t('finance.rec.window_days', { n }, n) }));

const DATE = /^\d{4}-\d{2}-\d{2}$/;
function validate() {
    const f = form.value;
    const next = {};
    if (f.name.trim() === '') next.name = t('finance.rec.err_name');
    const minor = parseMoney(f.amount, exponent.value);
    if (minor === null) next.amount = t('finance.error.amount', { digits: exponent.value });
    if (!DATE.test(f.starts_on)) next.starts_on = t('finance.error.date');
    if (f.ends_on !== '' && (!DATE.test(f.ends_on) || f.ends_on < f.starts_on)) next.ends_on = t('finance.rec.err_ends');
    if (creating.value && f.account_id === '') next.account_id = t('finance.error.account');
    if (f.repeat.kind === 'custom' && !intervalValid(f.repeat.interval)) next.recurrence = t('todo.error.interval');
    if (f.repeat.kind === 'weekly' && f.repeat.weekdays.length === 0) next.recurrence = t('todo.error.weekdays');
    if (f.tolerance.trim() !== '' && parseMoney(f.tolerance, exponent.value) === null && !/^0+([.,]0+)?$/.test(f.tolerance.trim())) next.tolerance = t('finance.error.amount', { digits: exponent.value });
    return next;
}

function payload() {
    const f = form.value;
    const out = {
        name: f.name.trim(),
        amount: f.amount.trim(),
        category_id: f.category_id === '' ? null : f.category_id,
        starts_on: f.starts_on,
        ends_on: f.ends_on === '' ? null : f.ends_on,
        recurrence: patternFromRepeat(f.repeat, f.starts_on),
        window_days: Number(f.window_days),
        tolerance: f.tolerance.trim() === '' ? '0' : f.tolerance.trim(),
        text_hint: f.text_hint.trim() === '' ? null : f.text_hint.trim(),
    };
    if (creating.value) out.account_id = f.account_id;
    return out;
}

const FIELD_OF = { name: 'name', amount: 'amount', starts_on: 'starts_on', ends_on: 'ends_on', account_id: 'account_id', recurrence: 'recurrence', 'recurrence.interval': 'recurrence', 'recurrence.weekdays': 'recurrence', tolerance: 'tolerance', text_hint: 'text_hint', window_days: 'window_days', category_id: 'category_id' };
const message = (value) => (typeof value === 'string' && te(value) ? t(value, { digits: exponent.value }) : t('finance.rec.err_save'));

function save() {
    const local = validate();
    errors.value = local;
    if (Object.keys(local).length) {
        setTimeout(() => body.value?.querySelector('[aria-invalid="true"]')?.focus(), 0);
        return;
    }
    const options = {
        preserveScroll: true,
        onStart: () => { processing.value = true; serverError.value = false; },
        onError: (errs) => {
            const mapped = {};
            let unknown = false;
            for (const [key, value] of Object.entries(errs ?? {})) {
                if (FIELD_OF[key]) mapped[FIELD_OF[key]] = message(value);
                else unknown = true;
            }
            errors.value = mapped;
            serverError.value = unknown || Object.keys(mapped).length === 0;
        },
        onHttpException: () => { serverError.value = true; },
        onNetworkError: () => { serverError.value = true; },
        onFinish: () => { processing.value = false; },
    };
    if (creating.value) router.post('/finance/recurring', payload(), options);
    else router.patch(`/finance/recurring/${props.item.id}`, payload(), options);
}
function confirmDelete() {
    router.delete(`/finance/recurring/${props.item.id}`, { preserveScroll: true });
}
const onEnter = (event) => { if (event.target?.tagName === 'INPUT') event.preventDefault(); };
</script>

<template>
    <UiDetailPanel :open="open && item !== null" :title="creating ? t('finance.panel.rec_new') : t('finance.panel.rec_edit')" @update:open="(v) => (v ? null : emit('close'))">
        <div ref="body" data-testid="recurring-form">
            <form novalidate @submit.prevent="save" @keydown.enter="onEnter">
                <UiAlert v-if="serverError" variant="danger" live class="rec-form__alert">{{ t('finance.rec.err_save') }}</UiAlert>

                <UiField :label="t('finance.rec.field_name')" required :error="errors.name">
                    <UiInput v-model="form.name" maxlength="120" autocomplete="off" />
                </UiField>

                <UiField :label="t('finance.rec.field_amount')" required :error="errors.amount">
                    <div class="rec-form__amount">
                        <UiInput v-model="form.amount" inputmode="decimal" autocomplete="off" />
                        <span class="rec-form__suffix" data-testid="currency-suffix">{{ currency }}</span>
                    </div>
                </UiField>

                <UiField :label="t('finance.field.category')" :error="errors.category_id">
                    <UiSelect v-model="form.category_id" :options="categoryOptions" />
                </UiField>

                <UiField v-if="creating" :label="t('finance.field.account')" required :error="errors.account_id">
                    <UiSelect v-model="form.account_id" :options="accountOptions" />
                </UiField>
                <div v-else class="rec-form__static">
                    <span class="rec-form__label">{{ t('finance.field.account') }}</span>
                    <p data-testid="account-static">{{ accountName }}</p>
                </div>

                <UiField :label="t('finance.rec.field_first')" required :error="errors.starts_on">
                    <UiInput v-model="form.starts_on" type="date" />
                </UiField>

                <RecurrenceFields v-model="form.repeat" :due-date="form.starts_on" :errors="{ interval: errors.recurrence, weekdays: errors.recurrence }" :show-mode="false" :allow-none="false" />
                <p v-if="errors.recurrence && !(['custom', 'weekly'].includes(form.repeat.kind))" class="rec-form__error" role="alert">{{ errors.recurrence }}</p>
                <p class="rec-form__hint">{{ t('finance.rec.clamp') }}</p>

                <UiField :label="t('finance.rec.field_ends')" :error="errors.ends_on">
                    <UiInput v-model="form.ends_on" type="date" />
                </UiField>

                <fieldset class="rec-form__hints">
                    <legend>{{ t('finance.rec.hints_title') }}</legend>
                    <UiField :label="t('finance.rec.hint_text')" :hint="t('finance.rec.hint_text_help')" :error="errors.text_hint">
                        <UiInput v-model="form.text_hint" maxlength="120" autocomplete="off" />
                    </UiField>
                    <UiField :label="t('finance.rec.hint_tolerance')" :hint="t('finance.rec.hint_tolerance_help')" :error="errors.tolerance">
                        <div class="rec-form__amount">
                            <UiInput v-model="form.tolerance" inputmode="decimal" autocomplete="off" />
                            <span class="rec-form__suffix">{{ currency }}</span>
                        </div>
                    </UiField>
                    <UiField :label="t('finance.rec.hint_window')" :hint="t('finance.rec.hint_window_help')" :error="errors.window_days">
                        <UiSelect v-model="form.window_days" :options="windowOptions" />
                    </UiField>
                </fieldset>
                <p class="rec-form__hint">{{ t('finance.rec.future_note') }}</p>
            </form>
        </div>

        <template #footer>
            <UiButton v-if="!creating" variant="ghost" :icon="Trash2" class="rec-form__delete" @click="deleteOpen = true">{{ t('finance.rec.delete') }}</UiButton>
            <UiButton variant="secondary" @click="emit('close')">{{ t('finance.close') }}</UiButton>
            <UiButton variant="primary" :loading="processing" @click="save">{{ t('finance.rec.save') }}</UiButton>
        </template>
    </UiDetailPanel>

    <UiConfirmDialog
        v-model:open="deleteOpen"
        :title="t('finance.rec.delete')"
        :body="t('finance.rec.delete_body', { name: item?.name ?? '' })"
        :confirm-label="t('finance.rec.delete')"
        :cancel-label="t('finance.rec.delete_keep')"
        @confirm="confirmDelete"
    />
</template>

<style scoped>
.rec-form__alert { margin-bottom: var(--space-4); }
.rec-form__amount { display: flex; align-items: center; gap: var(--space-2); }
.rec-form__amount :deep(.ui-input) { flex: 1; min-width: 0; }
.rec-form__suffix { flex: none; font-weight: 600; color: var(--color-text-muted); }
.rec-form__static { margin-bottom: var(--space-4); }
.rec-form__static p { margin: 0; overflow-wrap: anywhere; }
.rec-form__label { font-size: var(--text-sm); font-weight: 600; }
.rec-form__hint { margin: 0 0 var(--space-4); font-size: var(--text-sm); color: var(--color-text-muted); }
.rec-form__error { margin: 0 0 var(--space-2); color: var(--color-danger); font-size: var(--text-sm); }
.rec-form__hints { margin: 0 0 var(--space-2); padding: 0; border: 0; min-width: 0; }
.rec-form__hints legend { padding: 0; margin-bottom: var(--space-2); font-size: var(--text-base); font-weight: 600; }
.rec-form__delete { margin-inline-end: auto; color: var(--color-danger); }
</style>
