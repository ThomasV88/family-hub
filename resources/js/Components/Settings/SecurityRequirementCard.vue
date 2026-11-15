<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiCard from '../ui/UiCard.vue';
import UiSwitch from '../ui/UiSwitch.vue';
import UiDialog from '../ui/UiDialog.vue';
import UiButton from '../ui/UiButton.vue';
import ReauthForm from '../Security/ReauthForm.vue';
import { useToast } from '../ui/useToast.js';
import { securityFetch } from '../../Security/securityFetch.js';

// Requirement switch (UI-SPEC Screen 9 + amendment A3). Saves immediately when the admin's security window is
// fresh; otherwise the switch reverts and "Confirm it's you" opens in place, then the change is retried.
const props = defineProps({
    securityPolicy: { type: Object, required: true },
    confirmFresh: { type: Boolean, default: false },
    methods: { type: Object, default: () => ({}) },
});
const { t } = useI18n();
const toast = useToast();

const saved = computed(() => !!props.securityPolicy.required);
const shown = ref(saved.value);
const fresh = ref(props.confirmFresh);
const busy = ref(false);
const dialogOpen = ref(false);
const wanted = ref(false);
const usable = computed(() => !!(props.methods.password || props.methods.totp));

watch(saved, (v) => { shown.value = v; });
watch(() => props.confirmFresh, (v) => { fresh.value = v; });

const pendingText = computed(() => (props.securityPolicy.pending > 0
    ? t('members.security.pending', { n: props.securityPolicy.pending }, props.securityPolicy.pending)
    : t('members.security.pending_zero')));

function askConfirm(next) {
    wanted.value = next;
    shown.value = saved.value;
    dialogOpen.value = true;
}

async function send(next) {
    busy.value = true;
    const result = await securityFetch('/settings/security/policy', { method: 'PUT', body: { required: next } });
    busy.value = false;
    if (result.ok) {
        shown.value = next;
        toast.show(t(next ? 'members.security.on_toast' : 'members.security.off_toast'));
        router.reload({ only: ['securityPolicy', 'members', 'confirmFresh'] });
        return;
    }
    if (result.error === 'confirm_required') {
        fresh.value = false;
        return askConfirm(next);
    }
    shown.value = saved.value;
}

async function change(next) {
    if (busy.value) return;
    shown.value = next;
    await nextTick();
    if (!fresh.value) return askConfirm(next);
    await send(next);
}

function confirmed() {
    fresh.value = true;
    dialogOpen.value = false;
    shown.value = wanted.value;
    send(wanted.value);
}

function signInAgain() {
    router.post('/confirm-access/sign-in-again');
}
</script>

<template>
    <UiCard :title="t('members.security.card_title')" class="requirement-card">
        <UiSwitch :model-value="shown" :aria-busy="busy ? 'true' : 'false'" aria-describedby="requirement-help" data-testid="requirement-switch" @update:model-value="change">
            {{ t('members.security.require_label') }}
        </UiSwitch>
        <p id="requirement-help" class="requirement-card__help">{{ t('members.security.require_help') }}</p>
        <p class="requirement-card__pending" data-testid="requirement-pending">{{ pendingText }}</p>

        <UiDialog :open="dialogOpen" :title="t('reauth.title')" @update:open="dialogOpen = $event">
            <p class="requirement-card__body">{{ t('members.security.confirm_first') }}</p>
            <ReauthForm v-if="usable" :methods="methods" mode="dialog" @confirmed="confirmed" />
            <div v-else class="requirement-card__none" data-testid="reauth-no-method">
                <p class="requirement-card__body">{{ t('reauth.no_method') }}</p>
                <UiButton variant="primary" @click="signInAgain">{{ t('reauth.cta.sign_in_again') }}</UiButton>
                <a href="/settings/security">{{ t('reauth.setup_first') }}</a>
            </div>
        </UiDialog>
    </UiCard>
</template>

<style scoped>
.requirement-card__help { margin: var(--space-2) 0 0; color: var(--color-text-muted); font-size: var(--text-sm); overflow-wrap: anywhere; }
.requirement-card__pending { margin: var(--space-3) 0 0; color: var(--color-text-muted); font-size: var(--text-sm); }
.requirement-card__body { margin: 0 0 var(--space-3); }
.requirement-card__none { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-3); }
</style>
