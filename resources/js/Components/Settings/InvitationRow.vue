<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { RotateCw, Copy, Ban } from '@lucide/vue';
import UiListRow from '../ui/UiListRow.vue';
import UiTag from '../ui/UiTag.vue';
import UiButton from '../ui/UiButton.vue';

const props = defineProps({
    invitation: { type: Object, required: true },
});
const emit = defineEmits(['resend', 'copy', 'revoke']);
const { t, locale } = useI18n();

const LANGUAGES = { sk: 'Slovenčina', pl: 'Polski', en: 'English' };
const date = computed(() => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(new Date(props.invitation.expiresAt)));
const status = computed(() => {
    switch (props.invitation.state) {
        case 'expired': return { variant: 'danger', text: t('settings.invites.expired') };
        case 'revoked': return { variant: 'danger', text: t('settings.invites.revoked') };
        default: return { variant: 'warn', text: t('settings.invites.pending', { date: date.value }) };
    }
});
const actionable = computed(() => ['valid', 'expired'].includes(props.invitation.state));
</script>

<template>
    <UiListRow>
        <span class="invitation-row__email">{{ invitation.email }}</span>
        <template #meta>
            <span class="invitation-row__tags">
                <UiTag>{{ t(`permissions.preset.${invitation.preset}`) }}</UiTag>
                <UiTag>{{ t('settings.invites.language', { language: LANGUAGES[invitation.locale] ?? invitation.locale }) }}</UiTag>
                <UiTag :variant="status.variant">{{ status.text }}</UiTag>
                <UiTag v-if="invitation.mailFailed" variant="warn">{{ t('settings.invites.mail_failed') }}</UiTag>
            </span>
        </template>
        <template v-if="actionable" #trailing>
            <span class="invitation-row__actions">
                <UiButton size="sm" variant="ghost" icon-only :icon="RotateCw" :label="t('settings.invites.resend_aria', { email: invitation.email })" :title="t('settings.invites.resend')" @click="emit('resend', invitation)" />
                <UiButton size="sm" variant="ghost" icon-only :icon="Copy" :label="t('settings.invites.copy_aria', { email: invitation.email })" :title="t('settings.invites.copy')" @click="emit('copy', invitation)" />
                <UiButton size="sm" variant="ghost" icon-only :icon="Ban" :label="t('settings.invites.revoke_aria', { email: invitation.email })" :title="t('settings.invites.revoke')" @click="emit('revoke', invitation)" />
            </span>
        </template>
    </UiListRow>
</template>

<style scoped>
.invitation-row__email { overflow-wrap: anywhere; }
.invitation-row__tags { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.invitation-row__actions { display: inline-flex; gap: var(--space-1); }
</style>
