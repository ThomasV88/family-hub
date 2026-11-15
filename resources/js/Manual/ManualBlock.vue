<script setup>
import { useId } from 'vue';
import { useI18n } from 'vue-i18n';
import UiAlert from '../Components/ui/UiAlert.vue';
import ManualText from './ManualText.js';

defineProps({ block: { type: Object, required: true } });
const { t } = useI18n();
const tableId = `mt-${useId()}`;
</script>

<template>
    <p v-if="block.type === 'p'" class="mb-p"><ManualText :text="block.text" /></p>

    <div v-else-if="block.type === 'steps'" class="mb-steps">
        <p class="mb-steps__title">{{ block.title || t('manual.steps_title') }}</p>
        <ol class="mb-steps__list">
            <li v-for="(item, i) in block.items" :key="i"><ManualText :text="item" /></li>
        </ol>
    </div>

    <ul v-else-if="block.type === 'list'" class="mb-list">
        <li v-for="(item, i) in block.items" :key="i"><ManualText :text="item" /></li>
    </ul>

    <UiAlert v-else-if="block.type === 'tip'" variant="info" class="mb-alert">
        <strong>{{ t('manual.tip') }}</strong> <ManualText :text="block.text" />
    </UiAlert>

    <UiAlert v-else-if="block.type === 'warning'" variant="warning" class="mb-alert">
        <strong>{{ t('manual.warning') }}</strong> <ManualText :text="block.text" />
    </UiAlert>

    <p v-else-if="block.type === 'example'" class="mb-example">
        <strong>{{ t('manual.example') }}</strong> <ManualText :text="block.text" />
    </p>

    <div v-else-if="block.type === 'table'" class="mb-table">
        <div class="mb-table__scroll" tabindex="0" role="region" :aria-labelledby="tableId">
            <table>
                <caption :id="tableId" class="visually-hidden">{{ block.caption }}</caption>
                <thead>
                    <tr><th v-for="(col, c) in block.columns" :key="c" scope="col"><ManualText :text="col" /></th></tr>
                </thead>
                <tbody>
                    <tr v-for="(row, r) in block.rows" :key="r">
                        <template v-for="(cell, c) in row" :key="c">
                            <th v-if="c === 0" scope="row"><ManualText :text="cell" /></th>
                            <td v-else><ManualText :text="cell" /></td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<style scoped>
.mb-p { margin: 0 0 var(--space-3); line-height: 1.6; overflow-wrap: anywhere; }
.mb-steps { margin: 0 0 var(--space-3); }
.mb-steps__title { margin: 0 0 var(--space-1); font-weight: 600; font-size: var(--text-sm); color: var(--color-text-muted); }
.mb-steps__list, .mb-list { margin: 0 0 var(--space-3); padding-inline-start: var(--space-6); line-height: 1.6; }
.mb-steps__list li, .mb-list li { margin-block-end: var(--space-1); padding-inline-start: var(--space-1); overflow-wrap: anywhere; }
.mb-alert { margin-block: var(--space-3); }
.mb-example {
    margin: 0 0 var(--space-3);
    padding: var(--space-3);
    border-inline-start: 3px solid var(--color-border-strong);
    background: var(--color-surface-2);
    border-radius: var(--radius-md);
    line-height: 1.6;
    overflow-wrap: anywhere;
}
.mb-table { margin: 0 0 var(--space-3); }
.mb-table__scroll { overflow-x: auto; border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.mb-table table { width: 100%; border-collapse: collapse; font-size: var(--text-sm); }
.mb-table th, .mb-table td { padding: var(--space-2) var(--space-3); text-align: start; vertical-align: top; border-block-end: 1px solid var(--color-border); overflow-wrap: anywhere; }
.mb-table thead th { background: var(--color-surface-2); font-weight: 600; }
.mb-table tbody th { font-weight: 600; }
.mb-table tr:last-child th, .mb-table tr:last-child td { border-block-end: 0; }
</style>

<style>
.manual-link { color: var(--color-primary); text-decoration: underline; }
.manual-link:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; border-radius: 2px; }
</style>
