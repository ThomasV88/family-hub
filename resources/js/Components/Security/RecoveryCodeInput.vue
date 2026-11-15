<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import UiField from '../ui/UiField.vue';
import UiInput from '../ui/UiInput.vue';

// Recovery codes are secrets: no browser autofill history, spaces trimmed.
defineProps({
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

defineExpose({
    focus: () => input.value?.focus(),
    clear: () => emit('update:modelValue', ''),
});
</script>

<template>
    <UiField :label="label || t('twofactor.recovery.label')" :hint="hint" :error="error" :id="id">
        <UiInput
            ref="input"
            class="recovery-input"
            type="text"
            autocomplete="off"
            maxlength="32"
            autocapitalize="none"
            spellcheck="false"
            :autofocus="autofocus"
            :disabled="disabled"
            :model-value="modelValue"
            @update:model-value="emit('update:modelValue', $event.replace(/\s+/g, ''))"
        />
        <span class="visually-hidden" role="alert">{{ error }}</span>
    </UiField>
</template>

<style scoped>
.recovery-input :deep(.ui-control) { font-family: var(--font-mono); min-height: 48px; }
</style>
