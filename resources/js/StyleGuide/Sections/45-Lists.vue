<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import StyleSection from '../StyleSection.vue';
import UiList from '../../Components/ui/UiList.vue';
import UiListRow from '../../Components/ui/UiListRow.vue';
import UiTag from '../../Components/ui/UiTag.vue';
import UiTable from '../../Components/ui/UiTable.vue';

const { t, d, n } = useI18n();
const selected = ref('bill');

const columns = computed(() => [
    { key: 'date', label: t('style.demo.col_date') },
    { key: 'description', label: t('style.demo.col_description') },
    { key: 'category', label: t('style.demo.col_category') },
    { key: 'amount', label: t('style.demo.col_amount'), numeric: true },
]);
const rows = computed(() => [
    { id: 1, date: d(new Date('2026-11-02T09:00:00Z'), 'short'), description: t('style.demo.row_milk'), category: t('style.demo.category_groceries'), amount: n(12.5, 'decimal') },
    { id: 2, date: d(new Date('2026-11-05T09:00:00Z'), 'short'), description: t('style.demo.row_bill'), category: t('style.demo.category_home'), amount: n(84.2, 'decimal') },
    { id: 3, date: d(new Date('2026-11-09T09:00:00Z'), 'short'), description: t('style.demo.category_doctor'), category: t('style.demo.category_doctor'), amount: n(30, 'decimal') },
]);
</script>

<template>
    <StyleSection id="lists" title-key="style.section.lists">
        <UiList class="lists__list">
            <UiListRow as="button" @select="selected = 'milk'">
                <template #leading>
                    <label class="lists__check"><input type="checkbox" /><span class="visually-hidden">{{ t('style.demo.row_milk') }}</span></label>
                </template>
                {{ t('style.demo.row_milk') }}
                <template #meta>{{ t('style.demo.row_today') }}</template>
                <template #trailing><UiTag variant="warn">{{ t('style.demo.tag_due') }}</UiTag></template>
            </UiListRow>
            <UiListRow as="button" :selected="selected === 'bill'" :title="t('style.demo.row_bill')" @select="selected = 'bill'">
                <template #leading>
                    <label class="lists__check"><input type="checkbox" /><span class="visually-hidden">{{ t('style.demo.row_bill') }}</span></label>
                </template>
                {{ t('style.demo.row_bill') }}
                <template #trailing><UiTag variant="danger">{{ t('style.demo.tag_overdue') }}</UiTag></template>
            </UiListRow>
            <UiListRow as="button" disabled>
                {{ t('style.state.disabled') }}
                <template #meta>{{ t('style.demo.row_today') }}</template>
            </UiListRow>
        </UiList>

        <UiTable :caption="t('style.demo.table_caption')" :columns="columns" :rows="rows" />

        <div class="lists__narrow">
            <UiTable :caption="`${t('style.demo.table_caption')} (360 px)`" :columns="columns" :rows="rows" />
        </div>
        <UiTable :caption="t('style.state.loading')" :columns="columns" :rows="[]" loading />
        <UiTable :caption="t('style.state.empty')" :columns="columns" :rows="[]" />
    </StyleSection>
</template>

<style scoped>
.lists__list { margin-bottom: var(--space-6); border: 1px solid var(--color-border); border-radius: var(--radius-md); overflow: hidden; }
.lists__check { display: inline-flex; align-items: center; justify-content: center; min-width: 44px; min-height: 44px; }
.lists__check input { width: 20px; height: 20px; }
.lists__narrow { max-width: 360px; margin-block: var(--space-6); }
</style>
