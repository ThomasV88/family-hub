<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import UiField from '../Components/ui/UiField.vue';
import UiSelect from '../Components/ui/UiSelect.vue';

const props = defineProps({
    // remind_before_minutes: 0 (at due time) | 15 | 60 | 1440 | null (no reminder)
    modelValue: { type: Number, default: 0 },
    hasDate: { type: Boolean, default: false },
    hasTime: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    error: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();

const OPTIONS = [
    { key: 'at_due', minutes: 0 },
    { key: 'before_15m', minutes: 15 },
    { key: 'before_1h', minutes: 60 },
    { key: 'before_1d', minutes: 1440 },
    { key: 'none', minutes: null },
];
const keyOf = (minutes) => OPTIONS.find((o) => o.minutes === (minutes ?? null))?.key ?? 'at_due';

const current = computed(() => (props.hasDate ? keyOf(props.modelValue) : 'at_due'));
const hint = computed(() => {
    if (!props.hasDate) return t('todo.reminder.need_date');
    if (!props.hasTime) return `${t('todo.reminder.need_time')} ${t('todo.reminder.hint')}`;
    return t('todo.reminder.hint');
});
const isDisabled = (o) => (o.minutes === 15 || o.minutes === 60) && !props.hasTime;
function pick(key) {
    emit('update:modelValue', OPTIONS.find((o) => o.key === key).minutes);
}
</script>

<template>
    <UiField :label="t('todo.reminder.label')" :hint="hint" :error="error">
        <UiSelect :model-value="current" :disabled="disabled || !hasDate" @update:model-value="pick">
            <option v-for="o in OPTIONS" :key="o.key" :value="o.key" :disabled="isDisabled(o)">{{ t(`todo.reminder.${o.key}`) }}</option>
        </UiSelect>
    </UiField>
</template>
