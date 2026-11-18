<script setup>
import { computed, onBeforeUnmount, ref, useId } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { FileText, Image as ImageIcon } from '@lucide/vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiConfirmDialog from '../../Components/ui/UiConfirmDialog.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiProgress from '../../Components/ui/UiProgress.vue';
import { securityUpload } from '../../Security/securityTransfer.js';
import { handleHealthRefusal } from '../healthRefusal.js';
import { formatSize } from '../documentFormat.js';

// Adds files to one batch: one request per file, one at a time, through the ordinary document upload URL with the batch
// id. The picked File objects live in this component's memory only (never in storage) and are dropped on unmount.
// A 429 waits 30 s and retries the same file at most 3 times; the other refusals stay on their row with a reason.
const props = defineProps({
    personId: { type: String, required: true },
    batchId: { type: String, required: true },
    // Documents already in the batch.
    existing: { type: Number, default: 0 },
    limits: { type: Object, default: () => ({ maxMb: 10, batchMaxFiles: 50 }) },
});
const emit = defineEmits(['uploaded', 'uploading']);
const { t, te, locale } = useI18n();

const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];
const THROTTLE_WAIT_MS = 30000;
const THROTTLE_TRIES = 3;

// status: waiting | uploading | throttled | done | failed
const items = ref([]);
const groupError = ref('');
const running = ref(false);
const leaveOpen = ref(false);
const inputId = `batch-files-${useId()}`;
let seq = 0;
let controller = null;
let allowLeave = false;
let pendingUrl = null;
let sleepTimer = null;
let sleepResolve = null;
let disposed = false;

const max = computed(() => Number(props.limits?.batchMaxFiles ?? 50));
const queued = computed(() => items.value.filter((i) => ['waiting', 'uploading', 'throttled'].includes(i.status)).length);
const room = computed(() => Math.max(0, max.value - props.existing - queued.value));
const waiting = computed(() => items.value.filter((i) => i.status === 'waiting'));
const hint = computed(() => t('health.batch.files_hint', { count: max.value, max: props.limits?.maxMb ?? 10 }));

const extensionOf = (name) => (name.includes('.') ? name.split('.').pop().toLowerCase() : '');
const iconFor = (item) => (['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'].includes(extensionOf(item.file.name)) ? ImageIcon : FileText);

function pick(event) {
    const files = [...(event.target.files ?? [])];
    event.target.value = '';
    groupError.value = '';
    items.value = items.value.filter((i) => i.status !== 'done');
    const free = room.value;
    if (files.length > free) groupError.value = t('health.batch.err_full', { count: max.value });
    for (const file of files.slice(0, free)) {
        const item = { key: ++seq, file, status: 'waiting', progress: 0, error: '', retry: false, tries: 0 };
        // Client pre-check of extension and size only; the server stays the authority on content.
        if (!EXTENSIONS.includes(extensionOf(file.name))) {
            item.status = 'failed';
            item.error = t('health.upload.err_type');
        } else if (file.size > (props.limits?.maxMb ?? 10) * 1048576) {
            item.status = 'failed';
            item.error = t('health.upload.err_size', { max: props.limits?.maxMb ?? 10 });
        }
        items.value.push(item);
    }
    if (waiting.value.length > 0) run();
}

function messageFor(result) {
    const data = result.data ?? {};
    const params = { max: data.params?.max ?? props.limits?.maxMb ?? 10, count: data.params?.count ?? max.value };
    if (result.error === 'too_large') return { text: t('health.upload.err_size', { max: params.max }), retry: false };
    if (result.error === 'validation') {
        const key = data.errors?.file?.[0] ?? data.errors?.upload_batch_id?.[0];
        if (typeof key === 'string' && te(key)) {
            // Duplicate, full and closed cannot be fixed by trying again.
            const final = ['health.batch.err_duplicate', 'health.batch.err_full', 'health.batch.err_closed', 'health.upload.err_type', 'health.upload.err_size', 'health.upload.err_read'].includes(key);
            return { text: t(key, params), retry: !final };
        }
    }
    return { text: t('health.upload.err_failed'), retry: true };
}

const sleep = (ms) => new Promise((resolve) => {
    sleepResolve = resolve;
    sleepTimer = setTimeout(() => { sleepTimer = null; sleepResolve = null; resolve(); }, ms);
});
function cancelSleep() {
    if (sleepTimer !== null) { clearTimeout(sleepTimer); sleepTimer = null; }
    sleepResolve?.();
    sleepResolve = null;
}

async function run() {
    if (running.value) return;
    running.value = true;
    emit('uploading', true);
    try {
        let next;
        while (!disposed && (next = items.value.find((i) => i.status === 'waiting'))) {
            next.status = 'uploading';
            next.progress = 0;
            controller = new AbortController();
            const body = new FormData();
            body.append('file', next.file);
            body.append('upload_batch_id', props.batchId);
            const result = await securityUpload(`/health/people/${props.personId}/documents`, body, {
                signal: controller.signal,
                replay: true,
                onProgress: (fraction) => { next.progress = fraction; },
            });
            controller = null;
            if (result.ok) {
                next.status = 'done';
                next.progress = 1;
                emit('uploaded', result.data ?? {});
                continue;
            }
            if (result.error === 'aborted') {
                items.value = items.value.filter((i) => i !== next);
                break;
            }
            if (handleHealthRefusal(result.error, { onBeforeLeave: () => { allowLeave = true; } })) {
                next.status = 'failed';
                next.error = t('health.upload.err_failed');
                next.retry = true;
                break;
            }
            if (result.error === 'throttled' && next.tries < THROTTLE_TRIES) {
                next.tries += 1;
                next.status = 'throttled';
                await sleep(THROTTLE_WAIT_MS);
                if (disposed) break;
                if (next.status === 'throttled') next.status = 'waiting';
                continue;
            }
            const { text, retry: canRetry } = result.error === 'throttled' ? { text: t('health.upload.err_failed'), retry: true } : messageFor(result);
            next.status = 'failed';
            next.error = text;
            next.retry = canRetry;
        }
    } finally {
        running.value = false;
        emit('uploading', false);
    }
}

function retry(item) {
    item.status = 'waiting';
    item.error = '';
    item.progress = 0;
    item.tries = 0;
    run();
}

function dismiss(item) {
    items.value = items.value.filter((i) => i !== item);
}

const statusText = (item) => {
    if (item.status === 'waiting') return t('health.batch.upload_waiting');
    if (item.status === 'throttled') return t('health.batch.err_throttled_wait');
    if (item.status === 'uploading') return `${t('health.batch.uploading')} ${Math.round(item.progress * 100)}%`;
    if (item.status === 'done') return t('health.batch.upload_done');
    return '';
};

// ----- leave guard (only while a transfer runs) -----
const off = router.on?.('before', (event) => {
    const visit = event?.detail?.visit;
    if (!running.value || allowLeave || !visit || visit.method !== 'get') return undefined;
    pendingUrl = visit.url?.href ?? String(visit.url);
    leaveOpen.value = true;
    return false;
});
const onUnload = (event) => {
    if (running.value && !allowLeave) event.preventDefault();
};
window.addEventListener('beforeunload', onUnload);
onBeforeUnmount(() => {
    disposed = true;
    if (typeof off === 'function') off();
    window.removeEventListener('beforeunload', onUnload);
    controller?.abort();
    cancelSleep();
    items.value = [];
});
function leave() {
    allowLeave = true;
    controller?.abort();
    if (pendingUrl) router.visit(pendingUrl);
}
</script>

<template>
    <div class="batch-uploader" data-testid="batch-uploader">
        <UiField :id="inputId" :label="t('health.batch.files_label')" :hint="hint" :error="groupError">
            <input
                :id="inputId"
                type="file"
                multiple
                accept="application/pdf,image/jpeg,image/png,image/webp,image/heic,image/heif,.pdf,.jpg,.jpeg,.png,.webp,.heic,.heif"
                class="batch-uploader__input"
                :aria-describedby="groupError ? `${inputId}-hint ${inputId}-error` : `${inputId}-hint`"
                :disabled="room === 0"
                data-testid="batch-input"
                @change="pick"
            >
        </UiField>

        <ul v-if="items.length > 0" class="batch-uploader__queue" data-testid="batch-queue">
            <li v-for="item in items" :key="item.key" class="batch-uploader__row" :data-status="item.status">
                <component :is="iconFor(item)" :size="20" :stroke-width="1.75" aria-hidden="true" class="batch-uploader__icon" />
                <div class="batch-uploader__main">
                    <span class="batch-uploader__name">{{ item.file.name }}</span>
                    <span class="batch-uploader__size">{{ formatSize(item.file.size, locale) }}</span>
                    <UiProgress v-if="item.status === 'uploading' || item.status === 'done'" :value="Math.round(item.progress * 100)" :label="item.file.name" />
                    <span role="status" class="batch-uploader__status">{{ statusText(item) }}</span>
                    <p v-if="item.status === 'failed'" role="alert" class="batch-uploader__error">{{ item.error }}</p>
                    <div v-if="item.status === 'failed' || item.status === 'waiting'" class="batch-uploader__actions">
                        <UiButton v-if="item.status === 'failed' && item.retry" variant="secondary" size="sm" data-testid="batch-retry" @click="retry(item)">{{ t('health.batch.retry') }}</UiButton>
                        <UiButton v-else variant="ghost" size="sm" data-testid="batch-dismiss" @click="dismiss(item)">{{ t('health.batch.remove_file') }}</UiButton>
                    </div>
                </div>
            </li>
        </ul>

        <UiConfirmDialog
            v-model:open="leaveOpen"
            :title="t('health.upload.leave_title')"
            :body="t('health.upload.leave_body')"
            :confirm-label="t('health.upload.leave')"
            :cancel-label="t('health.upload.keep')"
            @confirm="leave"
        />
    </div>
</template>

<style scoped>
.batch-uploader { display: flex; flex-direction: column; }
.batch-uploader__input { font: inherit; color: inherit; min-height: 44px; }
.batch-uploader__queue { list-style: none; margin: 0 0 var(--space-4); padding: 0; display: flex; flex-direction: column; gap: var(--space-2); }
.batch-uploader__row { display: flex; align-items: flex-start; gap: var(--space-3); padding: var(--space-2) var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.batch-uploader__icon { flex: none; margin-top: 2px; }
.batch-uploader__main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: var(--space-1); }
.batch-uploader__name { font-weight: 600; min-width: 0; overflow-wrap: break-word; word-break: normal; }
.batch-uploader__actions { display: flex; flex-wrap: wrap; gap: var(--space-2); align-self: flex-start; }
.batch-uploader__size, .batch-uploader__status { font-size: var(--text-sm); color: var(--color-text-muted); }
.batch-uploader__error { margin: 0; min-width: 0; overflow-wrap: break-word; font-size: var(--text-sm); color: var(--color-danger); }
</style>
