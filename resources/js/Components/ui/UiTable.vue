<script setup>
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import UiSkeleton from './UiSkeleton.vue';
import UiEmptyState from './UiEmptyState.vue';

const props = defineProps({
    caption: { type: String, required: true },
    captionHidden: { type: Boolean, default: false },
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
    rowKey: { type: String, default: 'id' },
});
const { t } = useI18n();
const captionId = `ui-table-${useId()}`;
const colspan = computed(() => props.columns.length);
const cls = (col) => ({ 'is-numeric': col.numeric, 'is-end': col.align === 'end' });
</script>

<template>
    <div class="ui-table">
        <div class="ui-table__scroll" tabindex="0" role="region" :aria-labelledby="captionId">
            <table :aria-busy="loading ? 'true' : undefined">
                <caption :id="captionId" :class="{ 'visually-hidden': captionHidden, 'ui-table__caption': !captionHidden }">{{ caption }}</caption>
                <thead>
                    <tr>
                        <th v-for="col in columns" :key="col.key" scope="col" :class="cls(col)">{{ col.label }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-if="loading">
                        <tr v-for="n in 3" :key="`s${n}`">
                            <td v-for="col in columns" :key="col.key" :data-label="col.label"><UiSkeleton width="70%" /></td>
                        </tr>
                    </template>
                    <tr v-else-if="!rows.length" class="ui-table__empty">
                        <td :colspan="colspan"><UiEmptyState :title="t('empty.generic.title')" :body="t('empty.generic.body')" /></td>
                    </tr>
                    <template v-else>
                        <tr v-for="(row, i) in rows" :key="row[rowKey] ?? i">
                            <td v-for="col in columns" :key="col.key" :data-label="col.label" :class="cls(col)">
                                <slot :name="`cell-${col.key}`" :row="row">{{ row[col.key] }}</slot>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</template>

<style scoped>
.ui-table { container-type: inline-size; container-name: table; }
.ui-table__scroll { overflow-x: auto; border: 1px solid var(--color-border); border-radius: var(--radius-md); background: var(--color-surface); }
.ui-table__scroll:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
table { width: 100%; border-collapse: collapse; font-size: var(--text-base); line-height: 1.5; }
.ui-table__caption { padding: var(--space-3); text-align: start; font-size: var(--text-sm); font-weight: 600; color: var(--color-text-muted); }
thead th {
    position: sticky;
    top: 0;
    padding: var(--space-2) var(--space-3);
    background: var(--color-surface-2);
    font-size: var(--text-sm);
    font-weight: 600;
    text-align: start;
    white-space: nowrap;
}
td { padding: var(--space-3); border-top: 1px solid var(--color-border); }
tbody tr:hover { background: var(--color-surface-2); }
.is-numeric { font-variant-numeric: tabular-nums; text-align: end; }
.is-end { text-align: end; }
.ui-table__empty:hover { background: none; }

@container table (max-width: 559px) {
    thead {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
        white-space: nowrap;
    }
    table, tbody, tr, td { display: block; }
    tr { border-top: 1px solid var(--color-border); }
    tr:first-child { border-top: 0; }
    td { display: flex; justify-content: space-between; gap: var(--space-3); border-top: 0; padding: var(--space-2) var(--space-3); }
    td::before { content: attr(data-label); font-size: var(--text-sm); font-weight: 600; color: var(--color-text-muted); }
    td[colspan] { display: block; }
    td[colspan]::before { content: none; }
}
</style>
