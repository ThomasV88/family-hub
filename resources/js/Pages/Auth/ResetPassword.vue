<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Eye, EyeOff } from '@lucide/vue';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiErrorState from '../../Components/ui/UiErrorState.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiInput from '../../Components/ui/UiInput.vue';

const props = defineProps({
    token: { type: String, default: '' },
    email: { type: String, default: '' },
    valid: { type: Boolean, default: false },
});

const { t } = useI18n();
const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });
const clientErrors = ref({});
const showPassword = ref(false);
const passwordInput = ref(null);
const confirmInput = ref(null);

const translate = (value) => (value ? t(value) : '');
const passwordError = computed(() => clientErrors.value.password || translate(form.errors?.password));
const confirmError = computed(() => clientErrors.value.confirm);
// An expired token found at submit time is reported on the email key.
const tokenError = computed(() => translate(form.errors?.email));

function submit() {
    if (form.processing) return;
    const errors = {};
    if (form.password.length < 12) errors.password = t('invite.accept.error.password_short');
    else if (form.password !== form.password_confirmation) errors.confirm = t('invite.accept.error.password_mismatch');
    clientErrors.value = errors;
    if (errors.password) return passwordInput.value?.focus();
    if (errors.confirm) return confirmInput.value?.focus();
    form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <Head :title="t('auth.reset.title')" />
    <AuthLayout v-if="valid" :title="t('auth.reset.title')">
        <UiAlert v-if="tokenError" variant="danger" live class="reset__alert" data-testid="token-alert">
            {{ tokenError }}
            <Link href="/forgot-password">{{ t('auth.reset.invalid_action') }}</Link>
        </UiAlert>
        <form novalidate @submit.prevent="submit">
            <UiField :label="t('auth.reset.new_password')" :hint="`${t('invite.accept.password_hint')} ${t('auth.reset.hint')}`" :error="passwordError">
                <div class="reset__password">
                    <UiInput
                        ref="passwordInput"
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="new-password"
                        autofocus
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
            <UiField :label="t('auth.reset.confirm')" :error="confirmError">
                <UiInput
                    ref="confirmInput"
                    v-model="form.password_confirmation"
                    :type="showPassword ? 'text' : 'password'"
                    autocomplete="new-password"
                />
            </UiField>
            <UiButton type="submit" variant="primary" class="reset__full" :loading="form.processing">
                {{ t('auth.reset.submit') }}
            </UiButton>
        </form>
    </AuthLayout>
    <AuthLayout v-else :title="t('auth.reset.invalid_title')">
        <UiErrorState :title="t('auth.reset.invalid_title')" :body="t('auth.reset.invalid_body')" :heading-level="2" live>
            <template #action>
                <UiButton variant="primary" href="/forgot-password">{{ t('auth.reset.invalid_action') }}</UiButton>
            </template>
        </UiErrorState>
    </AuthLayout>
</template>

<style scoped>
.reset__alert { margin-bottom: var(--space-4); }
.reset__password { display: flex; align-items: center; gap: var(--space-2); }
.reset__password :deep(.ui-input) { flex: 1; min-width: 0; }
.reset__full { display: flex; width: 100%; }
</style>
