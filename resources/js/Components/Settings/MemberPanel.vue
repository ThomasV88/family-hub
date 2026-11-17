<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import UiDetailPanel from '../ui/UiDetailPanel.vue';
import UiTabs from '../ui/UiTabs.vue';
import UiConfirmDialog from '../ui/UiConfirmDialog.vue';
import PermissionsTab from './PermissionsTab.vue';
import SessionsTab from './SessionsTab.vue';
import MemberSecurityTab from './MemberSecurityTab.vue';
import ChildProfileTab from './ChildProfileTab.vue';
import UiTag from '../ui/UiTag.vue';
import UiAvatar from '../ui/UiAvatar.vue';
import WishlistManagersTab from './WishlistManagersTab.vue';

const props = defineProps({
    member: { type: Object, required: true },
    presets: { type: Object, required: true },
    // All members, for the wishlist managers tab.
    members: { type: Array, default: () => [] },
    // Members who may manage a list (sign in, Wishlist Edit); null offers everyone (older callers).
    eligibleParents: { type: Array, default: null },
    // A photo upload that failed right after a child was created.
    photoError: { type: String, default: '' },
    securityPolicy: { type: Object, default: () => ({ required: false, pending: 0 }) },
    confirmFresh: { type: Boolean, default: false },
    methods: { type: Object, default: () => ({}) },
    initialTab: { type: String, default: 'permissions' },
});
const emit = defineEmits(['close']);
const { t } = useI18n();

const isChild = computed(() => props.member.isChild === true);
// A child opens on its Profile tab (the first one) unless a specific other tab was asked for.
const tab = ref(isChild.value && props.initialTab === 'permissions' ? 'profile' : props.initialTab);
const permissionsDirty = ref(false);
const wishlistDirty = ref(false);
const profileDirty = ref(false);
const dirty = computed(() => permissionsDirty.value || wishlistDirty.value || profileDirty.value);
const confirming = ref(false);

// One entry per tab, plus one panel slot below.
const tabs = computed(() => (isChild.value ? [
    { key: 'profile', label: t('settings.members.child.tab_profile') },
    { key: 'permissions', label: t('permissions.tab.permissions') },
    { key: 'wishlist', label: t('settings.members.wishlist_managers.tab') },
] : [
    { key: 'permissions', label: t('permissions.tab.permissions') },
    { key: 'wishlist', label: t('settings.members.wishlist_managers.tab') },
    { key: 'sessions', label: t('permissions.tab.sessions') },
    { key: 'security', label: t('members.security.tab') },
]));

function requestClose() {
    if (dirty.value) {
        confirming.value = true;
        return;
    }
    emit('close');
}
</script>

<template>
    <UiDetailPanel :open="true" :title="member.name" @update:open="(open) => !open && requestClose()">
        <div v-if="isChild" class="member-panel__head">
            <UiAvatar :name="member.name" :user-id="member.id" decorative />
            <span class="member-panel__tags">
                <UiTag>{{ t('settings.members.child.tag_child') }}</UiTag>
                <UiTag variant="warn">{{ t('settings.members.child.tag_no_signin') }}</UiTag>
            </span>
        </div>
        <p v-else class="member-panel__email">{{ member.email }}</p>
        <UiTabs v-model="tab" :tabs="tabs" :label="member.name">
            <template v-if="isChild" #panel-profile>
                <ChildProfileTab :member="member" :photo-error="photoError" @dirty="profileDirty = $event" />
            </template>
            <template #panel-permissions>
                <PermissionsTab :member="member" :presets="presets" @dirty="permissionsDirty = $event" />
            </template>
            <template #panel-wishlist>
                <WishlistManagersTab :member="member" :members="members" :candidates="eligibleParents" @dirty="wishlistDirty = $event" />
            </template>
            <template v-if="!isChild" #panel-sessions>
                <SessionsTab :member="member" />
            </template>
            <template v-if="!isChild" #panel-security>
                <MemberSecurityTab :member="member" :security-policy="securityPolicy" :confirm-fresh="confirmFresh" :methods="methods" />
            </template>
        </UiTabs>
        <UiConfirmDialog
            v-model:open="confirming"
            :title="t('permissions.discard_title')"
            :body="t('permissions.discard_body', { name: member.name })"
            :confirm-label="t('permissions.discard_confirm')"
            :cancel-label="t('permissions.discard_keep')"
            @confirm="emit('close')"
        />
    </UiDetailPanel>
</template>

<style scoped>
.member-panel__head { display: flex; align-items: center; gap: var(--space-3); margin: 0 0 var(--space-3); }
.member-panel__tags { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.member-panel__email { margin: 0 0 var(--space-3); color: var(--color-text-muted); overflow-wrap: anywhere; }
</style>
