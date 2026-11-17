<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Plus, Search, ShoppingCart } from '@lucide/vue';
import AppShell from '../../Layouts/AppShell.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import UiErrorState from '../../Components/ui/UiErrorState.vue';
import UiInput from '../../Components/ui/UiInput.vue';
import UiList from '../../Components/ui/UiList.vue';
import UiSkeleton from '../../Components/ui/UiSkeleton.vue';
import UiTabs from '../../Components/ui/UiTabs.vue';
import CopyListDialog from '../../Shopping/CopyListDialog.vue';
import CreateListDialog from '../../Shopping/CreateListDialog.vue';
import ListRow from '../../Shopping/ListRow.vue';
import { plain } from '../../Shopping/listStatus.js';

// Shopping overview: the lists the member may see (the server filters; the client never decides access), Open and Archive tabs.
const props = defineProps({
    tab: { type: String, default: 'open' },
    lists: { type: Array, default: () => [] },
    archive: { type: Object, default: () => ({ data: [], currentPage: 1, lastPage: 1, total: 0 }) },
    counts: { type: Object, default: () => ({ open: 0, archive: 0 }) },
    stores: { type: Array, default: () => [] },
    responsibleCandidates: { type: Array, default: () => [] },
    canCreate: { type: Boolean, default: false },
});
const { t } = useI18n();

const SEARCH_FROM = 10;
const tab = ref(props.tab === 'archive' ? 'archive' : 'open');
const query = ref('');
const createOpen = ref(false);
const copyOpen = ref(false);
const copyTarget = ref(null);
const loading = ref(false);
const loadFailed = ref(false);
let lastPage = props.archive.currentPage ?? 1;

const label = (key, n) => (n > 0 ? t('shopping.overview.tab_count', { label: t(key), n }) : t(key));
const tabs = computed(() => [
    { key: 'open', label: label('shopping.overview.tab_open', props.counts.open) },
    { key: 'archive', label: label('shopping.overview.tab_archive', props.counts.archive) },
]);

// The selected tab lives in the address (?tab=archive, plus the page), so Back from an opened list returns to the same tab. This is a
// client-side replace of the current history entry: no request, the props stay and only `tab` follows the selection.
function tabUrl(key) {
    if (key !== 'archive') return '/shopping';
    const page = props.archive.currentPage ?? 1;

    return page > 1 ? `/shopping?tab=archive&page=${page}` : '/shopping?tab=archive';
}
watch(tab, (key) => {
    if (key === props.tab) return;
    router.replace({ url: tabUrl(key), props: (current) => ({ ...current, tab: key }), preserveScroll: true, preserveState: true });
});
// Back or Forward restores another tab.
watch(() => props.tab, (key) => { tab.value = key === 'archive' ? 'archive' : 'open'; });

const showSearch = computed(() => props.lists.length > SEARCH_FROM);
const visibleLists = computed(() => {
    const needle = plain(query.value.trim());
    if (!showSearch.value || needle === '') return props.lists;

    return props.lists.filter((l) => plain(l.name).includes(needle));
});

function openCopy(list) {
    copyTarget.value = { id: list.id, name: list.name };
    copyOpen.value = true;
}

// Archive pages come from the server (20 per page); the Open tab already has everything.
function goTo(page) {
    lastPage = page;
    loading.value = true;
    loadFailed.value = false;
    router.get('/shopping', { tab: 'archive', page }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onError: () => { loadFailed.value = true; },
        // A 5xx or a dropped connection is not a validation error: they have their own callbacks.
        onHttpException: () => { loadFailed.value = true; return false; },
        onNetworkError: () => { loadFailed.value = true; },
        onFinish: () => { loading.value = false; },
    });
}
const retry = () => goTo(lastPage);
</script>

<template>
    <Head :title="t('shopping.overview.title')" />
    <AppShell current="shopping">
        <div class="shop-index" data-testid="shopping-page">
            <div class="shop-index__head">
                <h1 class="shop-index__title">{{ t('shopping.overview.title') }}</h1>
                <UiButton v-if="canCreate" variant="primary" :icon="Plus" class="shop-index__new" @click="createOpen = true">{{ t('shopping.overview.new_list') }}</UiButton>
            </div>

            <UiTabs v-model="tab" :tabs="tabs" :label="t('shopping.overview.tabs_label')">
                <template #panel-open>
                    <UiEmptyState v-if="lists.length === 0" :heading-level="2" :icon="ShoppingCart" :title="t('shopping.overview.empty_open_title')" :body="t('shopping.overview.empty_open_body')">
                        <template v-if="canCreate" #action>
                            <UiButton variant="primary" :icon="Plus" @click="createOpen = true">{{ t('shopping.overview.new_list') }}</UiButton>
                        </template>
                    </UiEmptyState>
                    <template v-else>
                        <div v-if="showSearch" class="shop-index__search">
                            <label class="shop-index__search-label" for="shop-search">{{ t('shopping.overview.search_label') }}</label>
                            <UiInput id="shop-search" v-model="query" type="search" :icon="Search" autocomplete="off" />
                        </div>
                        <p v-if="visibleLists.length === 0" class="shop-index__none">{{ t('shopping.overview.search_none') }}</p>
                        <UiList v-else>
                            <ListRow v-for="list in visibleLists" :key="list.id" :list="list" @copy="openCopy" />
                        </UiList>
                    </template>
                </template>

                <template #panel-archive>
                    <UiErrorState v-if="loadFailed" live :heading-level="2" :title="t('shopping.overview.error_title')" :body="t('shopping.overview.error_body')" @retry="retry" />
                    <div v-else-if="loading" class="shop-index__loading" role="status" :aria-label="t('shopping.overview.loading')">
                        <UiSkeleton v-for="n in 3" :key="n" height="64px" />
                    </div>
                    <UiEmptyState v-else-if="archive.data.length === 0" :heading-level="2" :icon="ShoppingCart" :title="t('shopping.overview.empty_archive_title')" :body="t('shopping.overview.empty_archive_body')" />
                    <template v-else>
                        <UiList>
                            <ListRow v-for="list in archive.data" :key="list.id" :list="list" kind="archive" @copy="openCopy" />
                        </UiList>
                        <nav v-if="archive.lastPage > 1" class="shop-index__pager" :aria-label="t('shopping.overview.pager_label')">
                            <UiButton :disabled="archive.currentPage <= 1" @click="goTo(archive.currentPage - 1)">{{ t('shopping.overview.prev') }}</UiButton>
                            <span class="shop-index__page">{{ t('shopping.overview.page', { n: archive.currentPage, total: archive.lastPage }) }}</span>
                            <UiButton :disabled="archive.currentPage >= archive.lastPage" @click="goTo(archive.currentPage + 1)">{{ t('shopping.overview.next') }}</UiButton>
                        </nav>
                    </template>
                </template>
            </UiTabs>
        </div>

        <CreateListDialog v-if="canCreate" v-model:open="createOpen" :stores="stores" :candidates="responsibleCandidates" />
        <CopyListDialog v-model:open="copyOpen" :list="copyTarget" />
    </AppShell>
</template>

<style scoped>
.shop-index { display: flex; flex-direction: column; gap: var(--space-4); max-width: 840px; margin-inline: auto; width: 100%; }
.shop-index__head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3); }
.shop-index__title { margin: 0; font-size: var(--text-2xl); font-weight: 600; line-height: 1.2; overflow-wrap: anywhere; }
.shop-index__search { display: flex; flex-direction: column; gap: var(--space-1); margin-block-end: var(--space-3); }
.shop-index__search-label { font-size: var(--text-sm); font-weight: 600; }
.shop-index__none { margin: var(--space-4) 0; color: var(--color-text-muted); }
.shop-index__loading { display: flex; flex-direction: column; gap: var(--space-2); }
.shop-index__pager { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); margin-top: var(--space-4); }
.shop-index__page { color: var(--color-text-muted); font-size: var(--text-sm); }
@media (max-width: 640px) {
    .shop-index__new { width: 100%; }
}
</style>
