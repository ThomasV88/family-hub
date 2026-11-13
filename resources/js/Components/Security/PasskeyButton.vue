<script setup>
import { KeyRound } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../ui/UiButton.vue';
import { passkeysSupported } from '../../Security/webauthn.js';

// Rendered only where the browser supports passkeys; elsewhere a muted explanation. While busy the label stays
// visible (aria-busy) and further clicks are ignored without dropping keyboard focus.
defineProps({
    label: { type: String, default: '' },
    busy: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    variant: { type: String, default: 'secondary' },
    showUnsupported: { type: Boolean, default: true },
});
const emit = defineEmits(['start']);
const { t } = useI18n();
const supported = passkeysSupported();
</script>

<template>
    <UiButton
        v-if="supported"
        class="passkey-button"
        :variant="variant"
        :icon="KeyRound"
        :disabled="disabled"
        :aria-busy="busy ? 'true' : undefined"
        @click="!busy && emit('start')"
    >
        {{ label || t('passkey.cta.use') }}
    </UiButton>
    <p v-else-if="showUnsupported" class="passkey-unsupported">{{ t('passkey.unsupported') }}</p>
</template>

<style scoped>
.passkey-unsupported { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
</style>
