<script setup>
import { computed, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiAlert from '../../Components/ui/UiAlert.vue';
import HelpTip from '../../Components/ui/HelpTip.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import { useToast } from '../../Components/ui/useToast.js';
import { securityFetch } from '../../Security/securityFetch.js';
import { handleHealthRefusal } from '../healthRefusal.js';
import DocumentPane from '../Ai/DocumentPane.vue';
import AdjustmentPanel from './AdjustmentPanel.vue';
import BatchEntry from './BatchEntry.vue';
import BatchItemActions from './BatchItemActions.vue';

// The review of the current document of a batch: the document next to its findings, Approve or Reject on every one,
// and Continue. Everything shown comes from the server (page props and the answers of the choices route); nothing is
// kept in the browser. Text the model wrote is interpolated only; toasts carry counts, never finding text.
const props = defineProps({
    personId: { type: String, required: true },
    batchId: { type: String, required: true },
    current: { type: Object, required: true }, // {item, document, review, choices, adjustments}
    limits: { type: Object, default: () => ({ maxRescans: 5 }) },
    ai: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['changed']);
const { t, locale } = useI18n();
const toast = useToast();
const ids = { doc: useId(), entries: useId() };

const ORDER = [['visits', 'visit'], ['medications', 'medication'], ['allergies', 'allergy'], ['vaccinations', 'vaccination']];
const HEADINGS = { visit: 'section_visits', medication: 'section_medications', allergy: 'section_allergies', vaccination: 'section_vaccinations' };

const item = computed(() => props.current.item);
const extraction = computed(() => props.current.review?.extraction ?? null);
const specialties = computed(() => props.current.review?.specialties ?? []);
const sections = computed(() => ORDER.map(([list, type]) => ({
    type,
    entries: (extraction.value?.sections?.[list] ?? []).map((e) => ({ key: e.key, type, fields: e.fields ?? {} })),
})).filter((s) => s.entries.length > 0));
const keys = computed(() => sections.value.flatMap((s) => s.entries.map((e) => e.key)));

// The saved decisions, seeded from the props and then replaced by what the server answers.
const choices = ref({ ...(props.current.choices ?? {}) });
watch(() => [props.current.item.id, props.current.choices], () => { choices.value = { ...(props.current.choices ?? {}) }; });
const decisionOf = (key) => choices.value[key]?.decision ?? null;
const approved = computed(() => keys.value.filter((k) => decisionOf(k) === 'approve').length);
const rejected = computed(() => keys.value.filter((k) => decisionOf(k) === 'reject').length);
const undecided = computed(() => keys.value.length - approved.value - rejected.value);

const ready = computed(() => item.value.state === 'review' && extraction.value !== null);
const noFindings = computed(() => ready.value && keys.value.length === 0);

const filter = ref('');
const busy = ref(false);
const failed = ref(false);
const notReady = ref(false);
const entryErrors = ref({});

function onSaved(next) {
    choices.value = { ...next };
    entryErrors.value = {};
}
function onSuperseded() {
    notReady.value = true;
    emit('changed');
}

async function proceed() {
    if (busy.value || !ready.value) return;
    busy.value = true;
    failed.value = false;
    notReady.value = false;
    entryErrors.value = {};
    const result = await securityFetch(`/health/people/${props.personId}/upload-batches/${props.batchId}/items/${item.value.id}/continue`, { method: 'POST', replay: true });
    busy.value = false;
    if (result.error === 'cancelled') return;
    if (result.ok) {
        toast.show(t('health.batch.continue_done', { approved: result.data?.approved ?? 0, rejected: result.data?.rejected ?? 0 }));
        emit('changed');
        return;
    }
    if (result.status === 409) {
        notReady.value = true;
        return;
    }
    if (result.status === 422) {
        // Keys are entries.<key>.<field>: hand each entry its own fields so the message shows under the field.
        const byEntry = {};
        for (const [name, messages] of Object.entries(result.errors ?? {})) {
            const m = /^entries\.([^.]+)\.(.+)$/.exec(name);
            if (m) (byEntry[m[1]] ??= {})[m[2]] = messages;
        }
        entryErrors.value = byEntry;
        failed.value = Object.keys(byEntry).length === 0;
        return;
    }
    if (result.status === 423 && handleHealthRefusal(result.error)) return;
    failed.value = true;
}
</script>

<template>
    <div class="batch-review" data-testid="batch-review-body">
        <div class="batch-review__layout">
            <div class="batch-review__doc" role="group" :aria-labelledby="ids.doc" data-testid="batch-doc">
                <h3 :id="ids.doc" class="batch-review__heading">{{ current.document.title }}</h3>
                <DocumentPane :person-id="personId" :document="current.document" :image-filter="filter" />
                <AdjustmentPanel
                    v-if="item.state !== 'done'"
                    :person-id="personId"
                    :batch-id="batchId"
                    :item-id="item.id"
                    :adjustments="current.adjustments"
                    @update:filter="(v) => (filter = v)"
                />
            </div>

            <div class="batch-review__entries" role="group" :aria-labelledby="ids.entries">
                <h3 :id="ids.entries" class="batch-review__heading">{{ t('health.batch.review_title') }}</h3>
                <UiAlert v-if="!ready && item.state !== 'failed' && item.state !== 'done'" variant="info" data-testid="batch-not-ready">{{ t('health.batch.not_ready') }}</UiAlert>
                <p v-else-if="noFindings" class="batch-review__none" data-testid="batch-no-findings">{{ t('health.batch.no_findings') }}</p>

                <template v-if="ready">
                    <div v-for="section in sections" :key="section.type" class="batch-review__section" role="group" :aria-label="t(`health.ai.review.${HEADINGS[section.type]}`)" :data-section="section.type">
                        <h4 class="batch-review__sub">{{ t(`health.ai.review.${HEADINGS[section.type]}`) }}</h4>
                        <ul class="batch-review__list">
                            <BatchEntry
                                v-for="entry in section.entries"
                                :key="entry.key"
                                :person-id="personId"
                                :batch-id="batchId"
                                :item-id="item.id"
                                :entry="entry"
                                :choices="choices"
                                :specialties="specialties"
                                :errors="entryErrors[entry.key] ?? null"
                                @saved="onSaved"
                                @superseded="onSuperseded"
                            />
                        </ul>
                    </div>

                    <div class="batch-review__bar" data-testid="batch-continue-bar">
                        <p v-if="undecided > 0 && keys.length > 0" class="batch-review__warn" role="status" data-testid="batch-undecided">{{ t('health.batch.undecided', { count: undecided }, undecided) }}</p>
                        <p class="batch-review__counts" data-testid="batch-counts">{{ t('health.batch.approved') }}: {{ approved }} · {{ t('health.batch.rejected') }}: {{ rejected }}</p>
                        <p class="batch-review__hint">{{ t('health.batch.continue_hint') }}</p>
                        <UiButton variant="primary" :loading="busy" :disabled="busy" data-testid="batch-continue" @click="proceed">{{ t('health.batch.continue') }}</UiButton>
                        <HelpTip topic="health.batch_continue" :label="t('health.batch.continue')" />
                    </div>
                </template>

                <UiAlert v-if="notReady" variant="info" live data-testid="batch-continue-notready">{{ t('health.batch.not_ready') }}</UiAlert>
                <UiAlert v-if="failed" variant="danger" live data-testid="batch-continue-failed">{{ t('health.ai.review.action_failed') }}</UiAlert>
                <BatchItemActions :person-id="personId" :batch-id="batchId" :item="item" :limits="limits" :ai="ai" @changed="emit('changed')" />
            </div>
        </div>
    </div>
</template>

<style scoped>
.batch-review { container-type: inline-size; min-width: 0; }
.batch-review__layout { display: flex; flex-direction: column; gap: var(--space-5); min-width: 0; }
@container (min-width: 900px) {
    .batch-review__layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); align-items: start; gap: var(--space-6); }
}
.batch-review__doc, .batch-review__entries { display: flex; flex-direction: column; gap: var(--space-3); min-width: 0; }
.batch-review__heading { margin: 0; font-size: var(--text-lg); font-weight: 600; overflow-wrap: anywhere; }
.batch-review__sub { margin: 0; font-size: var(--text-base); font-weight: 600; }
.batch-review__section { display: flex; flex-direction: column; gap: var(--space-2); }
.batch-review__list { display: flex; flex-direction: column; gap: var(--space-3); margin: 0; padding: 0; list-style: none; }
.batch-review__none { margin: 0; color: var(--color-text-muted); }
.batch-review__bar { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-2); padding-top: var(--space-3); border-top: 1px solid var(--color-border); }
.batch-review__bar > * { margin: 0; }
.batch-review__warn { color: var(--color-warning-text, var(--color-text)); font-weight: 600; }
.batch-review__counts { font-weight: 600; }
.batch-review__hint { font-size: var(--text-sm); color: var(--color-text-muted); }
</style>
