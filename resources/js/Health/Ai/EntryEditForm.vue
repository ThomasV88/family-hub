<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiInput from '../../Components/ui/UiInput.vue';
import UiSelect from '../../Components/ui/UiSelect.vue';
import UiTextarea from '../../Components/ui/UiTextarea.vue';

// The Phase 7 field set of one entry type, prefilled with the proposal. It only displays what the server says is
// wrong (the same Phase 7 rules run there); it never validates on its own. The draft lives in component memory only:
// no browser storage, no Inertia form-state persistence. Free text is interpolated, never rendered as HTML.
const props = defineProps({
    type: { type: String, required: true }, // visit | medication | allergy | vaccination
    fields: { type: Object, default: () => ({}) },
    errors: { type: Object, default: null }, // raw server errors {field: [message key]}
    saving: { type: Boolean, default: false },
    specialties: { type: Array, default: () => [] },
});
const emit = defineEmits(['save', 'cancel', 'dirty-change']);
const { t, te, locale } = useI18n();

const KEYS = {
    visit: ['visited_on', 'doctor', 'specialty_key', 'specialty_other', 'diagnosis', 'notes'],
    medication: ['name', 'dose', 'schedule', 'started_on', 'ended_on', 'notes'],
    allergy: ['substance', 'reaction', 'severity'],
    vaccination: ['vaccine', 'given_on', 'next_due_on', 'notes'],
};
const text = (value) => (typeof value === 'string' ? value : typeof value === 'number' ? String(value) : '');

function seed() {
    const out = {};
    for (const key of KEYS[props.type] ?? []) out[key] = text(props.fields?.[key]);
    if (props.type === 'visit' && out.specialty_key !== '' && !props.specialties.includes(out.specialty_key)) {
        out.specialty_key = '';
        out.specialty_other = '';
    }
    if (props.type === 'allergy' && !['mild', 'moderate', 'severe'].includes(out.severity)) out.severity = '';
    return out;
}
const form = ref(seed());
const initial = JSON.stringify(form.value);
const dirty = computed(() => JSON.stringify(form.value) !== initial);
watch(dirty, (value) => emit('dirty-change', value));

const root = ref(null);

const specialtyOptions = computed(() => {
    const collator = new Intl.Collator(locale.value);
    const named = props.specialties.filter((key) => key !== 'other').map((value) => ({ value, label: t(`health.specialty.${value}`) }));
    named.sort((a, b) => collator.compare(a.label, b.label));
    return [{ value: '', label: '' }, ...named, ...(props.specialties.includes('other') ? [{ value: 'other', label: t('health.specialty.other') }] : [])];
});
const severityOptions = computed(() => [
    { value: '', label: t('health.allergy.severity_choose') },
    ...['mild', 'moderate', 'severe'].map((value) => ({ value, label: t(`health.severity.${value}`) })),
]);

// Server errors -> one translated message per field, in the 07 message style. Unknown fields or untranslatable
// messages fall back to the generic text in a form-level alert.
const mapped = computed(() => {
    const byField = {};
    let general = false;
    for (const [rawField, value] of Object.entries(props.errors ?? {})) {
        const field = rawField.replace(/^values\./, '');
        const list = Array.isArray(value) ? value : [value];
        let message = '';
        if (field === 'severity' && props.type === 'allergy') message = t('health.ai.review.needs_severity');
        else message = list.map((m) => (typeof m === 'string' && te(m) ? t(m) : '')).find((m) => m !== '') ?? '';
        if ((KEYS[props.type] ?? []).includes(field) && message !== '') byField[field] = message;
        else general = true;
    }
    return { byField, general: general || (props.errors && Object.keys(props.errors).length > 0 && Object.keys(byField).length === 0) };
});
const err = (field) => mapped.value.byField[field] ?? '';

function values() {
    const f = form.value;
    const out = {};
    for (const key of KEYS[props.type]) out[key] = f[key] === '' ? null : f[key];
    if (props.type === 'visit') {
        out.specialty_key = f.specialty_key === '' ? null : f.specialty_key;
        out.specialty_other = f.specialty_key === 'other' && f.specialty_other !== '' ? f.specialty_other : null;
    }
    return out;
}
function save() {
    if (props.saving) return;
    emit('save', values());
}

// First flagged field when there are errors, else the first field.
async function focusFirst() {
    await nextTick();
    const el = root.value?.querySelector('[aria-invalid="true"]') ?? root.value?.querySelector('input, select, textarea');
    el?.focus();
}
watch(() => props.errors, (value) => { if (value && Object.keys(value).length > 0) focusFirst(); });
defineExpose({ focusFirst, dirty });

const onKeydown = (event) => {
    if (event.key === 'Enter' && event.target?.tagName === 'INPUT') event.preventDefault();
    else if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        emit('cancel');
    }
};
const req = (key) => `${t(key)} ${t('health.required')}`;
</script>

<template>
    <form ref="root" novalidate class="edit-form" :data-testid="`edit-form-${type}`" :aria-busy="saving ? 'true' : undefined" @submit.prevent @keydown="onKeydown">
        <UiAlert v-if="mapped.general" variant="danger" live data-testid="edit-general-error">{{ t('health.error.load') }}</UiAlert>

        <template v-if="type === 'visit'">
            <UiField :label="req('health.visit.date')" :error="err('visited_on')">
                <UiInput v-model="form.visited_on" type="date" />
            </UiField>
            <UiField :label="t('health.visit.doctor')" :error="err('doctor')">
                <UiInput v-model="form.doctor" autocomplete="off" maxlength="120" />
            </UiField>
            <UiField :label="req('health.visit.specialty')" :error="err('specialty_key')">
                <UiSelect v-model="form.specialty_key" :options="specialtyOptions" />
            </UiField>
            <UiField v-if="form.specialty_key === 'other'" :label="req('health.visit.specialty_other')" :error="err('specialty_other')">
                <UiInput v-model="form.specialty_other" autocomplete="off" maxlength="80" />
            </UiField>
            <UiField :label="t('health.visit.diagnosis')" :error="err('diagnosis')">
                <UiInput v-model="form.diagnosis" autocomplete="off" maxlength="2000" />
            </UiField>
            <UiField :label="t('health.visit.notes')" :error="err('notes')">
                <UiTextarea v-model="form.notes" :rows="4" />
            </UiField>
        </template>

        <template v-else-if="type === 'medication'">
            <UiField :label="req('health.medication.name')" :error="err('name')">
                <UiInput v-model="form.name" autocomplete="off" maxlength="120" />
            </UiField>
            <UiField :label="t('health.medication.dose')" :error="err('dose')">
                <UiInput v-model="form.dose" autocomplete="off" maxlength="120" />
            </UiField>
            <UiField :label="t('health.medication.schedule')" :error="err('schedule')">
                <UiInput v-model="form.schedule" autocomplete="off" maxlength="160" />
            </UiField>
            <UiField :label="t('health.medication.start')" :error="err('started_on')">
                <UiInput v-model="form.started_on" type="date" />
            </UiField>
            <UiField :label="t('health.medication.end')" :error="err('ended_on')">
                <UiInput v-model="form.ended_on" type="date" />
            </UiField>
            <UiField :label="t('health.visit.notes')" :error="err('notes')">
                <UiTextarea v-model="form.notes" :rows="3" />
            </UiField>
        </template>

        <template v-else-if="type === 'allergy'">
            <UiField :label="req('health.allergy.substance')" :error="err('substance')">
                <UiInput v-model="form.substance" autocomplete="off" maxlength="120" />
            </UiField>
            <UiField :label="t('health.allergy.reaction')" :error="err('reaction')">
                <UiInput v-model="form.reaction" autocomplete="off" maxlength="200" />
            </UiField>
            <UiField :label="req('health.allergy.severity')" :error="err('severity')">
                <UiSelect v-model="form.severity" :options="severityOptions" />
            </UiField>
        </template>

        <template v-else>
            <UiField :label="req('health.vaccination.vaccine')" :error="err('vaccine')">
                <UiInput v-model="form.vaccine" autocomplete="off" maxlength="120" />
            </UiField>
            <UiField :label="req('health.vaccination.given_on')" :error="err('given_on')">
                <UiInput v-model="form.given_on" type="date" />
            </UiField>
            <UiField :label="t('health.vaccination.next_due')" :error="err('next_due_on')">
                <UiInput v-model="form.next_due_on" type="date" />
            </UiField>
            <UiField :label="t('health.visit.notes')" :error="err('notes')">
                <UiTextarea v-model="form.notes" :rows="3" />
            </UiField>
        </template>

        <div class="edit-form__actions">
            <UiButton variant="primary" :loading="saving" :disabled="saving" data-testid="edit-save" @click="save">{{ t(`health.ai.review.save_${type}`) }}</UiButton>
            <UiButton variant="secondary" :disabled="saving" data-testid="edit-cancel" @click="emit('cancel')">{{ t('health.ai.review.cancel_edit') }}</UiButton>
        </div>
    </form>
</template>

<style scoped>
.edit-form { display: flex; flex-direction: column; min-width: 0; }
.edit-form__actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
</style>
