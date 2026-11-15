<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ListChecks } from '@lucide/vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import UiErrorState from '../../Components/ui/UiErrorState.vue';
import UiSkeleton from '../../Components/ui/UiSkeleton.vue';
import UiTable from '../../Components/ui/UiTable.vue';

// Counts-only history. Only the contract fields are read, so an unexpected extra field in the data is never rendered.
const props = defineProps({
    // [{ startedAt, scope, dailyCap, wouldRead, read, failed, skipped, status }], newest first
    rows: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
    error: { type: Boolean, default: false },
    timezone: { type: String, default: 'Europe/Bratislava' },
});
const emit = defineEmits(['retry']);

const { t, locale } = useI18n();

const SCOPES = ['no_proposal', 'last_failed', 'all'];
const STATUSES = ['active', 'paused', 'completed', 'cancelled'];
const num = (v) => (Number.isFinite(Number(v)) ? Math.max(0, Math.trunc(Number(v))) : 0);

const columns = computed(() => [
    { key: 'startedAt', label: t('settings.ai_bulk.col_started') },
    { key: 'scope', label: t('settings.ai_bulk.col_scope') },
    { key: 'dailyCap', label: t('settings.ai_bulk.col_cap'), numeric: true },
    { key: 'wouldRead', label: t('settings.ai_bulk.col_would_read'), numeric: true },
    { key: 'read', label: t('settings.ai_bulk.col_read'), numeric: true },
    { key: 'failed', label: t('settings.ai_bulk.col_failed'), numeric: true },
    { key: 'skipped', label: t('settings.ai_bulk.col_skipped'), numeric: true },
    { key: 'status', label: t('settings.ai_bulk.col_status') },
]);

function started(iso) {
    const date = iso ? new Date(iso) : null;
    if (!date || Number.isNaN(date.getTime())) return { iso: '', text: '' };
    return { iso: date.toISOString(), text: new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short', timeZone: props.timezone }).format(date) };
}

const shown = computed(() => props.rows.slice(0, 10).map((row, i) => ({
    id: String(i),
    started: started(row?.startedAt),
    scope: SCOPES.includes(row?.scope) ? t(`settings.ai_bulk.scope_${row.scope}`) : '',
    dailyCap: num(row?.dailyCap),
    wouldRead: num(row?.wouldRead),
    read: num(row?.read),
    failed: num(row?.failed),
    skipped: num(row?.skipped),
    status: t(`settings.ai_bulk.tag_${STATUSES.includes(row?.status) ? (row.status === 'active' ? 'running' : row.status) : 'running'}`),
})));
</script>

<template>
    <div class="bulk-history" :aria-busy="loading ? 'true' : undefined">
        <h3 class="bulk-history__heading">{{ t('settings.ai_bulk.history_heading') }}</h3>

        <UiTable v-if="loading" :caption="t('settings.ai_bulk.history_heading')" caption-hidden :columns="columns" :rows="[]" loading />
        <UiErrorState v-else-if="error" :heading-level="4" @retry="emit('retry')">
            <template #action>
                <UiButton @click="emit('retry')">{{ t('settings.ai_bulk.try_again') }}</UiButton>
            </template>
        </UiErrorState>
        <UiEmptyState
            v-else-if="!shown.length"
            :heading-level="4"
            :icon="ListChecks"
            :title="t('settings.ai_bulk.history_empty_heading')"
            :body="t('settings.ai_bulk.history_empty_body')"
        />
        <template v-else>
            <UiTable :caption="t('settings.ai_bulk.history_heading')" caption-hidden :columns="columns" :rows="shown">
                <template #cell-startedAt="{ row }"><time :datetime="row.started.iso">{{ row.started.text }}</time></template>
                <template #cell-scope="{ row }">{{ row.scope }}</template>
                <template #cell-status="{ row }">{{ row.status }}</template>
            </UiTable>
            <p class="bulk-history__note">{{ t('settings.ai_bulk.history_note') }}</p>
        </template>
    </div>
</template>

<style scoped>
.bulk-history { display: flex; flex-direction: column; gap: var(--space-3); }
.bulk-history__heading { margin: 0; font-size: var(--text-lg); font-weight: 600; }
.bulk-history__note { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.bulk-history :deep(td), .bulk-history :deep(th) { overflow-wrap: anywhere; white-space: normal; }
</style>
