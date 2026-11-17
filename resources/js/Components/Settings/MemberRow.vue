<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import UiListRow from '../ui/UiListRow.vue';
import UiAvatar from '../ui/UiAvatar.vue';
import UiTag from '../ui/UiTag.vue';
import UiButton from '../ui/UiButton.vue';
import { ShieldCheck, ShieldOff, TriangleAlert, UserLock } from '@lucide/vue';

const props = defineProps({
    member: { type: Object, required: true },
    selected: { type: Boolean, default: false },
});
const emit = defineEmits(['manage']);
const { t } = useI18n();

const factorOn = computed(() => !!(props.member.security?.totp || props.member.security?.passkeys > 0));
const managedBy = computed(() => (props.member.managerNames?.length
    ? t('settings.members.child.managed_by', { names: props.member.managerNames.join(', ') })
    : t('settings.members.child.managed_by_nobody')));
const tint = ((props.member.id ?? 0) % 6) + 1;
</script>

<template>
    <UiListRow :selected="selected">
        <template #leading><UiAvatar :name="member.name" :tint="tint" :user-id="member.id" decorative /></template>
        <span class="member-row__name">{{ member.name }}</span>
        <template #meta>
            <span v-if="member.isChild" class="member-row__email member-row__email--muted">{{ managedBy }}</span>
            <span v-else class="member-row__email">{{ member.email }}</span>
            <span v-if="member.isChild" class="member-row__tags">
                <UiTag>{{ t('settings.members.child.tag_child') }}</UiTag>
                <UiTag variant="warn" :icon="UserLock">{{ t('settings.members.child.tag_no_signin') }}</UiTag>
                <UiTag v-if="member.invitation" variant="success">{{ t('settings.members.convert.tag_invited') }}</UiTag>
            </span>
            <span v-else class="member-row__tags">
                <UiTag>{{ member.isAdmin ? t('settings.role.admin') : t('settings.role.member') }}</UiTag>
                <UiTag v-if="member.isSelf">{{ t('settings.members.you') }}</UiTag>
                <UiTag variant="success">{{ t('settings.status.active') }}</UiTag>
                <UiTag v-if="member.security?.needs" variant="warn" :icon="TriangleAlert">{{ t('security.tag.needs') }}</UiTag>
                <UiTag v-else-if="member.security && factorOn" variant="success" :icon="ShieldCheck">{{ t('members.security.tag_on') }}</UiTag>
                <UiTag v-else-if="member.security" :icon="ShieldOff">{{ t('members.security.tag_off') }}</UiTag>
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
.member-row__email--muted { color: var(--color-text-muted); }
.member-row__tags { display: flex; flex-wrap: wrap; gap: var(--space-2); margin-block-start: var(--space-1); }
</style>
