<script setup>
import { router } from '@inertiajs/vue3';
import { Plus, Users } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../Components/ui/UiButton.vue';
import UiChip from '../Components/ui/UiChip.vue';

const props = defineProps({
    // [{ id, name, mode, is_member }]
    groups: { type: Array, default: () => [] },
    selectedId: { type: Number, default: null },
    canCreate: { type: Boolean, default: false },
    // Lets the page ask about unsaved panel edits before the list changes.
    guard: { type: Function, default: (next) => next() },
});
const emit = defineEmits(['create']);
const { t } = useI18n();

function choose(group) {
    if (group.id === props.selectedId) return;
    props.guard(() => router.get('/wishlist', { group: group.id }, { preserveState: false, replace: true }));
}
const create = () => props.guard(() => emit('create'));
</script>

<template>
    <div v-if="groups.length > 0 || canCreate" class="group-chips" role="group" :aria-label="t('wishlist.group.chips_label')">
        <UiChip v-for="group in groups" :key="group.id" :model-value="group.id === selectedId" @update:model-value="choose(group)">
            <Users :size="16" aria-hidden="true" class="group-chips__icon" />
            {{ group.name }}
        </UiChip>
        <UiButton v-if="canCreate" variant="secondary" :icon="Plus" @click="create">{{ t('wishlist.group.new') }}</UiButton>
    </div>
</template>

<style scoped>
.group-chips { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }
.group-chips :deep(.ui-chip) { gap: var(--space-2); }
.group-chips__icon { flex: none; }
</style>
