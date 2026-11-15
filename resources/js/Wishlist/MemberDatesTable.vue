<script setup>
import { computed } from 'vue';
import { Pencil } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../Components/ui/UiButton.vue';
import UiTable from '../Components/ui/UiTable.vue';
import { formatDayMonth } from './occasionIcons.js';

// Birth date shows day and month only; the year never reaches this component for other members.
const props = defineProps({
    members: { type: Array, default: () => [] },
});
const emit = defineEmits(['edit']);
const { t, locale } = useI18n();

const columns = computed(() => [
    { key: 'name', label: t('occasions.dates.member') },
    { key: 'birth', label: t('occasions.dates.birth') },
    { key: 'nameday', label: t('occasions.dates.nameday') },
    { key: 'actions', label: t('occasions.dates.edit'), align: 'end' },
]);
const birth = (m) => (m.birth ? formatDayMonth(m.birth.month, m.birth.day, locale.value) : null);
const nameday = (m) => (m.nameday ? `${m.nameday.name}, ${formatDayMonth(m.nameday.month, m.nameday.day, locale.value)}` : null);
</script>

<template>
    <section class="dates">
        <h2 class="dates__heading">{{ t('occasions.dates.title') }}</h2>
        <UiTable :caption="t('occasions.dates.title')" caption-hidden :columns="columns" :rows="members" data-testid="dates-table">
            <template #cell-name="{ row }">{{ row.name }}</template>
            <template #cell-birth="{ row }">
                <span v-if="birth(row)">{{ birth(row) }}</span>
                <span v-else class="dates__muted">{{ t('occasions.dates.not_set') }}</span>
            </template>
            <template #cell-nameday="{ row }">
                <span v-if="nameday(row)">{{ nameday(row) }}</span>
                <span v-else class="dates__muted">{{ t('occasions.dates.not_set') }}</span>
            </template>
            <template #cell-actions="{ row }">
                <UiButton v-if="row.can_edit_dates" variant="ghost" :icon="Pencil" :aria-label="`${row.birth ? t('occasions.dates.edit') : t('occasions.dates.add_birth')}: ${row.name}`" @click="emit('edit', row.id)">
                    {{ row.birth ? t('occasions.dates.edit') : t('occasions.dates.add_birth') }}
                </UiButton>
            </template>
        </UiTable>
    </section>
</template>

<style scoped>
.dates { display: flex; flex-direction: column; gap: var(--space-3); }
.dates__heading { margin: 0; font-size: var(--text-lg); font-weight: 600; line-height: 1.3; }
.dates__muted { color: var(--color-text-muted); }
</style>
