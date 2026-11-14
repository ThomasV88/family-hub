<script setup>
import { computed, onBeforeUnmount, ref, useId } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { FileText, Image as ImageIcon } from '@lucide/vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiConfirmDialog from '../Components/ui/UiConfirmDialog.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import UiProgress from '../Components/ui/UiProgress.vue';
import UiSelect from '../Components/ui/UiSelect.vue';
import { securityUpload } from '../Security/securityTransfer.js';
import { handleHealthRefusal } from './healthRefusal.js';
import { formatSize, visitLabel } from './documentFormat.js';

// Multi-file uploader. One request per file, one at a time, progress per file. The picked File objects live in this
// component's memory only (never in storage, never in Inertia form state) and are dropped on unmount. A 423 from the
// idle gate goes to the confirm page without a replay.
const props = defineProps({
    personId: { type: String, required: true },
    visits: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({ maxMb: 10, filesAtOnce: 10 }) },
    initialVisit: { type: String, default: null },
});
const emit = defineEmits(['close', 'uploading']);
const { t, te, locale } = useI18n();

const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];
const REFRESH = ['documents', 'unattachedDocumentIds', 'visits', 'timeline', 'timelineFilters', 'folders'];

// status: waiting | uploading | done | failed ; retryable only for failures that came from the transfer.
const items = ref([]);
const title = ref('');
const visitId = ref(props.initialVisit && props.visits.some((v) => v.id === props.initialVisit) ? props.initialVisit : '');
const groupError = ref('');
const running = ref(false);
const leaveOpen = ref(false);
const inputId = `files-${useId()}`;
let seq = 0;
let controller = null;
let allowLeave = false;
let pendingUrl = null;

const visitOptions = computed(() => [
    { value: '', label: t('health.doc.no_visit') },
    ...[...props.visits].sort((a, b) => b.visitedOn.localeCompare(a.visitedOn)).map((v) => ({ value: v.id, label: visitLabel(v, t) })),
]);
const waiting = computed(() => items.value.filter((i) => i.status === 'waiting'));
const hint = computed(() => t('health.upload.hint', { max: props.limits.maxMb, count: props.limits.filesAtOnce }));
const addLabel = computed(() => t('health.upload.add_n', { n: waiting.value.length }, waiting.value.length));
const singleFile = computed(() => items.value.length === 1 && items.value[0].status === 'waiting');

const extensionOf = (name) => (name.includes('.') ? name.split('.').pop().toLowerCase() : '');
const iconFor = (item) => (['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'].includes(extensionOf(item.file.name)) ? ImageIcon : FileText);

function pick(event) {
    const files = [...(event.target.files ?? [])];
    event.target.value = '';
    groupError.value = '';
    const room = props.limits.filesAtOnce - items.value.length;
    if (files.length > Math.max(room, 0)) {
        groupError.value = t('health.upload.err_limit', { count: props.limits.filesAtOnce });
    }
    for (const file of files.slice(0, Math.max(room, 0))) {
        const item = { key: ++seq, file, status: 'waiting', progress: 0, error: '', retry: false };
        // Client pre-check of extension and size only; the server stays the authority on content.
        if (!EXTENSIONS.includes(extensionOf(file.name))) {
            item.status = 'failed';
            item.error = t('health.upload.err_type');
        } else if (file.size > props.limits.maxMb * 1048576) {
            item.status = 'failed';
            item.error = t('health.upload.err_size', { max: props.limits.maxMb });
        }
        items.value.push(item);
    }
}

function messageFor(result) {
    const data = result.data ?? {};
    const params = { max: data.params?.max ?? props.limits.maxMb, count: data.params?.count ?? props.limits.filesAtOnce };
    if (result.error === 'too_large') return t('health.upload.err_size', { max: params.max });
    if (result.error === 'validation') {
        const key = data.errors?.file?.[0] ?? data.errors?.visit_id?.[0] ?? data.errors?.title?.[0];
        // The server's err_limit is the per-person cap (the batch limit is checked here), so it gets its own text.
        if (key === 'health.upload.err_limit') return t('health.upload.err_cap', { count: params.count });
        if (typeof key === 'string' && te(key)) return t(key, params);
    }
    return t('health.upload.err_failed');
}

function endGuard() {
    allowLeave = true;
}

async function run() {
    if (running.value) return;
    running.value = true;
    emit('uploading', true);
    let done = 0;
    try {
        let next;
        while ((next = items.value.find((i) => i.status === 'waiting'))) {
            next.status = 'uploading';
            next.progress = 0;
            controller = new AbortController();
            const body = new FormData();
            body.append('file', next.file);
            const named = items.value.length === 1 && title.value.trim() !== '';
            if (named) body.append('title', title.value.trim());
            if (visitId.value !== '') body.append('visit_id', visitId.value);
            const result = await securityUpload(`/health/people/${props.personId}/documents`, body, {
                signal: controller.signal,
                onProgress: (fraction) => { next.progress = fraction; },
            });
            controller = null;
            if (result.ok) {
                next.status = 'done';
                next.progress = 1;
                done += 1;
                continue;
            }
            if (result.error === 'aborted') {
                items.value = items.value.filter((i) => i !== next);
                break;
            }
            if (handleHealthRefusal(result.error, { onBeforeLeave: endGuard })) {
                next.status = 'failed';
                next.error = t('health.upload.err_failed');
                next.retry = true;
                break;
            }
            next.status = 'failed';
            next.error = messageFor(result);
            next.retry = true;
        }
    } finally {
        running.value = false;
        emit('uploading', false);
    }
    if (done > 0) router.reload({ only: REFRESH });
}

function retry(item) {
    item.status = 'waiting';
    item.error = '';
    item.progress = 0;
    run();
}

function cancel() {
    controller?.abort();
    items.value = items.value.filter((i) => i.status === 'done' || i.status === 'failed');
    if (!running.value) emit('close');
}

function dismiss(item) {
    items.value = items.value.filter((i) => i !== item);
}

const statusText = (item) => {
    if (item.status === 'waiting') return t('health.upload.waiting');
    if (item.status === 'uploading') return `${t('health.upload.uploading')} ${Math.round(item.progress * 100)}%`;
    if (item.status === 'done') return t('health.upload.done');
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
    if (typeof off === 'function') off();
    window.removeEventListener('beforeunload', onUnload);
    controller?.abort();
    items.value = [];
});
function leave() {
    allowLeave = true;
    controller?.abort();
    if (pendingUrl) router.visit(pendingUrl);
}
</script>

<template>
    <div class="uploader" data-testid="document-uploader">
        <UiField :id="inputId" :label="t('health.upload.files')" :hint="hint" :error="groupError">
            <input
                :id="inputId"
                type="file"
                :aria-describedby="groupError ? `${inputId}-hint ${inputId}-error` : `${inputId}-hint`"
                multiple
                accept="application/pdf,image/jpeg,image/png,image/webp,image/heic,image/heif,.pdf,.jpg,.jpeg,.png,.webp,.heic,.heif"
                class="uploader__input"
                data-testid="uploader-input"
                :disabled="running"
                @change="pick"
            >
        </UiField>
        <UiField v-if="singleFile" :label="t('health.upload.name')">
            <UiInput v-model="title" autocomplete="off" maxlength="120" />
        </UiField>
        <UiField v-if="items.length > 0 || visits.length > 0" :label="t('health.doc.attach_visit')">
            <UiSelect v-model="visitId" :options="visitOptions" :disabled="running" />
        </UiField>

        <ul v-if="items.length > 0" class="uploader__queue" data-testid="uploader-queue">
            <li v-for="item in items" :key="item.key" class="uploader__row" :data-status="item.status">
                <component :is="iconFor(item)" :size="20" :stroke-width="1.75" aria-hidden="true" class="uploader__icon" />
                <div class="uploader__main">
                    <span class="uploader__name">{{ item.file.name }}</span>
                    <span class="uploader__size">{{ formatSize(item.file.size, locale) }}</span>
                    <UiProgress v-if="item.status === 'uploading' || item.status === 'done'" :value="Math.round(item.progress * 100)" :label="item.file.name" />
                    <span role="status" class="uploader__status">{{ statusText(item) }}</span>
                    <p v-if="item.status === 'failed'" role="alert" class="uploader__error">{{ item.error }}</p>
                </div>
                <UiButton v-if="item.status === 'failed' && item.retry" variant="secondary" size="sm" data-testid="uploader-retry" @click="retry(item)">{{ t('health.upload.retry') }}</UiButton>
                <UiButton v-else-if="item.status === 'failed' || item.status === 'waiting'" variant="ghost" size="sm" @click="dismiss(item)">{{ t('health.upload.remove') }}</UiButton>
            </li>
        </ul>

        <div class="uploader__actions">
            <UiButton variant="secondary" data-testid="uploader-cancel" @click="cancel">{{ running ? t('health.upload.cancel') : t('ui.close') }}</UiButton>
            <UiButton v-if="waiting.length > 0 || running" variant="primary" :loading="running" :disabled="waiting.length === 0" data-testid="uploader-start" @click="run">{{ addLabel }}</UiButton>
        </div>

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
.uploader { display: flex; flex-direction: column; }
.uploader__input { font: inherit; color: inherit; min-height: 44px; }
.uploader__queue { list-style: none; margin: 0 0 var(--space-4); padding: 0; display: flex; flex-direction: column; gap: var(--space-2); }
.uploader__row { display: flex; align-items: flex-start; gap: var(--space-3); padding: var(--space-2) var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.uploader__icon { flex: none; margin-top: 2px; }
.uploader__main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: var(--space-1); }
.uploader__name { font-weight: 600; overflow-wrap: anywhere; }
.uploader__size, .uploader__status { font-size: var(--text-sm); color: var(--color-text-muted); }
.uploader__error { margin: 0; font-size: var(--text-sm); color: var(--color-danger); }
.uploader__actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: var(--space-3); }
</style>
