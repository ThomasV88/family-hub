<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import AppShell from '../../Layouts/AppShell.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiList from '../../Components/ui/UiList.vue';
import UiListRow from '../../Components/ui/UiListRow.vue';
import CategoryPanel from '../../Finance/CategoryPanel.vue';
import { categoryLabel } from '../../Finance/labels.js';

// Family-wide category list. Names only: no usage counts exist anywhere on this page. Rows open the panel (?cat=)
// for users with edit access.
const props = defineProps({
    finance: { type: Object, required: true },
    panelCategory: { type: Object, default: null },
});
const { t, locale } = useI18n();

const collator = computed(() => new Intl.Collator(locale.value));
function group(kind) {
    return props.finance.categories
        .filter((c) => c.kind === kind)
        .map((c) => ({ ...c, label: categoryLabel(c, t) }))
        .sort((a, b) => collator.value.compare(a.label, b.label));
}
const groups = computed(() => [
    { kind: 'expense', title: t('finance.cat.group_expense'), items: group('expense') },
    { kind: 'income', title: t('finance.cat.group_income'), items: group('income') },
]);

const OPTIONS = { preserveScroll: true, preserveState: true, replace: true, only: ['panelCategory'] };
const open = (cat) => router.get('/finance/categories', { cat }, OPTIONS);
const close = () => router.get('/finance/categories', {}, OPTIONS);
</script>

<template>
    <AppShell current="finances">
        <div class="cat-page" data-testid="finance-categories">
            <header class="cat-page__header">
                <div class="cat-page__heading">
                    <a class="cat-page__crumb" href="/finance">{{ t('finance.title') }}</a>
                    <h1 class="cat-page__title">{{ t('finance.cat.title') }}</h1>
                </div>
                <UiButton v-if="finance.canEdit" variant="primary" :icon="Plus" @click="open('new')">{{ t('finance.cat.add') }}</UiButton>
            </header>
            <section v-for="g in groups" :key="g.kind" :aria-labelledby="`cat-group-${g.kind}`" :data-group="g.kind">
                <h2 :id="`cat-group-${g.kind}`" class="cat-page__group">{{ g.title }}</h2>
                <UiList>
                    <UiListRow v-for="c in g.items" :key="c.id" :as="finance.canEdit ? 'button' : 'static'" class="cat-page__row" @select="open(c.id)">{{ c.label }}</UiListRow>
                </UiList>
            </section>
        </div>
        <CategoryPanel v-if="panelCategory && (finance.canEdit || panelCategory.mode !== 'new')" :key="panelCategory.id ?? 'new'" :category="panelCategory" :categories="finance.categories" @close="close" />
    </AppShell>
</template>

<style scoped>
.cat-page { display: flex; flex-direction: column; gap: var(--space-5); }
.cat-page__header { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: var(--space-3); }
.cat-page__heading { display: flex; flex-direction: column; gap: var(--space-1); }
.cat-page__crumb { color: var(--color-text-muted); font-size: var(--text-sm); }
.cat-page__title { margin: 0; font-size: var(--text-2xl); font-weight: 600; line-height: 1.2; }
.cat-page__group { margin: 0 0 var(--space-2); font-size: var(--text-lg); font-weight: 600; }
.cat-page__row { min-height: 56px; }
</style>
