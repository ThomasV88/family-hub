<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import UiTable from '../ui/UiTable.vue';
import UiTag from '../ui/UiTag.vue';
import UiButton from '../ui/UiButton.vue';

const props = defineProps({
    entries: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({ page: 1, lastPage: 1, total: 0 }) },
});
const emit = defineEmits(['page']);

const { t, locale } = useI18n();
const root = ref(null);

const columns = computed(() => [
    { key: 'when', label: t('audit.col.when') },
    { key: 'member', label: t('audit.col.member') },
    { key: 'event', label: t('audit.col.event') },
    { key: 'details', label: t('audit.col.details') },
    { key: 'ip', label: t('audit.col.ip') },
]);

const formatter = computed(() => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }));
const when = (iso) => formatter.value.format(new Date(iso));

const DANGER = ['login_failed', 'lockout'];
const variant = (row) => (DANGER.includes(row.event) ? 'danger' : 'neutral');
const eventLabel = (row) => (row.event === 'access_denied' ? t('audit.event.access_denied') : t(`audit.event.${row.group}`));

const moduleName = (key) => (key ? t(`permissions.module.${key}`) : '');
const levelName = (key) => (key ? t(`permissions.level.${key}`) : '');

function details(row) {
    const meta = row.meta ?? {};
    const module = moduleName(row.module);
    const name = row.targetLabel ?? '';
    switch (row.event) {
        case 'health_read':
        case 'finance_read':
            return t('audit.detail.read', { module });
        case 'health_write':
        case 'finance_write':
            return t('audit.detail.write', { module });
        case 'permission_changed':
            return t('audit.detail.permission', { module, name, from: levelName(meta.from), to: levelName(meta.to) });
        case 'login_success':
            return t('audit.detail.login');
        case 'access_denied':
            return t('audit.detail.access_denied', { module });
        case 'invite_created':
            return t('audit.detail.invite_created', { email: meta.email ?? '', preset: meta.preset ?? '' });
        case 'invite_resent':
        case 'invite_revoked':
            return t(`audit.detail.${row.event}`, { email: meta.email ?? '' });
        case 'finance_visibility_changed':
            return t(`audit.detail.finance_visibility_${meta.change === 'removed' ? 'removed' : 'added'}`, { name });
        case 'session_revoked':
            return t('audit.detail.session_revoked', { name, device: meta.device ?? '' });
        case 'session_revoked_all':
            return t('audit.detail.session_revoked_all', { name });
        default:
            return t(`audit.detail.${row.event}`);
    }
}

function focusCaption() {
    const caption = root.value?.querySelector('caption');
    if (caption) {
        caption.setAttribute('tabindex', '-1');
        caption.focus();
    }
}
defineExpose({ focusCaption });
</script>

<template>
    <div ref="root" class="audit-table">
        <UiTable :caption="t('audit.caption')" caption-hidden :columns="columns" :rows="entries">
            <template #cell-when="{ row }">
                <time :datetime="row.occurredAt">{{ when(row.occurredAt) }}</time>
            </template>
            <template #cell-member="{ row }">{{ row.actorLabel ?? '-' }}</template>
            <template #cell-event="{ row }"><UiTag :variant="variant(row)">{{ eventLabel(row) }}</UiTag></template>
            <template #cell-details="{ row }">{{ details(row) }}</template>
            <template #cell-ip="{ row }"><span class="audit-table__ip">{{ row.ip ?? '-' }}</span></template>
        </UiTable>
        <nav v-if="pagination.lastPage > 1" class="audit-table__pager" :aria-label="t('audit.caption')">
            <UiButton :disabled="pagination.page <= 1" @click="emit('page', pagination.page - 1)">{{ t('audit.prev') }}</UiButton>
            <span class="audit-table__page">{{ t('audit.page', { n: pagination.page, total: pagination.lastPage }) }}</span>
            <UiButton :disabled="pagination.page >= pagination.lastPage" @click="emit('page', pagination.page + 1)">{{ t('audit.next') }}</UiButton>
        </nav>
    </div>
</template>

<style scoped>
.audit-table__ip { font-family: var(--font-mono, ui-monospace, monospace); font-size: var(--text-sm); }
.audit-table__pager { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); margin-top: var(--space-4); }
.audit-table__page { color: var(--color-text-muted); font-size: var(--text-sm); }
</style>
