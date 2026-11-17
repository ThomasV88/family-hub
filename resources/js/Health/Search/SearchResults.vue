<script setup>
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Info, SearchX, Stethoscope, TriangleAlert, User } from '@lucide/vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import UiErrorState from '../../Components/ui/UiErrorState.vue';
import UiList from '../../Components/ui/UiList.vue';
import UiListRow from '../../Components/ui/UiListRow.vue';
import UiProgress from '../../Components/ui/UiProgress.vue';
import UiSkeleton from '../../Components/ui/UiSkeleton.vue';
import UiTag from '../../Components/ui/UiTag.vue';
import SearchSnippet from './SearchSnippet.vue';

// Results region of the document search. Every title, name and snippet is drawn by interpolation (never as HTML) and
// the empty state is one and the same text for "nothing matches" and "nothing you may open matches".
const props = defineProps({
    state: { type: String, required: true },
    items: { type: Array, default: () => [] },
    hasMore: { type: Boolean, default: false },
    reliability: { type: String, default: 'reliable' },
    refreshing: { type: Boolean, default: false },
    loadingMore: { type: Boolean, default: false },
    moreError: { type: String, default: null },
    errorKind: { type: String, default: 'generic' },
});
const emit = defineEmits(['clear', 'retry', 'more', 'open']);
const { t, locale } = useI18n();

const section = ref(null);
const heading = ref(null);
const list = ref(null);
const count = computed(() => props.items.length);
const headingText = computed(() => (props.hasMore ? t('health.search.heading_more') : t('health.search.heading', { n: count.value }, count.value)));
const loading = computed(() => props.state === 'loading');
const showList = computed(() => props.state === 'results');

const errorBody = computed(() => ({
    session: t('health.search.error_session'),
    forbidden: t('health.search.error_forbidden'),
}[props.errorKind] ?? ''));
const moreText = computed(() => (props.moreError === 'throttled' ? t('health.search.throttled') : props.moreError === 'session' ? t('health.search.error_session') : props.moreError === 'forbidden' ? t('health.search.error_forbidden') : t('health.search.more_failed')));

function formatDate(value) {
    const [y, m, d] = String(value ?? '').split('-').map(Number);
    if (!y || !m || !d) return '';
    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(new Date(y, m - 1, d));
}
const href = (item) => `/health/people/${item.personId}?panel=document&id=${item.documentId}`;
function onNavigate(item, event) {
    event.preventDefault();
    emit('open', item);
}

// Focus lands on the results heading; the empty and error states have their own h2, which is made focusable the same way.
function focusHeading() {
    const el = heading.value ?? section.value?.querySelector('h2');
    if (!el) return;
    el.setAttribute('tabindex', '-1');
    el.focus({ preventScroll: window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false });
}
async function focusRow(index) {
    await nextTick();
    list.value?.$el?.querySelectorAll?.('li a')?.[index]?.focus();
}
defineExpose({ focusHeading, focusRow });
</script>

<template>
    <section ref="section" class="search-results" :aria-label="t('health.search.results_aria')" :aria-busy="loading ? 'true' : undefined" data-testid="search-results">
        <div v-if="loading" class="search-results__loading" data-testid="search-loading">
            <UiProgress indeterminate :label="t('health.search.searching')" />
            <ul class="search-results__skeletons" role="presentation">
                <li v-for="n in 3" :key="n"><UiSkeleton width="60%" height="20px" /><UiSkeleton width="40%" height="14px" /><UiSkeleton width="90%" height="14px" /></li>
            </ul>
        </div>

        <UiErrorState v-else-if="state === 'error'" live :title="t('health.search.error_title')" :body="errorBody" :heading-level="2" data-testid="search-error">
            <template #action>
                <UiButton v-if="errorKind === 'generic'" variant="secondary" data-testid="search-retry" @click="emit('retry')">{{ t('health.search.error_retry') }}</UiButton>
                <UiButton v-else variant="ghost" data-testid="search-clear-error" @click="emit('clear')">{{ t('health.search.clear') }}</UiButton>
            </template>
        </UiErrorState>

        <div v-else-if="state === 'throttled'" data-testid="search-throttled">
            <UiAlert variant="warning" live>{{ t('health.search.throttled') }}</UiAlert>
            <div class="search-results__back"><UiButton variant="ghost" data-testid="search-clear-throttled" @click="emit('clear')">{{ t('health.search.clear') }}</UiButton></div>
        </div>

        <UiEmptyState v-else-if="state === 'empty'" :title="t('health.search.empty_title')" :body="t('health.search.empty_body')" :icon="SearchX" :heading-level="2" data-testid="search-empty">
            <template #action>
                <UiButton variant="ghost" data-testid="search-empty-clear" @click="emit('clear')">{{ t('health.search.empty_clear') }}</UiButton>
            </template>
        </UiEmptyState>

        <template v-else-if="showList">
            <header class="search-results__head">
                <h2 ref="heading" class="search-results__heading" tabindex="-1" data-testid="search-heading">{{ headingText }}</h2>
                <p v-if="hasMore" class="search-results__count" data-testid="search-count">{{ t('health.search.showing', { n: count }) }}</p>
                <UiButton variant="ghost" data-testid="search-clear-results" @click="emit('clear')">{{ t('health.search.clear') }}</UiButton>
            </header>
            <UiAlert v-if="reliability === 'uncertain'" variant="info" data-testid="search-uncertain">
                <span class="search-results__uncertain"><TriangleAlert :size="16" :stroke-width="1.75" aria-hidden="true" />{{ t('health.search.uncertain') }}</span>
            </UiAlert>
            <UiProgress v-if="refreshing" indeterminate :label="t('health.search.searching')" />
            <UiList ref="list" data-testid="search-list">
                <UiListRow v-for="item in items" :key="item.documentId" as="link" :href="href(item)" data-testid="search-row" @navigate="(_h, event) => onNavigate(item, event)">
                    {{ item.title }}
                    <template #meta>
                        <span class="search-results__meta">
                            <UiTag :icon="User">{{ item.personName }}</UiTag>
                            <span v-if="item.uploadedOn">{{ formatDate(item.uploadedOn) }}</span>
                            <UiTag v-if="item.visitId && item.visitName" :icon="Stethoscope">{{ item.visitName }}</UiTag>
                            <span v-if="item.page != null">{{ t('health.search.page', { n: item.page }) }}</span>
                        </span>
                        <span v-if="item.snippets?.length" class="search-results__snippets">
                            <SearchSnippet v-for="(snippet, index) in item.snippets.slice(0, 2)" :key="index" :segments="snippet" />
                        </span>
                        <span v-else class="search-results__plain" data-testid="search-title-only">{{ t('health.search.title_only') }}</span>
                        <span v-if="item.textComplete === false && item.pagesRead != null" class="search-results__partial" data-testid="search-partial"><Info :size="16" :stroke-width="1.75" aria-hidden="true" />{{ t('health.search.partial', { n: item.pagesRead }, item.pagesRead) }}</span>
                    </template>
                </UiListRow>
            </UiList>
            <UiAlert v-if="moreError" variant="danger" live data-testid="search-more-failed">
                {{ moreText }}
                <template #actions><UiButton variant="ghost" size="sm" data-testid="search-more-retry" @click="emit('more')">{{ t('health.search.error_retry') }}</UiButton></template>
            </UiAlert>
            <div v-if="hasMore && !moreError" class="search-results__more">
                <UiButton variant="ghost" :loading="loadingMore" data-testid="search-more" @click="emit('more')">{{ t('health.search.load_more') }}</UiButton>
            </div>
        </template>
    </section>
</template>

<style scoped>
.search-results { display: flex; flex-direction: column; gap: var(--space-4); min-width: 0; container-type: inline-size; }
.search-results__head { display: flex; flex-wrap: wrap; align-items: baseline; gap: var(--space-1) var(--space-4); }
.search-results__heading { margin: 0; font-size: var(--text-xl); font-weight: 600; line-height: 1.2; }
.search-results__heading:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.search-results__count { margin: 0; flex-basis: 100%; order: 2; font-size: var(--text-sm); color: var(--color-text-muted); }
.search-results__head > :last-child { margin-inline-start: auto; }
.search-results__uncertain { display: inline-flex; align-items: flex-start; gap: var(--space-1); }
.search-results__back { margin-top: var(--space-2); }
.search-results__loading { display: flex; flex-direction: column; gap: var(--space-4); }
.search-results__skeletons { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: var(--space-4); }
.search-results__skeletons li { display: flex; flex-direction: column; gap: var(--space-2); min-height: 64px; }
.search-results__meta { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-1) var(--space-2); }
.search-results__snippets { display: flex; flex-direction: column; gap: var(--space-1); margin-top: var(--space-1); }
.search-results__plain { display: block; margin-top: var(--space-1); }
.search-results__partial { display: inline-flex; align-items: flex-start; gap: var(--space-1); margin-top: var(--space-1); }
.search-results__more { display: flex; }
.search-results :deep(.ui-row) { min-height: 64px; }
.search-results :deep(.ui-row__title) { overflow: visible; white-space: normal; overflow-wrap: anywhere; font-weight: 600; }
.search-results :deep(.ui-row__main--interactive:is(:hover, :focus-visible) .ui-row__title) { text-decoration: underline; color: var(--color-primary); }
.search-results :deep(.ui-row__meta) { display: flex; flex-direction: column; gap: var(--space-1); }
@container (max-width: 639px) {
    .search-results__more > * { width: 100%; }
}
</style>
