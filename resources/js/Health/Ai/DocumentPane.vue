<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ChevronLeft, ChevronRight, ExternalLink, FileText } from '@lucide/vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiSkeleton from '../../Components/ui/UiSkeleton.vue';
import UiTag from '../../Components/ui/UiTag.vue';
import { documentUrl } from '../documentFormat.js';

// The document next to the suggestions: an image, or a PDF one derivative page at a time. Every picture is a plain
// <img> of an authenticated route (health.documents.preview / health.documents.page), nothing is kept in the browser.
// A failed load shows the Phase 7 recovery state; a retry is a click, never a loop.
const props = defineProps({
    personId: { type: String, required: true },
    document: { type: Object, required: true },
});
const { t } = useI18n();

const page = ref(1);
const state = ref('loading'); // loading | loaded | failed
const attempt = ref(0);

const total = computed(() => Number(props.document.pageCount) || 0);
const mode = computed(() => {
    if (props.document.kind === 'image') return 'image';
    if (props.document.kind === 'pdf' && total.value > 0) return 'pdf';
    return 'card';
});
const openUrl = computed(() => documentUrl(props.personId, props.document.id, props.document.kind === 'image' ? 'preview/view' : 'file'));
const baseSrc = computed(() => (mode.value === 'pdf'
    ? documentUrl(props.personId, props.document.id, `pages/${page.value}`)
    : documentUrl(props.personId, props.document.id, 'preview/view')));
const src = computed(() => (attempt.value === 0 ? baseSrc.value : `${baseSrc.value}?retry=${attempt.value}`));

watch(() => props.document.id, () => { page.value = 1; state.value = 'loading'; attempt.value = 0; });
watch(page, () => { state.value = 'loading'; attempt.value = 0; });

function retry() {
    attempt.value += 1;
    state.value = 'loading';
}
</script>

<template>
    <div class="doc-pane" data-testid="document-pane">
        <template v-if="mode !== 'card'">
            <div v-if="state === 'failed'" class="doc-pane__failed" data-testid="preview-failed">
                <p>{{ t('health.doc.preview_failed') }}</p>
                <div class="doc-pane__actions">
                    <UiButton variant="secondary" href="/confirm-access" data-testid="preview-confirm">{{ t('health.doc.confirm_its_you') }}</UiButton>
                    <UiButton variant="ghost" data-testid="preview-retry" @click="retry">{{ t('health.doc.retry_load') }}</UiButton>
                </div>
            </div>
            <div v-else class="doc-pane__frame">
                <UiSkeleton v-if="state === 'loading'" height="320px" />
                <a :href="openUrl" target="_blank" rel="noopener" class="doc-pane__link" :class="{ 'doc-pane__link--hidden': state === 'loading' }" :tabindex="state === 'loading' ? -1 : undefined">
                    <img
                        :key="`${page}-${attempt}`"
                        :src="src"
                        :alt="mode === 'pdf' ? t('health.ai.review.page_status', { n: page, total }) + ': ' + document.title : document.title"
                        class="doc-pane__img"
                        data-testid="document-image"
                        @load="state = 'loaded'"
                        @error="state = 'failed'"
                    >
                </a>
            </div>
            <div v-if="mode === 'pdf'" class="doc-pane__pager" data-testid="pdf-pager">
                <UiButton variant="secondary" :icon="ChevronLeft" :disabled="page <= 1" data-testid="page-prev" @click="page -= 1">{{ t('health.ai.review.page_prev') }}</UiButton>
                <span role="status" class="doc-pane__status" data-testid="page-status">{{ t('health.ai.review.page_status', { n: page, total }) }}</span>
                <UiButton variant="secondary" :icon="ChevronRight" :disabled="page >= total" data-testid="page-next" @click="page += 1">{{ t('health.ai.review.page_next') }}</UiButton>
            </div>
        </template>
        <div v-else class="doc-pane__card" data-testid="preview-card">
            <FileText :size="32" :stroke-width="1.75" aria-hidden="true" />
            <div class="doc-pane__card-text">
                <strong class="doc-pane__title">{{ document.title }}</strong>
                <UiTag>{{ t('health.doc.no_preview') }}</UiTag>
                <span class="doc-pane__meta">{{ t('health.doc.no_preview_body') }}</span>
                <UiButton variant="secondary" :icon="ExternalLink" :href="openUrl" target="_blank" rel="noopener" data-testid="doc-open">{{ t('health.doc.open_new_tab') }}</UiButton>
            </div>
        </div>
    </div>
</template>

<style scoped>
.doc-pane { min-width: 0; }
.doc-pane__frame { padding: var(--space-2); border-radius: var(--radius-md); background: var(--color-surface-2); }
.doc-pane__link { display: block; }
.doc-pane__link--hidden { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; overflow: hidden; }
.doc-pane__img { display: block; width: 100%; height: auto; object-fit: contain; }
.doc-pane__pager { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-2); margin-top: var(--space-3); }
.doc-pane__status { font-size: var(--text-sm); color: var(--color-text-muted); }
.doc-pane__failed { display: flex; flex-direction: column; gap: var(--space-2); padding: var(--space-4); border: 1px solid var(--color-border); border-radius: var(--radius-md); background: var(--color-surface-2); }
.doc-pane__failed p { margin: 0; }
.doc-pane__actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.doc-pane__card { display: flex; gap: var(--space-3); align-items: flex-start; padding: var(--space-4); border: 1px solid var(--color-border); border-radius: var(--radius-md); background: var(--color-surface-2); }
.doc-pane__card svg { flex: none; }
.doc-pane__card-text { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-2); min-width: 0; }
.doc-pane__title { overflow-wrap: anywhere; }
.doc-pane__meta { font-size: var(--text-sm); color: var(--color-text-muted); }
</style>
