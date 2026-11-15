<script setup>
import { Table2, ChartLine } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../../Components/ui/UiButton.vue';

// Secondary toggle between a chart and its exact table alternative.
defineProps({ modelValue: { type: Boolean, default: false } });
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();
</script>

<template>
    <UiButton variant="secondary" size="sm" :icon="modelValue ? ChartLine : Table2" :aria-pressed="modelValue ? 'true' : 'false'" @click="emit('update:modelValue', !modelValue)">
        {{ modelValue ? t('finance.chart.view_chart') : t('finance.chart.view_table') }}
    </UiButton>
</template>
