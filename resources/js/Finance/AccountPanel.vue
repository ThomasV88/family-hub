<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiCheckbox from '../Components/ui/UiCheckbox.vue';
import UiDetailPanel from '../Components/ui/UiDetailPanel.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import UiSelect from '../Components/ui/UiSelect.vue';
import { CURRENCIES, parseMoney, toInputString } from './money.js';

// Admin account panel: metadata and "Visible to". Money fields exist only when the admin is ticked on the account
// (account.opening is non-null); otherwise they are neither rendered nor sent.
const props = defineProps({
    // null creates a new account.
    account: { type: Object, default: null },
    // Every member: [{ id, name, financeAccess }]
    members: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);
const { t, locale } = useI18n();

const isNew = computed(() => props.account === null);
const opening = computed(() => props.account?.opening ?? null);
const showOpening = computed(() => isNew.value || opening.value !== null);
const locked = computed(() => !isNew.value && opening.value?.currencyLocked === true);
const typeOptions = computed(() => ['bank', 'cash', 'savings', 'card'].map((value) => ({ value, label: t(`finance.acc.type_${value}`) })));
const currencyOptions = Object.keys(CURRENCIES).map((value) => ({ value, label: value }));

const today = () => new Date().toISOString().slice(0, 10);
function initial() {
    const data = {
        name: props.account?.name ?? '',
        type: props.account?.type ?? 'bank',
        member_ids: [...(props.account?.memberIds ?? [])],
    };
    if (isNew.value) {
        return { ...data, currency: 'EUR', opening_balance: '', balance_as_of: today() };
    }
    if (opening.value) {
        const base = { ...data, opening_balance: toInputString(opening.value.minor, 2, locale.value), balance_as_of: opening.value.asOf };
        return locked.value ? base : { ...base, currency: props.account.currency };
    }
    return data;
}
const form = useForm(initial());
const failed = ref(false);
const localErrors = ref({});

const tr = (key) => (key ? t(key, { digits: 2 }) : '');
const err = (field) => tr(localErrors.value[field] ?? form.errors?.[field]);
const memberError = computed(() => {
    const key = Object.keys(form.errors ?? {}).find((k) => k === 'member_ids' || k.startsWith('member_ids.'));
    return key ? tr(form.errors[key]) : '';
});

const checked = (id) => form.member_ids.includes(id);
const canTick = (member) => member.financeAccess || checked(member.id);
function toggle(id, on) {
    const without = form.member_ids.filter((x) => x !== id);
    form.member_ids = on ? [...without, id] : without;
}
const anyDisabled = computed(() => props.members.some((m) => !canTick(m)));

const ZERO = /^\s*[-−]?\s*0+(?:[.,]0+)?\s*$/;
function validate() {
    const errors = {};
    if (!form.name.trim()) errors.name = 'finance.acc.err_name';
    if (showOpening.value) {
        const raw = String(form.opening_balance ?? '');
        if (raw.trim() !== '' && !ZERO.test(raw) && parseMoney(raw, 2, { allowNegative: true }) === null) errors.opening_balance = 'finance.error.amount';
        if (!/^\d{4}-\d{2}-\d{2}$/.test(String(form.balance_as_of ?? ''))) errors.balance_as_of = 'finance.error.date';
    }
    localErrors.value = errors;
    return Object.keys(errors).length === 0;
}

function submit() {
    failed.value = false;
    if (!validate()) return;
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onError: () => { failed.value = true; },
        onHttpException: () => { failed.value = true; },
        onNetworkError: () => { failed.value = true; },
    };
    if (isNew.value) form.post('/settings/finance-accounts', options);
    else form.patch(`/settings/finance-accounts/${props.account.id}`, options);
}
</script>

<template>
    <UiDetailPanel :open="true" :title="isNew ? t('finance.acc.panel_new') : t('finance.acc.panel_edit')" @update:open="(open) => !open && emit('close')">
        <form id="finance-account-form" class="acc-panel" novalidate @submit.prevent="submit">
            <UiAlert v-if="failed" variant="danger" live>{{ t('finance.acc.err_save') }}</UiAlert>
            <UiField :label="t('finance.acc.field_name')" :error="err('name')" required>
                <UiInput v-model="form.name" maxlength="60" autocomplete="off" />
            </UiField>
            <UiField :label="t('finance.acc.field_type')" :error="err('type')">
                <UiSelect v-model="form.type" :options="typeOptions" />
            </UiField>
            <UiField
                :label="t('finance.acc.field_currency')"
                :hint="locked ? t('finance.acc.currency_locked') : ''"
                :error="err('currency')"
            >
                <UiSelect
                    :model-value="locked || (!isNew && !showOpening) ? (account?.currency ?? 'EUR') : form.currency"
                    :options="currencyOptions"
                    :disabled="locked || (!isNew && !showOpening)"
                    @update:model-value="form.currency = $event"
                />
            </UiField>
            <template v-if="showOpening">
                <UiField :label="t('finance.acc.field_opening')" :error="err('opening_balance')">
                    <UiInput v-model="form.opening_balance" inputmode="decimal" autocomplete="off" />
                </UiField>
                <UiField :label="t('finance.acc.field_opening_date')" :error="err('balance_as_of')">
                    <UiInput v-model="form.balance_as_of" type="date" />
                </UiField>
            </template>
            <fieldset class="acc-panel__members">
                <legend class="acc-panel__legend">{{ t('finance.acc.visible_legend') }}</legend>
                <p class="acc-panel__hint">{{ t('finance.acc.visible_hint') }}</p>
                <ul class="acc-panel__list" role="list">
                    <li v-for="member in members" :key="member.id">
                        <UiCheckbox :model-value="checked(member.id)" :disabled="!canTick(member)" @update:model-value="toggle(member.id, $event)">
                            {{ member.name }}<span v-if="!canTick(member)" class="acc-panel__no-access"> ({{ t('finance.acc.no_access') }})</span>
                        </UiCheckbox>
                    </li>
                </ul>
                <p v-if="anyDisabled" class="acc-panel__hint">{{ t('finance.acc.no_access_hint') }}</p>
                <p v-if="memberError" class="acc-panel__error" role="alert">{{ memberError }}</p>
            </fieldset>
        </form>
        <template #footer>
            <UiButton variant="ghost" @click="emit('close')">{{ t('ui.close') }}</UiButton>
            <UiButton type="submit" form="finance-account-form" variant="primary" :loading="form.processing" :disabled="form.processing">{{ t('finance.acc.save') }}</UiButton>
        </template>
    </UiDetailPanel>
</template>

<style scoped>
.acc-panel { display: flex; flex-direction: column; gap: var(--space-4); }
.acc-panel__members { margin: 0; padding: 0; border: 0; min-width: 0; display: flex; flex-direction: column; gap: var(--space-2); }
.acc-panel__legend { padding: 0; font-size: var(--text-base); font-weight: 600; }
.acc-panel__hint { margin: 0; color: var(--color-text-muted); font-size: var(--text-sm); }
.acc-panel__list { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: var(--space-1); }
.acc-panel__no-access { color: var(--color-text-muted); }
.acc-panel__error { margin: 0; color: var(--color-danger); font-size: var(--text-sm); }
</style>
