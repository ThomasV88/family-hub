<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Files } from '@lucide/vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import { securityFetch } from '../../Security/securityFetch.js';
import { handleHealthRefusal } from '../healthRefusal.js';
import AiNoticeDialog from '../Ai/AiNoticeDialog.vue';

// "Upload many documents": opens the person's batch (or the one that is already open) and goes to its page. The first
// batch of a member goes through the same AI notice as Run AI. Nothing is kept in the browser.
const props = defineProps({
    personId: { type: String, required: true },
    // PersonPage `ai` prop: {providers, noticeAcknowledged, textNotice}
    ai: { type: Object, default: () => ({}) },
});
const { t } = useI18n();

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

function begin() {
    if (busy.value) return;
    alertKey.value = '';
    failed.value = false;
    // Without an earlier acknowledgment the dialog comes first; the server checks it again either way.
    if (!acknowledged.value && !props.ai?.noticeAcknowledged) {
        noticeOpen.value = true;
        return;
    }
    send(false);
}

function confirmNotice(shown) {
    noticeOpen.value = false;
    send(true, Array.isArray(shown) ? shown : providers.value);
}

async function send(withAck, shown = null) {
    busy.value = true;
    const body = withAck ? { acknowledged: true, providers: [...shown], ...(textNotice.value ? { textNotice: true } : {}) } : {};
    const result = await securityFetch(`/health/people/${props.personId}/upload-batches`, { method: 'POST', body, replay: true });
    busy.value = false;
    if (result.ok && result.data?.batchId) {
        acknowledged.value = true;
        router.visit(`/health/people/${props.personId}/upload-batches/${result.data.batchId}`);
        return;
    }
    if (result.status === 423 && handleHealthRefusal(result.error)) return;
    if (result.status === 409) {
        const reason = result.data?.reason;
        if (reason === 'consent_off') { alertKey.value = 'consent_off_start'; return; }
        if (reason === 'unavailable') { alertKey.value = 'unavailable'; return; }
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
</script>

<template>
    <div class="start-batch">
        <UiButton variant="secondary" :icon="Files" :loading="busy" class="person-header__edit-action" data-testid="header-add-batch" @click="begin">{{ t('health.batch.start') }}</UiButton>
        <UiAlert v-if="alertKey" variant="info" live class="start-batch__alert" data-testid="batch-start-alert">{{ t(`health.batch.${alertKey}`) }}</UiAlert>
        <UiAlert v-if="failed" variant="danger" live class="start-batch__alert" data-testid="batch-start-failed">{{ t('health.ai.run.queue_failed') }}</UiAlert>
        <AiNoticeDialog :open="noticeOpen" :variant="variant" :providers="providers" :text="textNotice" @confirm="confirmNotice" @cancel="noticeOpen = false" />
    </div>
</template>

<style scoped>
.start-batch { display: contents; }
.start-batch__alert { flex-basis: 100%; }
</style>
