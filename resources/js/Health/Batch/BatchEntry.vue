<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Check, Pill, ShieldAlert, Stethoscope, Syringe, X } from '@lucide/vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import { securityFetch } from '../../Security/securityFetch.js';
import { handleHealthRefusal } from '../healthRefusal.js';
import EntryEditForm from '../Ai/EntryEditForm.vue';
import { TYPE_SECTION, entryRows, entryTitle } from '../Ai/entryText.js';

// One finding of a batch document. The member marks it Approve or Reject; every change sends the full choices object to
// the server and shows the server's answer (no optimistic state, only a busy flag). Text written by the model is
// rendered by interpolation only. Nothing is kept in the browser.
const props = defineProps({
    personId: { type: String, required: true },
    batchId: { type: String, required: true },
    itemId: { type: String, required: true },
    entry: { type: Object, required: true }, // {key, type, fields}
    choices: { type: Object, default: () => ({}) }, // all saved choices of the item {key: {decision, values?}}
    specialties: { type: Array, default: () => [] },
    errors: { type: Object, default: null }, // {field: [message key]} from a refused Continue
    readOnly: { type: Boolean, default: false },
});
const emit = defineEmits(['saved', 'superseded']);
const { t, te, locale } = useI18n();
const ids = { title: useId() };
const root = ref(null);

const TYPE_ICONS = { visit: Stethoscope, medication: Pill, allergy: ShieldAlert, vaccination: Syringe };
const busy = ref(false);
const failed = ref(false);
const editing = ref(false);
const formErrors = ref(null);
const formRef = ref(null);

const choice = computed(() => props.choices?.[props.entry.key] ?? null);
const decision = computed(() => choice.value?.decision ?? null);
const fields = computed(() => (decision.value === 'approve' ? { ...(props.entry.fields ?? {}), ...(choice.value?.values ?? {}) } : (props.entry.fields ?? {})));
const i18n = { t, te, get locale() { return locale.value; } };
const typeWord = computed(() => t(`health.ai.review.${TYPE_SECTION[props.entry.type]}`));
const title = computed(() => entryTitle(props.entry.type, fields.value, i18n));
const shownTitle = computed(() => title.value || typeWord.value);
const rows = computed(() => entryRows(props.entry.type, fields.value, i18n));
const edited = computed(() => decision.value === 'approve' && Object.keys(choice.value?.values ?? {}).length > 0);
const named = (key) => t(key, { type: typeWord.value, title: title.value || typeWord.value });

// A refused Continue opens the entry's form with the server's messages under the fields.
watch(() => props.errors, (value) => {
    if (value && Object.keys(value).length > 0) {
        editing.value = true;
        formErrors.value = { ...value };
        nextTick(() => formRef.value?.focusFirst());
    }
}, { immediate: true });

async function send(next) {
    if (busy.value) return false;
    busy.value = true;
    failed.value = false;
    const result = await securityFetch(`/health/people/${props.personId}/upload-batches/${props.batchId}/items/${props.itemId}/choices`, { method: 'PUT', body: { choices: next }, replay: true });
    if (result.error === 'cancelled') { busy.value = false; return false; }
    busy.value = false;
    if (result.ok) {
        emit('saved', result.data?.choices ?? {});
        return true;
    }
    if (result.status === 409) {
        emit('superseded');
        return false;
    }
    if (result.status === 423 && handleHealthRefusal(result.error)) return false;
    failed.value = true;
    return false;
}

async function mark(value) {
    const next = { ...(props.choices ?? {}) };
    if (decision.value === value) delete next[props.entry.key];
    else next[props.entry.key] = { decision: value };
    editing.value = false;
    await send(next);
}

async function save(values) {
    const next = { ...(props.choices ?? {}), [props.entry.key]: { decision: 'approve', values } };
    formErrors.value = null;
    if (await send(next)) {
        editing.value = false;
        await nextTick();
        root.value?.querySelector('[data-action="edit"]')?.focus();
    }
}
function cancelEdit() {
    editing.value = false;
    formErrors.value = null;
}
</script>

<template>
    <li ref="root" class="entry-item" :data-entry-key="entry.key" data-testid="batch-entry">
        <UiCard>
            <div class="entry" role="group" :aria-labelledby="ids.title" :aria-busy="busy ? 'true' : undefined" :data-decision="decision ?? 'none'">
                <div class="entry__head">
                    <component :is="TYPE_ICONS[entry.type]" :size="20" :stroke-width="1.75" aria-hidden="true" class="entry__icon" />
                    <strong :id="ids.title" class="entry__title">{{ shownTitle }}</strong>
                </div>

                <EntryEditForm
                    v-if="editing"
                    ref="formRef"
                    :type="entry.type"
                    :fields="fields"
                    :errors="formErrors"
                    :saving="busy"
                    :specialties="specialties"
                    @save="save"
                    @cancel="cancelEdit"
                />
                <dl v-else-if="decision !== 'reject' && rows.length > 0" class="entry__fields" data-testid="entry-fields">
                    <div v-for="row in rows" :key="row.label" class="entry__row">
                        <dt>{{ row.label }}</dt>
                        <dd>{{ row.value }}</dd>
                    </div>
                </dl>
                <p v-if="edited && !editing" class="entry__detail" data-testid="entry-edited">{{ t('health.ai.review.edited_detail') }}</p>

                <UiAlert v-if="failed" variant="danger" live data-testid="entry-failed">{{ t('health.ai.review.action_failed') }}</UiAlert>

                <div v-if="!readOnly && !editing" class="entry__actions">
                    <div class="entry__toggle" role="group" :aria-label="t('health.batch.review_title')">
                        <UiButton
                            :variant="decision === 'approve' ? 'primary' : 'secondary'"
                            :icon="Check"
                            :disabled="busy"
                            :aria-pressed="decision === 'approve' ? 'true' : 'false'"
                            :aria-label="`${t('health.batch.approve')}: ${shownTitle}`"
                            data-action="approve"
                            data-testid="entry-approve"
                            @click="mark('approve')"
                        >{{ decision === 'approve' ? t('health.batch.approved') : t('health.batch.approve') }}</UiButton>
                        <UiButton
                            :variant="decision === 'reject' ? 'primary' : 'secondary'"
                            :icon="X"
                            :disabled="busy"
                            :aria-pressed="decision === 'reject' ? 'true' : 'false'"
                            :aria-label="`${t('health.batch.reject')}: ${shownTitle}`"
                            data-action="reject"
                            data-testid="entry-reject"
                            @click="mark('reject')"
                        >{{ decision === 'reject' ? t('health.batch.rejected') : t('health.batch.reject') }}</UiButton>
                    </div>
                    <UiButton v-if="decision === 'approve'" variant="ghost" :disabled="busy" :aria-label="named('health.ai.review.edit_aria')" data-action="edit" data-testid="entry-edit" @click="editing = true">{{ t('health.batch.edit') }}</UiButton>
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
.entry__fields { display: flex; flex-direction: column; gap: var(--space-2); margin: 0; }
.entry__row { display: grid; grid-template-columns: minmax(6rem, 10rem) 1fr; gap: var(--space-1) var(--space-3); }
.entry__row dt { font-size: var(--text-sm); font-weight: 600; color: var(--color-text-muted); }
.entry__row dd { margin: 0; overflow-wrap: anywhere; white-space: pre-wrap; }
@container (max-width: 420px) { .entry__row { grid-template-columns: 1fr; } }
.entry__detail { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.entry__actions { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }
.entry__toggle { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.entry[data-decision='reject'] .entry__title { color: var(--color-text-muted); text-decoration: line-through; }
</style>
