<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { FileText } from '@lucide/vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiSkeleton from '../Components/ui/UiSkeleton.vue';
import UiTag from '../Components/ui/UiTag.vue';
import { documentUrl, formatSize } from './documentFormat.js';

// Preview of one document. Images are plain <img> of the authenticated preview route (the browser holds nothing but
// its normal cache entry, which the server marks private/no-cache); a PDF is a file card, never an inline embed; HEIC
// has no preview. A failed image load shows one recovery state; a retry is a click, never a loop, and the state does
// not tell a refused session from a missing file.
const props = defineProps({
    personId: { type: String, required: true },
    document: { type: Object, required: true },
});
const { t, locale } = useI18n();

const state = ref('loading'); // loading | loaded | failed
const attempt = ref(0);
watch(() => props.document.id, () => { state.value = 'loading'; attempt.value = 0; });

const src = () => {
    const base = documentUrl(props.personId, props.document.id, 'preview/view');
    return attempt.value === 0 ? base : `${base}?retry=${attempt.value}`;
};
function retry() {
    attempt.value += 1;
    state.value = 'loading';
}
</script>

<template>
    <div class="doc-preview" data-testid="document-preview">
        <template v-if="document.kind === 'image'">
            <div v-if="state === 'failed'" class="doc-preview__failed" data-testid="preview-failed">
                <p>{{ t('health.doc.preview_failed') }}</p>
                <div class="doc-preview__actions">
                    <UiButton variant="secondary" href="/confirm-access" data-testid="preview-confirm">{{ t('health.doc.confirm_its_you') }}</UiButton>
                    <UiButton variant="ghost" data-testid="preview-retry" @click="retry">{{ t('health.doc.retry_load') }}</UiButton>
                </div>
            </div>
            <template v-else>
                <UiSkeleton v-if="state === 'loading'" height="240px" />
                <img
                    :key="attempt"
                    :src="src()"
                    :alt="document.title"
                    class="doc-preview__img"
                    :class="{ 'doc-preview__img--hidden': state === 'loading' }"
                    data-testid="preview-image"
                    @load="state = 'loaded'"
                    @error="state = 'failed'"
                >
            </template>
        </template>
        <div v-else class="doc-preview__card" data-testid="preview-card">
            <FileText :size="32" :stroke-width="1.75" aria-hidden="true" />
            <div class="doc-preview__card-text">
                <strong class="doc-preview__title">{{ document.title }}</strong>
                <template v-if="document.kind === 'pdf'">
                    <span class="doc-preview__meta">{{ t('health.doc.pdf_meta', { size: formatSize(document.sizeBytes, locale) }) }}</span>
                </template>
                <template v-else>
                    <UiTag>{{ t('health.doc.no_preview') }}</UiTag>
                    <span class="doc-preview__meta">{{ t('health.doc.no_preview_body') }}</span>
                </template>
            </div>
        </div>
    </div>
</template>

<style scoped>
.doc-preview { min-width: 0; }
.doc-preview__img { display: block; max-width: 100%; max-height: 480px; margin-inline: auto; object-fit: contain; border-radius: var(--radius-md); }
.doc-preview__img--hidden { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
.doc-preview__failed { display: flex; flex-direction: column; gap: var(--space-2); padding: var(--space-4); border: 1px solid var(--color-border); border-radius: var(--radius-md); background: var(--color-surface-2); }
.doc-preview__failed p { margin: 0; }
.doc-preview__actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.doc-preview__card { display: flex; gap: var(--space-3); align-items: flex-start; padding: var(--space-4); border: 1px solid var(--color-border); border-radius: var(--radius-md); background: var(--color-surface-2); }
.doc-preview__card svg { flex: none; }
.doc-preview__card-text { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-1); min-width: 0; }
.doc-preview__title { overflow-wrap: anywhere; }
.doc-preview__meta { font-size: var(--text-sm); color: var(--color-text-muted); }
</style>
