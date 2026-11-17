<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { BellOff } from '@lucide/vue';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';

// The reminder of a shopping list: one local date and time (family time zone, as the list header shows it), pushed to the
// responsible person only. modelValue is "YYYY-MM-DDTHH:mm" or '' (no reminder). pushOffName: the responsible person's name
// when they have no push notifications turned on (the server sends that fact only to people who may set the reminder).
const props = defineProps({
    modelValue: { type: String, default: '' },
    readonly: { type: Boolean, default: false },
    error: { type: String, default: '' },
    pushOffName: { type: String, default: '' },
    sent: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();

const hint = computed(() => (props.sent && props.modelValue ? `${t('shopping.reminder.hint')} ${t('shopping.reminder.sent_note')}` : t('shopping.reminder.hint')));
</script>

<template>
    <div class="reminder">
        <UiField :label="t('shopping.reminder.label')" help="shopping.reminder" :hint="hint" :error="error">
            <UiInput type="datetime-local" :model-value="modelValue" :readonly="readonly" data-testid="reminder-input" @update:model-value="emit('update:modelValue', $event)" />
        </UiField>
        <UiButton v-if="!readonly && modelValue" class="reminder__remove" variant="ghost" :icon="BellOff" data-testid="reminder-remove" @click="emit('update:modelValue', '')">{{ t('shopping.reminder.remove') }}</UiButton>
        <UiAlert v-if="pushOffName" variant="info" data-testid="reminder-push-off">{{ t('shopping.reminder.push_off', { name: pushOffName }) }}</UiAlert>
    </div>
</template>

<style scoped>
.reminder { display: flex; flex-direction: column; gap: var(--space-2); margin-bottom: var(--space-4); }
.reminder :deep(.ui-field) { margin-bottom: 0; }
.reminder__remove { align-self: flex-start; }
</style>
