<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import ProvenanceTags from './ProvenanceTags.vue';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiConfirmDialog from '../Components/ui/UiConfirmDialog.vue';
import UiDetailPanel from '../Components/ui/UiDetailPanel.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';

// Create, edit and read-only presentation of one medication. The draft lives in component memory only: no Inertia
// form-state persistence and no browser storage. Free text is interpolated, never rendered as HTML.
const props = defineProps({
    open: { type: Boolean, default: false },
    personId: { type: String, required: true },
    medication: { type: Object, default: null },
    canEdit: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);
const verifying = ref(false);
function verify() {
    if (!props.medication || verifying.value) return;
    router.post(`/health/people/${props.personId}/medications/${props.medication.id}/verify`, {}, {
        preserveScroll: true,
        onStart: () => { verifying.value = true; },
        onFinish: () => { verifying.value = false; },
    });
}
const { t, te } = useI18n();

const blank = () => ({ name: '', dose: '', schedule: '', started_on: '', ended_on: '' });
const fromMedication = (m) => ({ name: m.name ?? '', dose: m.dose ?? '', schedule: m.schedule ?? '', started_on: m.startedOn ?? '', ended_on: m.endedOn ?? '' });
const form = ref(blank());
const initial = ref(JSON.stringify(blank()));
const errors = ref({});
const serverError = ref(false);
const processing = ref(false);
const discardOpen = ref(false);
const deleteOpen = ref(false);

const editing = computed(() => props.medication !== null);
const readOnly = computed(() => !props.canEdit);
const title = computed(() => (editing.value ? t('health.medication.title_edit') : t('health.medication.title_new')));
const dirty = computed(() => JSON.stringify(form.value) !== initial.value);

watch(() => [props.open, props.medication?.id], () => {
    if (!props.open) return;
    form.value = props.medication ? fromMedication(props.medication) : blank();
    initial.value = JSON.stringify(form.value);
    errors.value = {};
    serverError.value = false;
}, { immediate: true });

function requestClose() {
    if (!readOnly.value && dirty.value) {
        discardOpen.value = true;
        return;
    }
    emit('close');
}

const FIELDS = ['name', 'dose', 'schedule', 'started_on', 'ended_on'];
const message = (value) => (typeof value === 'string' && te(value) ? t(value) : t('health.error.load'));

// ISO dates compare as strings. The server stays the authority and shows the same message.
function localErrors() {
    const out = {};
    const f = form.value;
    if (f.name.trim() === '') out.name = t('health.medication.err_name');
    if (f.started_on && f.ended_on && f.ended_on < f.started_on) out.ended_on = t('health.medication.err_end_before_start');
    return out;
}

const base = computed(() => `/health/people/${props.personId}/medications`);
const callbacks = {
    preserveScroll: true,
    onStart: () => { processing.value = true; serverError.value = false; },
    onSuccess: () => emit('close'),
    onError: (errs) => {
        const mapped = {};
        let unknown = false;
        for (const [key, value] of Object.entries(errs ?? {})) {
            if (FIELDS.includes(key)) mapped[key] = message(value);
            else unknown = true;
        }
        errors.value = mapped;
        serverError.value = unknown || Object.keys(mapped).length === 0;
    },
    onHttpException: () => { serverError.value = true; },
    onNetworkError: () => { serverError.value = true; },
    onFinish: () => { processing.value = false; },
};

function save() {
    errors.value = localErrors();
    if (Object.keys(errors.value).length > 0) return;
    const f = form.value;
    const data = { name: f.name, dose: f.dose || null, schedule: f.schedule || null, started_on: f.started_on || null, ended_on: f.ended_on || null };
    if (editing.value) router.patch(`${base.value}/${props.medication.id}`, data, callbacks);
    else router.post(base.value, data, callbacks);
}
function remove() {
    router.delete(`${base.value}/${props.medication.id}`, callbacks);
}
const onEnter = (event) => { if (event.target?.tagName === 'INPUT') event.preventDefault(); };
</script>

<template>
    <UiDetailPanel :open="open" :title="title" @update:open="(v) => (v ? null : requestClose())">
        <ProvenanceTags v-if="medication" :provenance="medication.provenance" :can-verify="!readOnly" :verifying="verifying" class="panel-prov" data-testid="panel-provenance" @verify="verify" />
        <dl v-if="readOnly && medication" class="med-read" data-testid="medication-readonly">
            <div><dt>{{ t('health.medication.name') }}</dt><dd>{{ medication.name }}</dd></div>
            <div v-if="medication.dose"><dt>{{ t('health.medication.dose') }}</dt><dd>{{ medication.dose }}</dd></div>
            <div v-if="medication.schedule"><dt>{{ t('health.medication.schedule') }}</dt><dd>{{ medication.schedule }}</dd></div>
            <div v-if="medication.startedOn"><dt>{{ t('health.medication.start') }}</dt><dd>{{ medication.startedOn }}</dd></div>
            <div v-if="medication.endedOn"><dt>{{ t('health.medication.end') }}</dt><dd>{{ medication.endedOn }}</dd></div>
        </dl>
        <form v-else novalidate data-testid="medication-form" class="med-form" @submit.prevent="save" @keydown.enter="onEnter">
            <UiAlert v-if="serverError" variant="danger" live>{{ t('health.error.load') }}</UiAlert>

            <UiField :label="`${t('health.medication.name')} ${t('health.required')}`" :error="errors.name">
                <UiInput v-model="form.name" autocomplete="off" maxlength="120" />
            </UiField>
            <UiField :label="t('health.medication.dose')" :hint="t('health.medication.dose_hint')" :error="errors.dose">
                <UiInput v-model="form.dose" autocomplete="off" maxlength="120" />
            </UiField>
            <UiField :label="t('health.medication.schedule')" :hint="t('health.medication.schedule_hint')" :error="errors.schedule">
                <UiInput v-model="form.schedule" autocomplete="off" maxlength="160" />
            </UiField>
            <UiField :label="t('health.medication.start')" :error="errors.started_on">
                <UiInput v-model="form.started_on" type="date" />
            </UiField>
            <UiField :label="t('health.medication.end')" :hint="t('health.medication.end_hint')" :error="errors.ended_on">
                <UiInput v-model="form.ended_on" type="date" />
            </UiField>
        </form>
        <template #footer>
            <UiButton v-if="editing && !readOnly" variant="ghost" class="med-delete" data-testid="medication-delete" @click="deleteOpen = true">{{ t('health.medication.delete') }}</UiButton>
            <UiButton variant="secondary" @click="requestClose">{{ t('ui.close') }}</UiButton>
            <UiButton v-if="!readOnly" variant="primary" :loading="processing" data-testid="medication-save" @click="save">{{ t('health.medication.save') }}</UiButton>
        </template>
    </UiDetailPanel>

    <UiConfirmDialog
        v-model:open="deleteOpen"
        :title="t('health.medication.delete_title')"
        :body="t('health.medication.delete_body')"
        :confirm-label="t('health.medication.delete')"
        :cancel-label="t('health.medication.delete_keep')"
        @confirm="remove"
    />
    <UiConfirmDialog
        v-model:open="discardOpen"
        :title="t('health.discard.title')"
        :body="t('finance.discard.body')"
        :confirm-label="t('health.discard.discard')"
        :cancel-label="t('health.discard.keep')"
        @confirm="emit('close')"
    />
</template>

<style scoped>
.panel-prov { margin-bottom: var(--space-3); align-items: center; }
.med-form { display: flex; flex-direction: column; }
.med-read { display: flex; flex-direction: column; gap: var(--space-3); margin: 0; }
.med-read dt { font-size: var(--text-sm); color: var(--color-text-muted); }
.med-read dd { margin: 0; overflow-wrap: anywhere; }
.med-delete { margin-inline-end: auto; color: var(--color-danger); }
</style>
