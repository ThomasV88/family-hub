<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import OtpInput from '../../Components/Security/OtpInput.vue';
import RecoveryCodeInput from '../../Components/Security/RecoveryCodeInput.vue';

// Booleans only: the server never sends ids, names or emails to this page.
const props = defineProps({
    totp: { type: Boolean, default: false },
    passkey: { type: Boolean, default: false },
});

const { t } = useI18n();

const form = useForm({ code: '', recovery_code: '' });
const mode = ref('code'); // 'code' | 'recovery'
const otp = ref(null);
const recovery = ref(null);

const translate = (value) => (value ? t(value) : '');
const errorMessage = computed(() => translate(form.errors?.code || form.errors?.recovery_code));

// Lockout: submit stays disabled until the announced wait has elapsed. The visible minutes tick down once a minute.
const waitMinutes = ref(null);
const waitOver = ref(false);
let timer = null;
function tick() {
    waitMinutes.value -= 1;
    if (waitMinutes.value <= 0) {
        waitMinutes.value = null;
        waitOver.value = true;
        return;
    }
    timer = setTimeout(tick, 60 * 1000);
}
watch(
    () => form.errors?.throttle,
    (value) => {
        if (!value) return;
        clearTimeout(timer);
        waitOver.value = false;
        waitMinutes.value = Math.max(1, parseInt(value, 10) || 0);
        timer = setTimeout(tick, 60 * 1000);
    },
    { immediate: true },
);
onBeforeUnmount(() => clearTimeout(timer));
const locked = computed(() => waitMinutes.value !== null);

async function switchTo(next) {
    mode.value = next;
    form.clearErrors?.();
    await nextTick();
    (next === 'recovery' ? recovery : otp).value?.focus();
}

function submit() {
    if (locked.value || form.processing) return;
    const field = mode.value === 'recovery' ? 'recovery_code' : 'code';
    if (!form[field]) return (field === 'code' ? otp : recovery).value?.focus();
    form.transform((data) => ({ [field]: data[field] })).post('/two-factor-challenge', {
        onError: async () => {
            form.reset();
            await nextTick();
            (mode.value === 'recovery' ? recovery : otp).value?.focus();
        },
    });
}
</script>

<template>
    <Head :title="t('twofactor.challenge.title')" />
    <AuthLayout :title="t('twofactor.challenge.title')">
        <UiAlert v-if="locked" variant="danger" live class="challenge__alert" data-testid="throttle-alert">
            {{ t('twofactor.error.throttle', { n: waitMinutes }, waitMinutes) }}
        </UiAlert>
        <UiAlert v-else-if="waitOver" variant="info" live class="challenge__alert" data-testid="throttle-over">
            {{ t('twofactor.throttle_over') }}
        </UiAlert>
        <UiAlert v-else-if="errorMessage" variant="danger" live class="challenge__alert" data-testid="challenge-alert">
            {{ errorMessage }}
        </UiAlert>

        <form v-if="totp" novalidate @submit.prevent="submit">
            <p class="challenge__body">
                {{ mode === 'recovery' ? t('twofactor.challenge.recovery_body') : t('twofactor.challenge.totp_body') }}
            </p>
            <OtpInput v-if="mode === 'code'" ref="otp" v-model="form.code" autofocus />
            <RecoveryCodeInput v-else ref="recovery" v-model="form.recovery_code" />
            <UiButton type="submit" variant="primary" class="challenge__full" :loading="form.processing" :disabled="locked">
                {{ t('twofactor.cta.verify_code') }}
            </UiButton>
            <p class="challenge__switch">
                <button
                    v-if="mode === 'code'"
                    type="button"
                    class="challenge__link"
                    data-testid="use-recovery"
                    @click="switchTo('recovery')"
                >
                    {{ t('twofactor.challenge.use_recovery') }}
                </button>
                <button v-else type="button" class="challenge__link" data-testid="use-totp" @click="switchTo('code')">
                    {{ t('twofactor.challenge.use_totp') }}
                </button>
            </p>
        </form>
        <p v-else-if="passkey" class="challenge__body" data-testid="passkey-only">{{ t('twofactor.challenge.passkey_only') }}</p>

        <!-- Plan 06-09 renders the passkey button here; it needs the page-level lock state. -->
        <slot name="passkey" :locked="locked" />

        <p class="challenge__back"><Link href="/login" data-testid="back-to-login">{{ t('auth.back_to_login') }}</Link></p>
    </AuthLayout>
</template>

<style scoped>
.challenge__alert { margin-bottom: var(--space-4); }
.challenge__body { margin: 0 0 var(--space-4); color: var(--color-text-muted); }
.challenge__full { display: flex; width: 100%; margin-top: var(--space-4); }
.challenge__switch { margin: var(--space-4) 0 0; text-align: center; font-size: var(--text-sm); }
.challenge__link {
    min-height: 44px;
    padding: 0 var(--space-2);
    background: none;
    border: 0;
    color: var(--color-primary);
    font: inherit;
    text-decoration: underline;
    cursor: pointer;
}
.challenge__back { margin: var(--space-4) 0 0; text-align: center; font-size: var(--text-sm); }
.challenge__back a { display: inline-block; padding-block: var(--space-3); color: var(--color-primary); }
</style>
