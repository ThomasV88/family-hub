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
import { INTL_LOCALES } from '../i18n.js';
import { categoryLabel, formatDay } from './labels.js';
import { exponentOf, parseMoney, toInputString } from './money.js';

// New or edit panel for one monthly category budget. The limit applies from the selected month; progress numbers
// never come from here (the server owns spent, percent and the over amount).
const props = defineProps({
    open: { type: Boolean, default: false },
    finance: { type: Object, required: true },
    // { mode: 'new', currency } | { id, categoryId, currency, limitMinor, ... } | null
    budget: { type: Object, default: null },
    // overview blocks: [{ currency, budgets: [{ categoryId }] }], used to hide categories that already have a budget
    blocks: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);
const { t, te, locale } = useI18n();

const creating = computed(() => !props.budget || props.budget.mode === 'new');
const mixed = computed(() => creating.value && props.finance.selectedAccount === 'all' && (props.finance.currencies ?? []).length > 1);

const form = ref({ category_id: '', currency: 'EUR', amount: '' });
const errors = ref({});
const serverError = ref(false);
const processing = ref(false);
const removeOpen = ref(false);

const expenseCategories = computed(() => (props.finance.categories ?? []).filter((c) => c.kind === 'expense'));
const takenIds = (currency) => new Set((props.blocks.find((b) => b.currency === currency)?.budgets ?? []).map((b) => b.categoryId));
const categoryOptions = computed(() => expenseCategories.value
    .filter((c) => !takenIds(form.value.currency).has(c.id))
    .map((c) => ({ value: c.id, label: categoryLabel(c, t) })));
const currencyOptions = computed(() => (props.finance.currencies ?? []).map((code) => ({ value: code, label: code })));
const exponent = computed(() => exponentOf(form.value.currency));
const editCategory = computed(() => categoryLabel(expenseCategories.value.find((c) => c.id === props.budget?.categoryId) ?? null, t));
const monthLabel = computed(() => formatDay(`${props.finance.month}-01`, INTL_LOCALES[locale.value] ?? locale.value, { month: 'long', year: 'numeric' }));

function initial() {
    if (creating.value) {
        const currency = props.budget?.currency ?? props.finance.currencies?.[0] ?? 'EUR';
        const free = expenseCategories.value.find((c) => !takenIds(currency).has(c.id));
        return { category_id: free?.id ?? '', currency, amount: '' };
    }
    const currency = props.budget.currency;
    return {
        category_id: props.budget.categoryId,
        currency,
        amount: props.budget.limitMinor ? toInputString(props.budget.limitMinor, exponentOf(currency), locale.value) : '',
    };
}
watch(() => [props.budget?.id ?? props.budget?.mode ?? null, props.open], () => {
    form.value = initial();
    errors.value = {};
    serverError.value = false;
}, { immediate: true });
watch(() => form.value.currency, () => {
    if (creating.value && takenIds(form.value.currency).has(form.value.category_id)) {
        form.value.category_id = categoryOptions.value[0]?.value ?? '';
    }
});

function validate() {
    const next = {};
    if (creating.value && form.value.category_id === '') next.category_id = t('finance.error.category');
    if (parseMoney(form.value.amount, exponent.value) === null) next.amount = t('finance.budget.err_amount', { digits: exponent.value });
    return next;
}
const message = (value) => (typeof value === 'string' && te(value) ? t(value, { digits: exponent.value }) : t('finance.budget.err_save'));
const FIELDS = { category_id: 'category_id', amount: 'amount', currency: 'category_id' };

function save() {
    const local = validate();
    errors.value = local;
    if (Object.keys(local).length) return;
    const amount = form.value.amount.trim();
    const options = {
        preserveScroll: true,
        onStart: () => { processing.value = true; serverError.value = false; },
        onSuccess: () => emit('close'),
        onError: (errs) => {
            const mapped = {};
            let unknown = false;
            for (const [key, value] of Object.entries(errs ?? {})) {
                if (FIELDS[key]) mapped[FIELDS[key]] = message(value);
                else unknown = true;
            }
            errors.value = mapped;
            serverError.value = unknown || Object.keys(mapped).length === 0;
        },
        onHttpException: () => { serverError.value = true; },
        onNetworkError: () => { serverError.value = true; },
        onFinish: () => { processing.value = false; },
    };
    if (creating.value) {
        router.post('/finance/budgets', { category_id: form.value.category_id, currency: form.value.currency, amount, from_month: props.finance.month }, options);
    } else {
        router.patch(`/finance/budgets/${props.budget.id}`, { amount, from_month: props.finance.month }, options);
    }
}
function confirmRemove() {
    router.delete(`/finance/budgets/${props.budget.id}`, { data: { from_month: props.finance.month }, preserveScroll: true, onSuccess: () => emit('close') });
}
const onEnter = (event) => { if (event.target?.tagName === 'INPUT') event.preventDefault(); };
</script>

<template>
    <UiDetailPanel :open="open && budget !== null" :title="creating ? t('finance.budget.panel_new') : t('finance.budget.panel_edit')" @update:open="(v) => (v ? null : emit('close'))">
        <form novalidate data-testid="budget-form" class="bud-form" @submit.prevent="save" @keydown.enter="onEnter">
            <UiAlert v-if="serverError" variant="danger" live>{{ t('finance.budget.err_save') }}</UiAlert>

            <UiField v-if="creating" :label="t('finance.budget.field_category')" required :error="errors.category_id">
                <UiSelect v-model="form.category_id" :options="categoryOptions" />
            </UiField>
            <div v-else class="bud-form__static">
                <span class="bud-form__label">{{ t('finance.budget.field_category') }}</span>
                <p data-testid="category-static">{{ editCategory }}</p>
            </div>

            <UiField v-if="mixed" :label="t('finance.acc.field_currency')">
                <UiSelect v-model="form.currency" :options="currencyOptions" />
            </UiField>

            <UiField :label="t('finance.budget.field_limit')" required :hint="t('finance.budget.hint', { month: monthLabel })" :error="errors.amount">
                <div class="bud-form__amount">
                    <UiInput v-model="form.amount" inputmode="decimal" autocomplete="off" />
                    <span class="bud-form__suffix" data-testid="currency-suffix">{{ form.currency }}</span>
                </div>
            </UiField>
        </form>
        <template #footer>
            <UiButton v-if="!creating" variant="ghost" :icon="Trash2" class="bud-form__remove" @click="removeOpen = true">{{ t('finance.budget.remove') }}</UiButton>
            <UiButton variant="secondary" @click="emit('close')">{{ t('finance.close') }}</UiButton>
            <UiButton variant="primary" :loading="processing" @click="save">{{ t('finance.budget.save') }}</UiButton>
        </template>
    </UiDetailPanel>

    <UiConfirmDialog
        v-model:open="removeOpen"
        :title="t('finance.budget.remove_title')"
        :body="t('finance.budget.remove_body', { category: editCategory, month: monthLabel })"
        :confirm-label="t('finance.budget.remove')"
        :cancel-label="t('finance.budget.keep')"
        @confirm="confirmRemove"
    />
</template>

<style scoped>
.bud-form { display: flex; flex-direction: column; gap: var(--space-4); }
.bud-form__static p { margin: 0; }
.bud-form__label { font-size: var(--text-sm); font-weight: 600; color: var(--color-text-muted); }
.bud-form__amount { display: flex; align-items: center; gap: var(--space-2); }
.bud-form__suffix { font-weight: 600; color: var(--color-text-muted); }
.bud-form__remove { margin-inline-end: auto; color: var(--color-danger); }
</style>
