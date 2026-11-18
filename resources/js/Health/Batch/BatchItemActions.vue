<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RefreshCw, Trash2 } from '@lucide/vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import HelpTip from '../../Components/ui/HelpTip.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiConfirmDialog from '../../Components/ui/UiConfirmDialog.vue';
import { useToast } from '../../Components/ui/useToast.js';
import { securityFetch } from '../../Security/securityFetch.js';
import { handleHealthRefusal } from '../healthRefusal.js';
import AiNoticeDialog from '../Ai/AiNoticeDialog.vue';

// What can be done with one unfinished document apart from reviewing it: scan it again (with the AI notice when the
// server asks for it), keep it without findings or remove it from the batch. The server decides every step (limit,
// notice, consent); this only shows its answer. Nothing is kept in the browser.
const props = defineProps({
    personId: { type: String, required: true },
    batchId: { type: String, required: true },
    item: { type: Object, required: true }, // {id, state, rescanCount, failureCode?}
    limits: { type: Object, default: () => ({ maxRescans: 5 }) },
    ai: { type: Object, default: () => ({}) }, // {providers, noticeAcknowledged, textNotice}
});
const emit = defineEmits(['changed']);
const { t } = useI18n();
const toast = useToast();

const base = computed(() => `/health/people/${props.personId}/upload-batches/${props.batchId}/items/${props.item.id}`);
const maxRescans = computed(() => Number(props.limits?.maxRescans ?? 5));
const used = ref(Number(props.item.rescanCount ?? 0));
watch(() => [props.item.id, props.item.rescanCount], () => { used.value = Number(props.item.rescanCount ?? 0); });
const left = computed(() => Math.max(0, maxRescans.value - used.value));
const limitReached = computed(() => left.value === 0);
const failedItem = computed(() => props.item.state === 'failed');
const unfinished = computed(() => props.item.state !== 'done');

const busy = ref(false);
const alertKey = ref('');
const failed = ref(false);
const noticeOpen = ref(false);
const providersLocal = ref(null);
const textLocal = ref(null);
const acknowledged = ref(false);
watch(() => props.ai, () => { providersLocal.value = null; textLocal.value = null; });
const providers = computed(() => {
    if (Array.isArray(providersLocal.value) && providersLocal.value.length > 0) return providersLocal.value;
    return Array.isArray(props.ai?.providers) && props.ai.providers.length > 0 ? props.ai.providers : ['codex'];
});
const textNotice = computed(() => textLocal.value ?? Boolean(props.ai?.textNotice));
const variant = computed(() => {
    const codex = providers.value.includes('codex');
    const copilot = providers.value.includes('copilot');
    if (codex && copilot) return 'multi';
    return copilot ? 'github' : 'codex';
});

function rescan() {
    if (busy.value || limitReached.value) return;
    alertKey.value = '';
    failed.value = false;
    if (!acknowledged.value && !props.ai?.noticeAcknowledged) {
        noticeOpen.value = true;
        return;
    }
    sendRescan(false);
}
function confirmNotice(shown) {
    noticeOpen.value = false;
    sendRescan(true, Array.isArray(shown) ? shown : providers.value);
}
async function sendRescan(withAck, shown = null) {
    busy.value = true;
    const body = withAck ? { acknowledged: true, providers: [...shown], ...(textNotice.value ? { textNotice: true } : {}) } : {};
    const result = await securityFetch(`${base.value}/rescan`, { method: 'POST', body, replay: true });
    busy.value = false;
    if (result.error === 'cancelled') return;
    if (result.ok) {
        acknowledged.value = true;
        used.value += 1;
        toast.show(t('health.batch.rescan_queued'));
        emit('changed');
        return;
    }
    if (result.status === 423 && handleHealthRefusal(result.error)) return;
    if (result.status === 409) {
        const reason = result.data?.reason;
        if (reason === 'limit') { used.value = maxRescans.value; alertKey.value = 'limit'; return; }
        if (reason === 'consent_off') { alertKey.value = 'consent_off_start'; return; }
        if (reason === 'unavailable') { alertKey.value = 'unavailable'; return; }
        if (reason === 'done') { emit('changed'); return; }
        if (reason === 'notice_required') {
            if (Array.isArray(result.data?.providers) && result.data.providers.length > 0) providersLocal.value = result.data.providers;
            if (typeof result.data?.textNotice === 'boolean') textLocal.value = result.data.textNotice;
            acknowledged.value = false;
            noticeOpen.value = true;
            return;
        }
    }
    failed.value = true;
}

// Keep the document without findings: the same Continue as in the review, nothing is saved from it.
async function keep() {
    if (busy.value) return;
    busy.value = true;
    failed.value = false;
    alertKey.value = '';
    const result = await securityFetch(`${base.value}/continue`, { method: 'POST', replay: true });
    busy.value = false;
    if (result.error === 'cancelled') return;
    if (result.ok) {
        toast.show(t('health.batch.continue_done', { approved: result.data?.approved ?? 0, rejected: result.data?.rejected ?? 0 }));
        emit('changed');
        return;
    }
    if (result.status === 423 && handleHealthRefusal(result.error)) return;
    if (result.status === 409) { alertKey.value = 'not_ready'; return; }
    failed.value = true;
}

const removeOpen = ref(false);
async function remove() {
    if (busy.value) return;
    busy.value = true;
    failed.value = false;
    const result = await securityFetch(base.value, { method: 'DELETE', replay: true });
    busy.value = false;
    if (result.error === 'cancelled') return;
    if (result.ok) {
        emit('changed');
        return;
    }
    if (result.status === 423 && handleHealthRefusal(result.error)) return;
    failed.value = true;
}
</script>

<template>
    <div v-if="unfinished" class="item-actions" data-testid="item-actions">
        <UiAlert v-if="failedItem" variant="info" data-testid="item-failed">{{ t('health.batch.failed_hint') }}</UiAlert>

        <div class="item-actions__rescan">
            <UiButton
                :variant="failedItem ? 'primary' : 'secondary'"
                :icon="RefreshCw"
                :loading="busy"
                :disabled="busy || limitReached"
                data-testid="item-rescan"
                @click="rescan"
            >{{ failedItem ? t('health.ai.run.try_again') : t('health.batch.rescan') }}</UiButton>
            <HelpTip topic="health.batch_rescan" :label="t('health.batch.rescan')" />
            <p class="item-actions__hint" data-testid="item-rescan-hint">{{ t('health.batch.rescan_hint') }}</p>
            <p v-if="limitReached" class="item-actions__limit" role="status" data-testid="item-rescan-limit">{{ t('health.batch.rescan_limit', { count: maxRescans }) }}</p>
            <p v-else class="item-actions__hint" data-testid="item-rescan-left">{{ t('health.batch.rescan_left', { count: left }) }}</p>
        </div>

        <div class="item-actions__row">
            <UiButton v-if="failedItem" variant="secondary" :disabled="busy" data-testid="item-keep" @click="keep">{{ t('health.batch.keep_without') }}</UiButton>
            <UiButton variant="ghost" :icon="Trash2" :disabled="busy" data-testid="item-remove" @click="removeOpen = true">{{ t('health.batch.remove_doc') }}</UiButton>
        </div>

        <UiAlert v-if="alertKey" variant="info" live data-testid="item-alert">{{ alertKey === 'limit' ? t('health.batch.rescan_limit', { count: maxRescans }) : t(`health.batch.${alertKey}`) }}</UiAlert>
        <UiAlert v-if="failed" variant="danger" live data-testid="item-action-failed">{{ t('health.ai.run.queue_failed') }}</UiAlert>

        <AiNoticeDialog :open="noticeOpen" :variant="variant" :providers="providers" :text="textNotice" @confirm="confirmNotice" @cancel="noticeOpen = false" />
        <UiConfirmDialog
            v-model:open="removeOpen"
            :title="t('health.batch.remove_doc_title')"
            :body="t('health.batch.remove_doc_body')"
            :confirm-label="t('health.batch.remove_doc')"
            :cancel-label="t('health.batch.remove_doc_keep')"
            @confirm="remove"
        />
    </div>
</template>

<style scoped>
.item-actions { display: flex; flex-direction: column; gap: var(--space-3); min-width: 0; padding-top: var(--space-3); border-top: 1px solid var(--color-border); }
.item-actions__rescan { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-1); }
.item-actions__rescan > p { margin: 0; }
.item-actions__hint { font-size: var(--text-sm); color: var(--color-text-muted); }
.item-actions__limit { font-weight: 600; }
.item-actions__row { display: flex; flex-wrap: wrap; gap: var(--space-2); }
</style>
