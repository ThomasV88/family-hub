<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiInput from '../../Components/ui/UiInput.vue';
import UiRadio from '../../Components/ui/UiRadio.vue';
import UiSelect from '../../Components/ui/UiSelect.vue';
import UiSwitch from '../../Components/ui/UiSwitch.vue';
import { useFrameWidth } from '../../Shell/shellState.js';
import { dateFormatLabel, delimiterKey, encodingLabel } from './importApi.js';

// Step 2. The sample table shows the first rows exactly as decoded, text only (cells that start with = + - @ stay
// plain text). Every parse-relevant change is reported to the parent, which re-sends the file to the preview route.
const props = defineProps({
    settings: { type: Object, required: true },
    config: { type: Object, required: true },
    headers: { type: Array, default: () => [] },
    sample: { type: Array, default: () => [] },
    columnCount: { type: Number, default: 0 },
    suggested: { type: Object, default: null },
    busy: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
    alert: { type: String, default: '' },
    submitting: { type: Boolean, default: false },
});
const emit = defineEmits(['update:settings', 'parse', 'submit', 'back']);
const { t } = useI18n();

const root = ref(null);
const width = useFrameWidth(root);
const wide = computed(() => width.value >= 560);
const twoCols = computed(() => width.value >= 640);

function set(patch, parse = false) {
    emit('update:settings', { ...props.settings, ...patch });
    if (parse) emit('parse', { ...props.settings, ...patch });
}
function setCol(role, value) {
    set({ cols: { ...props.settings.cols, [role]: value } });
}

const colLabel = (i) => (props.settings.hasHeader && props.headers[i] ? props.headers[i] : t('finance.imp.col_n', { n: i + 1 }));
const columnOptions = computed(() => [
    { value: '', label: t('finance.imp.col_unused') },
    ...Array.from({ length: props.columnCount }, (_, i) => ({ value: String(i), label: colLabel(i) })),
]);

const delimiterOptions = computed(() => props.config.delimiters.map((d) => ({ value: d, label: t(`finance.imp.${delimiterKey(d)}`) })));
const encodingOptions = computed(() => props.config.encodings.map((e) => ({ value: e, label: encodingLabel(e, t) })));
const dateOptions = computed(() => props.config.dateFormats.map((f) => ({ value: f, label: dateFormatLabel(f) })));
const decimalOptions = computed(() => [
    { value: ',', label: t('finance.imp.decimal_comma') },
    { value: '.', label: t('finance.imp.decimal_dot') },
]);
const groupingOptions = computed(() => [
    { value: '', label: t('finance.imp.grouping_none') },
    { value: ' ', label: t('finance.imp.grouping_space') },
    { value: '.', label: t('finance.imp.grouping_dot') },
    { value: ',', label: t('finance.imp.grouping_comma') },
]);

const ROLE_LABEL = { date: 'col_date', amount: 'col_amount', income: 'col_income', expense: 'col_expense', indicator: 'col_indicator', description: 'col_description', counterparty: 'col_payee', reference: 'col_reference', currency: 'col_currency' };
const rolesOf = (i) => Object.entries(props.settings.cols).filter(([, v]) => v === String(i)).map(([role]) => t(`finance.imp.${ROLE_LABEL[role]}`));

const columns = computed(() => Array.from({ length: props.columnCount }, (_, i) => ({ index: i, label: colLabel(i), roles: rolesOf(i) })));
// Enter in a text field never submits the multi-field form.
function onEnter(event) {
    if (event.target.tagName === 'INPUT' && !['checkbox', 'radio'].includes(event.target.type)) event.preventDefault();
}
const cell = (row, i) => row[i] ?? '';
</script>

<template>
    <form ref="root" class="imp-map" data-testid="import-step-columns" @submit.prevent="emit('submit')" @keydown.enter="onEnter">
        <h2 class="imp-map__title">{{ t('finance.imp.map_title') }}</h2>
        <UiAlert v-if="alert" variant="warning" live>{{ alert }}</UiAlert>
        <UiAlert v-if="errors.general" variant="danger" live>{{ errors.general }}</UiAlert>
        <p v-if="suggested?.encoding" class="imp-map__suggest" data-testid="import-suggested">{{ t('finance.imp.enc_suggested', { encoding: encodingLabel(suggested.encoding, t) }) }}</p>

        <div class="imp-map__sample" :aria-busy="busy ? 'true' : undefined">
            <h3 class="imp-map__sub">{{ t('finance.imp.sample') }}</h3>
            <div v-if="wide" class="imp-map__scroll" tabindex="0" role="region" :aria-label="t('finance.imp.sample')">
                <table class="imp-table">
                    <thead>
                        <tr>
                            <th v-for="c in columns" :key="c.index" scope="col">
                                <span>{{ c.label }}</span>
                                <span v-for="r in c.roles" :key="r" class="imp-table__role">{{ r }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, r) in sample" :key="r"><td v-for="c in columns" :key="c.index">{{ cell(row, c.index) }}</td></tr>
                    </tbody>
                </table>
            </div>
            <ul v-else class="imp-cards">
                <li v-for="(row, r) in sample" :key="r" class="imp-cards__item">
                    <dl>
                        <div v-for="c in columns" :key="c.index" class="imp-cards__line">
                            <dt>{{ c.label }}</dt>
                            <dd>{{ cell(row, c.index) }}</dd>
                        </div>
                    </dl>
                </li>
            </ul>
        </div>

        <div class="imp-map__grid" :class="{ 'is-two': twoCols }">
            <UiField :label="t('finance.imp.sep')">
                <UiSelect :model-value="settings.delimiter" :options="delimiterOptions" @update:model-value="set({ delimiter: $event }, true)" />
            </UiField>
            <UiField :label="t('finance.imp.encoding')" :error="errors.encoding">
                <UiSelect :model-value="settings.encoding" :options="encodingOptions" @update:model-value="set({ encoding: $event }, true)" />
            </UiField>
            <UiField :label="t('finance.imp.decimal')">
                <UiSelect :model-value="settings.decimalSeparator" :options="decimalOptions" @update:model-value="set({ decimalSeparator: $event, groupingSeparator: $event === settings.groupingSeparator ? '' : settings.groupingSeparator })" />
            </UiField>
            <UiField :label="t('finance.imp.grouping')">
                <UiSelect :model-value="settings.groupingSeparator" :options="groupingOptions.filter((o) => o.value !== settings.decimalSeparator)" @update:model-value="set({ groupingSeparator: $event })" />
            </UiField>
            <UiField :label="t('finance.imp.date_format')">
                <UiSelect :model-value="settings.dateFormat" :options="dateOptions" @update:model-value="set({ dateFormat: $event })" />
            </UiField>
            <UiField v-if="settings.hasHeader" :label="t('finance.imp.header_row')">
                <UiInput type="number" inputmode="numeric" min="1" :model-value="settings.headerRow" @update:model-value="set({ headerRow: Math.max(1, parseInt($event, 10) || 1) }, true)" />
            </UiField>
        </div>
        <UiSwitch :model-value="settings.hasHeader" @update:model-value="set({ hasHeader: $event }, true)">{{ t('finance.imp.has_header') }}</UiSwitch>

        <div role="radiogroup" :aria-label="t('finance.imp.amount_mode')" class="imp-map__modes">
            <span class="imp-map__sub" aria-hidden="true">{{ t('finance.imp.amount_mode') }}</span>
            <UiRadio name="imp-amount-mode" value="single" :model-value="settings.amountMode" @update:model-value="set({ amountMode: $event })">{{ t('finance.imp.amount_single') }}</UiRadio>
            <UiRadio name="imp-amount-mode" value="split" :model-value="settings.amountMode" @update:model-value="set({ amountMode: $event })">{{ t('finance.imp.amount_split') }}</UiRadio>
            <UiRadio name="imp-amount-mode" value="indicator" :model-value="settings.amountMode" @update:model-value="set({ amountMode: $event })">{{ t('finance.imp.amount_indicator') }}</UiRadio>
        </div>

        <div class="imp-map__grid" :class="{ 'is-two': twoCols }">
            <UiField :label="t('finance.imp.col_date')" :error="errors.date" required>
                <UiSelect :model-value="settings.cols.date" :options="columnOptions" @update:model-value="setCol('date', $event)" />
            </UiField>
            <UiField v-if="settings.amountMode !== 'split'" :label="t('finance.imp.col_amount')" :error="errors.amount" required>
                <UiSelect :model-value="settings.cols.amount" :options="columnOptions" @update:model-value="setCol('amount', $event)" />
            </UiField>
            <template v-else>
                <UiField :label="t('finance.imp.col_income')" :error="errors.amount" required>
                    <UiSelect :model-value="settings.cols.income" :options="columnOptions" @update:model-value="setCol('income', $event)" />
                </UiField>
                <UiField :label="t('finance.imp.col_expense')" :error="errors.amount" required>
                    <UiSelect :model-value="settings.cols.expense" :options="columnOptions" @update:model-value="setCol('expense', $event)" />
                </UiField>
            </template>
            <template v-if="settings.amountMode === 'indicator'">
                <UiField :label="t('finance.imp.col_indicator')" :error="errors.indicator" required>
                    <UiSelect :model-value="settings.cols.indicator" :options="columnOptions" @update:model-value="setCol('indicator', $event)" />
                </UiField>
                <UiField :label="t('finance.imp.indicator_expense')">
                    <UiInput :model-value="settings.indicatorExpense" @update:model-value="set({ indicatorExpense: $event })" />
                </UiField>
                <UiField :label="t('finance.imp.indicator_income')">
                    <UiInput :model-value="settings.indicatorIncome" @update:model-value="set({ indicatorIncome: $event })" />
                </UiField>
            </template>
            <UiField :label="t('finance.imp.col_description')" :error="errors.description" required>
                <UiSelect :model-value="settings.cols.description" :options="columnOptions" @update:model-value="setCol('description', $event)" />
            </UiField>
            <UiField :label="t('finance.imp.col_payee')">
                <UiSelect :model-value="settings.cols.counterparty" :options="columnOptions" @update:model-value="setCol('counterparty', $event)" />
            </UiField>
            <UiField :label="t('finance.imp.col_reference')">
                <UiSelect :model-value="settings.cols.reference" :options="columnOptions" @update:model-value="setCol('reference', $event)" />
            </UiField>
            <UiField :label="t('finance.imp.col_currency')">
                <UiSelect :model-value="settings.cols.currency" :options="columnOptions" @update:model-value="setCol('currency', $event)" />
            </UiField>
        </div>

        <div class="imp-map__profile">
            <UiSwitch :model-value="settings.saveProfile" @update:model-value="set({ saveProfile: $event })">{{ t('finance.imp.save_profile') }}</UiSwitch>
            <UiField v-if="settings.saveProfile" :label="t('finance.imp.profile_name')" :error="errors.profileName" required>
                <UiInput maxlength="60" :model-value="settings.profileName" @update:model-value="set({ profileName: $event })" />
            </UiField>
        </div>

        <div class="imp-map__footer">
            <UiButton variant="primary" type="submit" :loading="submitting">{{ t('finance.imp.preview') }}</UiButton>
            <UiButton variant="secondary" @click="emit('back')">{{ t('finance.back') }}</UiButton>
        </div>
    </form>
</template>

<style scoped>
.imp-map { display: flex; flex-direction: column; gap: var(--space-4); }
.imp-map__title { margin: 0; font-size: var(--text-xl); font-weight: 600; line-height: 1.2; }
.imp-map__sub { margin: 0; font-weight: 600; }
.imp-map__suggest { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.imp-map__sample { display: flex; flex-direction: column; gap: var(--space-2); }
.imp-map__sample[aria-busy='true'] { opacity: 0.6; }
.imp-map__scroll { overflow-x: auto; max-width: 100%; border: 1px solid var(--color-border); border-radius: var(--radius-md, 8px); }
.imp-table { border-collapse: collapse; width: 100%; font-size: var(--text-sm); }
.imp-table th, .imp-table td { padding: var(--space-2) var(--space-3); text-align: start; vertical-align: top; overflow-wrap: anywhere; }
.imp-table td { border-top: 1px solid var(--color-border); }
.imp-table__role { display: block; font-weight: 400; color: var(--color-text-muted); }
.imp-cards { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--space-2); }
.imp-cards__item { border: 1px solid var(--color-border); border-radius: var(--radius-md, 8px); padding: var(--space-3); }
.imp-cards__item dl { margin: 0; }
.imp-cards__line { display: flex; justify-content: space-between; gap: var(--space-3); font-size: var(--text-sm); }
.imp-cards__line dt { color: var(--color-text-muted); }
.imp-cards__line dd { margin: 0; text-align: end; overflow-wrap: anywhere; }
.imp-map__grid { display: grid; grid-template-columns: 1fr; gap: var(--space-4); }
.imp-map__grid.is-two { grid-template-columns: 1fr 1fr; }
.imp-map__modes { display: flex; flex-direction: column; gap: var(--space-2); }
.imp-map__profile { display: flex; flex-direction: column; gap: var(--space-3); max-width: 360px; }
.imp-map__footer { display: flex; flex-wrap: wrap; gap: var(--space-2); }
</style>
