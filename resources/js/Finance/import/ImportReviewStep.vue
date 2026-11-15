<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Copy, Link2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiCheckbox from '../../Components/ui/UiCheckbox.vue';
import UiSelect from '../../Components/ui/UiSelect.vue';
import UiTag from '../../Components/ui/UiTag.vue';
import { INTL_LOCALES } from '../../i18n.js';
import { useFrameWidth } from '../../Shell/shellState.js';
import MoneyAmount from '../MoneyAmount.vue';
import { categoryLabel, formatDay } from '../labels.js';

// Step 3. Counts, states and match names come from the server; the client only tracks which rows are ticked and the
// category overrides. Nothing is saved until the commit button. Row text is rendered as text only.
const props = defineProps({
    batch: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
});
const { t, locale } = useI18n();
const intl = computed(() => INTL_LOCALES[locale.value] ?? locale.value);

const root = ref(null);
const width = useFrameWidth(root);
const wide = computed(() => width.value >= 640);

const rows = computed(() => props.batch.rows);
const isDuplicate = (row) => row.state === 'duplicate' || row.state === 'probable_duplicate';
const included = ref(new Set(rows.value.filter((r) => r.state !== 'invalid' && r.include).map((r) => r.id)));
const overrides = ref({});
const selectedCount = computed(() => included.value.size);

function toggle(row, on) {
    const next = new Set(included.value);
    if (on) next.add(row.id);
    else next.delete(row.id);
    included.value = next;
}
const selectNew = () => { included.value = new Set([...included.value, ...rows.value.filter((r) => r.state === 'new').map((r) => r.id)]); };
const clearSelection = () => { included.value = new Set(); };

// Paging keeps the DOM small for big files; counts always cover all rows.
const PAGE = 50;
const paged = computed(() => rows.value.length > 200);
const shown = ref(PAGE);
const visibleRows = computed(() => (paged.value ? rows.value.slice(0, shown.value) : rows.value));

const counts = computed(() => props.batch.counts);
const duplicateCount = computed(() => (counts.value.duplicate ?? 0) + (counts.value.probable ?? 0));

const day = (iso) => formatDay(iso, intl.value, { day: 'numeric', month: 'short', year: 'numeric' });
const categoryOptions = (row) => [
    { value: '', label: t('finance.tx.uncategorized') },
    ...props.categories.filter((c) => c.kind === row.kind).map((c) => ({ value: c.id, label: categoryLabel(c, t) })),
];
const categoryValue = (row) => overrides.value[row.id] ?? '';
const setCategory = (row, value) => { overrides.value = { ...overrides.value, [row.id]: value }; };
const reason = (row) => {
    const known = ['date', 'amount', 'columns', 'currency', 'before_open', 'both'];
    return t(`finance.imp.reason_${known.includes(row.errorCode) ? row.errorCode : 'amount'}`, { n: row.rowNo });
};
const checkLabel = (row) => (isDuplicate(row) && !included.value.has(row.id) ? t('finance.imp.row_dup_label', { n: row.rowNo }) : t('finance.imp.row_check', { n: row.rowNo }));
const catLabel = (row) => `${t('finance.tx.col_category')}: ${row.description}`;

const busy = ref(false);
const error = ref('');
function commit() {
    if (selectedCount.value === 0 || busy.value) return;
    busy.value = true;
    error.value = '';
    const ids = rows.value.filter((r) => included.value.has(r.id)).map((r) => r.id);
    const categories = {};
    for (const id of ids) if (overrides.value[id]) categories[id] = overrides.value[id];
    router.post(`/finance/import/${props.batch.id}/commit`, { include: ids, categories }, {
        preserveScroll: true,
        onError: (errs) => { error.value = errs?.include ? t('finance.imp.select_one') : t('finance.imp.err_commit'); },
        onFinish: () => { busy.value = false; },
    });
}
function back() {
    router.delete(`/finance/import/${props.batch.id}`);
}
</script>

<template>
    <div ref="root" class="imp-review" data-testid="import-step-review">
        <div class="imp-review__summary">
            <p class="imp-review__total">{{ t('finance.imp.rows', { n: counts.total }, counts.total) }}</p>
            <ul class="imp-review__tags">
                <li><UiTag variant="success">{{ t('finance.imp.tag_new', { n: counts.new }) }}</UiTag></li>
                <li><UiTag variant="warn" :icon="Copy">{{ t('finance.imp.tag_dup', { n: duplicateCount }) }}</UiTag></li>
                <li><UiTag variant="danger">{{ t('finance.imp.tag_bad', { n: counts.invalid }) }}</UiTag></li>
            </ul>
        </div>
        <p class="imp-review__help">{{ t('finance.imp.dup_help') }}</p>
        <div class="imp-review__bulk">
            <UiButton variant="ghost" size="sm" @click="selectNew">{{ t('finance.imp.select_new') }}</UiButton>
            <UiButton variant="ghost" size="sm" @click="clearSelection">{{ t('finance.imp.clear_sel') }}</UiButton>
        </div>

        <UiAlert v-if="error" variant="danger" live>{{ error }}</UiAlert>

        <div v-if="wide" class="imp-review__scroll">
            <table class="imp-rtable">
                <caption class="visually-hidden">{{ t('finance.imp.step_review') }}</caption>
                <thead>
                    <tr>
                        <th scope="col"><span class="visually-hidden">{{ t('finance.imp.col_select') }}</span></th>
                        <th scope="col">{{ t('finance.tx.col_date') }}</th>
                        <th scope="col">{{ t('finance.tx.col_description') }}</th>
                        <th scope="col">{{ t('finance.tx.col_category') }}</th>
                        <th scope="col" class="is-end">{{ t('finance.tx.col_amount') }}</th>
                        <th scope="col">{{ t('finance.imp.col_status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in visibleRows" :key="row.id" :class="{ 'is-left-out': isDuplicate(row) && !included.has(row.id) }">
                        <td>
                            <UiCheckbox v-if="row.state !== 'invalid'" :model-value="included.has(row.id)" :aria-label="checkLabel(row)" @update:model-value="toggle(row, $event)" />
                        </td>
                        <td><time v-if="row.bookedOn" :datetime="row.bookedOn">{{ day(row.bookedOn) }}</time></td>
                        <td>
                            <span class="imp-desc" :class="{ 'imp-struck': isDuplicate(row) && !included.has(row.id) }">{{ row.description }}</span>
                            <span v-if="row.state === 'invalid'" class="imp-reason">{{ reason(row) }}</span>
                        </td>
                        <td>
                            <UiSelect v-if="row.state !== 'invalid'" :model-value="categoryValue(row)" :options="categoryOptions(row)" :aria-label="catLabel(row)" @update:model-value="setCategory(row, $event)" />
                        </td>
                        <td class="is-end"><span v-if="row.amountMinor !== null" :class="{ 'imp-struck': isDuplicate(row) && !included.has(row.id) }"><MoneyAmount :minor="row.amountMinor" :currency="batch.currency" signed /></span></td>
                        <td>
                            <span class="imp-tags">
                                <UiTag v-if="row.state === 'new'" variant="success">{{ t('finance.imp.row_new') }}</UiTag>
                                <UiTag v-else-if="isDuplicate(row)" variant="warn" :icon="Copy">{{ t('finance.imp.row_dup') }}</UiTag>
                                <UiTag v-else variant="danger">{{ t('finance.imp.row_bad') }}</UiTag>
                                <UiTag v-if="row.matchName" :icon="Link2">{{ t('finance.tx.matches', { name: row.matchName }) }}</UiTag>
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ul v-else class="imp-rlist">
            <li v-for="row in visibleRows" :key="row.id" class="imp-rrow">
                <div class="imp-rrow__line">
                    <UiCheckbox v-if="row.state !== 'invalid'" :model-value="included.has(row.id)" :aria-label="checkLabel(row)" @update:model-value="toggle(row, $event)" />
                    <span class="imp-rrow__desc imp-desc" :class="{ 'imp-struck': isDuplicate(row) && !included.has(row.id) }">{{ row.description }}</span>
                    <span v-if="row.amountMinor !== null" class="imp-rrow__amount" :class="{ 'imp-struck': isDuplicate(row) && !included.has(row.id) }"><MoneyAmount :minor="row.amountMinor" :currency="batch.currency" signed /></span>
                </div>
                <div class="imp-rrow__meta">
                    <time v-if="row.bookedOn" :datetime="row.bookedOn">{{ day(row.bookedOn) }}</time>
                    <UiTag v-if="row.state === 'new'" variant="success">{{ t('finance.imp.row_new') }}</UiTag>
                    <UiTag v-else-if="isDuplicate(row)" variant="warn" :icon="Copy">{{ t('finance.imp.row_dup') }}</UiTag>
                    <UiTag v-else variant="danger">{{ t('finance.imp.row_bad') }}</UiTag>
                    <UiTag v-if="row.matchName" :icon="Link2">{{ t('finance.tx.matches', { name: row.matchName }) }}</UiTag>
                </div>
                <p v-if="row.state === 'invalid'" class="imp-reason">{{ reason(row) }}</p>
                <UiSelect v-else :model-value="categoryValue(row)" :options="categoryOptions(row)" :aria-label="catLabel(row)" @update:model-value="setCategory(row, $event)" />
            </li>
        </ul>

        <div v-if="paged && shown < rows.length" class="imp-review__more">
            <UiButton variant="secondary" @click="shown += PAGE">{{ t('finance.imp.more_rows') }}</UiButton>
        </div>

        <div class="imp-review__footer" data-testid="import-footer">
            <p class="imp-review__note">{{ t('finance.imp.nothing_saved') }}</p>
            <p v-if="selectedCount === 0" id="imp-select-hint" class="imp-review__hint">{{ t('finance.imp.select_one') }}</p>
            <div class="imp-review__actions">
                <UiButton variant="primary" :disabled="selectedCount === 0" :loading="busy" :aria-describedby="selectedCount === 0 ? 'imp-select-hint' : undefined" @click="commit">{{ t('finance.imp.commit', { n: selectedCount }, selectedCount) }}</UiButton>
                <UiButton variant="secondary" @click="back">{{ t('finance.back') }}</UiButton>
            </div>
        </div>
    </div>
</template>

<style scoped>
.imp-review { display: flex; flex-direction: column; gap: var(--space-3); }
.imp-review__summary { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-3); }
.imp-review__total { margin: 0; font-size: var(--text-xl); font-weight: 600; }
.imp-review__tags { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: var(--space-2); }
.imp-review__help { margin: 0; color: var(--color-text-muted); }
.imp-review__bulk { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.imp-review__scroll { overflow-x: auto; max-width: 100%; }
.imp-rtable { border-collapse: collapse; width: 100%; }
.imp-rtable th { padding: var(--space-2) var(--space-3); text-align: start; font-size: var(--text-sm); font-weight: 600; background: var(--color-surface-2); }
.imp-rtable td { padding: var(--space-2) var(--space-3); border-top: 1px solid var(--color-border); vertical-align: middle; overflow-wrap: anywhere; }
.imp-rtable .is-end { text-align: end; white-space: nowrap; }
.imp-desc { overflow-wrap: anywhere; }
.imp-struck { text-decoration: line-through; color: var(--color-text-muted); }
.imp-reason { display: block; margin: 0; font-size: var(--text-sm); color: var(--color-danger-text, var(--color-text)); }
.imp-tags { display: flex; flex-wrap: wrap; gap: var(--space-1); }
.imp-rlist { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.imp-rrow { display: flex; flex-direction: column; gap: var(--space-2); padding-block: var(--space-3); border-top: 1px solid var(--color-border); }
.imp-rrow__line { display: flex; align-items: center; gap: var(--space-2); min-height: 44px; }
.imp-rrow__desc { flex: 1; min-width: 0; }
.imp-rrow__amount { flex: none; white-space: nowrap; font-weight: 600; }
.imp-rrow__meta { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); font-size: var(--text-sm); color: var(--color-text-muted); }
.imp-review__more { display: flex; justify-content: center; }
.imp-review__footer { position: sticky; bottom: 0; display: flex; flex-direction: column; gap: var(--space-2); padding-block: var(--space-3); background: var(--color-bg, var(--color-surface)); border-top: 1px solid var(--color-border); }
.imp-review__note, .imp-review__hint { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.imp-review__actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
</style>
