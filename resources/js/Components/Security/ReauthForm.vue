<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Eye, EyeOff } from '@lucide/vue';
import UiAlert from '../ui/UiAlert.vue';
import UiButton from '../ui/UiButton.vue';
import UiField from '../ui/UiField.vue';
import UiInput from '../ui/UiInput.vue';
import OtpInput from './OtpInput.vue';
import PasskeyButton from './PasskeyButton.vue';
import { getAssertion, passkeysSupported } from '../../Security/webauthn.js';
import { securityFetch } from '../../Security/securityFetch.js';

// The shared "Confirm it's you" form. `page` mode posts through Inertia and follows the server redirect;
// `dialog` mode posts JSON in place and emits `confirmed`.
const props = defineProps({
    methods: { type: Object, required: true },
    mode: { type: String, default: 'page', validator: (v) => ['page', 'dialog'].includes(v) },
});
const emit = defineEmits(['confirmed']);
const { t } = useI18n();

const method = ref(props.methods.password ? 'password' : 'code');
const password = ref('');
const code = ref('');
const showPassword = ref(false);
const error = ref('');
const busy = ref(false);
const passwordInput = ref(null);
const otpInput = ref(null);

const canPassword = computed(() => !!props.methods.password);
const canCode = computed(() => !!props.methods.totp);
const canPasskey = computed(() => !!props.methods.passkey && passkeysSupported());
const passkeyBusy = ref(false);
const hasForm = computed(() => canPassword.value || canCode.value);
const usingPassword = computed(() => method.value === 'password' && canPassword.value);

// Throttle: minutes shown, refreshed once per minute; when it ends a polite status announces it.
const throttleMinutes = ref(null);
const throttleOver = ref(false);
let timer = null;
function startThrottle(minutes) {
    clearInterval(timer);
    throttleOver.value = false;
    throttleMinutes.value = Math.max(1, parseInt(minutes, 10) || 1);
    timer = setInterval(() => {
        throttleMinutes.value -= 1;
        if (throttleMinutes.value <= 0) {
            clearInterval(timer);
            throttleMinutes.value = null;
            throttleOver.value = true;
        }
    }, 60 * 1000);
}
onBeforeUnmount(() => clearInterval(timer));
const locked = computed(() => throttleMinutes.value !== null);

const errorMessage = computed(() => (error.value ? t(error.value) : ''));

function switchTo(next) {
    method.value = next;
    error.value = '';
    password.value = '';
    code.value = '';
}

async function refocus() {
    await nextTick();
    (usingPassword.value ? passwordInput.value : otpInput.value)?.focus();
}

function fail(errors) {
    if (errors?.throttle) {
        startThrottle(errors.throttle);
    } else {
        error.value = errors?.password || errors?.code || 'reauth.error.password';
    }
    password.value = '';
    code.value = '';
    refocus();
}

async function submit() {
    if (locked.value || busy.value) return;
    const value = usingPassword.value ? password.value : code.value;
    if (!value) {
        error.value = usingPassword.value ? 'auth.error.password_required' : 'twofactor.error.code';
        return refocus();
    }
    error.value = '';
    throttleOver.value = false;
    const payload = usingPassword.value ? { password: value } : { code: value };

    busy.value = true;
    if (props.mode === 'dialog') {
        const result = await securityFetch('/confirm-access', { method: 'POST', body: payload });
        busy.value = false;
        if (result.ok) return emit('confirmed');
        if (result.error === 'throttle') {
            startThrottle(result.minutes);
            return;
        }
        if (result.error === 'invalid') return fail(result.errors);
        return fail({ [usingPassword.value ? 'password' : 'code']: usingPassword.value ? 'reauth.error.password' : 'twofactor.error.code' });
    }
    router.post('/confirm-access', payload, {
        preserveScroll: true,
        onError: (errors) => fail(errors),
        onFinish: () => { busy.value = false; },
    });
}

// Passkey: options, device prompt, then confirm. Cancel is quiet; any failure shows the same generic copy.
async function usePasskey() {
    if (locked.value || busy.value || passkeyBusy.value) return;
    passkeyBusy.value = true;
    error.value = '';
    throttleOver.value = false;
    try {
        const opts = await securityFetch('/confirm-access/passkey/options');
        if (!opts.ok) return failPasskey(opts);
        let credential;
        try {
            credential = await getAssertion(opts.data.options);
        } catch (e) {
            if (e?.name !== 'cancelled') error.value = 'twofactor.error.passkey';
            return;
        }
        const result = await securityFetch('/confirm-access/passkey', { method: 'POST', body: { credential, mode: props.mode } });
        if (!result.ok) return failPasskey(result);
        if (props.mode === 'dialog') return emit('confirmed');
        const target = result.data?.redirect;
        router.visit(typeof target === 'string' && target.startsWith('/') && !target.startsWith('//') ? target : '/');
    } finally {
        passkeyBusy.value = false;
    }
}

function failPasskey(result) {
    if (result.error === 'throttle') return startThrottle(result.minutes);
    error.value = 'twofactor.error.passkey';
}

watch(() => props.methods, () => {
    if (!usingPassword.value && !canCode.value) method.value = 'password';
});
</script>

<template>
    <div class="reauth-form">
        <UiAlert v-if="locked" variant="danger" live data-testid="throttle-alert" class="reauth-form__alert">
            {{ t('twofactor.error.throttle', { n: throttleMinutes }, throttleMinutes) }}
        </UiAlert>
        <UiAlert v-else-if="errorMessage" variant="danger" live data-testid="error-alert" class="reauth-form__alert">
            {{ errorMessage }}
        </UiAlert>
        <p v-if="throttleOver && !locked" role="status" class="reauth-form__over" data-testid="throttle-over">
            {{ t('twofactor.throttle_over') }}
        </p>

        <form v-if="hasForm" novalidate @submit.prevent="submit">
            <UiField v-if="usingPassword" :label="t('auth.password')">
                <div class="reauth-form__password">
                    <UiInput
                        ref="passwordInput"
                        v-model="password"
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="current-password"
                        :disabled="busy"
                    />
                    <UiButton
                        variant="ghost"
                        icon-only
                        :icon="showPassword ? EyeOff : Eye"
                        :label="showPassword ? t('auth.hide_password') : t('auth.show_password')"
                        :aria-pressed="showPassword ? 'true' : 'false'"
                        data-testid="toggle-password"
                        @click="showPassword = !showPassword"
                    />
                </div>
            </UiField>
            <OtpInput v-else ref="otpInput" v-model="code" :disabled="busy" />

            <UiButton type="submit" variant="primary" class="reauth-form__full" :loading="busy" :disabled="locked">
                {{ usingPassword ? t('reauth.cta.password') : t('twofactor.cta.confirm_code') }}
            </UiButton>

            <p v-if="usingPassword && canCode" class="reauth-form__switch">
                <UiButton variant="ghost" size="sm" data-testid="use-code" @click="switchTo('code')">
                    {{ t('twofactor.challenge.use_totp') }}
                </UiButton>
            </p>
            <p v-else-if="!usingPassword && canPassword" class="reauth-form__switch">
                <UiButton variant="ghost" size="sm" data-testid="use-password" @click="switchTo('password')">
                    {{ t('reauth.use_password') }}
                </UiButton>
            </p>
        </form>

        <template v-if="canPasskey">
            <div v-if="hasForm" class="reauth-form__divider" role="separator"><span>{{ t('auth.or') }}</span></div>
            <PasskeyButton
                class="reauth-form__passkey"
                :variant="hasForm ? 'secondary' : 'primary'"
                :busy="passkeyBusy"
                :disabled="locked"
                data-testid="use-passkey"
                @start="usePasskey"
            />
            <p v-if="passkeyBusy" role="status" class="reauth-form__waiting" data-testid="passkey-waiting">{{ t('reauth.passkey_waiting') }}</p>
        </template>

        <template v-if="$slots.alternatives">
            <div v-if="hasForm || canPasskey" class="reauth-form__divider" role="separator"><span>{{ t('auth.or') }}</span></div>
            <slot name="alternatives" />
        </template>
    </div>
</template>

<style scoped>
.reauth-form__alert { margin-bottom: var(--space-4); }
.reauth-form__over { margin: 0 0 var(--space-4); color: var(--color-text-muted); font-size: var(--text-sm); }
.reauth-form__password { display: flex; align-items: center; gap: var(--space-2); }
.reauth-form__password :deep(.ui-input) { flex: 1; min-width: 0; }
.reauth-form__full { display: flex; width: 100%; margin-top: var(--space-4); }
.reauth-form__passkey { display: flex; width: 100%; }
.reauth-form__waiting { margin: var(--space-2) 0 0; color: var(--color-text-muted); font-size: var(--text-sm); text-align: center; }
.reauth-form__switch { margin: var(--space-3) 0 0; text-align: center; }
.reauth-form__divider { display: flex; align-items: center; gap: var(--space-3); margin-block: var(--space-4); color: var(--color-text-muted); font-size: var(--text-sm); }
.reauth-form__divider::before, .reauth-form__divider::after { content: ''; flex: 1; height: 1px; background: var(--color-border); }
</style>
