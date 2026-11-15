<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Eye, EyeOff } from '@lucide/vue';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiErrorState from '../../Components/ui/UiErrorState.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiInput from '../../Components/ui/UiInput.vue';
import GoogleGlyph from '../../Components/GoogleGlyph.vue';

const props = defineProps({
    // 'valid' | 'expired' | 'used' | 'revoked' (revoked also covers unknown links)
    state: { type: String, default: 'revoked' },
    email: { type: String, default: null },
    token: { type: String, default: null },
    googleEnabled: { type: Boolean, default: false },
});

const { t } = useI18n();
const page = usePage();

const form = useForm({ name: '', password: '', password_confirmation: '' });
const clientErrors = ref({});
const showPassword = ref(false);
const nameInput = ref(null);
const passwordInput = ref(null);
const confirmInput = ref(null);

const translate = (value) => (value ? t(value) : '');
const nameError = computed(() => clientErrors.value.name || translate(form.errors?.name));
const passwordError = computed(() => clientErrors.value.password || translate(form.errors?.password));
const confirmError = computed(() => clientErrors.value.confirm);
const mismatch = computed(() => page.props.flash?.status === 'google_mismatch');
const googleHref = computed(() => `/auth/google/redirect?invite=${encodeURIComponent(props.token ?? '')}`);
const invalidKey = computed(() => `invite.invalid.${['expired', 'used'].includes(props.state) ? props.state : 'revoked'}`);

function submit() {
    if (form.processing) return;
    const errors = {};
    if (!form.name.trim()) errors.name = t('invite.accept.error.name_required');
    else if (form.password.length < 12) errors.password = t('invite.accept.error.password_short');
    else if (form.password !== form.password_confirmation) errors.confirm = t('invite.accept.error.password_mismatch');
    clientErrors.value = errors;
    if (errors.name) return nameInput.value?.focus();
    if (errors.password) return passwordInput.value?.focus();
    if (errors.confirm) return confirmInput.value?.focus();
    form.post(`/invite/${encodeURIComponent(props.token)}`, { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <Head :title="t('invite.accept.title')" />
    <AuthLayout v-if="state === 'valid'" :title="t('invite.accept.title')">
        <p class="invite__body">{{ t('invite.accept.body', { email }) }}</p>
        <UiAlert v-if="mismatch" variant="danger" live class="invite__alert" data-testid="mismatch-alert">
            {{ t('invite.accept.google_mismatch', { email }) }}
        </UiAlert>
        <form novalidate @submit.prevent="submit">
            <UiField :label="t('auth.email')">
                <UiInput :model-value="email" type="email" disabled autocomplete="username" />
            </UiField>
            <UiField :label="t('invite.accept.name')" :error="nameError">
                <UiInput ref="nameInput" v-model="form.name" autocomplete="name" autofocus />
            </UiField>
            <UiField :label="t('auth.password')" :hint="t('invite.accept.password_hint')" :error="passwordError">
                <div class="invite__password">
                    <UiInput
                        ref="passwordInput"
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="new-password"
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
            <UiField :label="t('auth.password_confirm')" :error="confirmError">
                <UiInput
                    ref="confirmInput"
                    v-model="form.password_confirmation"
                    :type="showPassword ? 'text' : 'password'"
                    autocomplete="new-password"
                />
            </UiField>
            <UiButton type="submit" variant="primary" class="invite__full" :loading="form.processing">
                {{ t('invite.accept.submit') }}
            </UiButton>
        </form>

        <template v-if="googleEnabled">
            <div class="invite__divider" role="separator"><span>{{ t('auth.or') }}</span></div>
            <UiButton :href="googleHref" variant="secondary" class="invite__full">
                <GoogleGlyph />
                {{ t('invite.accept.google') }}
            </UiButton>
            <p class="invite__hint">{{ t('invite.accept.google_hint', { email }) }}</p>
        </template>
    </AuthLayout>
    <AuthLayout v-else :title="t('invite.invalid.title')">
        <UiErrorState :title="t('invite.invalid.title')" :body="t(invalidKey)" :heading-level="2" live>
            <template #action>
                <UiButton variant="primary" href="/login">{{ t('invite.invalid.action') }}</UiButton>
            </template>
        </UiErrorState>
    </AuthLayout>
</template>

<style scoped>
.invite__body { margin: 0 0 var(--space-4); color: var(--color-text-muted); }
.invite__alert { margin-bottom: var(--space-4); }
.invite__password { display: flex; align-items: center; gap: var(--space-2); }
.invite__password :deep(.ui-input) { flex: 1; min-width: 0; }
.invite__full { display: flex; width: 100%; }
.invite__divider { display: flex; align-items: center; gap: var(--space-3); margin-block: var(--space-4); color: var(--color-text-muted); font-size: var(--text-sm); }
.invite__divider::before, .invite__divider::after { content: ''; flex: 1; height: 1px; background: var(--color-border); }
.invite__hint { margin: var(--space-2) 0 0; text-align: center; color: var(--color-text-muted); font-size: var(--text-sm); }
</style>
