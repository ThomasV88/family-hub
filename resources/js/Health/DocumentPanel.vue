<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Download, ExternalLink } from '@lucide/vue';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiConfirmDialog from '../Components/ui/UiConfirmDialog.vue';
import UiDetailPanel from '../Components/ui/UiDetailPanel.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import UiSelect from '../Components/ui/UiSelect.vue';
import { securityDownload } from '../Security/securityTransfer.js';
import { saveBlob } from '../Security/saveBlob.js';
import DocumentPreview from './DocumentPreview.vue';
import DocumentUploader from './DocumentUploader.vue';
import { documentUrl, visitLabel } from './documentFormat.js';
import { handleHealthRefusal } from './healthRefusal.js';

// Create (uploader) and view/edit/read-only presentation of one document. Drafts, picked files and downloaded blobs
// live in component memory only; an object URL is revoked as soon as the download was started. Titles are
// interpolated, never rendered as HTML.
const props = defineProps({
    open: { type: Boolean, default: false },
    personId: { type: String, required: true },
    document: { type: Object, default: null },
    visits: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({ maxMb: 10, filesAtOnce: 10 }) },
    canEdit: { type: Boolean, default: false },
    initialVisit: { type: String, default: null },
});
const emit = defineEmits(['close']);
const { t, te } = useI18n();

const form = ref({ title: '', visit_id: '' });
const initial = ref(JSON.stringify(form.value));
const errors = ref({});
const serverError = ref(false);
const downloadError = ref(false);
const processing = ref(false);
const downloading = ref(false);
const deleteOpen = ref(false);
const discardOpen = ref(false);

const creating = computed(() => props.document === null);
const readOnly = computed(() => !props.canEdit);
const dirty = computed(() => JSON.stringify(form.value) !== initial.value);
const panelTitle = computed(() => (creating.value ? t('health.doc.add') : props.document.title));
const base = computed(() => `/health/people/${props.personId}/documents`);
const visitOptions = computed(() => [
    { value: '', label: t('health.doc.no_visit') },
    ...[...props.visits].sort((a, b) => b.visitedOn.localeCompare(a.visitedOn)).map((v) => ({ value: v.id, label: visitLabel(v, t) })),
]);
const attachedVisit = computed(() => props.visits.find((v) => v.id === props.document?.visitId) ?? null);
// Images open the inline derivative; everything else opens the file route.
const openUrl = computed(() => (props.document ? documentUrl(props.personId, props.document.id, props.document.kind === 'image' ? 'preview/view' : 'file') : ''));

watch(() => [props.open, props.document?.id], () => {
    if (!props.open) return;
    form.value = props.document ? { title: props.document.title, visit_id: props.document.visitId ?? '' } : { title: '', visit_id: '' };
    initial.value = JSON.stringify(form.value);
    errors.value = {};
    serverError.value = false;
    downloadError.value = false;
}, { immediate: true });

function requestClose() {
    if (!creating.value && !readOnly.value && dirty.value) {
        discardOpen.value = true;
        return;
    }
    emit('close');
}

const message = (value) => (typeof value === 'string' && te(value) ? t(value) : t('health.error.load'));
const callbacks = {
    preserveScroll: true,
    onStart: () => { processing.value = true; serverError.value = false; },
    onSuccess: () => emit('close'),
    onError: (errs) => {
        const mapped = {};
        for (const [key, value] of Object.entries(errs ?? {})) {
            if (key === 'title' || key === 'visit_id') mapped[key] = message(value);
        }
        errors.value = mapped;
        serverError.value = Object.keys(mapped).length === 0;
    },
    onHttpException: () => { serverError.value = true; },
    onNetworkError: () => { serverError.value = true; },
    onFinish: () => { processing.value = false; },
};

function save() {
    errors.value = {};
    if (form.value.title.trim() === '') {
        errors.value = { title: t('health.error.load') };
        return;
    }
    // visit_id is always sent: an absent key would keep the current visit, null detaches it.
    router.patch(`${base.value}/${props.document.id}`, { title: form.value.title.trim(), visit_id: form.value.visit_id === '' ? null : form.value.visit_id }, callbacks);
}
function remove() {
    router.delete(`${base.value}/${props.document.id}`, callbacks);
}

async function download() {
    downloadError.value = false;
    downloading.value = true;
    const result = await securityDownload(documentUrl(props.personId, props.document.id, 'file'));
    downloading.value = false;
    if (!result.ok) {
        if (handleHealthRefusal(result.error)) return;
        downloadError.value = true;
        return;
    }
    saveBlob(result.blob, result.filename ?? 'document');
}
const onEnter = (event) => { if (event.target?.tagName === 'INPUT') event.preventDefault(); };
</script>

<template>
    <UiDetailPanel :open="open" :title="panelTitle" @update:open="(v) => (v ? null : requestClose())">
        <DocumentUploader v-if="creating && open" :person-id="personId" :visits="visits" :limits="limits" :initial-visit="initialVisit" @close="emit('close')" />
        <div v-else-if="document" class="doc-panel" data-testid="document-panel">
            <UiAlert v-if="serverError" variant="danger" live>{{ t('health.error.load') }}</UiAlert>
            <UiAlert v-if="downloadError" variant="danger" live>{{ t('health.error.load') }}</UiAlert>

            <DocumentPreview :person-id="personId" :document="document" />
            <div class="doc-panel__links">
                <UiButton variant="secondary" :icon="ExternalLink" :href="openUrl" target="_blank" rel="noopener" data-testid="doc-open">{{ t('health.doc.open_new_tab') }}</UiButton>
                <UiButton variant="secondary" :icon="Download" :loading="downloading" data-testid="doc-download" @click="download">{{ t('health.doc.download') }}</UiButton>
            </div>

            <form v-if="!readOnly" novalidate class="doc-panel__form" data-testid="document-form" @submit.prevent="save" @keydown.enter="onEnter">
                <UiField :label="t('health.doc.title_field')" :error="errors.title">
                    <UiInput v-model="form.title" autocomplete="off" maxlength="120" />
                </UiField>
                <UiField :label="t('health.doc.attach_visit')" :error="errors.visit_id">
                    <UiSelect v-model="form.visit_id" :options="visitOptions" />
                </UiField>
            </form>
            <dl v-else class="doc-panel__read" data-testid="document-readonly">
                <div><dt>{{ t('health.doc.title_field') }}</dt><dd>{{ document.title }}</dd></div>
                <div v-if="attachedVisit"><dt>{{ t('health.doc.attach_visit') }}</dt><dd>{{ visitLabel(attachedVisit, t) }}</dd></div>
            </dl>
        </div>
        <template #footer>
            <UiButton v-if="!creating && !readOnly" variant="ghost" class="doc-delete" data-testid="doc-delete" @click="deleteOpen = true">{{ t('health.doc.delete') }}</UiButton>
            <template v-if="!creating">
                <UiButton variant="secondary" @click="requestClose">{{ t('ui.close') }}</UiButton>
                <UiButton v-if="!readOnly" variant="primary" :loading="processing" data-testid="doc-save" @click="save">{{ t('health.visit.save') }}</UiButton>
            </template>
        </template>
    </UiDetailPanel>

    <UiConfirmDialog
        v-model:open="deleteOpen"
        :title="t('health.doc.delete_title')"
        :body="t('health.doc.delete_body')"
        :confirm-label="t('health.doc.delete')"
        :cancel-label="t('health.doc.delete_keep')"
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
.doc-panel { display: flex; flex-direction: column; gap: var(--space-4); }
.doc-panel__links { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.doc-panel__form { display: flex; flex-direction: column; }
.doc-panel__read { display: flex; flex-direction: column; gap: var(--space-3); margin: 0; }
.doc-panel__read dt { font-size: var(--text-sm); color: var(--color-text-muted); }
.doc-panel__read dd { margin: 0; overflow-wrap: anywhere; }
.doc-delete { margin-inline-end: auto; color: var(--color-danger); }
</style>
