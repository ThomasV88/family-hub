<script setup>
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiAvatar from '../Components/ui/UiAvatar.vue';
import UiChip from '../Components/ui/UiChip.vue';

const props = defineProps({
    // [{ id, name, is_viewer }], the viewer first (the server orders them).
    members: { type: Array, default: () => [] },
    selectedId: { type: Number, default: null },
    // Lets the page ask about unsaved panel edits before the list changes.
    guard: { type: Function, default: (next) => next() },
});
const { t } = useI18n();

function choose(member) {
    if (member.id === props.selectedId) return;
    props.guard(() => router.get('/wishlist', { member: member.id }, { preserveState: false, replace: true }));
}
</script>

<template>
    <div class="person-chips" role="group" :aria-label="t('wishlist.chips.label')">
        <UiChip v-for="(member, index) in members" :key="member.id" :model-value="member.id === selectedId" @update:model-value="choose(member)">
            <UiAvatar :name="member.name" :tint="(index % 6) + 1" :size="32" decorative class="person-chips__avatar" />
            {{ member.is_viewer ? t('wishlist.chips.you', { name: member.name }) : member.name }}
        </UiChip>
    </div>
</template>

<style scoped>
.person-chips { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.person-chips :deep(.ui-chip) { gap: var(--space-2); }
.person-chips__avatar { flex: none; }
</style>
