<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Ban, Sparkles } from '@lucide/vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiDialog from '../../Components/ui/UiDialog.vue';
import { useToast } from '../../Components/ui/useToast.js';
import { securityFetch } from '../../Security/securityFetch.js';
import { handleHealthRefusal } from '../healthRefusal.js';
import AiNoticeDialog from './AiNoticeDialog.vue';
import AiProvenanceLine from './AiProvenanceLine.vue';
import AiStatusTag from './AiStatusTag.vue';
import { formatWhen, isPast } from './aiWhen.js';
import HelpTip from '../../Components/ui/HelpTip.vue';

// Start, follow and cancel the AI reading of one document. Model-free: only run state is rendered, never anything
// the AI produced. Nothing is stored in the browser; the server answer always wins over the props it was given.
const props = defineProps({
    personId: { type: String, required: true },
    document: { type: Object, required: true },
    // PersonPage `ai` prop: {canRun, available, providers, noticeAcknowledged, textNotice}
    ai: { type: Object, default: () => ({ canRun: false, available: false, providers: ['codex'], noticeAcknowledged: false }) },
    // useAiStatus() result shared by the whole page: {statuses, failedPolls, stoppedLongWait, stalled, refresh, markQueued}
    poller: { type: Object, required: true },
    consentOn: { type: Boolean, default: false },
});
const emit = defineEmits(['go-header']);
const { t, locale } = useI18n();
const toast = useToast();
const ids = { sends: useId(), reason: useId() };

const entry = computed(() => props.poller.statuses.value?.[props.document.id] ?? null);
const status = computed(() => entry.value?.status ?? null);
const waitingQuota = computed(() => status.value === 'waiting');
// A run in flight (queued, running or waiting for quota): the tag, the detail and Cancel run replace Run AI.
const waiting = computed(() => status.value === 'queued' || status.value === 'running' || waitingQuota.value);
const appLocale = computed(() => ({ 'sk-SK': 'sk', 'pl-PL': 'pl', 'en-GB': 'en' }[locale.value] ?? locale.value));
const availableAt = computed(() => (waitingQuota.value ? entry.value?.availableAt ?? null : null));
// The set a 409 answer reported as current wins over the page-load prop until the prop changes again.
const providersLocal = ref(null);
watch(() => props.ai?.providers, () => { providersLocal.value = null; });
const providers = computed(() => {
    if (Array.isArray(providersLocal.value) && providersLocal.value.length > 0) return providersLocal.value;
    return Array.isArray(props.ai?.providers) && props.ai.providers.length > 0 ? props.ai.providers : ['codex'];
});
// Whether the notice and the provider line also say that the recognised text is stored here (full-text reading on).
// A 409 answer that reopens the notice can turn it on for a page that loaded before the switch was.
const textLocal = ref(null);
watch(() => props.ai?.textNotice, () => { textLocal.value = null; });
const textNotice = computed(() => textLocal.value ?? Boolean(props.ai?.textNotice));
const providerKind = computed(() => {
    const hasCodex = providers.value.includes('codex');
    const hasCopilot = providers.value.includes('copilot');
    if (hasCodex && hasCopilot) return 'both';
    return hasCopilot ? 'copilot' : 'codex';
});
const providerLine = computed(() => `${textNotice.value ? 'provider_text' : 'provider'}_${providerKind.value}`);
const noticeVariant = computed(() => ({ both: 'multi', copilot: 'github', codex: 'codex' }[providerKind.value]));
const canRun = computed(() => Boolean(props.ai?.canRun));

// Local corrections from a 409 answer; the matching prop wins again when it changes.
const consentLocal = ref(null);
const availableLocal = ref(null);
const unsupportedLocal = ref(false);
const ackLocal = ref(false);
watch(() => props.consentOn, () => { consentLocal.value = null; });
watch(() => props.ai?.available, () => { availableLocal.value = null; });

const consent = computed(() => consentLocal.value ?? props.consentOn);
const available = computed(() => availableLocal.value ?? (props.poller.available?.value ?? props.ai?.available ?? false));
const unsupported = computed(() => unsupportedLocal.value);
const acknowledged = computed(() => ackLocal.value || Boolean(props.ai?.noticeAcknowledged));

// The one reason the button is dead, if any (HEIC cannot be fixed by consent, so it comes first).
const blocked = computed(() => {
    if (unsupported.value) return { key: 'heic_line' };
    if (!consent.value) return { key: 'consent_off_line', link: true };
    if (!available.value) return { key: 'unavailable_line' };
    return null;
});

const noticeOpen = ref(false);
const rerunOpen = ref(false);
const failed = ref(false);
const busy = ref(false);
const cancelling = ref(false);
const announcement = ref('');
const cancelButton = ref(null);
const runButton = ref(null);
const keepCurrent = ref(null);

const pending = computed(() => Number(entry.value?.pending ?? 0));
const hasEarlierReady = computed(() => status.value === 'ready' || Boolean(entry.value?.readyExtractionId));
const reviewUrl = computed(() => `/health/people/${props.personId}/documents/${props.document.id}/review`);
const runLabel = computed(() => (status.value === 'ready' || status.value === 'failed' ? t('health.ai.run.run_again') : t('health.ai.run.run')));
const runVariant = computed(() => (status.value === 'ready' ? 'ghost' : 'secondary'));
const describedBy = computed(() => (blocked.value ? ids.reason : ids.sends));
const readyCount = computed(() => (typeof entry.value?.entryCount === 'number' ? entry.value.entryCount : null));
const partialPages = computed(() => (status.value === 'ready' || status.value === 'text_saved') && entry.value?.pagesRead != null && entry.value?.pagesTotal != null && entry.value.pagesRead < entry.value.pagesTotal);
// The recognised text of this run is already stored (step 1 done). A text-only run has no step 2.
const textSaved = computed(() => entry.value?.textSaved === true);
const detail = computed(() => {
    switch (status.value) {
        case 'queued': return t('health.ai.status.wait_detail');
        case 'running': return textSaved.value && entry.value?.mode !== 'text' ? t('health.ai.status.text_saved_preparing') : t('health.ai.status.wait_detail');
        case 'text_saved': return t('health.ai.status.text_saved_detail');
        case 'ready': return readyCount.value ? t('health.ai.status.ready_detail', { n: readyCount.value }, readyCount.value) : '';
        case 'waiting': return t('health.ai.status.waiting_detail');
        case 'failed': {
            const code = { quota_timeout: 'quota_timeout_detail', quota_exhausted: 'quota_exhausted_detail', no_agent: 'no_agent_detail', notice_required: 'notice_required_detail' }[entry.value?.failureCode];
            return t(`health.ai.status.${code ?? 'failed_detail'}`);
        }
        case 'cancelled': return entry.value.cancelReason === 'consent' ? t('health.ai.status.consent_detail') : t('health.ai.status.cancelled_detail');
        default: return '';
    }
});

// A second line while waiting for quota or after a failure: the text is kept, so say so.
const extraDetail = computed(() => {
    if (!textSaved.value) return '';
    if (status.value === 'waiting') return t('health.ai.status.waiting_text_saved');
    return status.value === 'failed' ? t('health.ai.status.failed_text_saved') : '';
});

function announce(text) {
    announcement.value = '';
    nextTick(() => { announcement.value = text; });
}

// "The text is saved" is announced once per run, whether it comes with the first save or with the end of a text-only run.
let savedAnnounced = false;
watch(() => entry.value?.extractionId ?? null, () => { savedAnnounced = false; });
function announceSaved() {
    if (savedAnnounced) return;
    savedAnnounced = true;
    announce(t('health.ai.status.announce_text_saved'));
}
watch(() => status.value === 'running' && textSaved.value, (now, before) => {
    if (before === false && now === true) announceSaved();
});

// Only transitions are announced; the first value seen on mount and unchanged polls say nothing.
watch(status, (next, previous) => {
    if (previous === undefined || previous === null || next === previous) return;
    if (next === 'text_saved') return announceSaved();
    if (next === 'waiting') {
        const at = entry.value?.availableAt;
        announce(at && !isPast(at) ? t('health.ai.status.waiting_announce', { when: formatWhen(at, appLocale.value) }) : t('health.ai.status.waiting_soon'));
        return;
    }
    const key = { running: 'announce_running', ready: 'announce_ready', failed: 'announce_failed', cancelled: 'announce_cancelled' }[next];
    if (key) announce(t(`health.ai.status.${key}`));
});

function pressBlocked() {
    if (blocked.value) announce(t(`health.ai.run.${blocked.value.key}`));
}

function begin() {
    if (busy.value) return;
    if (blocked.value) return pressBlocked();
    failed.value = false;
    if (pending.value > 0 && hasEarlierReady.value) {
        rerunOpen.value = true;
        return;
    }
    proceed();
}

function proceed() {
    if (!acknowledged.value) {
        noticeOpen.value = true;
        return;
    }
    send(false);
}

// The acknowledgment names the provider set the dialog showed; the server refuses it when that set is no longer the enabled one.
function confirmNotice(shown) {
    noticeOpen.value = false;
    send(true, Array.isArray(shown) ? shown : providers.value);
}

async function send(withAck, shown = null) {
    busy.value = true;
    const result = await securityFetch(`/health/people/${props.personId}/documents/${props.document.id}/ai`, {
        method: 'POST',
        body: withAck ? { acknowledged: true, providers: [...shown] } : {},
        replay: true,
    });
    busy.value = false;
    if (result.ok) {
        ackLocal.value = true;
        props.poller.markQueued(props.document.id, result.data?.extractionId ?? null);
        toast.show(t('health.ai.run.started_toast'));
        props.poller.refresh();
        await nextTick();
        cancelButton.value?.$el?.focus?.();
        return;
    }
    if (result.status === 423 && handleHealthRefusal(result.error)) return;
    if (result.status === 409) {
        const reason = result.data?.reason;
        if (reason === 'consent_off') { consentLocal.value = false; return; }
        if (reason === 'unavailable') { availableLocal.value = false; return; }
        if (reason === 'unsupported') { unsupportedLocal.value = true; return; }
        if (reason === 'active') { props.poller.refresh(); return; }
        if (reason === 'notice_required') {
            // The enabled providers changed after the page loaded: ask again with the current set.
            if (Array.isArray(result.data?.providers) && result.data.providers.length > 0) providersLocal.value = result.data.providers;
            if (typeof result.data?.textNotice === 'boolean') textLocal.value = result.data.textNotice;
            ackLocal.value = false;
            noticeOpen.value = true;
            return;
        }
    }
    failed.value = true;
}

async function cancel() {
    if (cancelling.value || !entry.value?.extractionId) return;
    cancelling.value = true;
    const result = await securityFetch(`/health/people/${props.personId}/ai/${entry.value.extractionId}/cancel`, { method: 'POST', body: {}, replay: true });
    cancelling.value = false;
    if (result.ok) {
        toast.show(t('health.ai.run.cancelled_toast'));
        await props.poller.refresh();
        await nextTick();
        // The Cancel run button is gone now; keyboard focus goes back to Run AI.
        runButton.value?.$el?.focus?.();
        return;
    }
    if (result.status === 423 && handleHealthRefusal(result.error)) return;
    failed.value = true;
}

const pollFailed = computed(() => props.poller.failedPolls.value >= 3);
</script>

<template>
    <section class="run-ai" :aria-busy="waiting ? 'true' : undefined" data-testid="run-ai">
        <p class="visually-hidden" role="status" data-testid="ai-announce">{{ announcement }}</p>

        <div v-if="entry" class="run-ai__state">
            <AiStatusTag :status="entry.status" :cancel-reason="entry.cancelReason" :entry-count="canRun ? readyCount : null" :available-at="availableAt" :failure-code="entry.failureCode ?? null" />
            <template v-if="canRun">
                <p v-if="detail" class="run-ai__detail" data-testid="ai-detail">{{ detail }}</p>
                <p v-if="extraDetail" class="run-ai__detail" data-testid="ai-detail-text">{{ extraDetail }}</p>
                <AiProvenanceLine v-if="status === 'ready' && entry.agent" :agent="entry.agent" :model="entry.model ?? null" />
                <p v-if="partialPages" class="run-ai__detail" data-testid="ai-pages">{{ t('health.ai.status.pages_read', { n: entry.pagesRead }, entry.pagesRead) }}</p>
            </template>
        </div>

        <template v-if="canRun">
            <UiAlert v-if="waiting && poller.stoppedLongWait.value" variant="info" data-testid="ai-long-wait">{{ t('health.ai.run.still_waiting') }}</UiAlert>
            <UiAlert v-if="waitingQuota && poller.stalled?.value" variant="info" data-testid="ai-stalled">{{ t('health.ai.status.still_waiting') }}</UiAlert>
            <div v-if="waiting && pollFailed" class="run-ai__poll" data-testid="ai-poll-failed">
                <span>{{ t('health.ai.run.poll_failed') }}</span>
                <UiButton variant="ghost" size="sm" @click="poller.refresh()">{{ t('health.ai.run.try_again') }}</UiButton>
            </div>
            <UiAlert v-if="failed" variant="danger" live data-testid="ai-run-failed">{{ t('health.ai.run.queue_failed') }}</UiAlert>

            <div class="run-ai__actions">
                <UiButton v-if="waiting" ref="cancelButton" variant="secondary" :icon="Ban" :loading="cancelling" data-testid="ai-cancel" @click="cancel">{{ t('health.ai.run.cancel') }}</UiButton>
                <template v-else>
                    <UiButton v-if="status === 'ready' || entry?.readyExtractionId" :variant="status === 'ready' ? 'primary' : 'secondary'" :href="reviewUrl" data-testid="ai-review">{{ t('health.ai.run.review') }}</UiButton>
                    <UiButton
                        ref="runButton"
                        :variant="runVariant"
                        :icon="Sparkles"
                        :loading="busy"
                        :aria-disabled="blocked ? 'true' : undefined"
                        :aria-describedby="describedBy"
                        data-testid="ai-run"
                        @click="begin"
                    >{{ runLabel }}</UiButton>
                    <HelpTip topic="health.ai_run" :label="t('health.ai.run.run')" />
                </template>
            </div>
            <template v-if="!waiting">
                <p v-if="blocked" :id="ids.reason" class="run-ai__line" data-testid="ai-blocked">
                    {{ t(`health.ai.run.${blocked.key}`) }}
                    <button v-if="blocked.link" type="button" class="run-ai__link" data-testid="ai-go-header" @click="emit('go-header')">{{ t('health.ai.run.consent_off_link') }}</button>
                </p>
                <p v-else :id="ids.sends" class="run-ai__line" data-testid="ai-sends">{{ t(`health.ai.run.${providerLine}`) }}</p>
            </template>
        </template>

        <AiNoticeDialog :open="noticeOpen" :variant="noticeVariant" :providers="providers" :text="textNotice" @confirm="confirmNotice" @cancel="noticeOpen = false" />
        <UiDialog :open="rerunOpen" :title="t('health.ai.run.rerun_title')" :initial-focus="() => keepCurrent" @update:open="(v) => { if (!v) rerunOpen = false; }">
            <p class="run-ai__body">{{ t('health.ai.run.rerun_body') }}</p>
            <template #footer>
                <UiButton ref="keepCurrent" variant="secondary" data-testid="ai-rerun-keep" @click="rerunOpen = false">{{ t('health.ai.run.rerun_keep') }}</UiButton>
                <UiButton variant="primary" data-testid="ai-rerun-confirm" @click="() => { rerunOpen = false; proceed(); }">{{ t('health.ai.run.run_again') }}</UiButton>
            </template>
        </UiDialog>
    </section>
</template>

<style scoped>
.run-ai { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-2); }
.run-ai__state { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-1); }
.run-ai__detail, .run-ai__line { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); max-width: 64ch; }
.run-ai__actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.run-ai__poll { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); font-size: var(--text-sm); }
.run-ai__body { margin: 0; font-size: var(--text-base); }
.run-ai__link { background: none; border: 0; padding: 0; min-height: 44px; font: inherit; color: var(--color-link, var(--color-primary)); text-decoration: underline; cursor: pointer; }
</style>
