<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiInput from '../../Components/ui/UiInput.vue';

const { t } = useI18n();
const page = usePage();

const form = useForm({ email: '' });
const clientError = ref('');
const emailInput = ref(null);
const sentAlert = ref(null);

const sent = computed(() => page.props.flash?.status === 'reset_link_sent');
const emailError = computed(() => clientError.value || (form.errors?.email ? t(form.errors.email) : ''));

// After sending, the confirmation takes focus so screen readers announce it.
watch(sent, async (value) => {
    if (!value) return;
    await nextTick();
    (sentAlert.value?.$el ?? sentAlert.value)?.focus?.();
}, { immediate: true, flush: 'post' });

const throttleMinutes = ref(null);
let throttleTimer = null;
watch(
    () => form.errors?.throttle,
    (value) => {
        if (!value) return;
        const minutes = Math.max(1, parseInt(value, 10) || 0);
        clearTimeout(throttleTimer);
        throttleMinutes.value = minutes;
        throttleTimer = setTimeout(() => { throttleMinutes.value = null; }, minutes * 60 * 1000);
    },
    { immediate: true },
);
onBeforeUnmount(() => clearTimeout(throttleTimer));
const locked = computed(() => throttleMinutes.value !== null);

function submit() {
    if (locked.value || form.processing) return;
    const email = form.email.trim();
    if (!email) clientError.value = t('auth.error.email_required');
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) clientError.value = t('auth.error.email_invalid');
    else clientError.value = '';
    if (clientError.value) return emailInput.value?.focus();
    form.post('/forgot-password');
}
</script>

<template>
    <Head :title="t('auth.forgot.title')" />
    <AuthLayout :title="t('auth.forgot.title')">
        <UiAlert v-if="sent" ref="sentAlert" variant="success" live tabindex="-1" class="forgot__alert" data-testid="sent-alert">
            {{ t('auth.forgot.sent') }}
        </UiAlert>
        <UiAlert v-if="locked" variant="danger" live class="forgot__alert" data-testid="throttle-alert">
            {{ t('auth.error.throttle', { minutes: throttleMinutes }, throttleMinutes) }}
        </UiAlert>

        <p class="forgot__body">{{ t('auth.forgot.body') }}</p>
        <form novalidate @submit.prevent="submit">
            <UiField :label="t('auth.email')" :error="emailError">
                <UiInput
                    ref="emailInput"
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    inputmode="email"
                    autofocus
                />
            </UiField>
            <UiButton type="submit" variant="primary" class="forgot__full" :loading="form.processing" :disabled="locked">
                {{ t('auth.forgot.submit') }}
            </UiButton>
        </form>
        <p class="forgot__back"><Link href="/login">{{ t('auth.back_to_login') }}</Link></p>
    </AuthLayout>
</template>

<style scoped>
.forgot__alert { margin-bottom: var(--space-4); }
.forgot__body { margin: 0 0 var(--space-4); color: var(--color-text-muted); }
.forgot__full { display: flex; width: 100%; }
.forgot__back { margin: var(--space-4) 0 0; text-align: center; font-size: var(--text-sm); }
.forgot__back a { color: var(--color-primary); }
</style>
