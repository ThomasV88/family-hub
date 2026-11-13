<script setup>
import { computed, onBeforeUnmount, reactive, ref, shallowRef } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShell from '../../Layouts/AppShell.vue';
import UiConfirmDialog from '../../Components/ui/UiConfirmDialog.vue';
import ImportHistory from '../../Finance/import/ImportHistory.vue';
import ImportMappingStep from '../../Finance/import/ImportMappingStep.vue';
import ImportStepper from '../../Finance/import/ImportStepper.vue';
import ImportUploadStep from '../../Finance/import/ImportUploadStep.vue';
import { checkFile, preview, serverMessage } from '../../Finance/import/importApi.js';

// Steps File and Columns plus the history list. The chosen File and everything decoded from it live in component
// memory only; nothing is written to browser storage. The server stages a draft batch on "Preview rows".
const props = defineProps({
    finance: { type: Object, required: true },
    import: { type: Object, required: true },
});
const { t } = useI18n();
// `import` is a reserved word in template expressions, so the template reads the prop through this alias.
const imp = computed(() => props.import);

const config = computed(() => ({
    encodings: props.import.encodings,
    dateFormats: props.import.dateFormats,
    delimiters: props.import.delimiters,
}));
const maxBytes = computed(() => props.import.limits.maxBytes);
const accounts = computed(() => props.import.accounts);

const step = ref('file');
const file = shallowRef(null);
const accountId = ref(
    accounts.value.some((a) => a.id === props.finance.selectedAccount) ? props.finance.selectedAccount : (accounts.value.length === 1 ? accounts.value[0].id : ''),
);
const profileId = ref(props.import.profiles[0]?.id ?? '');
const fileError = ref('');
const busy = ref(false);

const headers = ref([]);
const sample = ref([]);
const columnCount = ref(0);
const suggested = ref(null);
const previewBusy = ref(false);
const errors = ref({});
const alertText = ref('');
const submitting = ref(false);

const ROLES = ['date', 'amount', 'income', 'expense', 'indicator', 'description', 'counterparty', 'reference', 'currency'];
const emptyCols = () => Object.fromEntries(ROLES.map((r) => [r, '']));
const settings = ref(blankSettings());

function blankSettings() {
    return {
        delimiter: ';', encoding: 'UTF-8', decimalSeparator: ',', groupingSeparator: '', dateFormat: props.import.dateFormats[0],
        hasHeader: true, headerRow: 1, amountMode: 'single', cols: emptyCols(), indicatorExpense: '', indicatorIncome: '',
        saveProfile: false, profileName: '',
    };
}

const dirty = computed(() => file.value !== null);
const current = computed(() => step.value);

// ----- step 1 -----
function onFile(f) {
    file.value = f;
    const code = f ? checkFile(f, maxBytes.value) : null;
    fileError.value = code ? t(`finance.imp.err_${code}`) : '';
}

function previewError(code) {
    if (code === 'decode_failed') return t('finance.imp.err_decode');
    if (code === 'too_large') return t('finance.imp.err_size');
    if (code === 'too_many_rows') return t('finance.imp.err_rows', { max: props.import.limits.maxRows });
    return t('finance.imp.err_read');
}

function applyPreview(data) {
    headers.value = data.headers ?? [];
    sample.value = data.sample ?? [];
    columnCount.value = Math.max(headers.value.length, ...sample.value.map((r) => r.length), 0);
    suggested.value = data.suggested ?? null;
}

async function readFile() {
    const code = file.value ? checkFile(file.value, maxBytes.value) : 'type';
    if (code) {
        fileError.value = t(`finance.imp.err_${code}`);
        return;
    }
    fileError.value = '';
    const saved = props.import.profiles.find((p) => p.id === profileId.value);
    busy.value = true;
    if (saved) {
        router.post('/finance/import', { file: file.value, account_id: accountId.value, profile_id: saved.id }, {
            forceFormData: true,
            onError: (errs) => {
                if (errs.profile_id || errs.settings) {
                    openColumns(saved);
                } else {
                    fileError.value = serverMessage(errs.file ?? errs.account_id, t, { max: props.import.limits.maxRows });
                    busy.value = false;
                }
            },
            onFinish: () => { if (step.value === 'file' && !alertText.value) busy.value = false; },
        });
        return;
    }
    await openColumns(null);
}

async function openColumns(saved) {
    const first = saved ? { encoding: saved.settings.encoding, delimiter: saved.settings.delimiter, headerRow: saved.settings.headerRow } : {};
    const result = await preview(file.value, first);
    busy.value = false;
    if (!result.ok) {
        fileError.value = previewError(result.error);
        return;
    }
    const data = result.data;
    applyPreview(data);
    errors.value = {};
    const next = blankSettings();
    next.encoding = props.import.encodings.includes(data.encoding) ? data.encoding : 'UTF-8';
    next.delimiter = props.import.delimiters.includes(data.delimiter) ? data.delimiter : next.delimiter;
    next.hasHeader = (data.headerRow ?? 0) > 0;
    next.headerRow = data.headerRow > 0 ? data.headerRow : 1;
    if (saved?.settings && Object.keys(saved.settings).length) {
        const s = saved.settings;
        next.decimalSeparator = s.decimalSeparator;
        next.groupingSeparator = s.groupingSeparator ?? '';
        next.dateFormat = s.dateFormat;
        next.amountMode = s.amountMode;
        next.hasHeader = s.headerRow > 0;
        next.headerRow = s.headerRow > 0 ? s.headerRow : 1;
        next.indicatorExpense = (s.indicatorExpense ?? []).join(', ');
        next.indicatorIncome = (s.indicatorIncome ?? []).join(', ');
        for (const [role, col] of Object.entries(s.columns ?? {})) {
            const byName = col.name ? headers.value.findIndex((h) => h === col.name) : -1;
            const idx = byName >= 0 ? byName : col.index;
            if (idx !== null && idx !== undefined && idx < columnCount.value) next.cols[role] = String(idx);
        }
    }
    settings.value = next;
    alertText.value = saved ? t('finance.imp.err_profile') : '';
    step.value = 'columns';
}

// ----- step 2 -----
let token = 0;
async function reparse(next) {
    const mine = ++token;
    previewBusy.value = true;
    const result = await preview(file.value, { encoding: next.encoding, delimiter: next.delimiter, headerRow: next.hasHeader ? next.headerRow : 0 });
    if (mine !== token) return;
    previewBusy.value = false;
    const { encoding: _e, general: _g, ...rest } = errors.value;
    errors.value = rest;
    if (!result.ok) {
        errors.value = result.error === 'decode_failed' ? { ...rest, encoding: previewError('decode_failed') } : { ...rest, general: previewError(result.error) };
        return;
    }
    applyPreview(result.data);
    const cols = { ...settings.value.cols };
    for (const role of ROLES) if (cols[role] !== '' && Number(cols[role]) >= columnCount.value) cols[role] = '';
    settings.value = { ...settings.value, cols };
}

function validate() {
    const s = settings.value;
    const e = {};
    if (s.cols.date === '') e.date = t('finance.imp.err_map_date');
    if (s.cols.description === '') e.description = t('finance.imp.err_map_description');
    if (s.amountMode === 'split') {
        if (s.cols.income === '' || s.cols.expense === '') e.amount = t('finance.imp.err_map_amount');
    } else if (s.cols.amount === '') {
        e.amount = t('finance.imp.err_map_amount');
    }
    if (s.amountMode === 'indicator' && (s.cols.indicator === '' || (tokens(s.indicatorExpense).length === 0 && tokens(s.indicatorIncome).length === 0))) {
        e.indicator = t('finance.imp.err_map_indicator');
    }
    if (s.saveProfile && s.profileName.trim() === '') e.profileName = t('finance.imp.err_profile_name');
    return e;
}
const tokens = (text) => text.split(',').map((v) => v.trim()).filter((v) => v !== '');

function spec() {
    const s = settings.value;
    const active = ['date', 'description', 'counterparty', 'reference', 'currency'];
    if (s.amountMode === 'split') active.push('income', 'expense');
    else active.push('amount');
    if (s.amountMode === 'indicator') active.push('indicator');
    const columns = {};
    for (const role of active) {
        if (s.cols[role] === '') continue;
        const index = Number(s.cols[role]);
        columns[role] = { name: s.hasHeader && headers.value[index] ? headers.value[index] : null, index };
    }
    return {
        delimiter: s.delimiter, encoding: s.encoding, decimalSeparator: s.decimalSeparator, groupingSeparator: s.groupingSeparator === '' ? null : s.groupingSeparator,
        dateFormat: s.dateFormat, headerRow: s.hasHeader ? s.headerRow : 0, amountMode: s.amountMode, columns,
        indicatorExpense: s.amountMode === 'indicator' ? tokens(s.indicatorExpense) : [], indicatorIncome: s.amountMode === 'indicator' ? tokens(s.indicatorIncome) : [],
    };
}

function submit() {
    errors.value = validate();
    if (Object.keys(errors.value).length) return;
    submitting.value = true;
    // Settings travel as one JSON string: multipart fields are trimmed, which would erase tab and space values.
    const data = { file: file.value, account_id: accountId.value, settings: JSON.stringify(spec()) };
    if (settings.value.saveProfile) {
        data.save_profile = '1';
        data.profile_name = settings.value.profileName.trim();
    }
    router.post('/finance/import', data, {
        forceFormData: true,
        onError: (errs) => {
            const e = {};
            if (errs.profile_name) e.profileName = t('finance.imp.err_profile_name');
            if (errs.settings) e.general = t('finance.imp.err_profile');
            if (errs.file) e.general = serverMessage(errs.file, t, { max: props.import.limits.maxRows });
            if (errs.account_id) e.general = serverMessage(errs.account_id, t);
            errors.value = Object.keys(e).length ? e : { general: t('finance.imp.err_read') };
        },
        onFinish: () => { submitting.value = false; },
    });
}

function backToFile() {
    token++;
    step.value = 'file';
    alertText.value = '';
    errors.value = {};
}

// ----- leave guard -----
const leaveOpen = ref(false);
let pendingUrl = null;
let allowLeave = false;
const off = router.on?.('before', (event) => {
    const visit = event?.detail?.visit;
    if (!dirty.value || allowLeave || !visit || visit.method !== 'get') return undefined;
    pendingUrl = visit.url?.href ?? String(visit.url);
    leaveOpen.value = true;
    return false;
});
onBeforeUnmount(() => { if (typeof off === 'function') off(); });
function leave() {
    allowLeave = true;
    if (pendingUrl) router.visit(pendingUrl);
}
</script>

<template>
    <AppShell current="finances">
        <div class="imp-page" data-testid="import-page">
            <header class="imp-page__header">
                <h1 class="imp-page__title">{{ t('finance.imp.title') }}</h1>
                <ImportStepper :current="current" />
            </header>
            <ImportUploadStep
                v-if="step === 'file'"
                :accounts="accounts"
                :profiles="imp.profiles"
                :account="accountId"
                :profile="profileId"
                :file="file"
                :file-error="fileError"
                :busy="busy"
                @update:account="accountId = $event"
                @update:profile="profileId = $event"
                @file="onFile"
                @read="readFile"
            />
            <ImportMappingStep
                v-else
                v-model:settings="settings"
                :config="config"
                :headers="headers"
                :sample="sample"
                :column-count="columnCount"
                :suggested="suggested"
                :busy="previewBusy"
                :errors="errors"
                :alert="alertText"
                :submitting="submitting"
                @parse="reparse"
                @submit="submit"
                @back="backToFile"
            />
            <ImportHistory v-if="step === 'file'" :items="imp.history" :timezone="finance.timezone" :can-edit="finance.canEdit" />
            <UiConfirmDialog
                v-model:open="leaveOpen"
                :title="t('finance.imp.leave_title')"
                :body="t('finance.imp.leave_body')"
                :confirm-label="t('finance.imp.leave_confirm')"
                :cancel-label="t('finance.imp.leave_stay')"
                @confirm="leave"
            />
        </div>
    </AppShell>
</template>

<style scoped>
.imp-page { display: flex; flex-direction: column; gap: var(--space-5); max-width: 960px; }
.imp-page__header { display: flex; flex-direction: column; gap: var(--space-3); }
.imp-page__title { margin: 0; font-size: var(--text-2xl, 1.75rem); font-weight: 600; line-height: 1.2; }
</style>
