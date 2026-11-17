<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ChefHat, Heart, Plus, Search, SearchX, SlidersHorizontal, X } from '@lucide/vue';
import AppShell from '../../Layouts/AppShell.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiDialog from '../../Components/ui/UiDialog.vue';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import UiErrorState from '../../Components/ui/UiErrorState.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiInput from '../../Components/ui/UiInput.vue';
import UiSkeleton from '../../Components/ui/UiSkeleton.vue';
import RecipeCard from '../../Components/Recipes/RecipeCard.vue';
import ImportDraftAlerts from '../../Components/Recipes/ImportDraftAlerts.vue';
import ImportFromUrlDialog from '../../Components/Recipes/ImportFromUrlDialog.vue';
import NewRecipeDialog from '../../Components/Recipes/NewRecipeDialog.vue';
import RecipeFilters from '../../Components/Recipes/RecipeFilters.vue';
import { activeChips, activeCount, cleared, normaliseFilters, toQuery, withoutChip } from '../../Components/Recipes/recipeFilters.js';

// Recipe overview: the recipes the member may see (the server filters; the client never decides access), search and filters in the address.
const props = defineProps({
    recipes: { type: Array, default: () => [] },
    hasMore: { type: Boolean, default: false },
    page: { type: Number, default: 1 },
    filters: { type: Object, default: () => ({}) },
    filterOptions: { type: Object, default: () => ({ categories: [], authors: [] }) },
    canCreate: { type: Boolean, default: false },
    // Reading recipes from a web page: { available (an AI agent is enabled), acknowledged (the notice was confirmed), providers }.
    import: { type: Object, default: () => ({ available: false, acknowledged: false, providers: [] }) },
    // The member's own import drafts: [{ id, status, host, reason }].
    drafts: { type: Array, default: () => [] },
});
const { t } = useI18n();

const SEARCH_DEBOUNCE_MS = 300;
const filters = ref(normaliseFilters(props.filters));
const q = ref(filters.value.q);
const shown = ref([...props.recipes]);
const hasMoreShown = ref(props.hasMore);
const loading = ref(false);
const loadingMore = ref(false);
const loadFailed = ref(false);
const sheetOpen = ref(false);
const announcement = ref('');
let timer = null;

const chips = computed(() => activeChips(filters.value, props.filterOptions, t));
const count = computed(() => activeCount(filters.value));
const searching = computed(() => filters.value.q.trim() !== '' || q.value.trim() !== '');
const anyFilter = computed(() => count.value > 0 || searching.value);
const onlyFavourites = computed(() => filters.value.favourites && count.value === 1 && !searching.value);

// Server -> view: page 1 replaces the cards, later pages append (already shown cards stay).
watch(() => [props.recipes, props.page], () => {
    if (props.page <= 1) {
        shown.value = [...props.recipes];
    } else {
        const known = new Set(shown.value.map((r) => r.id));
        const added = props.recipes.filter((r) => !known.has(r.id));
        shown.value = [...shown.value, ...added];
        announcement.value = added.length > 0 ? t('recipes.overview.loaded_more', { n: added.length }, added.length) : '';
    }
    hasMoreShown.value = props.hasMore;
});
watch(() => props.hasMore, (v) => { hasMoreShown.value = v; });
// Back or Forward restores another address.
watch(() => props.filters, (value) => {
    const next = normaliseFilters(value);
    if (timer !== null || JSON.stringify(toQuery(next)) === JSON.stringify(toQuery(filters.value))) return;
    filters.value = next;
    q.value = next.q;
});

function visit(next, page = null, extra = {}) {
    return router.get('/recipes', toQuery(next, page), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onError: () => { loadFailed.value = true; },
        // A 5xx or a dropped connection is not a validation error: they have their own callbacks.
        onHttpException: () => { loadFailed.value = true; return false; },
        onNetworkError: () => { loadFailed.value = true; },
        ...extra,
    });
}
function apply(next) {
    filters.value = next;
    loadFailed.value = false;
    loading.value = true;
    announcement.value = '';
    visit(next, null, { onFinish: () => { loading.value = false; } });
}

function onSearch(value) {
    q.value = value;
    if (timer !== null) clearTimeout(timer);
    timer = setTimeout(() => {
        timer = null;
        if (filters.value.q === q.value.trim()) return;
        apply({ ...filters.value, q: q.value.trim() });
    }, SEARCH_DEBOUNCE_MS);
}
// The ingredient box in the filter panel is typed into too: it waits like the search box.
let panelTimer = null;
function onPanel(next) {
    const typingIngredient = next.ingredient !== filters.value.ingredient && next.categories.length === filters.value.categories.length
        && next.author === filters.value.author && next.show === filters.value.show && next.favourites === filters.value.favourites;
    filters.value = { ...next, q: filters.value.q };
    if (panelTimer !== null) clearTimeout(panelTimer);
    if (typingIngredient) {
        panelTimer = setTimeout(() => { panelTimer = null; apply(filters.value); }, SEARCH_DEBOUNCE_MS);
    } else {
        panelTimer = null;
        apply(filters.value);
    }
}
const toggleFavourites = () => apply({ ...filters.value, favourites: !filters.value.favourites });
const removeChip = (chip) => apply(withoutChip(filters.value, chip));
function clearAll() {
    if (timer !== null) { clearTimeout(timer); timer = null; }
    q.value = '';
    sheetOpen.value = false;
    apply(cleared());
}
function loadMore() {
    if (loadingMore.value) return;
    loadingMore.value = true;
    announcement.value = '';
    visit(filters.value, props.page + 1, {
        only: ['recipes', 'hasMore', 'page'],
        onFinish: () => { loadingMore.value = false; },
    });
}
const retry = () => apply(filters.value);
// New recipe: the choice dialog when reading web pages is available, the plain form otherwise.
const importInfo = computed(() => props.import ?? { available: false, acknowledged: false, providers: [] });
const newOpen = ref(false);
const urlOpen = ref(false);
function goNew(event) {
    event.preventDefault();
    if (importInfo.value.available) newOpen.value = true;
    else router.visit('/recipes/create');
}
let reloadingDrafts = false;
function reloadDrafts() {
    if (reloadingDrafts) return;
    reloadingDrafts = true;
    router.reload({ only: ['drafts', 'import'], preserveScroll: true, preserveState: true, onFinish: () => { reloadingDrafts = false; } });
}

onBeforeUnmount(() => {
    if (timer !== null) clearTimeout(timer);
    if (panelTimer !== null) clearTimeout(panelTimer);
});
</script>

<template>
    <Head :title="t('recipes.overview.title')" />
    <AppShell current="recipes">
        <div class="rec-index" data-testid="recipes-page">
            <div class="rec-index__head">
                <h1 class="rec-index__title">{{ t('recipes.overview.title') }}</h1>
                <UiButton v-if="canCreate" variant="primary" :icon="Plus" href="/recipes/create" class="rec-index__new" @click="goNew">{{ t('recipes.overview.new_recipe') }}</UiButton>
            </div>

            <ImportDraftAlerts v-if="canCreate" :drafts="drafts" @changed="reloadDrafts" @retry="urlOpen = true" />

            <div class="rec-tools">
                <div class="rec-tools__bar">
                    <div class="rec-tools__search">
                        <UiField :label="t('recipes.overview.search_label')" label-visually-hidden>
                            <UiInput :model-value="q" type="search" :icon="Search" :placeholder="t('recipes.overview.search_placeholder')" autocomplete="off" @update:model-value="onSearch" />
                        </UiField>
                    </div>
                    <UiButton :variant="filters.favourites ? 'primary' : 'secondary'" :icon="Heart" :aria-pressed="filters.favourites ? 'true' : 'false'" data-testid="favourites-toggle" @click="toggleFavourites">{{ t('recipes.overview.favourites') }}</UiButton>
                    <UiButton variant="ghost" :icon="SlidersHorizontal" class="rec-tools__filters-btn" data-testid="filters-button" @click="sheetOpen = true">{{ count > 0 ? t('recipes.overview.filters_active', { n: count }) : t('recipes.overview.filters') }}</UiButton>
                </div>
                <div class="rec-tools__inline" data-testid="inline-filters">
                    <RecipeFilters :model-value="filters" :options="filterOptions" @update:model-value="onPanel" />
                </div>
                <div v-if="chips.length > 0 || anyFilter" class="rec-tools__chips">
                    <ul v-if="chips.length > 0" class="rec-chips" :aria-label="t('recipes.overview.active_filters')">
                        <li v-for="chip in chips" :key="chip.id">
                            <button type="button" class="rec-chip" :aria-label="t('recipes.filter.remove', { label: chip.label })" data-testid="filter-chip" @click="removeChip(chip)">
                                <span class="rec-chip__label">{{ chip.label }}</span>
                                <X :size="16" :stroke-width="1.75" aria-hidden="true" />
                            </button>
                        </li>
                    </ul>
                    <UiButton variant="ghost" size="sm" data-testid="clear-filters" @click="clearAll">{{ t('recipes.overview.clear_filters') }}</UiButton>
                </div>
            </div>

            <UiErrorState v-if="loadFailed" live :heading-level="2" :title="t('recipes.overview.error_title')" :body="t('recipes.overview.error_body')" @retry="retry" />
            <div v-else-if="loading" class="rec-grid" role="status" :aria-label="t('recipes.overview.loading')" data-testid="recipes-loading">
                <UiSkeleton v-for="n in 6" :key="n" height="18rem" />
            </div>
            <template v-else-if="shown.length > 0">
                <div class="rec-grid" role="list" :aria-label="t('recipes.overview.list_label')" data-testid="recipes-grid">
                    <div v-for="recipe in shown" :key="recipe.id" role="listitem" class="rec-grid__cell">
                        <RecipeCard :recipe="recipe" />
                    </div>
                </div>
                <div v-if="hasMoreShown" class="rec-index__more">
                    <UiButton variant="ghost" :loading="loadingMore" data-testid="load-more" @click="loadMore">{{ t('recipes.overview.load_more') }}</UiButton>
                </div>
            </template>
            <UiEmptyState v-else-if="!anyFilter" :heading-level="2" :icon="ChefHat" :title="t('recipes.overview.empty_title')" :body="t('recipes.overview.empty_body')">
                <template v-if="canCreate" #action>
                    <UiButton variant="primary" :icon="Plus" href="/recipes/create" @click="goNew">{{ t('recipes.overview.new_recipe') }}</UiButton>
                </template>
            </UiEmptyState>
            <UiEmptyState v-else-if="onlyFavourites" :heading-level="2" :icon="Heart" :title="t('recipes.overview.nofav_title')" :body="t('recipes.overview.nofav_body')" />
            <UiEmptyState v-else :heading-level="2" :icon="SearchX" :title="t('recipes.overview.nomatch_title')" :body="t('recipes.overview.nomatch_body')">
                <template #action>
                    <UiButton variant="ghost" @click="clearAll">{{ t('recipes.overview.clear_filters') }}</UiButton>
                </template>
            </UiEmptyState>

            <p class="visually-hidden" role="status" aria-live="polite" data-testid="recipes-announce">{{ announcement }}</p>
        </div>

        <NewRecipeDialog v-if="canCreate && importInfo.available" v-model:open="newOpen" @url="urlOpen = true" />
        <ImportFromUrlDialog v-if="canCreate && importInfo.available" v-model:open="urlOpen" :info="importInfo" @background="reloadDrafts" />

        <UiDialog v-model:open="sheetOpen" :title="t('recipes.filter.dialog_title')">
            <RecipeFilters :model-value="filters" :options="filterOptions" @update:model-value="onPanel" />
            <template #footer>
                <UiButton variant="ghost" @click="clearAll">{{ t('recipes.overview.clear_filters') }}</UiButton>
                <UiButton variant="primary" @click="sheetOpen = false">{{ t('recipes.filter.dialog_done') }}</UiButton>
            </template>
        </UiDialog>
    </AppShell>
</template>

<style scoped>
.rec-index { container-type: inline-size; container-name: rec; display: flex; flex-direction: column; gap: var(--space-4); max-width: var(--content-max); margin-inline: auto; width: 100%; }
.rec-index__head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3); }
.rec-index__title { margin: 0; font-size: var(--text-2xl); font-weight: 600; line-height: 1.2; overflow-wrap: anywhere; }
.rec-index__more { display: flex; justify-content: center; }

.rec-tools { display: flex; flex-direction: column; gap: var(--space-3); }
.rec-tools__bar { display: flex; flex-wrap: wrap; align-items: flex-start; gap: var(--space-2); }
.rec-tools__search { flex: 1 1 14rem; min-width: 0; }
.rec-tools__inline { display: none; }
.rec-tools__chips { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }

.rec-chips { display: flex; flex-wrap: wrap; gap: var(--space-2); margin: 0; padding: 0; list-style: none; }
.rec-chip {
    display: inline-flex;
    align-items: center;
    gap: var(--space-1);
    min-height: var(--control-sm);
    max-width: 100%;
    padding: var(--space-1) var(--space-3);
    border: 1px solid var(--color-primary);
    border-radius: var(--radius-full);
    background: var(--color-primary-soft);
    color: var(--color-primary);
    font: inherit;
    font-size: var(--text-sm);
    font-weight: 600;
    cursor: pointer;
}
.rec-chip__label { overflow-wrap: anywhere; text-align: start; }
.rec-chip:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
@media (pointer: coarse) {
    .rec-chip { min-height: var(--control-touch); }
}

/* Cards: 1 column under 480 px of container width, 2 from 480 px, 3 from 840 px; never more than 3. */
.rec-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--space-4); }
.rec-grid__cell { display: flex; flex-direction: column; min-width: 0; }
.rec-grid__cell > :deep(*) { flex: 1; }
@container rec (min-width: 480px) {
    .rec-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@container rec (min-width: 840px) {
    .rec-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--space-6); }
}
/* Filters: a bottom sheet under 640 px of container width, an inline row from 640 px. */
@container rec (max-width: 639px) {
    .rec-index__new { width: 100%; }
}
@container rec (min-width: 640px) {
    .rec-tools__inline { display: block; }
    .rec-tools__filters-btn { display: none; }
}
</style>
