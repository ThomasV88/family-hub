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
import UiSelect from '../Components/ui/UiSelect.vue';
import UiListRow from '../Components/ui/UiListRow.vue';
import UiTextarea from '../Components/ui/UiTextarea.vue';
import AttachDocumentDialog from './AttachDocumentDialog.vue';

// Create, edit and read-only presentation of one visit. The draft lives in component memory only: no Inertia
// form-state persistence and no browser storage. Free text is interpolated, never rendered as HTML.
const props = defineProps({
    open: { type: Boolean, default: false },
    personId: { type: String, required: true },
    visit: { type: Object, default: null },
    canEdit: { type: Boolean, default: false },
    specialties: { type: Array, default: () => [] },
    documents: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'open-document', 'upload-document']);
const verifying = ref(false);
function verify() {
    if (!props.visit || verifying.value) return;
    router.post(`/health/people/${props.personId}/visits/${props.visit.id}/verify`, {}, {
        preserveScroll: true,
        onStart: () => { verifying.value = true; },
        onFinish: () => { verifying.value = false; },
    });
}
const { t, te, locale } = useI18n();

const blank = () => ({ visited_on: '', doctor: '', specialty_key: '', specialty_other: '', diagnosis: '', notes: '' });
const fromVisit = (v) => ({
    visited_on: v.visitedOn ?? '',
    doctor: v.doctor ?? '',
    specialty_key: v.specialtyKey ?? '',
    specialty_other: v.specialtyOther ?? '',
    diagnosis: v.diagnosis ?? '',
    notes: v.notes ?? '',
});
const form = ref(blank());
const initial = ref(JSON.stringify(blank()));
const errors = ref({});
const serverError = ref(false);
const processing = ref(false);
const discardOpen = ref(false);
const deleteOpen = ref(false);

const editing = computed(() => props.visit !== null);
const readOnly = computed(() => !props.canEdit);
const title = computed(() => (editing.value ? t('health.visit.title_edit') : t('health.visit.title_new')));
const dirty = computed(() => JSON.stringify(form.value) !== initial.value);

const specialtyOptions = computed(() => {
    const collator = new Intl.Collator(locale.value);
    const named = props.specialties.filter((key) => key !== 'other').map((value) => ({ value, label: t(`health.specialty.${value}`) }));
    named.sort((a, b) => collator.compare(a.label, b.label));
    return [{ value: '', label: '' }, ...named, ...(props.specialties.includes('other') ? [{ value: 'other', label: t('health.specialty.other') }] : [])];
});
const specialtyText = computed(() => {
    const v = props.visit;
    if (!v) return '';
    return v.specialtyKey === 'other' && v.specialtyOther ? v.specialtyOther : t(`health.specialty.${v.specialtyKey}`);
});

watch(() => [props.open, props.visit?.id], () => {
    if (!props.open) return;
    form.value = props.visit ? fromVisit(props.visit) : blank();
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

const FIELDS = ['visited_on', 'doctor', 'specialty_key', 'specialty_other', 'diagnosis', 'notes'];
const message = (value) => (typeof value === 'string' && te(value) ? t(value) : t('health.error.load'));

function localErrors() {
    const out = {};
    if (!form.value.visited_on) out.visited_on = t('health.visit.err_date');
    if (!form.value.specialty_key) out.specialty_key = t('health.visit.err_specialty');
    else if (form.value.specialty_key === 'other' && form.value.specialty_other.trim() === '') out.specialty_other = t('health.visit.err_specialty_other');
    return out;
}

const base = computed(() => `/health/people/${props.personId}/visits`);
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
    const data = {
        visited_on: f.visited_on,
        doctor: f.doctor || null,
        specialty_key: f.specialty_key,
        specialty_other: f.specialty_key === 'other' ? f.specialty_other : null,
        diagnosis: f.diagnosis || null,
        notes: f.notes || null,
    };
    if (editing.value) router.patch(`${base.value}/${props.visit.id}`, data, callbacks);
    else router.post(base.value, data, callbacks);
}
function remove() {
    router.delete(`${base.value}/${props.visit.id}`, callbacks);
}

// Documents of this visit. Attach and detach are PATCHes that always carry visit_id (null detaches); detaching never
// deletes the document.
const attachOpen = ref(false);
const attachBusy = ref(false);
const attached = computed(() => (props.visit ? props.documents.filter((d) => d.visitId === props.visit.id) : []));
const unattached = computed(() => props.documents.filter((d) => d.visitId === null));
const docCallbacks = {
    preserveScroll: true,
    onStart: () => { attachBusy.value = true; },
    onSuccess: () => { attachOpen.value = false; },
    onError: () => { serverError.value = true; },
    onHttpException: () => { serverError.value = true; },
    onNetworkError: () => { serverError.value = true; },
    onFinish: () => { attachBusy.value = false; },
};
function setDocumentVisit(doc, visitId) {
    router.patch(`/health/people/${props.personId}/documents/${doc.id}`, { title: doc.title, visit_id: visitId }, docCallbacks);
}
const attachDocument = (id) => {
    const doc = props.documents.find((d) => d.id === id);
    if (doc) setDocumentVisit(doc, props.visit.id);
};
const onEnter = (event) => { if (event.target?.tagName === 'INPUT') event.preventDefault(); };
</script>

<template>
    <UiDetailPanel :open="open" :title="title" @update:open="(v) => (v ? null : requestClose())">
        <ProvenanceTags v-if="visit" :provenance="visit.provenance" :can-verify="!readOnly" :verifying="verifying" class="panel-prov" data-testid="panel-provenance" @verify="verify" />
        <dl v-if="readOnly && visit" class="visit-read" data-testid="visit-readonly">
            <div><dt>{{ t('health.visit.date') }}</dt><dd>{{ visit.visitedOn }}</dd></div>
            <div v-if="visit.doctor"><dt>{{ t('health.visit.doctor') }}</dt><dd>{{ visit.doctor }}</dd></div>
            <div><dt>{{ t('health.visit.specialty') }}</dt><dd>{{ specialtyText }}</dd></div>
            <div v-if="visit.diagnosis"><dt>{{ t('health.visit.diagnosis') }}</dt><dd>{{ visit.diagnosis }}</dd></div>
            <div v-if="visit.notes"><dt>{{ t('health.visit.notes') }}</dt><dd class="visit-read__notes">{{ visit.notes }}</dd></div>
        </dl>
        <form v-else novalidate data-testid="visit-form" class="visit-form" @submit.prevent="save" @keydown.enter="onEnter">
            <UiAlert v-if="serverError" variant="danger" live>{{ t('health.error.load') }}</UiAlert>

            <UiField :label="`${t('health.visit.date')} ${t('health.required')}`" :error="errors.visited_on">
                <UiInput v-model="form.visited_on" type="date" />
            </UiField>
            <UiField :label="t('health.visit.doctor')" :error="errors.doctor">
                <UiInput v-model="form.doctor" autocomplete="off" maxlength="120" />
            </UiField>
            <UiField :label="`${t('health.visit.specialty')} ${t('health.required')}`" :error="errors.specialty_key">
                <UiSelect v-model="form.specialty_key" :options="specialtyOptions" />
            </UiField>
            <UiField v-if="form.specialty_key === 'other'" :label="`${t('health.visit.specialty_other')} ${t('health.required')}`" :error="errors.specialty_other">
                <UiInput v-model="form.specialty_other" autocomplete="off" maxlength="80" />
            </UiField>
            <UiField :label="t('health.visit.diagnosis')" :error="errors.diagnosis">
                <UiInput v-model="form.diagnosis" autocomplete="off" maxlength="2000" />
            </UiField>
            <UiField :label="t('health.visit.notes')" :hint="t('health.visit.notes_hint')" :error="errors.notes">
                <UiTextarea v-model="form.notes" :rows="4" />
            </UiField>
        </form>
        <section v-if="editing && (attached.length > 0 || !readOnly)" class="visit-docs" data-testid="visit-documents" aria-labelledby="visit-docs-title">
            <h3 id="visit-docs-title" class="visit-docs__title">{{ t('health.visit.documents') }}</h3>
            <ul v-if="attached.length > 0" class="visit-docs__list">
                <UiListRow v-for="doc in attached" :key="doc.id" as="button" @select="emit('open-document', doc.id)">
                    {{ doc.title }}
                    <template v-if="!readOnly" #trailing>
                        <UiButton variant="ghost" size="sm" data-testid="doc-detach" @click.stop="setDocumentVisit(doc, null)">{{ t('health.visit.remove_from_visit') }}</UiButton>
                    </template>
                </UiListRow>
            </ul>
            <UiButton v-if="!readOnly" variant="secondary" data-testid="visit-attach" @click="attachOpen = true">{{ t('health.visit.attach') }}</UiButton>
        </section>
        <template #footer>
            <UiButton v-if="editing && !readOnly" variant="ghost" class="visit-delete" data-testid="visit-delete" @click="deleteOpen = true">{{ t('health.visit.delete') }}</UiButton>
            <UiButton variant="secondary" @click="requestClose">{{ t('ui.close') }}</UiButton>
            <UiButton v-if="!readOnly" variant="primary" :loading="processing" data-testid="visit-save" @click="save">{{ t('health.visit.save') }}</UiButton>
        </template>
    </UiDetailPanel>

    <AttachDocumentDialog
        v-model:open="attachOpen"
        :documents="unattached"
        :busy="attachBusy"
        @attach="attachDocument"
        @upload="() => { attachOpen = false; emit('upload-document', visit.id); }"
    />
    <UiConfirmDialog
        v-model:open="deleteOpen"
        :title="t('health.visit.delete_title')"
        :body="t('health.visit.delete_body')"
        :confirm-label="t('health.visit.delete')"
        :cancel-label="t('health.visit.delete_keep')"
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
.visit-form { display: flex; flex-direction: column; }
.visit-read { display: flex; flex-direction: column; gap: var(--space-3); margin: 0; }
.visit-read dt { font-size: var(--text-sm); color: var(--color-text-muted); }
.visit-read dd { margin: 0; overflow-wrap: anywhere; }
.visit-read__notes { white-space: pre-wrap; }
.visit-docs { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-2); margin-block-start: var(--space-4); padding-block-start: var(--space-4); border-block-start: 1px solid var(--color-border); }
.visit-docs__title { margin: 0; font-size: var(--text-base); font-weight: 600; }
.visit-docs__list { list-style: none; margin: 0; padding: 0; width: 100%; }
.visit-delete { margin-inline-end: auto; color: var(--color-danger); }
</style>
