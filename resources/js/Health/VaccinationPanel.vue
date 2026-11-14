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

// Create, edit, mark-as-given and read-only presentation of one vaccination. The draft lives in component memory only:
// no Inertia form-state persistence and no browser storage. Free text is interpolated, never rendered as HTML.
const props = defineProps({
    open: { type: Boolean, default: false },
    personId: { type: String, required: true },
    vaccination: { type: Object, default: null },
    canEdit: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);
const verifying = ref(false);
function verify() {
    if (!props.vaccination || verifying.value) return;
    router.post(`/health/people/${props.personId}/vaccinations/${props.vaccination.id}/verify`, {}, {
        preserveScroll: true,
        onStart: () => { verifying.value = true; },
        onFinish: () => { verifying.value = false; },
    });
}
const { t, te } = useI18n();

const blank = () => ({ vaccine: '', given_on: '', next_due_on: '' });
const fromVaccination = (v) => ({ vaccine: v.vaccine ?? '', given_on: v.givenOn ?? '', next_due_on: v.nextDueOn ?? '' });
const form = ref(blank());
const initial = ref(JSON.stringify(blank()));
const errors = ref({});
const serverError = ref(false);
const processing = ref(false);
const discardOpen = ref(false);
const deleteOpen = ref(false);

// Mark as given: a small inline form. The date defaults to today in the browser; the server validates it (not in the future).
const marking = ref(false);
const mark = ref({ given_on: '', next_due_on: '' });
const markErrors = ref({});
const localToday = () => {
    const d = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};

const editing = computed(() => props.vaccination !== null);
const readOnly = computed(() => !props.canEdit);
const title = computed(() => (editing.value ? t('health.vaccination.title_edit') : t('health.vaccination.title_new')));
const dirty = computed(() => JSON.stringify(form.value) !== initial.value);

watch(() => [props.open, props.vaccination?.id], () => {
    if (!props.open) return;
    form.value = props.vaccination ? fromVaccination(props.vaccination) : blank();
    initial.value = JSON.stringify(form.value);
    errors.value = {};
    markErrors.value = {};
    marking.value = false;
    serverError.value = false;
}, { immediate: true });

function requestClose() {
    if (!readOnly.value && dirty.value) {
        discardOpen.value = true;
        return;
    }
    emit('close');
}

const FIELDS = ['vaccine', 'given_on', 'next_due_on'];
const message = (value) => (typeof value === 'string' && te(value) ? t(value) : t('health.error.load'));

// ISO dates compare as strings. The server stays the authority and shows the same message.
function localErrors(f, requireVaccine) {
    const out = {};
    if (requireVaccine && f.vaccine.trim() === '') out.vaccine = t('health.vaccination.err_vaccine');
    if (f.given_on === '') out.given_on = t('health.vaccination.err_given_on');
    if (f.given_on && f.next_due_on && f.next_due_on < f.given_on) out.next_due_on = t('health.vaccination.err_next_due');
    return out;
}

const base = computed(() => `/health/people/${props.personId}/vaccinations`);
const callbacksFor = (target) => ({
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
        target.value = mapped;
        serverError.value = unknown || Object.keys(mapped).length === 0;
    },
    onHttpException: () => { serverError.value = true; },
    onNetworkError: () => { serverError.value = true; },
    onFinish: () => { processing.value = false; },
});

function save() {
    errors.value = localErrors(form.value, true);
    if (Object.keys(errors.value).length > 0) return;
    const f = form.value;
    const data = { vaccine: f.vaccine, given_on: f.given_on, next_due_on: f.next_due_on || null };
    if (editing.value) router.patch(`${base.value}/${props.vaccination.id}`, data, callbacksFor(errors));
    else router.post(base.value, data, callbacksFor(errors));
}
function remove() {
    router.delete(`${base.value}/${props.vaccination.id}`, callbacksFor(errors));
}
function startMark() {
    mark.value = { given_on: localToday(), next_due_on: '' };
    markErrors.value = {};
    marking.value = true;
}
function submitMark() {
    markErrors.value = localErrors(mark.value, false);
    if (Object.keys(markErrors.value).length > 0) return;
    router.post(`${base.value}/${props.vaccination.id}/given`, { given_on: mark.value.given_on, next_due_on: mark.value.next_due_on || null }, callbacksFor(markErrors));
}
const onEnter = (event) => { if (event.target?.tagName === 'INPUT') event.preventDefault(); };
</script>

<template>
    <UiDetailPanel :open="open" :title="title" @update:open="(v) => (v ? null : requestClose())">
        <ProvenanceTags v-if="vaccination" :provenance="vaccination.provenance" :can-verify="!readOnly" :verifying="verifying" class="panel-prov" data-testid="panel-provenance" @verify="verify" />
        <dl v-if="readOnly && vaccination" class="vacc-read" data-testid="vaccination-readonly">
            <div><dt>{{ t('health.vaccination.vaccine') }}</dt><dd>{{ vaccination.vaccine }}</dd></div>
            <div><dt>{{ t('health.vaccination.given_on') }}</dt><dd>{{ vaccination.givenOn }}</dd></div>
            <div v-if="vaccination.nextDueOn"><dt>{{ t('health.vaccination.next_due') }}</dt><dd>{{ vaccination.nextDueOn }}</dd></div>
        </dl>
        <template v-else>
            <form novalidate data-testid="vaccination-form" class="vacc-form" @submit.prevent="save" @keydown.enter="onEnter">
                <UiAlert v-if="serverError" variant="danger" live>{{ t('health.error.load') }}</UiAlert>

                <UiField :label="`${t('health.vaccination.vaccine')} ${t('health.required')}`" :error="errors.vaccine">
                    <UiInput v-model="form.vaccine" autocomplete="off" maxlength="120" />
                </UiField>
                <UiField :label="`${t('health.vaccination.given_on')} ${t('health.required')}`" :error="errors.given_on">
                    <UiInput v-model="form.given_on" type="date" />
                </UiField>
                <UiField :label="t('health.vaccination.next_due')" :hint="t('health.vaccination.next_due_hint')" :error="errors.next_due_on">
                    <UiInput v-model="form.next_due_on" type="date" />
                </UiField>
            </form>
            <section v-if="editing" class="vacc-mark" data-testid="vaccination-mark">
                <UiButton v-if="!marking" variant="secondary" data-testid="vaccination-mark-open" @click="startMark">{{ t('health.vaccination.mark_given') }}</UiButton>
                <form v-else novalidate data-testid="vaccination-mark-form" class="vacc-form" @submit.prevent="submitMark" @keydown.enter="onEnter">
                    <UiField :label="`${t('health.vaccination.given_on')} ${t('health.required')}`" :error="markErrors.given_on">
                        <UiInput v-model="mark.given_on" type="date" />
                    </UiField>
                    <UiField :label="t('health.vaccination.next_due')" :hint="t('health.vaccination.next_due_hint')" :error="markErrors.next_due_on">
                        <UiInput v-model="mark.next_due_on" type="date" />
                    </UiField>
                    <div class="vacc-mark__actions">
                        <UiButton variant="primary" :loading="processing" data-testid="vaccination-mark-submit" @click="submitMark">{{ t('health.vaccination.mark_given') }}</UiButton>
                        <UiButton variant="ghost" @click="marking = false">{{ t('ui.close') }}</UiButton>
                    </div>
                </form>
            </section>
        </template>
        <template #footer>
            <UiButton v-if="editing && !readOnly" variant="ghost" class="vacc-delete" data-testid="vaccination-delete" @click="deleteOpen = true">{{ t('health.vaccination.delete') }}</UiButton>
            <UiButton variant="secondary" @click="requestClose">{{ t('ui.close') }}</UiButton>
            <UiButton v-if="!readOnly" variant="primary" :loading="processing" data-testid="vaccination-save" @click="save">{{ t('health.vaccination.save') }}</UiButton>
        </template>
    </UiDetailPanel>

    <UiConfirmDialog
        v-model:open="deleteOpen"
        :title="t('health.vaccination.delete_title')"
        :body="t('health.vaccination.delete_body')"
        :confirm-label="t('health.vaccination.delete')"
        :cancel-label="t('health.vaccination.delete_keep')"
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
.vacc-form { display: flex; flex-direction: column; }
.vacc-mark { margin-top: var(--space-4); padding-top: var(--space-4); border-top: 1px solid var(--color-border); display: flex; flex-direction: column; align-items: flex-start; }
.vacc-mark__actions { display: flex; gap: var(--space-2); }
.vacc-read { display: flex; flex-direction: column; gap: var(--space-3); margin: 0; }
.vacc-read dt { font-size: var(--text-sm); color: var(--color-text-muted); }
.vacc-read dd { margin: 0; overflow-wrap: anywhere; }
.vacc-delete { margin-inline-end: auto; color: var(--color-danger); }
</style>
