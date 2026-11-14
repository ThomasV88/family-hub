<script setup>
import { computed, nextTick, provide, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, ListChecks } from '@lucide/vue';
import AppShell from '../../Layouts/AppShell.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiConfirmDialog from '../../Components/ui/UiConfirmDialog.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import UiErrorState from '../../Components/ui/UiErrorState.vue';
import UiSkeleton from '../../Components/ui/UiSkeleton.vue';
import { useFrameWidth } from '../../Shell/shellState.js';
import AiStatusTag from '../../Health/Ai/AiStatusTag.vue';
import DocumentPane from '../../Health/Ai/DocumentPane.vue';
import ReviewPaneToggle from '../../Health/Ai/ReviewPaneToggle.vue';
import SuggestionList from '../../Health/Ai/SuggestionList.vue';

// Side-by-side review of one AI proposal. The server decides everything shown; the page only holds the per-entry
// states it was given and the answers it gets back. No draft, no selection and no page image is kept in any browser
// storage (the Inertia history stays encrypted). Nothing is saved until the member acts on an entry.
const props = defineProps({
    person: { type: Object, default: null },
    document: { type: Object, default: null },
    extraction: { type: Object, default: null },
    newestExtractionId: { type: String, default: null },
    consentOn: { type: Boolean, default: false },
    specialties: { type: Array, default: () => [] },
    pane: { type: String, default: null },
    loading: { type: Boolean, default: false },
});
const { t, locale } = useI18n();

const WIDE = 800;
const root = ref(null);
const width = useFrameWidth(root);
const wide = computed(() => width.value >= WIDE);

const ORDER = [['visits', 'visit'], ['medications', 'medication'], ['allergies', 'allergy'], ['vaccinations', 'vaccination']];

// Per-entry state, seeded from the props and then driven by the server answers.
const states = reactive({});
for (const [list, type] of ORDER) {
    for (const item of props.extraction?.sections?.[list] ?? []) {
        states[item.key] = { state: item.state, recordId: item.recordId ?? null, edited: Boolean(item.edited), type };
    }
}
const sections = computed(() => ORDER.map(([list, type]) => ({
    type,
    entries: (props.extraction?.sections?.[list] ?? []).map((item) => ({
        key: item.key,
        type,
        fields: item.fields ?? {},
        state: states[item.key]?.state ?? item.state,
        recordId: states[item.key]?.recordId ?? null,
        edited: states[item.key]?.edited ?? false,
    })),
})));
const orderedKeys = computed(() => sections.value.flatMap((s) => s.entries.map((e) => e.key)));
const total = computed(() => orderedKeys.value.length);
const pending = computed(() => orderedKeys.value.filter((k) => states[k]?.state === 'pending').length);
const superseded = ref(Boolean(props.extraction?.readOnly));

const personUrl = computed(() => `/health/people/${props.person?.id}`);
const documentUrl = computed(() => `${personUrl.value}?panel=document&id=${props.document?.id}`);
const reviewUrl = computed(() => `${personUrl.value}/documents/${props.document?.id}/review`);
const newestUrl = computed(() => (props.newestExtractionId ? `${reviewUrl.value}?extraction=${props.newestExtractionId}` : reviewUrl.value));

const runDate = computed(() => {
    const d = new Date(props.extraction?.runAt ?? '');
    return Number.isNaN(d.getTime()) ? '' : new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(d);
});
const pagesLine = computed(() => {
    const e = props.extraction;
    return e && e.pagesRead != null && e.pagesTotal != null && e.pagesRead < e.pagesTotal
        ? t('health.ai.status.pages_read', { n: e.pagesRead }, e.pagesRead)
        : '';
});
const leftText = computed(() => (pending.value === 0 ? t('health.ai.review.all_handled_short') : t('health.ai.review.left', { n: pending.value }, pending.value)));

// Stacked layout: which pane shows. Backed by ?pane= through a history replace, never an Inertia visit.
const visiblePane = ref(props.pane === 'document' ? 'document' : 'entries');
const documentHeading = ref(null);
const entriesHeading = ref(null);
const completion = ref(null);
async function setPane(value) {
    visiblePane.value = value;
    const url = new URL(window.location.href);
    url.searchParams.set('pane', value);
    window.history.replaceState(window.history.state, '', url);
    await nextTick();
    (value === 'document' ? documentHeading : entriesHeading).value?.focus();
}
const showDocument = computed(() => wide.value || visiblePane.value === 'document');
const showEntries = computed(() => wide.value || visiblePane.value === 'entries');
function skipToSuggestions() {
    entriesHeading.value?.focus();
}

// One polite status region for every announcement.
const live = ref('');
function announce(message) {
    live.value = '';
    nextTick(() => { live.value = message; });
}

async function focusAfterAccept(key) {
    await nextTick();
    const keys = orderedKeys.value;
    const start = keys.indexOf(key);
    const target = [...keys.slice(start + 1), ...keys.slice(0, start)].find((k) => states[k]?.state === 'pending');
    if (target) {
        document.querySelector(`[data-entry-key="${target}"] [data-action="accept"]`)?.focus();
    } else {
        completion.value?.focus();
    }
}

function onDecided({ key, action, state, recordId, edited }) {
    const prev = states[key] ?? {};
    states[key] = { ...prev, state, recordId, edited };
    if (action === 'accept' && state === 'accepted') {
        announce(pending.value === 0
            ? t('health.ai.review.completion')
            : `${t('health.ai.review.announce_added')}. ${t('health.ai.review.left', { n: pending.value }, pending.value)}`);
        focusAfterAccept(key);
    } else if (action === 'accept' && state === 'rejected') {
        announce(t('health.ai.review.announce_rejected'));
    } else if (action === 'reject' && state === 'rejected') {
        announce(t('health.ai.review.announce_rejected'));
    } else if (action === 'undo' && state === 'pending') {
        announce(t('health.ai.review.announce_undone'));
    }
}

// Edit in place: one entry at a time. The page holds which entry is open, the server errors that opened it and
// whether its form is dirty; the entries reach this through provide/inject. The drafts themselves live in the form
// components (memory only) and nowhere else. Opening another entry while the open one is clean switches silently;
// while it is dirty a discard confirm runs first, and "Keep editing" returns focus into the open form.
const editingKey = ref(null);
const editErrors = ref(null);
const editDirty = ref(false);
const switchTo = ref(null); // {key, errors} waiting on the discard confirm
const switchOpen = ref(false);
const specialtyList = computed(() => props.specialties);

function openEdit(key, errors) {
    editingKey.value = key;
    editErrors.value = errors && Object.keys(errors).length > 0 ? errors : null;
    editDirty.value = false;
}
function closeEdit(key) {
    if (editingKey.value !== key) return;
    editingKey.value = null;
    editErrors.value = null;
    editDirty.value = false;
}
function setDirty(key, value) {
    if (editingKey.value === key) editDirty.value = value;
}
provide('reviewEdit', { editingKey, errors: editErrors, specialties: specialtyList, close: closeEdit, setDirty });

function onEditRequested({ key, errors }) {
    if (editingKey.value === key) return;
    if (editingKey.value !== null && editDirty.value) {
        switchTo.value = { key, errors };
        switchOpen.value = true;
        return;
    }
    openEdit(key, errors);
}
function confirmSwitch() {
    const next = switchTo.value;
    switchTo.value = null;
    if (next) openEdit(next.key, next.errors);
}
function keepCurrent() {
    switchTo.value = null;
    const key = editingKey.value;
    // The dialog gives focus back to the button that opened it; take it into the open form right after.
    setTimeout(() => {
        const el = document.querySelector(`[data-entry-key="${key}"] form [aria-invalid="true"], [data-entry-key="${key}"] form input, [data-entry-key="${key}"] form select, [data-entry-key="${key}"] form textarea`);
        el?.focus();
    }, 0);
}
const switchLabel = computed(() => {
    const entry = sections.value.flatMap((s) => s.entries).find((e) => e.key === editingKey.value);
    const f = entry?.fields ?? {};
    const first = [f.doctor, f.name, f.substance, f.vaccine].find((v) => typeof v === 'string' && v.trim() !== '');
    return first?.trim() ?? t('health.ai.review.pane_suggestions');
});
// A superseded run is read-only: nothing stays open for editing.
watch(superseded, (value) => { if (value) closeEdit(editingKey.value); });

const retry = () => router.reload();
</script>

<template>
    <AppShell current="health">
        <Head :title="t('health.title')" />
        <div ref="root" class="ai-review" data-testid="ai-review">
            <header v-if="person && document" class="ai-review__header">
                <div><UiButton variant="ghost" :icon="ArrowLeft" :href="documentUrl" data-testid="back-document">{{ t('health.ai.review.back_document') }}</UiButton></div>
                <h1 class="ai-review__title">{{ t('health.ai.review.title') }}</h1>
                <template v-if="extraction">
                    <p class="ai-review__meta">
                        <AiStatusTag :status="superseded ? 'superseded' : 'ready'" />
                        <time v-if="runDate" :datetime="extraction.runAt" data-testid="run-date">{{ t('health.ai.review.run_at', { date: runDate }) }}</time>
                    </p>
                    <p class="ai-review__progress" data-testid="progress">{{ leftText }}</p>
                    <UiAlert variant="info" data-testid="warning">{{ t('health.ai.review.warning') }}</UiAlert>
                    <p v-if="pagesLine" class="ai-review__muted" data-testid="pages-read">{{ pagesLine }}</p>
                    <p v-if="!consentOn" class="ai-review__muted" data-testid="consent-off">{{ t('health.ai.review.consent_off_review') }}</p>
                    <UiAlert v-if="superseded" variant="warning" data-testid="superseded-banner">
                        {{ t('health.ai.review.superseded_banner') }}
                        <template #actions>
                            <UiButton variant="secondary" :href="newestUrl" data-testid="open-newest">{{ t('health.ai.review.open_newest') }}</UiButton>
                        </template>
                    </UiAlert>
                </template>
            </header>
            <h1 v-else class="ai-review__title">{{ t('health.ai.review.title') }}</h1>

            <div v-if="loading" class="ai-review__skeleton" data-testid="review-loading" role="status" aria-busy="true">
                <span class="visually-hidden">{{ t('ui.loading') }}</span>
                <div class="ai-review__panes ai-review__panes--wide">
                    <UiSkeleton height="360px" />
                    <div class="ai-review__skeleton-list"><UiSkeleton height="120px" /><UiSkeleton height="120px" /><UiSkeleton height="120px" /></div>
                </div>
            </div>
            <UiErrorState v-else-if="!person || !document || !extraction" :heading-level="2" data-testid="review-error" @retry="retry" />
            <UiEmptyState v-else-if="total === 0" :title="t('health.ai.review.empty_title')" :body="t('health.ai.review.empty_body')" :icon="ListChecks" :heading-level="2" data-testid="review-empty">
                <template #action>
                    <UiButton variant="secondary" :href="documentUrl">{{ t('health.ai.review.back_document') }}</UiButton>
                </template>
            </UiEmptyState>
            <template v-else>
                <a v-if="wide" href="#ai-suggestions" class="ai-review__skip" data-testid="skip-link" @click.prevent="skipToSuggestions">{{ t('health.ai.review.skip_link') }}</a>
                <ReviewPaneToggle v-else :model-value="visiblePane" @update:model-value="setPane" />

                <div :class="['ai-review__panes', { 'ai-review__panes--wide': wide }]">
                    <section v-show="showDocument" :hidden="!showDocument" class="ai-review__pane ai-review__pane--document" aria-labelledby="ai-document-h" data-testid="pane-document">
                        <h2 id="ai-document-h" ref="documentHeading" tabindex="-1" class="ai-review__pane-title">{{ t('health.ai.review.pane_document') }}</h2>
                        <DocumentPane :person-id="person.id" :document="document" />
                    </section>
                    <section v-show="showEntries" :hidden="!showEntries" class="ai-review__pane" aria-labelledby="ai-suggestions-h" data-testid="pane-entries">
                        <h2 id="ai-suggestions-h" ref="entriesHeading" tabindex="-1" class="ai-review__pane-title">{{ t('health.ai.review.pane_suggestions') }}</h2>
                        <div id="ai-suggestions" class="ai-review__entries">
                            <SuggestionList
                                :person-id="person.id"
                                :extraction-id="extraction.id"
                                :sections="sections"
                                :document-date="extraction.documentDate"
                                :specialty-key="extraction.specialtyKey"
                                :read-only="superseded"
                                @decided="onDecided"
                                @superseded="superseded = true"
                                @edit-requested="onEditRequested"
                            />
                            <div v-if="pending === 0" ref="completion" tabindex="-1" class="ai-review__completion" data-testid="completion">
                                <UiAlert variant="success" live>
                                    {{ t('health.ai.review.completion') }}
                                    <template #actions>
                                        <UiButton variant="secondary" :href="personUrl">{{ t('health.ai.review.back_person') }}</UiButton>
                                        <UiButton variant="secondary" :href="documentUrl">{{ t('health.ai.review.back_document') }}</UiButton>
                                    </template>
                                </UiAlert>
                            </div>
                        </div>
                    </section>
                </div>
            </template>

            <UiConfirmDialog
                v-model:open="switchOpen"
                :title="t('health.ai.review.discard_title')"
                :body="switchLabel"
                :confirm-label="t('health.ai.review.discard_confirm')"
                :cancel-label="t('health.ai.review.keep_editing')"
                @confirm="confirmSwitch"
                @cancel="keepCurrent"
            />

            <div class="visually-hidden" role="status" data-testid="ai-live">{{ live }}</div>
        </div>
    </AppShell>
</template>

<style scoped>
.ai-review { display: flex; flex-direction: column; gap: var(--space-4); min-width: 0; }
.ai-review__header { display: flex; flex-direction: column; gap: var(--space-3); align-items: flex-start; }
.ai-review__header > :deep(.ui-alert) { align-self: stretch; }
.ai-review__title { margin: 0; font-size: var(--text-2xl); font-weight: 600; line-height: 1.2; }
.ai-review__meta { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); margin: 0; color: var(--color-text-muted); font-size: var(--text-sm); }
.ai-review__progress { margin: 0; font-weight: 600; }
.ai-review__muted { margin: 0; color: var(--color-text-muted); font-size: var(--text-sm); }
.ai-review__panes { display: flex; flex-direction: column; gap: var(--space-4); min-width: 0; }
.ai-review__panes--wide { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: var(--space-6); align-items: start; }
.ai-review__pane { min-width: 0; }
.ai-review__panes--wide .ai-review__pane--document { position: sticky; top: var(--space-4); max-height: calc(100dvh - var(--space-8)); overflow: auto; }
.ai-review__pane-title { margin: 0 0 var(--space-3); font-size: var(--text-xl); font-weight: 600; }
.ai-review__pane-title:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.ai-review__entries { display: flex; flex-direction: column; gap: var(--space-4); }
.ai-review__completion:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.ai-review__skeleton-list { display: flex; flex-direction: column; gap: var(--space-3); }
.ai-review__skip { position: absolute; inset-inline-start: var(--space-2); z-index: 10; padding: var(--space-2) var(--space-4); border-radius: var(--radius-md); background: var(--color-surface); color: var(--color-primary); font-weight: 600; transform: translateY(-300%); }
.ai-review__skip:focus { transform: none; }
</style>
