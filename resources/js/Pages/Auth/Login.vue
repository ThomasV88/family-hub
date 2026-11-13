<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Eye, EyeOff } from '@lucide/vue';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiCheckbox from '../../Components/ui/UiCheckbox.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiInput from '../../Components/ui/UiInput.vue';
import GoogleGlyph from '../../Components/GoogleGlyph.vue';

// Google sign-in is rendered only once the server reports credentials are configured (plan 09).
defineProps({ googleEnabled: { type: Boolean, default: false } });

const { t } = useI18n();
const page = usePage();

const form = useForm({ email: '', password: '', remember: false });
const clientErrors = ref({});
const showPassword = ref(false);
const emailInput = ref(null);
const passwordInput = ref(null);

// Server messages are i18n keys (error contract); anything else is shown as-is.
const translate = (value) => (value ? t(value) : '');

// Lockout: the button stays disabled until the announced wait has elapsed.
const throttleMinutes = ref(null);
let throttleTimer = null;
function startThrottle(minutes) {
    clearTimeout(throttleTimer);
    throttleMinutes.value = minutes;
    throttleTimer = setTimeout(() => {
        throttleMinutes.value = null;
    }, minutes * 60 * 1000);
}
watch(
    () => form.errors?.throttle,
    (value) => {
        const minutes = Math.max(1, parseInt(value, 10) || 0);
        if (value) startThrottle(minutes);
    },
    { immediate: true },
);
onBeforeUnmount(() => clearTimeout(throttleTimer));

const locked = computed(() => throttleMinutes.value !== null);
const failedMessage = computed(() => (form.errors?.email === 'auth.error.failed' ? t('auth.error.failed') : ''));
const statusAlert = computed(() => {
    const map = {
        signed_out: { variant: 'info', key: 'auth.signed_out' },
        session_ended: { variant: 'info', key: 'auth.session_ended' },
        password_reset: { variant: 'success', key: 'auth.reset.done' },
    };
    return map[page.props.flash?.status] ?? null;
});
const emailError = computed(() => clientErrors.value.email || (failedMessage.value ? '' : translate(form.errors?.email)));
const passwordError = computed(() => clientErrors.value.password || translate(form.errors?.password));

function validate() {
    const errors = {};
    const email = form.email.trim();
    if (!email) errors.email = t('auth.error.email_required');
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.email = t('auth.error.email_invalid');
    if (!form.password) errors.password = t('auth.error.password_required');
    clientErrors.value = errors;
    return errors;
}

function submit() {
    if (locked.value || form.processing) return;
    const errors = validate();
    if (errors.email) return emailInput.value?.focus();
    if (errors.password) return passwordInput.value?.focus();
    form.post('/login', { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head :title="t('auth.title.login')" />
    <AuthLayout :title="t('auth.title.login')" :footnote="t('auth.invite_only')">
        <UiAlert v-if="statusAlert" :variant="statusAlert.variant" live class="login__alert" data-testid="status-alert">
            {{ t(statusAlert.key) }}
        </UiAlert>
        <UiAlert v-if="locked" variant="danger" live class="login__alert" data-testid="throttle-alert">
            {{ t('auth.error.throttle', { minutes: throttleMinutes }, throttleMinutes) }}
        </UiAlert>
        <UiAlert v-else-if="failedMessage" variant="danger" live class="login__alert" data-testid="failed-alert">
            {{ failedMessage }}
        </UiAlert>

        <form novalidate @submit.prevent="submit">
            <UiField :label="t('auth.email')" :error="emailError">
                <UiInput
                    ref="emailInput"
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                    inputmode="email"
                    autofocus
                />
            </UiField>
            <UiField :label="t('auth.password')" :error="passwordError">
                <div class="login__password">
                    <UiInput
                        ref="passwordInput"
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="current-password"
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
            <div class="login__remember">
                <UiCheckbox v-model="form.remember">{{ t('auth.remember') }}</UiCheckbox>
            </div>
            <UiButton type="submit" variant="primary" class="login__full" :loading="form.processing" :disabled="locked">
                {{ t('auth.submit.login') }}
            </UiButton>
        </form>

        <template v-if="googleEnabled">
            <div class="login__divider" role="separator"><span>{{ t('auth.or') }}</span></div>
            <UiButton href="/auth/google/redirect" variant="secondary" class="login__full">
                <GoogleGlyph />
                {{ t('auth.google') }}
            </UiButton>
        </template>

        <p class="login__forgot"><Link href="/forgot-password">{{ t('auth.forgot_link') }}</Link></p>
    </AuthLayout>
</template>

<style scoped>
.login__alert { margin-bottom: var(--space-4); }
.login__password { display: flex; align-items: center; gap: var(--space-2); }
.login__password :deep(.ui-input) { flex: 1; min-width: 0; }
.login__remember { margin-bottom: var(--space-4); }
.login__full { display: flex; width: 100%; }
.login__divider { display: flex; align-items: center; gap: var(--space-3); margin-block: var(--space-4); color: var(--color-text-muted); font-size: var(--text-sm); }
.login__divider::before, .login__divider::after { content: ''; flex: 1; height: 1px; background: var(--color-border); }
.login__forgot { margin: var(--space-4) 0 0; text-align: center; font-size: var(--text-sm); }
.login__forgot a { color: var(--color-primary); }
</style>
