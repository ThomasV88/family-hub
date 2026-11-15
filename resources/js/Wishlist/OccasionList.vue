<script setup>
import { Plus } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../Components/ui/UiButton.vue';
import UiEmptyState from '../Components/ui/UiEmptyState.vue';
import UiList from '../Components/ui/UiList.vue';
import OccasionRow from './OccasionRow.vue';

defineProps({
    upcoming: { type: Array, default: () => [] },
    canAdd: { type: Boolean, default: false },
});
const emit = defineEmits(['add']);
const { t } = useI18n();
</script>

<template>
    <section class="occasion-list" aria-labelledby="occasions-upcoming">
        <h2 id="occasions-upcoming" class="occasion-list__heading">{{ t('occasions.upcoming') }}</h2>
        <UiEmptyState v-if="upcoming.length === 0" :title="t('occasions.empty.title')" :body="t('occasions.empty.body')" :heading-level="3">
            <template v-if="canAdd" #action>
                <UiButton variant="primary" :icon="Plus" @click="emit('add')">{{ t('occasions.add') }}</UiButton>
            </template>
        </UiEmptyState>
        <UiList v-else data-testid="upcoming-list">
            <OccasionRow v-for="occasion in upcoming" :key="occasion.key" :occasion="occasion" />
        </UiList>
    </section>
</template>

<style scoped>
.occasion-list { display: flex; flex-direction: column; gap: var(--space-3); }
.occasion-list__heading { margin: 0; font-size: var(--text-lg); font-weight: 600; line-height: 1.3; }
</style>
