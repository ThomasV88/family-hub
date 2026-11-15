<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import UiField from '../ui/UiField.vue';
import UiInput from '../ui/UiInput.vue';

// One native one-time-code input (not six boxes): phones autofill and paste work. Never submits by itself.
const props = defineProps({
    modelValue: { type: String, default: '' },
    label: { type: String, default: '' },
    hint: { type: String, default: '' },
    error: { type: String, default: '' },
    id: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    autofocus: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();
const input = ref(null);

function onUpdate(value) {
    emit('update:modelValue', value.replace(/\s+/g, ''));
}

defineExpose({
    focus: () => input.value?.focus(),
    clear: () => emit('update:modelValue', ''),
});
</script>

<template>
    <UiField :label="label || t('twofactor.code.label')" :hint="hint" :error="error" :id="id">
        <UiInput
            ref="input"
            class="otp-input"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            pattern="[0-9]*"
            maxlength="6"
            autocapitalize="none"
            spellcheck="false"
            :autofocus="autofocus"
            :disabled="disabled"
            :model-value="modelValue"
            @update:model-value="onUpdate"
        />
        <!-- Live region for the error: the visible text sits in UiField; this announces it once. -->
        <span class="visually-hidden" role="alert">{{ error }}</span>
    </UiField>
</template>

<style scoped>
.otp-input :deep(.ui-control) { font-family: var(--font-mono); font-size: var(--text-lg, 1.125rem); letter-spacing: 0.2em; min-height: 48px; }
</style>
