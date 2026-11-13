<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Ban, Eye, Pencil } from '@lucide/vue';
import UiTable from '../ui/UiTable.vue';
import UiTag from '../ui/UiTag.vue';
import UiButton from '../ui/UiButton.vue';
import { MODULE_KEYS } from '../../Settings/presets.js';

defineProps({ members: { type: Array, default: () => [] } });
const emit = defineEmits(['open']);
const { t } = useI18n();

const ICONS = { none: Ban, view: Eye, edit: Pencil };
const variant = (level) => (level === 'edit' ? 'success' : 'neutral');

const columns = computed(() => [
    { key: 'member', label: t('permissions.members_tab') },
    ...MODULE_KEYS.map((key) => ({ key, label: t(`permissions.module.${key}`) })),
    { key: 'settings', label: t('permissions.module.settings') },
]);
</script>

<template>
    <div class="matrix">
        <UiTable :caption="t('permissions.matrix_caption')" caption-hidden :columns="columns" :rows="members">
            <template #cell-member="{ row }">
                <UiButton variant="ghost" size="sm" class="matrix__open" :aria-label="t('permissions.matrix_row_aria', { name: row.name })" @click="emit('open', row.id)">{{ row.name }}</UiButton>
            </template>
            <template v-for="key in MODULE_KEYS" :key="key" #[`cell-${key}`]="{ row }">
                <UiTag :variant="variant(row.access[key])" :icon="ICONS[row.access[key]]">{{ t(`permissions.level.${row.access[key]}`) }}</UiTag>
            </template>
            <template #cell-settings="{ row }">
                <UiTag v-if="row.isAdmin" variant="success">{{ t('settings.role.admin') }}</UiTag>
                <UiTag v-else>{{ t('permissions.settings_none') }}</UiTag>
            </template>
        </UiTable>
        <p class="matrix__legend">{{ t('permissions.legend') }}</p>
    </div>
</template>

<style scoped>
.matrix__legend { margin: var(--space-3) 0 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.matrix__open { max-width: 100%; overflow-wrap: anywhere; text-align: start; }
</style>
