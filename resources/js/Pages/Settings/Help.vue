<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { CircleQuestionMark, Search } from '@lucide/vue';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import UiErrorState from '../../Components/ui/UiErrorState.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiInput from '../../Components/ui/UiInput.vue';
import UiSelect from '../../Components/ui/UiSelect.vue';
import { useToast } from '../../Components/ui/useToast.js';
import { help, setTopicText } from '../../Help/helpStore.js';
import HelpRow from '../../Settings/Help/HelpRow.vue';

// Settings > Contextual help (administrators only): every registered "?" with its placement and its texts in all languages,
// editable inline with the same editor the Ctrl+click dialog uses.
const props = defineProps({
    sections: { type: Array, default: () => [] },
    topics: { type: Array, default: () => [] },
    orphans: { type: Array, default: () => [] },
    areas: { type: Array, default: () => [] },
    locales: { type: Array, default: () => ['sk', 'pl', 'en'] },
    maxLength: { type: Number, default: 400 },
    error: { type: Boolean, default: false },
});

const { t, locale } = useI18n();
const toast = useToast();

const rows = ref(props.topics.map((r) => ({ ...r })));
watch(() => props.topics, (next) => { rows.value = next.map((r) => ({ ...r })); });

const query = ref('');
const area = ref('');
const openKey = ref(null);

// The language the app is shown in (sk-SK, pl-PL, en-GB map to the short code the server uses).
const activeLocale = computed(() => (props.locales.find((l) => String(locale.value).toLowerCase().startsWith(l)) ?? 'en'));

const fold = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const haystack = (r) => fold([r.key, r.location.area, r.location.screen, r.location.field, ...Object.values(r.titles ?? {}), ...Object.values(r.texts)].join(' '));

const filtered = computed(() => {
    const words = fold(query.value).split(/\s+/).filter(Boolean);
    return rows.value.filter((r) => (!area.value || r.area === area.value) && words.every((w) => haystack(r).includes(w)));
});
const editedCount = computed(() => rows.value.filter((r) => props.locales.some((l) => r.edited[l])).length);
const anyFilter = computed(() => Boolean(query.value.trim() || area.value));
const areaOptions = computed(() => [{ value: '', label: t('settings.help.area_all') }, ...props.areas.map((a) => ({ value: a.id, label: a.label }))]);

function clear() {
    query.value = '';
    area.value = '';
}
function toggle(key) {
    openKey.value = openKey.value === key ? null : key;
}
function saved(key, data) {
    const index = rows.value.findIndex((r) => r.key === key);
    if (index !== -1 && data?.topic) rows.value[index] = data.topic;
    if (data?.current) setTopicText(key, data.current);
    toast.show(t('help.editor.saved'));
    openKey.value = null;
}
const retry = () => router.reload();
</script>

<template>
    <Head :title="t('settings.section.help')" />
    <SettingsLayout :sections="sections" current="help" title="settings.section.help">
        <div class="help-page">
            <UiErrorState v-if="error" :heading-level="2" :body="t('settings.help.load_failed')" @retry="retry" />
            <template v-else>
                <p class="help-page__intro">{{ t('settings.help.intro') }}</p>
                <p class="help-page__counts" data-testid="help-counts">
                    {{ t('settings.help.count_total', { n: rows.length }) }} {{ t('settings.help.count_edited', { n: editedCount }) }}
                </p>

                <form class="help-page__filters" role="search" :aria-label="t('settings.help.filters_label')" @submit.prevent>
                    <UiField :label="t('settings.help.search_label')" class="help-page__search">
                        <UiInput v-model="query" type="search" :icon="Search" :placeholder="t('settings.help.search_placeholder')" autocomplete="off" data-testid="help-search" />
                    </UiField>
                    <UiField :label="t('settings.help.area_label')" class="help-page__area">
                        <UiSelect v-model="area" :options="areaOptions" data-testid="help-area" />
                    </UiField>
                </form>

                <UiEmptyState v-if="!rows.length" :heading-level="2" :icon="CircleQuestionMark" :title="t('settings.help.empty_title')" :body="t('settings.help.empty_body')" />
                <UiEmptyState v-else-if="!filtered.length" :heading-level="2" :icon="Search" :title="t('settings.help.filtered_empty')">
                    <template #action><UiButton variant="ghost" @click="clear">{{ t('settings.help.clear') }}</UiButton></template>
                </UiEmptyState>
                <ul v-else class="help-page__list" data-testid="help-list" :aria-label="t('settings.help.list_label')">
                    <li v-for="r in filtered" :key="r.key">
                        <HelpRow
                            :topic="r"
                            :locales="locales"
                            :max-length="maxLength"
                            :active-locale="activeLocale"
                            :open="openKey === r.key"
                            @toggle="toggle(r.key)"
                            @saved="(data) => saved(r.key, data)"
                        />
                    </li>
                </ul>

                <UiCard v-if="orphans.length" :title="t('settings.help.orphans_title')" :heading-level="2" class="help-page__orphans" data-testid="help-orphans">
                    <p class="help-page__intro">{{ t('settings.help.orphans_body') }}</p>
                    <ul class="help-page__orphan-list">
                        <li v-for="o in orphans" :key="o.key"><code>{{ o.key }}</code></li>
                    </ul>
                </UiCard>
            </template>
        </div>
    </SettingsLayout>
</template>

<style scoped>
.help-page { container: help-page / inline-size; min-width: 0; }
.help-page__intro { margin: 0 0 var(--space-3); font-size: var(--text-sm); color: var(--color-text-muted); overflow-wrap: anywhere; }
.help-page__counts { margin: 0 0 var(--space-4); font-size: var(--text-sm); font-weight: 600; }
.help-page__filters { display: flex; flex-wrap: wrap; gap: var(--space-3); margin-bottom: var(--space-2); }
.help-page__search { flex: 2 1 14rem; min-width: 0; }
.help-page__area { flex: 1 1 10rem; min-width: 0; }
.help-page__list { display: flex; flex-direction: column; gap: var(--space-3); margin: var(--space-2) 0 0; padding: 0; list-style: none; }
.help-page__orphans { margin-top: var(--space-6); }
.help-page__orphan-list { margin: 0; padding-inline-start: var(--space-4); overflow-wrap: anywhere; }
@container help-page (max-width: 559px) {
    .help-page__search, .help-page__area { flex-basis: 100%; }
}
</style>
