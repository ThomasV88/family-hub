<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Copy } from '@lucide/vue';
import UiAlert from '../ui/UiAlert.vue';
import UiButton from '../ui/UiButton.vue';
import UiDialog from '../ui/UiDialog.vue';
import { useToast } from '../ui/useToast.js';
import { securityFetch } from '../../Security/securityFetch.js';
import OtpInput from './OtpInput.vue';
import ReauthPrompt from './ReauthPrompt.vue';
import RecoveryCodesPanel from './RecoveryCodesPanel.vue';

// 3-step authenticator setup. The in-dialog ReauthForm comes through ReauthPrompt (form, or no-method copy).
// Steps: confirm (stale security window) -> scan -> verify -> codes.
// The QR, the key, the typed code and the recovery codes live only in this component's local state, rendered
// with v-if, and are wiped on leaving a step, on cancel and on finish.
const props = defineProps({
    open: { type: Boolean, default: false },
    methods: { type: Object, required: true },
    confirmFresh: { type: Boolean, default: false },
    isAdmin: { type: Boolean, default: false },
});
const emit = defineEmits(['update:open', 'finished']);
const { t } = useI18n();
const toast = useToast();

const step = ref('scan'); // confirm | scan | verify | codes
const fresh = ref(props.confirmFresh);
const pending = ref(null); // the call to retry after an in-dialog confirmation: 'enable' | 'confirm'
const reauthInPlace = ref(false);
const loading = ref(false);
const failed = ref(false);
const svg = ref('');
const secretKey = ref('');
const showKey = ref(false);
const code = ref('');
const codeError = ref('');
const throttleMinutes = ref(null);
const recoveryCodes = ref([]);
const body = ref(null);
const otp = ref(null);
let throttleTimer = null;

const stepNumber = computed(() => ({ scan: 1, verify: 2, codes: 3 })[step.value] ?? 1);
const title = computed(() => (step.value === 'confirm' ? t('reauth.title') : t('twofactor.setup.title')));
const groupedKey = computed(() => secretKey.value.replace(/\s+/g, '').replace(/(.{4})/g, '$1 ').trim());

function wipe() {
    svg.value = '';
    secretKey.value = '';
    showKey.value = false;
    code.value = '';
    codeError.value = '';
    recoveryCodes.value = [];
    reauthInPlace.value = false;
    pending.value = null;
    failed.value = false;
    throttleMinutes.value = null;
    clearTimeout(throttleTimer);
}

async function focusFirst() {
    await nextTick();
    const root = body.value;
    const target = step.value === 'confirm' || reauthInPlace.value
        ? root?.querySelector('.reauth-form input, [data-testid="sign-in-again"]')
        : root?.querySelector('[data-step-heading]');
    target?.focus();
}

watch(() => props.open, (open) => {
    if (open) {
        wipe();
        fresh.value = props.confirmFresh;
        if (fresh.value) {
            begin();
        } else {
            step.value = 'confirm';
            pending.value = 'enable';
            focusFirst();
        }
    } else {
        wipe();
    }
}, { immediate: true });

function confirmNeeded(action) {
    fresh.value = false;
    pending.value = action;
    if (action === 'enable') {
        step.value = 'confirm';
    } else {
        reauthInPlace.value = true;
    }
    focusFirst();
}

// Start (or restart) the pending secret and fetch the QR and the key.
async function begin() {
    step.value = 'scan';
    failed.value = false;
    loading.value = true;
    const enabled = await securityFetch('/user/two-factor-authentication', { method: 'POST' });
    if (!enabled.ok && enabled.error === 'confirm_required') {
        loading.value = false;
        return confirmNeeded('enable');
    }
    // 409: a pending secret already exists, so just show it again.
    if (!enabled.ok && enabled.status !== 409) {
        loading.value = false;
        failed.value = true;
        return;
    }
    await loadSecret();
    loading.value = false;
    focusFirst();
}

async function loadSecret() {
    const [qr, key] = await Promise.all([
        securityFetch('/user/two-factor-qr-code'),
        securityFetch('/user/two-factor-secret-key'),
    ]);
    if (!qr.ok || !key.ok) {
        failed.value = true;
        return;
    }
    svg.value = qr.data.svg ?? '';
    secretKey.value = key.data.secretKey ?? '';
}

function toVerify() {
    svg.value = '';
    secretKey.value = '';
    showKey.value = false;
    step.value = 'verify';
    codeError.value = '';
    nextTick(() => otp.value?.focus());
}

async function backToScan() {
    step.value = 'scan';
    code.value = '';
    codeError.value = '';
    reauthInPlace.value = false;
    loading.value = true;
    await loadSecret();
    loading.value = false;
    focusFirst();
}

function startThrottle(minutes) {
    throttleMinutes.value = Math.max(1, minutes || 1);
    clearTimeout(throttleTimer);
    throttleTimer = setTimeout(() => { throttleMinutes.value = null; }, throttleMinutes.value * 60 * 1000);
}
onBeforeUnmount(() => clearTimeout(throttleTimer));

async function submitCode() {
    if (throttleMinutes.value !== null || loading.value) return;
    if (!code.value) {
        codeError.value = t('twofactor.error.code');
        return otp.value?.focus();
    }
    codeError.value = '';
    loading.value = true;
    const result = await securityFetch('/user/confirmed-two-factor-authentication', { method: 'POST', body: { code: code.value } });
    loading.value = false;
    if (result.ok) {
        recoveryCodes.value = result.data.recoveryCodes ?? [];
        code.value = '';
        reauthInPlace.value = false;
        step.value = 'codes';
        return focusFirst();
    }
    if (result.error === 'confirm_required') return confirmNeeded('confirm');
    if (result.error === 'throttle') return startThrottle(result.minutes);
    codeError.value = result.error === 'invalid' || result.status === 422 ? t('twofactor.setup.code_error') : t('error.generic.body');
    code.value = '';
    nextTick(() => otp.value?.focus());
}

async function onConfirmed() {
    fresh.value = true;
    const action = pending.value;
    pending.value = null;
    if (action === 'confirm') {
        reauthInPlace.value = false;
        await nextTick();
        return submitCode();
    }
    return begin();
}

async function cancel() {
    if (step.value === 'scan' || step.value === 'verify') {
        await securityFetch('/user/two-factor-authentication', { method: 'DELETE' });
    }
    wipe();
    emit('update:open', false);
}

function onUpdateOpen(value) {
    if (value) return emit('update:open', true);
    if (step.value === 'codes') return;
    cancel();
}

function finish() {
    wipe();
    router.clearHistory?.();
    toast.show(t('security.totp.enabled_toast'));
    emit('update:open', false);
    emit('finished');
}

async function copyKey() {
    try {
        await navigator.clipboard.writeText(secretKey.value.replace(/\s+/g, ''));
        toast.show(t('twofactor.setup.key_copied'));
    } catch {
        // The key stays selectable on screen.
    }
}
</script>

<template>
    <UiDialog
        :open="open"
        :title="title"
        :dismissable="step !== 'codes'"
        :initial-focus="() => body?.querySelector('.reauth-form input, [data-step-heading]')"
        @update:open="onUpdateOpen"
    >
        <div ref="body" class="setup" data-testid="setup-dialog">
            <template v-if="step === 'confirm'">
                <p class="setup__text">{{ t('security.confirm_first') }}</p>
                <ReauthPrompt :methods="methods" :is-admin="isAdmin" @confirmed="onConfirmed" />
            </template>

            <template v-else>
                <p v-if="step !== 'codes'" class="setup__step" data-testid="setup-step">{{ t('twofactor.setup.step', { step: stepNumber, total: 3 }) }}</p>
                <h3 v-if="step === 'scan'" class="setup__heading" tabindex="-1" data-step-heading>{{ t('twofactor.setup.scan_title') }}</h3>
                <h3 v-else-if="step === 'verify'" class="setup__heading" tabindex="-1" data-step-heading>{{ t('twofactor.setup.confirm_title') }}</h3>
                <h3 v-else class="setup__heading" tabindex="-1" data-step-heading>{{ t('twofactor.recovery.title') }}</h3>

                <template v-if="step === 'scan'">
                    <UiAlert v-if="failed" variant="danger" live>{{ t('error.generic.body') }}</UiAlert>
                    <p class="setup__text">{{ t('twofactor.setup.scan_body') }}</p>
                    <div v-if="svg" class="setup__qr" role="img" :aria-label="t('twofactor.setup.qr_label')" data-testid="qr" v-html="svg"></div>
                    <div v-if="secretKey" class="setup__key-block">
                        <UiButton variant="ghost" size="sm" :aria-expanded="showKey ? 'true' : 'false'" data-testid="cant-scan" @click="showKey = !showKey">
                            {{ t('twofactor.setup.cant_scan') }}
                        </UiButton>
                        <div v-if="showKey" class="setup__key" data-testid="key">
                            <span class="setup__key-label">{{ t('twofactor.setup.key_label') }}</span>
                            <code class="setup__key-value">{{ groupedKey }}</code>
                            <UiButton variant="secondary" size="sm" :icon="Copy" data-testid="copy-key" @click="copyKey">{{ t('twofactor.cta.copy_key') }}</UiButton>
                        </div>
                    </div>
                </template>

                <template v-else-if="step === 'verify'">
                    <template v-if="reauthInPlace">
                        <p class="setup__text">{{ t('security.confirm_first') }}</p>
                        <ReauthPrompt :methods="methods" :is-admin="isAdmin" @confirmed="onConfirmed" />
                    </template>
                    <form novalidate @submit.prevent="submitCode">
                        <UiAlert v-if="throttleMinutes !== null" variant="danger" live class="setup__alert">
                            {{ t('twofactor.error.throttle', { n: throttleMinutes }, throttleMinutes) }}
                        </UiAlert>
                        <OtpInput
                            ref="otp"
                            v-model="code"
                            :hint="t('twofactor.setup.code_hint')"
                            :error="codeError"
                            :disabled="loading"
                        />
                    </form>
                </template>

                <RecoveryCodesPanel v-else-if="step === 'codes'" :codes="recoveryCodes" @finish="finish" />
            </template>
        </div>

        <template v-if="step === 'scan' || step === 'verify'" #footer>
            <UiButton v-if="step === 'verify'" variant="ghost" data-testid="back-to-scan" @click="backToScan">{{ t('twofactor.cta.back_to_scan') }}</UiButton>
            <UiButton variant="secondary" data-testid="cancel-setup" @click="cancel">{{ t('twofactor.cta.cancel') }}</UiButton>
            <UiButton v-if="step === 'scan'" variant="primary" :disabled="!secretKey || loading" data-testid="next-step" @click="toVerify">{{ t('twofactor.cta.next') }}</UiButton>
            <UiButton v-else variant="primary" :loading="loading" :disabled="throttleMinutes !== null || reauthInPlace" data-testid="confirm-code" @click="submitCode">{{ t('twofactor.cta.confirm_code') }}</UiButton>
        </template>
        <template v-else-if="step === 'confirm'" #footer>
            <UiButton variant="secondary" data-testid="cancel-confirm" @click="cancel">{{ t('twofactor.cta.cancel') }}</UiButton>
        </template>
    </UiDialog>
</template>

<style scoped>
.setup__text { margin: 0 0 var(--space-4); }
.setup__step { margin: 0 0 var(--space-1); color: var(--color-text-muted); font-size: var(--text-sm); }
.setup__heading { margin: 0 0 var(--space-3); font-size: var(--text-lg); font-weight: 600; line-height: 1.3; }
.setup__heading:focus { outline: none; }
.setup__alert { margin-bottom: var(--space-4); }
.setup__qr {
    display: flex;
    justify-content: center;
    padding: var(--space-4);
    margin-bottom: var(--space-3);
    background: var(--color-surface-2);
    border-radius: var(--radius-md);
}
.setup__qr :deep(svg) { width: 192px; height: 192px; background: #ffffff; color: #000000; }
.setup__key-block { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-2); }
.setup__key { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-2); }
.setup__key-label { color: var(--color-text-muted); font-size: var(--text-sm); }
.setup__key-value { font-family: var(--font-mono); font-size: var(--text-base); word-break: break-word; user-select: all; }
</style>
