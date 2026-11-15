<script setup>
import { computed, nextTick, onMounted, provide, reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ChevronDown, Printer, Search } from '@lucide/vue';
import AppShell from '../../Layouts/AppShell.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiInput from '../../Components/ui/UiInput.vue';
import UiTag from '../../Components/ui/UiTag.vue';
import ManualBlock from '../../Manual/ManualBlock.vue';
import { filterTopics, sectionAnchor, topicAnchor } from '../../Manual/inline.js';

// Topics arrive already filtered by the server for this member's role and language (never trust the page to hide admin text).
const props = defineProps({
    categories: { type: Array, default: () => [] },
    topics: { type: Array, default: () => [] },
});

const { t } = useI18n();
const query = ref('');
const searching = computed(() => query.value.trim() !== '');
const showToc = ref(false);

// Short topics start open; long ones start collapsed so the page is easy to scan.
const open = reactive(new Set(props.topics.filter((tp) => tp.sections.length <= 3).flatMap((tp) => tp.sections.map((s) => `${tp.id}/${s.id}`))));
const isOpen = (topic, section) => searching.value || open.has(`${topic}/${section}`);

function toggle(topic, section) {
    const key = `${topic}/${section}`;
    if (open.has(key)) open.delete(key);
    else open.add(key);
}
const expandAll = () => props.topics.forEach((tp) => tp.sections.forEach((s) => open.add(`${tp.id}/${s.id}`)));
const collapseAll = () => open.clear();

const filtered = computed(() => filterTopics(props.topics, query.value));
const sectionCount = computed(() => filtered.value.reduce((n, f) => n + f.sections.length, 0));
const groups = computed(() =>
    props.categories
        .map((category) => ({ category, items: filtered.value.filter((f) => f.topic.category === category.id) }))
        .filter((g) => g.items.length),
);
const status = computed(() => (searching.value ? (sectionCount.value ? t('manual.results', sectionCount.value) : t('manual.no_results_title')) : ''));

// Jump to a topic or section: clear a search that hides it, open the section, scroll and move focus there.
async function goto({ topic, section }) {
    const exists = props.topics.find((tp) => tp.id === topic);
    if (!exists || (section && !exists.sections.some((s) => s.id === section))) return;
    if (searching.value && !filtered.value.some((f) => f.topic.id === topic && (!section || f.sections.some((s) => s.id === section)))) query.value = '';
    if (section) open.add(`${topic}/${section}`);
    showToc.value = false;
    await nextTick();
    const id = section ? sectionAnchor(topic, section) : topicAnchor(topic);
    const el = document.getElementById(id);
    if (!el) return;
    el.scrollIntoView?.({ block: 'start' });
    (section ? el.querySelector('button') : el)?.focus?.({ preventScroll: true });
    if (typeof history !== 'undefined') history.replaceState(history.state, '', `#${id}`);
}
provide('manual-goto', goto);
const print = () => window.print();

onMounted(() => {
    const m = /^#m-([a-z0-9-]+?)(?:--([a-z0-9-]+))?$/.exec(typeof location === 'undefined' ? '' : location.hash);
    if (m) goto({ topic: m[1], section: m[2] ?? null });
});
</script>

<template>
    <Head :title="t('manual.title')" />
    <AppShell current="manual">
        <div class="manual" data-testid="manual">
            <header class="manual__head">
                <h1 class="manual__title">{{ t('manual.title') }}</h1>
                <p class="manual__intro">{{ t('manual.intro') }}</p>
                <div class="manual__tools">
                    <UiField :label="t('manual.search_label')" class="manual__search">
                        <UiInput v-model="query" type="search" :icon="Search" :placeholder="t('manual.search_placeholder')" autocomplete="off" />
                    </UiField>
                    <div class="manual__buttons">
                        <UiButton size="sm" @click="expandAll">{{ t('manual.expand_all') }}</UiButton>
                        <UiButton size="sm" @click="collapseAll">{{ t('manual.collapse_all') }}</UiButton>
                        <UiButton size="sm" :icon="Printer" @click="print">{{ t('manual.print') }}</UiButton>
                    </div>
                </div>
                <p class="visually-hidden" role="status" aria-live="polite" data-testid="manual-status">{{ status }}</p>
                <p v-if="searching && sectionCount" class="manual__count" aria-hidden="true">{{ t('manual.results', sectionCount) }}</p>
            </header>

            <div class="manual__layout">
                <aside class="manual__side">
                    <button
                        type="button"
                        class="manual__toc-toggle"
                        :aria-expanded="showToc ? 'true' : 'false'"
                        aria-controls="manual-toc"
                        @click="showToc = !showToc"
                    >
                        {{ t('manual.on_this_page') }}
                        <ChevronDown :size="20" :stroke-width="1.75" aria-hidden="true" :class="['manual__chev', { 'is-open': showToc }]" />
                    </button>
                    <nav id="manual-toc" :aria-label="t('manual.on_this_page')" :class="['manual__toc', { 'is-shown': showToc }]">
                        <p class="manual__toc-title" aria-hidden="true">{{ t('manual.on_this_page') }}</p>
                        <div v-for="g in groups" :key="g.category.id" class="manual__toc-group">
                            <p class="manual__toc-cat">{{ g.category.title }}</p>
                            <ul class="manual__toc-list">
                                <li v-for="f in g.items" :key="f.topic.id">
                                    <a :href="`#${topicAnchor(f.topic.id)}`" @click.prevent="goto({ topic: f.topic.id, section: null })">{{ f.topic.title }}</a>
                                </li>
                            </ul>
                        </div>
                    </nav>
                </aside>

                <div class="manual__main">
                    <div v-if="searching && !groups.length" class="manual__empty" data-testid="manual-empty">
                        <h2 class="manual__empty-title">{{ t('manual.no_results_title') }}</h2>
                        <p>{{ t('manual.no_results_body') }}</p>
                        <UiButton @click="query = ''">{{ t('manual.clear_search') }}</UiButton>
                    </div>

                    <div v-for="g in groups" :key="g.category.id" class="manual__category">
                        <h2 :id="`mc-${g.category.id}`" class="manual__cat-title">{{ g.category.title }}</h2>
                        <div v-for="f in g.items" :id="topicAnchor(f.topic.id)" :key="f.topic.id" class="manual__topic" tabindex="-1">
                            <UiCard :title="f.topic.title" :heading-level="3" :title-id="`${topicAnchor(f.topic.id)}-h`">
                                <template v-if="f.topic.adminOnly" #action>
                                    <UiTag variant="warn">{{ t('manual.admin_only') }}</UiTag>
                                </template>
                                <p class="manual__summary">{{ f.topic.summary }}</p>
                                <ul v-if="f.sections.length > 1" class="manual__chips" :aria-label="t('manual.in_topic')">
                                    <li v-for="s in f.sections" :key="s.id">
                                        <a :href="`#${sectionAnchor(f.topic.id, s.id)}`" class="manual__chip" @click.prevent="goto({ topic: f.topic.id, section: s.id })">{{ s.title }}</a>
                                    </li>
                                </ul>
                                <div
                                    v-for="s in f.sections"
                                    :id="sectionAnchor(f.topic.id, s.id)"
                                    :key="s.id"
                                    class="msec"
                                >
                                    <div class="msec__bar">
                                        <h4 class="msec__h">
                                            <button
                                                type="button"
                                                class="msec__btn"
                                                :aria-expanded="isOpen(f.topic.id, s.id) ? 'true' : 'false'"
                                                :aria-controls="`${sectionAnchor(f.topic.id, s.id)}-panel`"
                                                @click="toggle(f.topic.id, s.id)"
                                            >
                                                <ChevronDown :size="20" :stroke-width="1.75" aria-hidden="true" :class="['msec__chev', { 'is-open': isOpen(f.topic.id, s.id) }]" />
                                                <span>{{ s.title }}</span>
                                            </button>
                                        </h4>
                                        <UiTag v-if="s.adminOnly && !f.topic.adminOnly" variant="warn">{{ t('manual.admin_only') }}</UiTag>
                                    </div>
                                    <div :id="`${sectionAnchor(f.topic.id, s.id)}-panel`" class="msec__panel" :hidden="!isOpen(f.topic.id, s.id)">
                                        <ManualBlock v-for="(block, i) in s.blocks" :key="i" :block="block" />
                                    </div>
                                </div>
                            </UiCard>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppShell>
</template>

<style scoped>
.manual { container: manual / inline-size; }
.manual__title { margin: 0 0 var(--space-2); font-size: var(--text-2xl); font-weight: 600; line-height: 1.2; }
.manual__intro { margin: 0 0 var(--space-4); max-width: 65ch; line-height: 1.6; color: var(--color-text-muted); }
.manual__tools { display: flex; flex-wrap: wrap; align-items: flex-end; gap: var(--space-3); margin-block-end: var(--space-4); }
.manual__search { flex: 1 1 16rem; max-width: 28rem; }
.manual__buttons { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.manual__count { margin: 0 0 var(--space-3); color: var(--color-text-muted); font-size: var(--text-sm); }

.manual__layout { display: grid; gap: var(--space-4); grid-template-columns: minmax(0, 1fr); align-items: start; }
.manual__side { min-width: 0; }
.manual__toc-toggle {
    display: flex; align-items: center; justify-content: space-between; gap: var(--space-2);
    width: 100%; min-height: 44px; padding: var(--space-2) var(--space-3);
    background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-md);
    font: inherit; font-weight: 600; cursor: pointer;
}
.manual__toc-toggle:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.manual__chev, .msec__chev { flex: none; transition: transform var(--duration) ease; }
.manual__chev.is-open, .msec__chev.is-open { transform: rotate(180deg); }
.manual__toc { display: none; margin-block-start: var(--space-2); padding: var(--space-3); background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.manual__toc.is-shown { display: block; }
.manual__toc-title { display: none; margin: 0 0 var(--space-2); font-weight: 600; }
.manual__toc-group + .manual__toc-group { margin-block-start: var(--space-3); }
.manual__toc-cat { margin: 0 0 var(--space-1); font-size: var(--text-sm); font-weight: 600; color: var(--color-text-muted); }
.manual__toc-list { margin: 0; padding: 0; list-style: none; }
.manual__toc-list a { display: block; min-height: 44px; padding: var(--space-2) var(--space-2); line-height: 1.3; color: var(--color-text); text-decoration: none; border-radius: var(--radius-sm); overflow-wrap: anywhere; }
.manual__toc-list a:hover { background: var(--color-surface-2); text-decoration: underline; }
.manual__toc-list a:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 1px; }

.manual__cat-title { margin: var(--space-6) 0 var(--space-3); font-size: var(--text-xl); font-weight: 600; line-height: 1.25; }
.manual__category:first-child .manual__cat-title { margin-block-start: 0; }
.manual__topic { margin-block-end: var(--space-4); scroll-margin-block-start: var(--space-4); }
.manual__topic:focus { outline: none; }
.manual__summary { margin: 0 0 var(--space-3); color: var(--color-text-muted); line-height: 1.6; }
.manual__chips { display: flex; flex-wrap: wrap; gap: var(--space-2); margin: 0 0 var(--space-3); padding: 0; list-style: none; }
.manual__chip { display: inline-flex; align-items: center; min-height: 44px; padding: 0 var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-full); color: var(--color-text); font-size: var(--text-sm); text-decoration: none; background: var(--color-surface); }
.manual__chip:hover { background: var(--color-surface-2); }
.manual__chip:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.manual__empty { padding: var(--space-6) var(--space-4); text-align: center; }
.manual__empty-title { margin: 0 0 var(--space-2); font-size: var(--text-lg); }

.msec { border-block-start: 1px solid var(--color-border); scroll-margin-block-start: var(--space-4); }
.msec__bar { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }
.msec__h { margin: 0; flex: 1 1 12rem; font-size: var(--text-base); font-weight: 600; line-height: 1.3; }
.msec__btn {
    display: flex; align-items: center; gap: var(--space-2); width: 100%; min-height: 48px; padding: var(--space-2) 0;
    background: transparent; border: 0; color: var(--color-text); font: inherit; font-weight: 600; text-align: start; cursor: pointer;
}
.msec__btn:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; border-radius: var(--radius-sm); }
.msec__btn span { min-width: 0; overflow-wrap: anywhere; }
.msec__panel { padding: 0 0 var(--space-2) calc(20px + var(--space-2)); }

@container manual (min-width: 960px) {
    .manual__layout { grid-template-columns: 16rem minmax(0, 1fr); gap: var(--space-8); }
    .manual__toc-toggle { display: none; }
    .manual__side { position: sticky; inset-block-start: var(--space-4); max-height: calc(100dvh - var(--space-8)); overflow-y: auto; }
    .manual__toc, .manual__toc.is-shown { display: block !important; margin: 0; }
    .manual__toc-title { display: block; }
}
</style>

<style>
@media print {
    .fh-frame:has(.manual) .sidebar,
    .fh-frame:has(.manual) .topbar,
    .fh-frame:has(.manual) .bottom-nav,
    .fh-frame:has(.manual) .fh-skip { display: none !important; }
    .manual .manual__tools, .manual .manual__side, .manual .manual__chips { display: none !important; }
    .manual .msec__panel[hidden] { display: block !important; }
    .manual .msec__chev { display: none; }
    .manual .manual__topic { break-inside: avoid-page; }
}
</style>
