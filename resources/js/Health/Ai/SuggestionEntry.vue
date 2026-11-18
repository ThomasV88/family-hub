<script setup>
import { computed, inject, nextTick, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Ban, Check, CircleDashed, Pill, ShieldAlert, Stethoscope, Syringe, Undo2 } from '@lucide/vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import UiConfirmDialog from '../../Components/ui/UiConfirmDialog.vue';
import UiTag from '../../Components/ui/UiTag.vue';
import { useToast } from '../../Components/ui/useToast.js';
import { securityFetch } from '../../Security/securityFetch.js';
import { handleHealthRefusal } from '../healthRefusal.js';
import EntryEditForm from './EntryEditForm.vue';
import { TYPE_SECTION, entryRows, entryTitle } from './entryText.js';

// One proposed entry. Everything the model wrote is rendered by text interpolation only (never HTML, never in a
// toast). No optimistic UI: the server answer decides the state, a repeat answer simply shows the real state. Nothing
// is kept in the browser.
const props = defineProps({
    personId: { type: String, required: true },
    extractionId: { type: String, required: true },
    entry: { type: Object, required: true }, // {key, type, fields, state, recordId, edited}
    readOnly: { type: Boolean, default: false },
});
const emit = defineEmits(['decided', 'superseded', 'edit-requested']);
const { t, te, locale } = useI18n();
const toast = useToast();
const ids = { title: useId(), tag: useId() };
const root = ref(null);

const busy = ref(false);
const failed = ref(false);
const serverErrors = ref([]);

// Edit-in-place state is coordinated by the page (one edit at a time) and reached through provide/inject, so the
// list in between needs no extra props. Without a provider the entry simply has no edit form.
const edit = inject('reviewEdit', null);
const editing = computed(() => Boolean(edit) && edit.editingKey.value === props.entry.key && props.entry.state === 'pending' && !props.readOnly);
const formErrors = ref(null);
const formRef = ref(null);
const dirty = ref(false);
const discardOpen = ref(false);
watch(editing, (open) => {
    dirty.value = false;
    formErrors.value = open && edit.errors.value ? { ...edit.errors.value } : null;
    if (open) nextTick(() => formRef.value?.focusFirst());
}, { immediate: true });
function onDirty(value) {
    dirty.value = value;
    edit?.setDirty(props.entry.key, value);
}

const TYPE_ICONS = { visit: Stethoscope, medication: Pill, allergy: ShieldAlert, vaccination: Syringe };
const typeWord = computed(() => t(`health.ai.review.${TYPE_SECTION[props.entry.type]}`));

const fields = computed(() => props.entry.fields ?? {});
const i18n = { t, te, get locale() { return locale.value; } };
const title = computed(() => entryTitle(props.entry.type, fields.value, i18n));
const shownTitle = computed(() => title.value || typeWord.value);
const rows = computed(() => entryRows(props.entry.type, fields.value, i18n));

const state = computed(() => props.entry.state);
const tag = computed(() => {
    if (state.value === 'accepted') return { icon: Check, variant: 'success', text: t('health.ai.review.entry_accepted') };
    if (state.value === 'rejected') return { icon: Ban, variant: 'neutral', text: t('health.ai.review.entry_rejected') };
    return { icon: CircleDashed, variant: 'neutral', text: t('health.ai.review.entry_pending') };
});
const named = (key) => (title.value ? t(key, { type: typeWord.value, title: title.value }) : `${t(key.replace('_aria', ''))} ${typeWord.value}`);
const recordHref = computed(() => `/health/people/${props.personId}?panel=${props.entry.type}&id=${props.entry.recordId}`);

const base = computed(() => `/health/people/${props.personId}/ai/${props.extractionId}/entries/${props.entry.key}`);

async function focusAction(name) {
    await nextTick();
    root.value?.querySelector(`[data-action="${name}"]`)?.focus();
}

// One server call; returns the answer's entry state or null when the entry was not changed.
async function call(method, tail, body, inForm = false) {
    busy.value = true;
    failed.value = false;
    serverErrors.value = [];
    const result = await securityFetch(`${base.value}/${tail}`, body === undefined ? { method, replay: true } : { method, body, replay: true });
    // The member closed "Confirm it's you": nothing was sent, the draft stays open.
    if (result.error === 'cancelled') return null;
    busy.value = false;
    if (result.ok) return result.data;
    if (result.status === 409) {
        if (inForm) edit?.close(props.entry.key);
        emit('superseded');
        return null;
    }
    if (result.status === 423) {
        // Refused again after the confirmation (or no dialog available): the draft is discarded and the refusal flow takes over.
        if (inForm) edit?.close(props.entry.key);
        handleHealthRefusal(result.error);
        return null;
    }
    if (result.status === 422 && inForm) {
        formErrors.value = { ...(result.errors ?? {}) };
        return null;
    }
    if (result.status === 422) {
        const messages = [];
        for (const [field, value] of Object.entries(result.errors ?? {})) {
            for (const m of Array.isArray(value) ? value : [value]) {
                if (field === 'severity' && props.entry.type === 'allergy') messages.push(t('health.ai.review.needs_severity'));
                else if (typeof m === 'string' && te(m)) messages.push(t(m));
            }
        }
        serverErrors.value = [...new Set(messages)];
        emit('edit-requested', { key: props.entry.key, errors: result.errors ?? {} });
        return null;
    }
    failed.value = true;
    return null;
}

async function accept() {
    if (busy.value) return;
    const data = await call('POST', 'accept');
    if (data === null) {
        if (failed.value || serverErrors.value.length > 0) focusAction('accept');
        return;
    }
    const next = data.state;
    emit('decided', { key: props.entry.key, action: 'accept', state: next, recordId: data.recordId ?? null, edited: Boolean(data.edited) });
    if (next === 'accepted') toast.show(t(`health.ai.review.saved_${props.entry.type}`));
    if (next === 'rejected') focusAction('undo');
}
// Save the edited values through the accept endpoint (the server re-validates with the Phase 7 rules).
async function saveEdit(values) {
    if (busy.value) return;
    formErrors.value = null;
    const data = await call('POST', 'accept', { values }, true);
    if (data === null) {
        if (editing.value && failed.value) formRef.value?.focusFirst();
        return;
    }
    const next = data.state;
    edit?.close(props.entry.key);
    emit('decided', { key: props.entry.key, action: 'accept', state: next, recordId: data.recordId ?? null, edited: next === 'accepted' ? true : Boolean(data.edited) });
    if (next === 'accepted') toast.show(t(`health.ai.review.saved_${props.entry.type}`));
    if (next === 'rejected') focusAction('undo');
}
async function collapse() {
    edit?.close(props.entry.key);
    await focusAction('edit');
}
function cancelEdit() {
    if (busy.value) return;
    if (dirty.value) discardOpen.value = true;
    else collapse();
}
function keepEditing() {
    // The dialog restores focus to the button that opened it; the form takes it back right after.
    setTimeout(() => formRef.value?.focusFirst(), 0);
}
function discard() {
    dirty.value = false;
    collapse();
}
async function reject() {
    if (busy.value) return;
    const data = await call('POST', 'reject');
    if (data === null) {
        if (failed.value) focusAction('reject');
        return;
    }
    emit('decided', { key: props.entry.key, action: 'reject', state: data.state, recordId: data.recordId ?? null, edited: Boolean(data.edited) });
    if (data.state === 'rejected') focusAction('undo');
}
async function undo() {
    if (busy.value) return;
    const data = await call('DELETE', 'reject');
    if (data === null) {
        if (failed.value) focusAction('undo');
        return;
    }
    emit('decided', { key: props.entry.key, action: 'undo', state: data.state, recordId: data.recordId ?? null, edited: Boolean(data.edited) });
    if (data.state === 'pending') focusAction('accept');
}
</script>

<template>
    <li ref="root" class="entry-item" :data-entry-key="entry.key" data-testid="suggestion-entry">
        <UiCard>
            <div class="entry" role="group" :aria-labelledby="ids.title" :aria-describedby="ids.tag" :aria-busy="busy ? 'true' : undefined" :data-state="state">
                <div class="entry__head">
                    <component :is="TYPE_ICONS[entry.type]" :size="20" :stroke-width="1.75" aria-hidden="true" class="entry__icon" />
                    <strong :id="ids.title" class="entry__title">{{ shownTitle }}</strong>
                    <UiTag :id="ids.tag" :variant="tag.variant" :icon="tag.icon" class="entry__tag" data-testid="entry-tag">{{ tag.text }}</UiTag>
                </div>

                <EntryEditForm
                    v-if="editing"
                    ref="formRef"
                    :type="entry.type"
                    :fields="fields"
                    :errors="formErrors"
                    :saving="busy"
                    :specialties="edit.specialties.value"
                    @save="saveEdit"
                    @cancel="cancelEdit"
                    @dirty-change="onDirty"
                />
                <UiConfirmDialog
                    v-if="editing"
                    v-model:open="discardOpen"
                    :title="t('health.ai.review.discard_title')"
                    :body="shownTitle"
                    :confirm-label="t('health.ai.review.discard_confirm')"
                    :cancel-label="t('health.ai.review.keep_editing')"
                    @confirm="discard"
                    @cancel="keepEditing"
                />

                <template v-if="state !== 'rejected' && !editing">
                    <dl v-if="rows.length > 0" class="entry__fields" data-testid="entry-fields">
                        <div v-for="row in rows" :key="row.label" class="entry__row">
                            <dt>{{ row.label }}</dt>
                            <dd>{{ row.value }}</dd>
                        </div>
                    </dl>
                    <p v-if="state === 'accepted' && entry.edited" class="entry__detail" data-testid="entry-edited">{{ t('health.ai.review.edited_detail') }}</p>
                </template>

                <ul v-if="serverErrors.length > 0 && !editing" class="entry__errors" data-testid="entry-errors">
                    <li v-for="message in serverErrors" :key="message">{{ message }}</li>
                </ul>
                <UiAlert v-if="failed" variant="danger" live data-testid="entry-failed">{{ t('health.ai.review.action_failed') }}</UiAlert>

                <div v-if="state === 'accepted' && entry.recordId" class="entry__actions">
                    <UiButton variant="secondary" :href="recordHref" data-action="open" data-testid="entry-open">{{ t('health.ai.review.open_in_record') }}</UiButton>
                </div>
                <div v-else-if="state === 'pending' && !readOnly && !editing" class="entry__actions">
                    <UiButton variant="secondary" :loading="busy" :disabled="busy" :aria-label="named('health.ai.review.accept_aria')" data-action="accept" data-testid="entry-accept" @click="accept">{{ t('health.ai.review.accept') }}</UiButton>
                    <UiButton variant="secondary" :disabled="busy" :aria-label="named('health.ai.review.edit_aria')" data-action="edit" data-testid="entry-edit" @click="emit('edit-requested', { key: entry.key, errors: null })">{{ t('health.ai.review.edit') }}</UiButton>
                    <UiButton variant="ghost" :disabled="busy" :aria-label="named('health.ai.review.reject_aria')" data-action="reject" data-testid="entry-reject" @click="reject">{{ t('health.ai.review.reject') }}</UiButton>
                </div>
                <div v-else-if="state === 'rejected' && !readOnly" class="entry__actions">
                    <UiButton variant="ghost" :icon="Undo2" :disabled="busy" :aria-label="named('health.ai.review.undo_aria')" data-action="undo" data-testid="entry-undo" @click="undo">{{ t('health.ai.review.undo') }}</UiButton>
                </div>
            </div>
        </UiCard>
    </li>
</template>

<style scoped>
.entry-item { list-style: none; min-width: 0; }
.entry { display: flex; flex-direction: column; gap: var(--space-3); min-width: 0; }
.entry__head { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); min-width: 0; }
.entry__icon { flex: none; color: var(--color-text-muted); }
.entry__title { flex: 1 1 8rem; min-width: 0; font-size: var(--text-base); font-weight: 600; overflow-wrap: anywhere; }
.entry__tag { white-space: normal; max-width: 100%; }
.entry__fields { display: flex; flex-direction: column; gap: var(--space-2); margin: 0; }
.entry__row { display: grid; grid-template-columns: minmax(6rem, 10rem) 1fr; gap: var(--space-1) var(--space-3); }
.entry__row dt { font-size: var(--text-sm); font-weight: 600; color: var(--color-text-muted); }
.entry__row dd { margin: 0; overflow-wrap: anywhere; white-space: pre-wrap; }
@container (max-width: 420px) { .entry__row { grid-template-columns: 1fr; } }
.entry__detail { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.entry__errors { margin: 0; padding-inline-start: var(--space-5); color: var(--color-danger); font-size: var(--text-sm); }
.entry__actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
@media (prefers-reduced-motion: no-preference) {
    .entry { transition: opacity 150ms ease; }
}
</style>
