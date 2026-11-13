<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShell from '../../Layouts/AppShell.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import GoogleGlyph from '../../Components/GoogleGlyph.vue';
import ReauthForm from '../../Components/Security/ReauthForm.vue';

const props = defineProps({
    methods: { type: Object, required: true },
    minutes: { type: Number, default: 15 },
    interrupted: { type: Boolean, default: false },
});
const { t } = useI18n();
const page = usePage();

const hasPassword = computed(() => !!props.methods.password);
const hasForm = computed(() => hasPassword.value || !!props.methods.totp);
const googleFailed = computed(() => page.props.flash?.status === 'google_reauth_failed');
const hasAny = computed(() => hasForm.value || !!props.methods.passkey);

const restarting = ref(false);
function signInAgain() {
    router.post('/confirm-access/sign-in-again', {}, {
        onStart: () => { restarting.value = true; },
        onFinish: () => { restarting.value = false; },
    });
}
</script>

<template>
    <Head :title="t('reauth.title')" />
    <AppShell>
        <div class="confirm" data-testid="confirm-access">
            <UiCard class="confirm__card">
                <h1 class="confirm__title">{{ t('reauth.title') }}</h1>

                <UiAlert v-if="interrupted" variant="info" live class="confirm__alert" data-testid="interrupted-alert">
                    {{ t('reauth.interrupted') }}
                </UiAlert>

                <UiAlert v-if="googleFailed" variant="danger" live class="confirm__alert" data-testid="google-failed-alert">
                    {{ t('reauth.google_failed') }}
                </UiAlert>

                <template v-if="hasAny">
                    <p class="confirm__body">
                        {{ hasPassword ? t('reauth.body', { minutes }) : t('reauth.passkey_only_body') }}
                    </p>
                    <ReauthForm :methods="methods" mode="page">
                        <template v-if="$slots.alternatives" #alternatives><slot name="alternatives" /></template>
                    </ReauthForm>
                </template>
                <template v-else>
                    <p class="confirm__body">{{ t('reauth.no_method') }}</p>
                    <UiButton variant="primary" class="confirm__full" :loading="restarting" data-testid="sign-in-again" @click="signInAgain">
                        {{ t('reauth.cta.sign_in_again') }}
                    </UiButton>
                    <!-- Full-page navigation (not Inertia): the member leaves for Google. Only sent for an eligible member with the flag on. -->
                    <UiButton v-if="methods.google" href="/confirm-access/google" variant="secondary" class="confirm__full confirm__google" data-testid="google-again">
                        <GoogleGlyph />
                        {{ t('reauth.google') }}
                    </UiButton>
                </template>

                <p class="confirm__back"><Link href="/">{{ t('reauth.back') }}</Link></p>
            </UiCard>
        </div>
    </AppShell>
</template>

<style scoped>
.confirm { display: flex; justify-content: center; padding-block: var(--space-6); }
.confirm__card { width: 100%; max-width: 440px; }
.confirm__title { margin: 0 0 var(--space-3); font-size: var(--text-xl); font-weight: 600; line-height: 1.3; }
.confirm__body { margin: 0 0 var(--space-4); color: var(--color-text-muted); }
.confirm__alert { margin-bottom: var(--space-4); }
.confirm__full { display: flex; width: 100%; }
.confirm__google { margin-top: var(--space-3); }
.confirm__back { margin: var(--space-4) 0 0; text-align: center; font-size: var(--text-sm); }
.confirm__back a { color: var(--color-primary); }
</style>
