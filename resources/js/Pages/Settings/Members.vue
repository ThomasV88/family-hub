<script setup>
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { UserPlus } from '@lucide/vue';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import UiList from '../../Components/ui/UiList.vue';
import UiTabs from '../../Components/ui/UiTabs.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import UiConfirmDialog from '../../Components/ui/UiConfirmDialog.vue';
import { useToast } from '../../Components/ui/useToast.js';
import InvitationRow from '../../Components/Settings/InvitationRow.vue';
import InviteDialog from '../../Components/Settings/InviteDialog.vue';
import MemberRow from '../../Components/Settings/MemberRow.vue';
import MemberPanel from '../../Components/Settings/MemberPanel.vue';
import SecurityRequirementCard from '../../Components/Settings/SecurityRequirementCard.vue';
import HealthAccessCard from '../../Components/Settings/HealthAccessCard.vue';
import PersonAccessPanel from '../../Components/Settings/PersonAccessPanel.vue';
import PermissionMatrix from '../../Components/Settings/PermissionMatrix.vue';

const props = defineProps({
    sections: { type: Array, default: () => [] },
    members: { type: Array, default: () => [] },
    presets: { type: Object, default: () => ({}) },
    openMember: { type: Number, default: null },
    invitations: { type: Array, default: () => [] },
    healthPeople: { type: Array, default: () => [] },
    securityPolicy: { type: Object, default: () => ({ required: false, pending: 0 }) },
    confirmFresh: { type: Boolean, default: false },
    methods: { type: Object, default: () => ({}) },
});

const { t } = useI18n();
const view = ref('members');
const openId = ref(props.openMember);
const openTab = ref('permissions');
const openPersonId = ref(null);
const openPerson = computed(() => props.healthPeople.find((p) => p.id === openPersonId.value) ?? null);
const toast = useToast();

// The invite link arrives only as one-shot flash data and is dropped from local state when the dialog closes.
const inviteOpen = ref(false);
const inviteResult = ref(null);
const revoking = ref(null);

function readInvite() {
    return usePage().props.flash?.data?.invite ?? null;
}
function closeInvite(open) {
    inviteOpen.value = open;
    if (!open) inviteResult.value = null;
}
function openInvite() {
    inviteResult.value = null;
    inviteOpen.value = true;
}
function rotate(invitation, kind) {
    router.post(`/settings/invitations/${invitation.id}/${kind}`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            const invite = readInvite();
            if (!invite) return;
            inviteResult.value = invite;
            inviteOpen.value = true;
            if (kind === 'resend') toast.show(t('settings.invites.resent_toast', { email: invitation.email }));
        },
    });
}
function confirmRevoke() {
    const invitation = revoking.value;
    if (!invitation) return;
    router.delete(`/settings/invitations/${invitation.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.show(t('settings.invites.revoked_toast')),
    });
}

const opened = computed(() => props.members.find((m) => m.id === openId.value) ?? null);
const viewTabs = computed(() => [
    { key: 'members', label: t('permissions.members_tab') },
    { key: 'matrix', label: t('permissions.matrix_tab') },
]);

function open(id, tab = 'permissions') {
    openPersonId.value = null;
    openTab.value = tab;
    openId.value = id;
}
</script>

<template>
    <Head :title="t('settings.section.members')" />
    <SettingsLayout :sections="sections" current="members" title="settings.section.members">
        <div class="members__header">
            <UiButton variant="primary" :icon="UserPlus" @click="openInvite">{{ t('settings.members.invite') }}</UiButton>
        </div>
        <UiTabs v-model="view" :tabs="viewTabs" :label="t('settings.section.members')">
            <template #panel-members>
                <SecurityRequirementCard class="members__card" :security-policy="securityPolicy" :confirm-fresh="confirmFresh" :methods="methods" />
                <UiCard :title="t('settings.members.title')" class="members__card">
                    <UiList>
                        <MemberRow v-for="member in members" :key="member.id" :member="member" :selected="member.id === openId" @manage="open(member.id)" />
                    </UiList>
                </UiCard>
                <HealthAccessCard class="members__card" :people="healthPeople" :selected-id="openPersonId" @manage="openId = null; openPersonId = $event" />
                <UiCard :title="t('settings.invites.title')" class="members__card">
                    <UiEmptyState v-if="!invitations.length" :title="t('settings.invites.empty_title')" :body="t('settings.invites.empty_body')">
                        <template #action>
                            <UiButton variant="primary" :icon="UserPlus" @click="openInvite">{{ t('settings.members.invite') }}</UiButton>
                        </template>
                    </UiEmptyState>
                    <UiList v-else>
                        <InvitationRow
                            v-for="invitation in invitations"
                            :key="invitation.id"
                            :invitation="invitation"
                            @resend="rotate($event, 'resend')"
                            @copy="rotate($event, 'link')"
                            @revoke="revoking = $event"
                        />
                    </UiList>
                </UiCard>
            </template>
            <template #panel-matrix>
                <PermissionMatrix :members="members" @open="open($event)" />
            </template>
        </UiTabs>
        <InviteDialog
            :open="inviteOpen"
            :presets="presets"
            :result="inviteResult"
            @update:open="closeInvite"
            @result="inviteResult = $event"
            @another="inviteResult = null"
        />
        <UiConfirmDialog
            :open="revoking !== null"
            :title="t('settings.invites.revoke_title')"
            :body="t('settings.invites.revoke_body', { email: revoking?.email ?? '' })"
            :confirm-label="t('settings.invites.revoke_confirm')"
            :cancel-label="t('settings.invites.revoke_keep')"
            @update:open="(v) => { if (!v) revoking = null; }"
            @confirm="confirmRevoke"
        />
        <template #panel>
            <PersonAccessPanel v-if="openPerson" :key="openPerson.id" :person="openPerson" :members="members" @close="openPersonId = null" />
            <MemberPanel v-else-if="opened" :key="`${opened.id}-${openTab}`" :member="opened" :presets="presets" :members="members" :security-policy="securityPolicy" :confirm-fresh="confirmFresh" :methods="methods" :initial-tab="openTab" @close="openId = null" />
        </template>
    </SettingsLayout>
</template>

<style scoped>
.members__header { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: var(--space-3); }
.members__card { margin-block-start: var(--space-4); }
</style>
