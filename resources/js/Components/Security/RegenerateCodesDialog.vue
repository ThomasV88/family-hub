<script setup>
import { nextTick, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiAlert from '../ui/UiAlert.vue';
import UiButton from '../ui/UiButton.vue';
import UiDialog from '../ui/UiDialog.vue';
import { securityFetch } from '../../Security/securityFetch.js';
import ReauthPrompt from './ReauthPrompt.vue';
import RecoveryCodesPanel from './RecoveryCodesPanel.vue';

// Create new recovery codes. Codes live only in local state and are wiped on finish; a stale security window
// (423) re-shows the form in place and retries afterwards.
const props = defineProps({
    open: { type: Boolean, default: false },
    methods: { type: Object, required: true },
    isAdmin: { type: Boolean, default: false },
});
const emit = defineEmits(['update:open', 'done']);
const { t } = useI18n();

const safe = ref(null);
const body = ref(null);
const reauth = ref(false);
const busy = ref(false);
const failed = ref(false);
const codes = ref([]);

function wipe() {
    codes.value = [];
    reauth.value = false;
    failed.value = false;
}
watch(() => props.open, () => wipe(), { immediate: true });

async function create() {
    if (busy.value) return;
    busy.value = true;
    failed.value = false;
    const result = await securityFetch('/user/two-factor-recovery-codes', { method: 'POST' });
    busy.value = false;
    if (result.ok) {
        reauth.value = false;
        codes.value = result.data.recoveryCodes ?? [];
        return;
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
    if (codes.value.length) return;
    emit('update:open', false);
}

function finish() {
    wipe();
    router.clearHistory?.();
    emit('update:open', false);
    emit('done');
}
</script>

<template>
    <UiDialog
        :open="open"
        :title="t('twofactor.recovery.regen_title')"
        :dismissable="!codes.length"
        :initial-focus="() => safe"
        @update:open="(v) => (v ? emit('update:open', true) : close())"
    >
        <div ref="body" data-testid="regen-dialog">
            <RecoveryCodesPanel v-if="codes.length" :codes="codes" :finish-label="t('twofactor.recovery.finish')" @finish="finish" />
            <template v-else>
                <p class="regen__text">{{ t('twofactor.recovery.regen_body') }}</p>
                <UiAlert v-if="failed" variant="danger" live class="regen__alert">{{ t('error.generic.body') }}</UiAlert>
                <template v-if="reauth">
                    <p class="regen__text">{{ t('security.confirm_first') }}</p>
                    <ReauthPrompt :methods="methods" :is-admin="isAdmin" @confirmed="create" />
                </template>
            </template>
        </div>
        <template v-if="!codes.length" #footer>
            <UiButton ref="safe" variant="secondary" data-testid="keep" @click="close">{{ t('twofactor.recovery.regen_keep') }}</UiButton>
            <UiButton v-if="!reauth" variant="danger" :loading="busy" data-testid="create" @click="create">{{ t('twofactor.cta.create_codes') }}</UiButton>
        </template>
    </UiDialog>
</template>

<style scoped>
.regen__text { margin: 0 0 var(--space-3); }
.regen__alert { margin-bottom: var(--space-3); }
</style>
