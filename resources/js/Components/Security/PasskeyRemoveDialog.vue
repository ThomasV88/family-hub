<script setup>
import { nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiAlert from '../ui/UiAlert.vue';
import UiButton from '../ui/UiButton.vue';
import UiDialog from '../ui/UiDialog.vue';
import { useToast } from '../ui/useToast.js';
import { securityFetch } from '../../Security/securityFetch.js';
import ReauthPrompt from './ReauthPrompt.vue';

// Remove a passkey. A stale security window (423) shows the confirm form in place and retries after confirming.
const props = defineProps({
    open: { type: Boolean, default: false },
    passkey: { type: Object, default: null },
    methods: { type: Object, required: true },
    isAdmin: { type: Boolean, default: false },
    // The requirement is on and this passkey is the member's only second step.
    lastFactor: { type: Boolean, default: false },
});
const emit = defineEmits(['update:open', 'removed', 'confirmed']);
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

async function remove() {
    if (busy.value || !props.passkey) return;
    busy.value = true;
    failed.value = false;
    const id = props.passkey.id;
    const result = await securityFetch(`/settings/security/passkeys/${encodeURIComponent(id)}`, { method: 'DELETE' });
    busy.value = false;
    if (result.ok) {
        toast.show(t('passkey.removed_toast'));
        emit('update:open', false);
        return emit('removed', id);
    }
    if (result.error === 'confirm_required') {
        reauth.value = true;
        await nextTick();
        body.value?.querySelector('.reauth-form input, .reauth-form button, [data-testid="sign-in-again"]')?.focus();
        return;
    }
    failed.value = true;
}

function confirmed() {
    emit('confirmed');
    remove();
}

function close() {
    emit('update:open', false);
}
</script>

<template>
    <UiDialog :open="open" :title="t('passkey.remove_title')" :initial-focus="() => safe" @update:open="(v) => (v ? emit('update:open', true) : close())">
        <div ref="body" data-testid="remove-dialog">
            <p class="remove__text">{{ t('passkey.remove_body', { name: passkey?.name ?? '' }) }}</p>
            <p v-if="lastFactor" class="remove__text" data-testid="last-factor">{{ t('security.last_factor_warning') }}</p>
            <UiAlert v-if="failed" variant="danger" live class="remove__alert">{{ t('error.generic.body') }}</UiAlert>
            <template v-if="reauth">
                <p class="remove__text">{{ t('security.confirm_first') }}</p>
                <ReauthPrompt :methods="methods" :is-admin="isAdmin" @confirmed="confirmed" />
            </template>
        </div>
        <template #footer>
            <UiButton ref="safe" variant="secondary" data-testid="keep" @click="close">{{ t('passkey.remove_keep') }}</UiButton>
            <UiButton v-if="!reauth" variant="danger" :loading="busy" data-testid="confirm-remove" @click="remove">{{ t('passkey.remove_confirm') }}</UiButton>
        </template>
    </UiDialog>
</template>

<style scoped>
.remove__text { margin: 0 0 var(--space-3); }
.remove__alert { margin-bottom: var(--space-3); }
</style>
