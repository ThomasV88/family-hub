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

// Create, edit and read-only presentation of one allergy. The draft lives in component memory only: no Inertia
// form-state persistence and no browser storage. Free text is interpolated, never rendered as HTML.
const props = defineProps({
    open: { type: Boolean, default: false },
    personId: { type: String, required: true },
    allergy: { type: Object, default: null },
    canEdit: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);
const verifying = ref(false);
function verify() {
    if (!props.allergy || verifying.value) return;
    router.post(`/health/people/${props.personId}/allergies/${props.allergy.id}/verify`, {}, {
        preserveScroll: true,
        onStart: () => { verifying.value = true; },
        onFinish: () => { verifying.value = false; },
    });
}
const { t, te } = useI18n();

const blank = () => ({ substance: '', reaction: '', severity: '' });
const fromAllergy = (a) => ({ substance: a.substance ?? '', reaction: a.reaction ?? '', severity: a.severity === 'unknown' ? '' : (a.severity ?? '') });
const form = ref(blank());
const initial = ref(JSON.stringify(blank()));
const errors = ref({});
const serverError = ref(false);
const processing = ref(false);
const discardOpen = ref(false);
const deleteOpen = ref(false);

const editing = computed(() => props.allergy !== null);
const readOnly = computed(() => !props.canEdit);
const title = computed(() => (editing.value ? t('health.allergy.title_edit') : t('health.allergy.title_new')));
const dirty = computed(() => JSON.stringify(form.value) !== initial.value);
// No default selection: the empty option forces a conscious choice.
const severityOptions = computed(() => [
    { value: '', label: t('health.allergy.severity_choose') },
    ...['mild', 'moderate', 'severe'].map((value) => ({ value, label: t(`health.severity.${value}`) })),
]);
const severityText = computed(() => t(`health.severity.${props.allergy?.severity ?? 'unknown'}`));

watch(() => [props.open, props.allergy?.id], () => {
    if (!props.open) return;
    form.value = props.allergy ? fromAllergy(props.allergy) : blank();
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

const FIELDS = ['substance', 'reaction', 'severity'];
const message = (value) => (typeof value === 'string' && te(value) ? t(value) : t('health.error.load'));

function localErrors() {
    const out = {};
    if (form.value.substance.trim() === '') out.substance = t('health.allergy.err_substance');
    if (!form.value.severity) out.severity = t('health.allergy.err_severity');
    return out;
}

const base = computed(() => `/health/people/${props.personId}/allergies`);
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
    const data = { substance: f.substance, reaction: f.reaction || null, severity: f.severity };
    if (editing.value) router.patch(`${base.value}/${props.allergy.id}`, data, callbacks);
    else router.post(base.value, data, callbacks);
}
function remove() {
    router.delete(`${base.value}/${props.allergy.id}`, callbacks);
}
const onEnter = (event) => { if (event.target?.tagName === 'INPUT') event.preventDefault(); };
</script>

<template>
    <UiDetailPanel :open="open" :title="title" @update:open="(v) => (v ? null : requestClose())">
        <ProvenanceTags v-if="allergy" :provenance="allergy.provenance" :can-verify="!readOnly" :verifying="verifying" class="panel-prov" data-testid="panel-provenance" @verify="verify" />
        <dl v-if="readOnly && allergy" class="allergy-read" data-testid="allergy-readonly">
            <div><dt>{{ t('health.allergy.substance') }}</dt><dd>{{ allergy.substance }}</dd></div>
            <div v-if="allergy.reaction"><dt>{{ t('health.allergy.reaction') }}</dt><dd>{{ allergy.reaction }}</dd></div>
            <div><dt>{{ t('health.allergy.severity') }}</dt><dd>{{ severityText }}</dd></div>
        </dl>
        <form v-else novalidate data-testid="allergy-form" class="allergy-form" @submit.prevent="save" @keydown.enter="onEnter">
            <UiAlert v-if="serverError" variant="danger" live>{{ t('health.error.load') }}</UiAlert>

            <UiField :label="`${t('health.allergy.substance')} ${t('health.required')}`" :error="errors.substance">
                <UiInput v-model="form.substance" autocomplete="off" maxlength="120" />
            </UiField>
            <UiField :label="t('health.allergy.reaction')" :error="errors.reaction">
                <UiInput v-model="form.reaction" autocomplete="off" maxlength="200" />
            </UiField>
            <UiField :label="`${t('health.allergy.severity')} ${t('health.required')}`" :error="errors.severity">
                <UiSelect v-model="form.severity" :options="severityOptions" />
            </UiField>
        </form>
        <template #footer>
            <UiButton v-if="editing && !readOnly" variant="ghost" class="allergy-delete" data-testid="allergy-delete" @click="deleteOpen = true">{{ t('health.allergy.delete') }}</UiButton>
            <UiButton variant="secondary" @click="requestClose">{{ t('ui.close') }}</UiButton>
            <UiButton v-if="!readOnly" variant="primary" :loading="processing" data-testid="allergy-save" @click="save">{{ t('health.allergy.save') }}</UiButton>
        </template>
    </UiDetailPanel>

    <UiConfirmDialog
        v-model:open="deleteOpen"
        :title="t('health.allergy.delete_title')"
        :body="t('health.allergy.delete_body')"
        :confirm-label="t('health.allergy.delete')"
        :cancel-label="t('health.allergy.delete_keep')"
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
.allergy-form { display: flex; flex-direction: column; }
.allergy-read { display: flex; flex-direction: column; gap: var(--space-3); margin: 0; }
.allergy-read dt { font-size: var(--text-sm); color: var(--color-text-muted); }
.allergy-read dd { margin: 0; overflow-wrap: anywhere; }
.allergy-delete { margin-inline-end: auto; color: var(--color-danger); }
</style>
