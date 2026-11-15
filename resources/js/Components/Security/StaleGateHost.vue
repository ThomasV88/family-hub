<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiButton from '../ui/UiButton.vue';
import UiDialog from '../ui/UiDialog.vue';
import { useToast } from '../ui/useToast.js';
import ReauthPrompt from './ReauthPrompt.vue';
import { cancel, confirmed, gate, setHosted, setIdentity, setNotifier } from '../../Security/staleGate.js';

// The one "Confirm it's you" dialog for refused writes (staleGate.js). Mounted once at the app root, so every page,
// form and panel gets it. It never navigates: the page underneath stays mounted with its half-filled form.
const { t } = useI18n();
const page = usePage();
const toast = useToast();

const methods = computed(() => gate.methods ?? { password: false, totp: false, passkey: false });
const isAdmin = computed(() => !!page.props?.auth?.user?.isAdmin);
const usable = computed(() => !!(methods.value.password || methods.value.totp || methods.value.passkey));

onMounted(() => {
    setIdentity(() => page.props?.auth?.user?.id ?? null);
    setNotifier((kind) => toast.show(t(kind === 'expired' ? 'security.confirm_modal.expired' : 'security.confirm_modal.replay_failed')));
    setHosted(true);
});
onBeforeUnmount(() => {
    // Never leave a request waiting on a dialog that no longer exists.
    cancel();
    setHosted(false);
});

// Focus starts on the first thing to answer (password, code or passkey), not on the close button.
const firstControl = () => document.querySelector('[data-testid="stale-gate"] input, [data-testid="stale-gate"] button');

function onOpen(open) {
    if (!open) cancel();
}
</script>

<template>
    <UiDialog :open="gate.open" :title="t('reauth.title')" :initial-focus="firstControl" @update:open="onOpen">
        <div data-testid="stale-gate">
            <p class="stale-gate__body">{{ usable ? t('security.confirm_modal.body') : t('security.confirm_modal.body_no_method') }}</p>
            <ReauthPrompt :methods="methods" :is-admin="isAdmin" @confirmed="confirmed" />
        </div>
        <template #footer>
            <UiButton variant="ghost" data-testid="stale-gate-cancel" @click="cancel">{{ t('security.confirm_modal.cancel') }}</UiButton>
        </template>
    </UiDialog>
</template>

<style scoped>
.stale-gate__body { margin: 0 0 var(--space-4); color: var(--color-text-muted); overflow-wrap: anywhere; }
</style>
