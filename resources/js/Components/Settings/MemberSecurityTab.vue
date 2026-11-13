<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiList from '../ui/UiList.vue';
import UiListRow from '../ui/UiListRow.vue';
import UiTag from '../ui/UiTag.vue';
import UiButton from '../ui/UiButton.vue';
import UiDialog from '../ui/UiDialog.vue';
import ReauthForm from '../Security/ReauthForm.vue';
import { useToast } from '../ui/useToast.js';
import { securityFetch } from '../../Security/securityFetch.js';

// Read-only factor status plus the admin reset. No form state, so the panel's unsaved-changes rule is unaffected.
const props = defineProps({
    member: { type: Object, required: true },
    securityPolicy: { type: Object, default: () => ({ required: false, pending: 0 }) },
    confirmFresh: { type: Boolean, default: false },
    methods: { type: Object, default: () => ({}) },
});
const { t } = useI18n();
const toast = useToast();

const security = computed(() => props.member.security ?? { totp: false, passkeys: 0, required: false, needs: false });
const hasFactor = computed(() => security.value.totp || security.value.passkeys > 0);
const underRequirement = computed(() => props.securityPolicy.required && security.value.required);
const usable = computed(() => !!(props.methods.password || props.methods.totp));

const open = ref(false);
const fresh = ref(props.confirmFresh);
const reauthNeeded = ref(false);
const busy = ref(false);
const safe = ref(null);

function ask() {
    reauthNeeded.value = !fresh.value;
    open.value = true;
}

async function reset() {
    busy.value = true;
    const result = await securityFetch(`/settings/members/${props.member.id}/second-factor`, { method: 'DELETE' });
    busy.value = false;
    if (result.ok) {
        open.value = false;
        toast.show(t('members.security.reset_toast', { name: props.member.name }));
        router.reload({ only: ['members', 'securityPolicy', 'confirmFresh'] });
        return;
    }
    if (result.error === 'confirm_required') {
        fresh.value = false;
        reauthNeeded.value = true;
        return;
    }
    open.value = false;
    router.reload({ only: ['members', 'securityPolicy'] });
}

function confirmed() {
    fresh.value = true;
    reauthNeeded.value = false;
    reset();
}

function signInAgain() {
    router.post('/confirm-access/sign-in-again');
}
</script>

<template>
    <div class="security-tab">
        <UiList :aria-label="t('members.security.tab')">
            <UiListRow>
                {{ t('security.totp.title') }}
                <template #trailing>
                    <UiTag :variant="security.totp ? 'success' : 'neutral'">{{ security.totp ? t('security.tag.on') : t('security.tag.off') }}</UiTag>
                </template>
            </UiListRow>
            <UiListRow>
                {{ t('passkey.title') }}
                <template #trailing>
                    <span data-testid="passkey-count">{{ security.passkeys > 0 ? t('passkey.count', { n: security.passkeys }, security.passkeys) : t('passkey.none') }}</span>
                </template>
            </UiListRow>
            <UiListRow>
                {{ t('members.security.require_label') }}
                <template #trailing>
                    <UiTag :variant="security.required && securityPolicy.required ? 'warn' : 'neutral'">{{ security.required && securityPolicy.required ? t('members.security.required') : t('members.security.not_required') }}</UiTag>
                </template>
            </UiListRow>
        </UiList>

        <div class="security-tab__footer">
            <p v-if="member.isSelf" class="security-tab__hint" data-testid="reset-self">
                <a href="/settings/security">{{ t('members.security.reset_self') }}</a>
            </p>
            <template v-else>
                <UiButton variant="danger" :disabled="!hasFactor" data-testid="reset-button" aria-describedby="reset-hint" @click="ask">{{ t('members.security.reset') }}</UiButton>
                <p v-if="!hasFactor" id="reset-hint" class="security-tab__hint">{{ t('members.security.reset_none') }}</p>
            </template>
        </div>

        <UiDialog :open="open" :title="t('members.security.reset_title')" :initial-focus="() => safe" @update:open="open = $event">
            <p class="security-tab__body">{{ t(underRequirement ? 'members.security.reset_body_required' : 'members.security.reset_body', { name: member.name }) }}</p>
            <template v-if="reauthNeeded">
                <ReauthForm v-if="usable" :methods="methods" mode="dialog" @confirmed="confirmed" />
                <div v-else class="security-tab__none" data-testid="reauth-no-method">
                    <p class="security-tab__body">{{ t('reauth.no_method') }}</p>
                    <UiButton variant="primary" @click="signInAgain">{{ t('reauth.cta.sign_in_again') }}</UiButton>
                    <a href="/settings/security">{{ t('reauth.setup_first') }}</a>
                </div>
            </template>
            <template #footer>
                <UiButton ref="safe" variant="secondary" @click="open = false">{{ t('members.security.reset_keep') }}</UiButton>
                <UiButton variant="danger" :loading="busy" :disabled="reauthNeeded" data-testid="reset-confirm" @click="reset">{{ t('members.security.reset') }}</UiButton>
            </template>
        </UiDialog>
    </div>
</template>

<style scoped>
.security-tab__footer { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-2); margin-top: var(--space-4); }
.security-tab__hint { margin: 0; color: var(--color-text-muted); font-size: var(--text-sm); }
.security-tab__body { margin: 0 0 var(--space-3); overflow-wrap: anywhere; }
.security-tab__none { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-3); }
</style>
