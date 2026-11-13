<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiButton from '../ui/UiButton.vue';
import ReauthForm from './ReauthForm.vue';

// In-dialog "Confirm it's you": the form when the member has a method, otherwise the no-method copy with
// "Sign in again" (and, for admins, a link to set up an authenticator). Never navigates to /confirm-access.
const props = defineProps({
    methods: { type: Object, required: true },
    isAdmin: { type: Boolean, default: false },
});
defineEmits(['confirmed']);
const { t } = useI18n();

const usable = computed(() => !!(props.methods.password || props.methods.totp || props.methods.passkey));

function signInAgain() {
    router.post('/confirm-access/sign-in-again');
}
</script>

<template>
    <div class="reauth-prompt" data-testid="reauth-prompt">
        <ReauthForm v-if="usable" :methods="methods" mode="dialog" @confirmed="$emit('confirmed')" />
        <div v-else class="reauth-prompt__none" data-testid="reauth-no-method">
            <p class="reauth-prompt__text">{{ t('reauth.no_method') }}</p>
            <UiButton variant="primary" data-testid="sign-in-again" @click="signInAgain">{{ t('reauth.cta.sign_in_again') }}</UiButton>
            <p v-if="isAdmin" class="reauth-prompt__text">
                <a href="/settings/security">{{ t('reauth.setup_first') }}</a>
            </p>
        </div>
    </div>
</template>

<style scoped>
.reauth-prompt__text { margin: 0 0 var(--space-3); }
.reauth-prompt__none { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-3); }
.reauth-prompt__none .reauth-prompt__text { margin: 0; }
</style>
