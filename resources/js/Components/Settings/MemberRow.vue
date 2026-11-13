<script setup>
import { useI18n } from 'vue-i18n';
import UiListRow from '../ui/UiListRow.vue';
import UiAvatar from '../ui/UiAvatar.vue';
import UiTag from '../ui/UiTag.vue';
import UiButton from '../ui/UiButton.vue';

const props = defineProps({
    member: { type: Object, required: true },
    selected: { type: Boolean, default: false },
});
const emit = defineEmits(['manage']);
const { t } = useI18n();

const tint = ((props.member.id ?? 0) % 6) + 1;
</script>

<template>
    <UiListRow :selected="selected">
        <template #leading><UiAvatar :name="member.name" :tint="tint" decorative /></template>
        <span class="member-row__name">{{ member.name }}</span>
        <template #meta>
            <span class="member-row__email">{{ member.email }}</span>
            <span class="member-row__tags">
                <UiTag>{{ member.isAdmin ? t('settings.role.admin') : t('settings.role.member') }}</UiTag>
                <UiTag v-if="member.isSelf">{{ t('settings.members.you') }}</UiTag>
                <UiTag variant="success">{{ t('settings.status.active') }}</UiTag>
            </span>
        </template>
        <template #trailing>
            <UiButton size="sm" :aria-label="t('settings.members.manage_aria', { name: member.name })" @click="emit('manage', member.id)">{{ t('settings.members.manage') }}</UiButton>
        </template>
    </UiListRow>
</template>

<style scoped>
.member-row__name { overflow-wrap: anywhere; }
.member-row__email { display: block; overflow-wrap: anywhere; }
.member-row__tags { display: flex; flex-wrap: wrap; gap: var(--space-2); margin-block-start: var(--space-1); }
</style>
