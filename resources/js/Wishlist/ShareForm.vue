<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../Components/ui/UiButton.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import { isValidPrice, normalizePrice } from './money.js';

// Inline "your share" form. Emits submit with the normalized amount string or null.
const props = defineProps({
    initial: { type: String, default: null },
    currency: { type: String, default: 'EUR' },
    joined: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['submit', 'cancel']);
const { t } = useI18n();

const amount = ref(props.initial ?? '');
const error = ref('');

function submit() {
    const raw = String(amount.value ?? '').trim();
    if (raw !== '' && (!isValidPrice(raw) || Number(normalizePrice(raw)) <= 0)) {
        error.value = t('wishlist.error.share_invalid');
        return;
    }
    error.value = '';
    emit('submit', raw === '' ? null : normalizePrice(raw));
}
</script>

<template>
    <form class="share-form" novalidate @submit.prevent="submit">
        <UiField :label="t('wishlist.res.share_label')" :hint="t('wishlist.res.share_hint')" :error="error">
            <span class="share-form__input">
                <UiInput v-model="amount" inputmode="decimal" autocomplete="off" />
                <span class="share-form__currency">{{ currency }}</span>
            </span>
        </UiField>
        <div class="share-form__actions">
            <UiButton type="submit" variant="primary" :loading="busy">{{ joined ? t('wishlist.res.save_share') : t('wishlist.res.join') }}</UiButton>
            <UiButton variant="secondary" :disabled="busy" @click="emit('cancel')">{{ t('wishlist.cancel') }}</UiButton>
        </div>
    </form>
</template>

<style scoped>
.share-form { display: flex; flex-direction: column; gap: var(--space-3); }
.share-form__input { display: flex; align-items: center; gap: var(--space-2); }
.share-form__input :deep(.ui-input) { flex: 1; min-width: 0; }
.share-form__currency { color: var(--color-text-muted); }
.share-form__actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
</style>
