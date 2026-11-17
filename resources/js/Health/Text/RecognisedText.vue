<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ChevronDown, ChevronUp, Copy, Info, Layers, Trash2, TriangleAlert } from '@lucide/vue';
import HelpTip from '../../Components/ui/HelpTip.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiConfirmDialog from '../../Components/ui/UiConfirmDialog.vue';
import UiSkeleton from '../../Components/ui/UiSkeleton.vue';
import UiTag from '../../Components/ui/UiTag.vue';
import { useToast } from '../../Components/ui/useToast.js';
import { securityFetch } from '../../Security/securityFetch.js';
import { handleHealthRefusal } from '../healthRefusal.js';
import { copyFormat, textLang } from './recognisedText.js';

// "Recognised text" of one document in its panel. The text is never in the page props: it is fetched when the section is
// expanded (a no-store answer), kept in this component's memory and dropped when the section is collapsed, the document
// changes or the panel closes. Everything is drawn as plain text, never as HTML. Anyone who may see the document may read
// and copy it; only editors get the remove button (the server enforces the same).
const props = defineProps({
    personId: { type: String, required: true },
    document: { type: Object, required: true },
    canEdit: { type: Boolean, default: false },
    // 'uncertain' while the evaluation gate has not shown the reading to be reliable.
    reliability: { type: String, default: 'reliable' },
    // A run for this document is queued, running or waiting for quota.
    runActive: { type: Boolean, default: false },
    // The newest run of this document has saved its text (the first batch is enough): the text it wrote is the one to
    // show, so the earlier-text line goes away and the section appears even before the page data was reloaded.
    textSaved: { type: Boolean, default: false },
});
const { t } = useI18n();
const toast = useToast();
const ids = { title: useId(), body: useId() };

const expanded = ref(false);
const loading = ref(false);
const data = ref(null);
const loadError = ref(null);
const gone = ref(false);
const removed = ref(false);
const confirmOpen = ref(false);
const removing = ref(false);
const removeError = ref(null);
let seq = 0;

const url = computed(() => `/health/people/${props.personId}/documents/${props.document.id}/text`);
const hasText = computed(() => (Boolean(props.document.hasText) || props.textSaved) && !removed.value);
const rereading = computed(() => props.runActive && !props.textSaved);
const visible = computed(() => !gone.value && (hasText.value || props.runActive));
const showDisclosure = computed(() => !gone.value && hasText.value);
const pages = computed(() => data.value?.pages ?? []);
const multiPage = computed(() => pages.value.length > 1);
const partial = computed(() => data.value !== null && data.value.complete === false);
const uncertain = computed(() => props.reliability === 'uncertain');
// The partial facts come with the text; the tags appear once it was loaded.
const pagesRead = computed(() => data.value?.pagesRead ?? pages.value.length);
const pagesTotal = computed(() => data.value?.pagesTotal ?? pages.value.length);

function drop() {
    seq += 1;
    expanded.value = false;
    loading.value = false;
    data.value = null;
    loadError.value = null;
    removeError.value = null;
}
// A new run saved its text: show that one, also when the earlier text is open (a removed text can be read again).
watch(() => props.textSaved, (now, before) => {
    if (now && !before) {
        removed.value = false;
        if (expanded.value) load();
    }
});
// Another document: the text goes with the old one.
watch(() => props.document.id, () => { drop(); gone.value = false; removed.value = false; confirmOpen.value = false; });

async function load() {
    const mine = ++seq;
    loading.value = true;
    loadError.value = null;
    const result = await securityFetch(url.value, { method: 'GET', replay: true });
    if (mine !== seq) return;
    loading.value = false;
    if (result.ok && Array.isArray(result.data?.pages)) {
        data.value = result.data;
        return;
    }
    if (result.status === 423 && handleHealthRefusal(result.error)) {
        drop();
        return;
    }
    if (result.status === 403 || result.status === 404) {
        // Hidden, gone or never there: the section disappears without a word.
        drop();
        gone.value = true;
        return;
    }
    loadError.value = result.status === 419 ? 'session' : 'generic';
}

function toggle() {
    if (expanded.value) {
        drop();
        return;
    }
    expanded.value = true;
    load();
}

async function copy() {
    if (data.value === null) return;
    const text = copyFormat(pages.value, (n) => t('health.document_text.page', { n }));
    try {
        await navigator.clipboard.writeText(text);
        toast.show(t('health.document_text.copied'));
    } catch {
        toast.show(t('health.document_text.copy_failed'));
    }
}

async function remove() {
    if (removing.value) return;
    removing.value = true;
    removeError.value = null;
    const result = await securityFetch(url.value, { method: 'DELETE', replay: true });
    removing.value = false;
    // 404: the text is already gone, which is what was asked for.
    if (result.ok || result.status === 404) {
        removed.value = true;
        drop();
        if (result.ok) toast.show(t('health.document_text.removed'));
        router.reload({ only: ['documents', 'search'], preserveScroll: true, preserveState: true });
        await nextTick();
        (document.querySelector('[data-testid="ai-run"]') ?? document.querySelector('[data-testid="doc-open"]'))?.focus?.();
        return;
    }
    if (result.status === 423 && handleHealthRefusal(result.error)) return;
    removeError.value = result.status === 419 ? 'session' : 'generic';
}

const textLabel = computed(() => t('health.document_text.region_aria', { title: props.document.title }));
</script>

<template>
    <section v-if="visible" class="rtext" :aria-labelledby="ids.title" data-testid="recognised-text">
        <template v-if="showDisclosure">
            <div class="rtext__head">
                <h3 :id="ids.title" class="rtext__heading">
                    <UiButton
                        variant="ghost"
                        class="rtext__toggle"
                        :icon="expanded ? ChevronUp : ChevronDown"
                        :aria-expanded="expanded ? 'true' : 'false'"
                        :aria-controls="ids.body"
                        :aria-label="t('health.document_text.toggle_aria')"
                        data-testid="rtext-toggle"
                        @click="toggle"
                    ><span class="rtext__title">{{ t('health.document_text.title') }}</span></UiButton>
                </h3>
                <HelpTip topic="health.document_text" :label="t('health.document_text.title')" />
                <span class="rtext__tags">
                    <UiTag v-if="uncertain" variant="warn" :icon="TriangleAlert" data-testid="rtext-tag-errors">{{ t('health.document_text.tag_errors') }}</UiTag>
                    <UiTag v-if="partial" :icon="Layers" data-testid="rtext-tag-partial">{{ t('health.document_text.tag_partial', { n: pagesRead, total: pagesTotal }) }}</UiTag>
                </span>
            </div>
            <p v-if="rereading" class="rtext__muted" role="status" data-testid="rtext-rereading">{{ t('health.document_text.being_reread') }}</p>

            <div :id="ids.body" class="rtext__body" :hidden="!expanded" data-testid="rtext-body">
                <template v-if="expanded">
                    <div v-if="loading" class="rtext__loading" aria-busy="true" role="status" :aria-label="t('health.document_text.loading')" data-testid="rtext-loading">
                        <UiSkeleton v-for="n in 5" :key="n" :width="n === 5 ? '60%' : '100%'" />
                    </div>
                    <UiAlert v-else-if="loadError" variant="danger" live data-testid="rtext-load-failed">
                        {{ loadError === 'session' ? t('health.document_text.error_session') : t('health.document_text.load_failed') }}
                        <template v-if="loadError !== 'session'" #actions><UiButton variant="ghost" size="sm" data-testid="rtext-retry" @click="load">{{ t('health.document_text.retry') }}</UiButton></template>
                    </UiAlert>
                    <template v-else-if="data">
                        <p class="rtext__muted rtext__info" data-testid="rtext-info"><Info :size="16" :stroke-width="1.75" aria-hidden="true" />{{ t('health.document_text.info') }}</p>
                        <UiAlert v-if="partial" variant="info" data-testid="rtext-partial-alert">{{ t('health.document_text.partial_alert', { n: pagesRead, total: pagesTotal }) }}</UiAlert>
                        <div class="rtext__box" role="region" tabindex="0" :aria-label="textLabel" :lang="textLang(data.language)" data-testid="rtext-text">
                            <div v-for="page in pages" :key="page.page" class="rtext__page" data-testid="rtext-page">
                                <p v-if="multiPage" class="rtext__pagelabel">{{ t('health.document_text.page', { n: page.page }) }}</p>
                                <p v-if="String(page.text ?? '').trim() === ''" class="rtext__muted rtext__empty">{{ t('health.document_text.empty_page') }}</p>
                                <p v-else class="rtext__text">{{ page.text }}</p>
                            </div>
                        </div>
                        <UiAlert v-if="removeError" variant="danger" live data-testid="rtext-remove-failed">{{ removeError === 'session' ? t('health.document_text.error_session') : t('health.document_text.remove_failed') }}</UiAlert>
                        <div class="rtext__actions">
                            <UiButton variant="ghost" :icon="Copy" data-testid="rtext-copy" @click="copy">{{ t('health.document_text.copy') }}</UiButton>
                            <UiButton v-if="canEdit" variant="ghost" class="rtext__remove" :icon="Trash2" :loading="removing" data-testid="rtext-remove" @click="confirmOpen = true">{{ t('health.document_text.remove') }}</UiButton>
                        </div>
                    </template>
                </template>
            </div>
        </template>
        <template v-else>
            <h3 :id="ids.title" class="rtext__heading rtext__heading--plain">{{ t('health.document_text.title') }}</h3>
            <p class="rtext__muted" role="status" data-testid="rtext-being-read">{{ t('health.document_text.being_read') }}</p>
        </template>
    </section>

    <UiConfirmDialog
        v-if="canEdit"
        v-model:open="confirmOpen"
        :title="t('health.document_text.remove_title')"
        :body="t('health.document_text.remove_body')"
        :confirm-label="t('health.document_text.remove_confirm')"
        :cancel-label="t('health.document_text.remove_keep')"
        @confirm="remove"
    />
</template>

<style scoped>
.rtext { display: flex; flex-direction: column; gap: var(--space-2); min-width: 0; }
.rtext__head { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }
.rtext__heading { margin: 0; flex: 1 1 auto; min-width: 0; font-size: var(--text-xl); font-weight: 600; line-height: 1.2; }
.rtext__heading--plain { flex: none; }
.rtext__heading :deep(.rtext__toggle) { width: 100%; justify-content: flex-start; min-height: 44px; }
.rtext__title { font-size: var(--text-xl); font-weight: 600; line-height: 1.2; text-align: start; }
.rtext__tags { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.rtext__muted { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.rtext__info { display: flex; align-items: flex-start; gap: var(--space-1); }
.rtext__body { display: flex; flex-direction: column; gap: var(--space-4); }
.rtext__body[hidden] { display: none; }
.rtext__loading { display: flex; flex-direction: column; gap: var(--space-3); padding: var(--space-4); border-radius: var(--radius-md); background: var(--color-surface-2); }
.rtext__box { max-height: 360px; overflow: auto; padding: var(--space-4); border-radius: var(--radius-md); background: var(--color-surface-2); }
.rtext__box:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.rtext__page + .rtext__page { margin-top: var(--space-4); }
.rtext__pagelabel { margin: 0 0 var(--space-2); padding-top: var(--space-2); border-top: 1px solid var(--color-border); font-size: var(--text-sm); font-weight: 600; color: var(--color-text-muted); }
.rtext__text { margin: 0; max-width: 72ch; font-size: var(--text-base); font-weight: 400; line-height: 1.5; white-space: pre-wrap; overflow-wrap: anywhere; }
.rtext__empty { padding-block: var(--space-2); }
.rtext__actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.rtext__remove { color: var(--color-danger); }
</style>
