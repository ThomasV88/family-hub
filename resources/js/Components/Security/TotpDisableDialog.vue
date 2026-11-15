<script setup>
import { nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiAlert from '../ui/UiAlert.vue';
import UiButton from '../ui/UiButton.vue';
import UiDialog from '../ui/UiDialog.vue';
import { useToast } from '../ui/useToast.js';
import { securityFetch } from '../../Security/securityFetch.js';
import ReauthPrompt from './ReauthPrompt.vue';

// Danger confirm for turning the authenticator app off. A stale security window (423) re-shows the form in place.
const props = defineProps({
    open: { type: Boolean, default: false },
    methods: { type: Object, required: true },
    isAdmin: { type: Boolean, default: false },
    // The requirement is on and the authenticator is the member's only second step.
    lastFactor: { type: Boolean, default: false },
});
const emit = defineEmits(['update:open', 'done']);
const { t } = useI18n();
const toast = useToast();

const safe = ref(null);
const body = ref(null);
const reauth = ref(false);
const busy = ref(false);
const failed = ref(false);

watch(() => props.open, (open) => {
    if (open) {
        reauth.value = false;
        failed.value = false;
    }
}, { immediate: true });

async function turnOff() {
    if (busy.value) return;
    busy.value = true;
    failed.value = false;
    const result = await securityFetch('/user/two-factor-authentication', { method: 'DELETE' });
    busy.value = false;
    if (result.ok) {
        toast.show(t('security.totp.disabled_toast'));
        emit('update:open', false);
        return emit('done');
    }
    if (result.error === 'confirm_required') {
        reauth.value = true;
        await nextTick();
        body.value?.querySelector('.reauth-form input, [data-testid="sign-in-again"]')?.focus();
        return;
    }
    failed.value = true;
}

function close() {
    emit('update:open', false);
}
</script>

<template>
    <UiDialog :open="open" :title="t('security.totp.disable')" :initial-focus="() => safe" @update:open="(v) => (v ? emit('update:open', true) : close())">
        <div ref="body" data-testid="disable-dialog">
            <p class="disable__text">{{ t('security.totp.disable_body') }}</p>
            <p v-if="lastFactor" class="disable__text" data-testid="last-factor">{{ t('security.last_factor_warning') }}</p>
            <UiAlert v-if="failed" variant="danger" live class="disable__alert">{{ t('error.generic.body') }}</UiAlert>
            <template v-if="reauth">
                <p class="disable__text">{{ t('security.confirm_first') }}</p>
                <ReauthPrompt :methods="methods" :is-admin="isAdmin" @confirmed="turnOff" />
            </template>
        </div>
        <template #footer>
            <UiButton ref="safe" variant="secondary" data-testid="keep" @click="close">{{ t('security.totp.disable_keep') }}</UiButton>
            <UiButton v-if="!reauth" variant="danger" :loading="busy" data-testid="turn-off" @click="turnOff">{{ t('security.totp.disable') }}</UiButton>
        </template>
    </UiDialog>
</template>

<style scoped>
.disable__text { margin: 0 0 var(--space-3); }
.disable__alert { margin-bottom: var(--space-3); }
</style>
