<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Ban, Check, CircleX, Clock, Loader } from '@lucide/vue';
import UiTable from '../../Components/ui/UiTable.vue';
import UiTag from '../../Components/ui/UiTag.vue';
import UiButton from '../../Components/ui/UiButton.vue';

const STATUS = {
    queued: { variant: 'neutral', icon: Clock },
    running: { variant: 'neutral', icon: Loader },
    succeeded: { variant: 'success', icon: Check },
    failed: { variant: 'danger', icon: CircleX },
    cancelled: { variant: 'neutral', icon: Ban },
};

const props = defineProps({
    rows: { type: Array, default: () => [] },
    page: { type: Number, default: 1 },
    pages: { type: Number, default: 1 },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['page']);
const { t, locale } = useI18n();

const columns = computed(() => [
    { key: 'time', label: t('settings.ai_calls.col_time') },
    { key: 'startedBy', label: t('settings.ai_calls.col_started_by') },
    { key: 'person', label: t('settings.ai_calls.col_person') },
    { key: 'agent', label: t('settings.ai_calls.col_agent') },
    { key: 'model', label: t('settings.ai_calls.col_model') },
    { key: 'status', label: t('settings.ai_calls.col_status') },
    { key: 'duration', label: t('settings.ai_calls.col_duration') },
]);

const dateFormat = computed(() => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Europe/Bratislava' }));
const unit = (value, u) => new Intl.NumberFormat(locale.value, { style: 'unit', unit: u, unitDisplay: 'short', maximumFractionDigits: 0 }).format(value);

function duration(row) {
    if (row.durationMs === null || row.durationMs === undefined || row.status === 'queued' || row.status === 'running') return '-';
    const total = Math.round(row.durationMs / 1000);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    const parts = [];
    if (h) parts.push(unit(h, 'hour'));
    if (m) parts.push(unit(m, 'minute'));
    if (s || !parts.length) parts.push(unit(s, 'second'));
    return parts.join(' ');
}
const model = (row) => (!row.model ? '-' : row.model === 'default' ? t('settings.ai_calls.default_model') : row.model);
const agent = (row) => (row.agent === 'codex' ? t('settings.ai_calls.agent_codex') : row.agent ?? '-');
const statusOf = (row) => STATUS[row.status] ?? STATUS.queued;
const statusLabel = (row) => (STATUS[row.status] ? t(`settings.ai_calls.status_${row.status}`) : String(row.status ?? ''));
</script>

<template>
    <div class="ai-table" :class="{ 'is-busy': busy }" :aria-busy="busy ? 'true' : undefined">
        <UiTable :caption="t('settings.ai_calls.title')" caption-hidden :columns="columns" :rows="rows">
            <template #cell-time="{ row }"><time :datetime="row.time">{{ dateFormat.format(new Date(row.time)) }}</time></template>
            <template #cell-startedBy="{ row }">{{ row.startedBy ?? '-' }}</template>
            <template #cell-person="{ row }">{{ row.person ?? t('settings.ai_calls.removed_person') }}</template>
            <template #cell-agent="{ row }">{{ agent(row) }}</template>
            <template #cell-model="{ row }">{{ model(row) }}</template>
            <template #cell-status="{ row }"><UiTag :variant="statusOf(row).variant" :icon="statusOf(row).icon" class="ai-table__tag">{{ statusLabel(row) }}</UiTag></template>
            <template #cell-duration="{ row }">{{ duration(row) }}</template>
        </UiTable>
        <nav v-if="pages > 1" class="ai-table__pager" :aria-label="`${t('settings.ai_calls.title')}, ${t('settings.ai_calls.pages', { n: pages }, pages)}`">
            <UiButton :disabled="page <= 1" @click="emit('page', page - 1)">{{ t('settings.ai_calls.prev') }}</UiButton>
            <span class="ai-table__page" role="status">{{ t('audit.page', { n: page, total: pages }) }}</span>
            <UiButton :disabled="page >= pages" @click="emit('page', page + 1)">{{ t('settings.ai_calls.next') }}</UiButton>
        </nav>
        <p class="ai-table__retention">{{ t('settings.ai_calls.retention') }}</p>
    </div>
</template>

<style scoped>
.is-busy :deep(.ui-table) { opacity: 0.6; }
.ai-table__tag { white-space: normal; max-width: 100%; }
.ai-table__pager { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3); margin-top: var(--space-4); }
.ai-table__page { color: var(--color-text-muted); font-size: var(--text-sm); }
.ai-table__retention { margin: var(--space-4) 0 0; color: var(--color-text-muted); font-size: var(--text-sm); }
:deep(td) { overflow-wrap: anywhere; }
@media (forced-colors: active) {
    .ai-table :deep(.ui-tag) { border: 1px solid currentColor; }
}
</style>
