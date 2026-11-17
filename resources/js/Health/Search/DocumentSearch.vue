<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Search, X } from '@lucide/vue';
import HelpTip from '../../Components/ui/HelpTip.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiInput from '../../Components/ui/UiInput.vue';
import UiSelect from '../../Components/ui/UiSelect.vue';
import SearchResults from './SearchResults.vue';
import { useDocumentSearch } from './useDocumentSearch.js';

// Search box of the Health page. What is typed stays in this component (see useDocumentSearch): never in the URL, the
// router, history, browser storage or Inertia state. The results region replaces the timeline while a query is active.
const props = defineProps({
    person: { type: Object, required: true },
    peopleCount: { type: Number, default: 1 },
    reliability: { type: String, default: 'reliable' },
});
const emit = defineEmits(['update:active', 'open']);
const { t } = useI18n();

const scope = ref('all');
const showScope = computed(() => props.peopleCount > 1);
const search = useDocumentSearch({ scopePersonId: () => (showScope.value && scope.value === 'person' ? props.person.id : null) });
const scopeOptions = computed(() => [
    { value: 'all', label: t('health.search.scope_all') },
    { value: 'person', label: props.person.name },
]);

const input = ref(null);
const results = ref(null);
const announcement = ref('');
const focusInput = async () => { await nextTick(); input.value?.focus(); };

watch(search.active, (value) => emit('update:active', value), { immediate: true });
// A search scoped to one person is forgotten when another person is opened; "All people" stays.
watch(() => props.person.id, () => { if (showScope.value && scope.value === 'person') search.clear(); });
watch(scope, () => search.rescope());

function announce(text) {
    announcement.value = '';
    nextTick(() => { announcement.value = text; });
}
// Counts and states only; titles and snippets are never put into the live region.
watch([search.state, () => search.items.value.length, () => search.hasMore.value], ([state, count, more]) => {
    if (state === 'results') announce(more ? t('health.search.showing', { n: count }) : t('health.search.heading', { n: count }, count));
    else if (state === 'empty') announce(t('health.search.empty_title'));
    else if (state === 'loading' || state === 'idle' || state === 'too_short') announcement.value = '';
});
watch(() => search.loaded.value.tick, () => announce(t('health.search.loaded_more', { n: search.loaded.value.count })));
watch(() => search.focusTick.value, async () => {
    await nextTick();
    results.value?.focusHeading();
});
watch(() => search.loaded.value.tick, () => results.value?.focusRow(search.loaded.value.firstIndex));

function onClear() {
    search.clear();
    focusInput();
}
function onEscape() {
    if (search.query.value !== '') onClear();
}
</script>

<template>
    <div class="doc-search" data-testid="doc-search">
        <form role="search" :aria-label="t('health.search.label')" class="doc-search__form" data-testid="doc-search-form" @submit.prevent="search.submit()">
            <div class="doc-search__field">
                <UiField :label="t('health.search.label')" label-visually-hidden>
                    <UiInput
                        ref="input"
                        :model-value="search.query.value"
                        type="search"
                        :icon="Search"
                        :placeholder="t('health.search.placeholder')"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                        enterkeyhint="search"
                        inputmode="search"
                        maxlength="100"
                        data-testid="search-input"
                        @update:model-value="search.setQuery"
                        @keydown.esc="onEscape"
                    />
                </UiField>
                <UiButton v-if="search.query.value !== ''" class="doc-search__x" variant="ghost" icon-only :icon="X" :label="t('health.search.clear')" data-testid="search-x" @click="onClear" />
            </div>
            <UiField v-if="showScope" :label="t('health.search.scope_label')" class="doc-search__scope">
                <UiSelect v-model="scope" :options="scopeOptions" data-testid="search-scope" />
            </UiField>
            <UiButton type="submit" variant="primary" :icon="Search" class="doc-search__submit" data-testid="search-submit">{{ t('health.search.submit') }}</UiButton>
        </form>

        <p class="visually-hidden" role="status" data-testid="search-announce">{{ announcement }}</p>
        <p class="doc-search__hint" aria-live="polite" data-testid="search-hint">
            <template v-if="search.state.value === 'too_short'">{{ t('health.search.too_short') }}</template>
        </p>
        <p v-if="search.state.value === 'idle' || search.state.value === 'too_short'" class="doc-search__hint doc-search__hint--idle" data-testid="search-idle">
            {{ t('health.search.idle_hint') }}
            <HelpTip topic="health.search" :label="t('health.search.label')" />
        </p>

        <SearchResults
            v-if="search.active.value"
            ref="results"
            :state="search.state.value"
            :items="search.items.value"
            :has-more="search.hasMore.value"
            :reliability="reliability"
            :refreshing="search.refreshing.value"
            :loading-more="search.loadingMore.value"
            :more-error="search.moreError.value"
            :error-kind="search.errorKind.value"
            @clear="onClear"
            @retry="search.retry()"
            @more="search.loadMore()"
            @open="(item) => emit('open', item)"
        />
    </div>
</template>

<style scoped>
.doc-search { display: flex; flex-direction: column; gap: var(--space-4); min-width: 0; container-type: inline-size; }
.doc-search__form { display: flex; flex-direction: column; gap: var(--space-2); }
.doc-search__field { position: relative; flex: 1; min-width: 0; }
.doc-search__field :deep(.ui-control) { padding-inline-end: calc(var(--space-2) + 44px); }
.doc-search__field :deep(input[type='search']::-webkit-search-cancel-button) { appearance: none; -webkit-appearance: none; display: none; }
.doc-search__x { position: absolute; inset-inline-end: 0; top: 0; }
.doc-search__hint { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.doc-search__hint:empty { display: none; }
.doc-search__hint--idle { display: flex; align-items: center; flex-wrap: wrap; gap: var(--space-1); }
@container (min-width: 640px) {
    .doc-search__form { flex-direction: row; align-items: flex-end; }
    .doc-search__scope { width: 200px; flex: none; }
}
</style>
