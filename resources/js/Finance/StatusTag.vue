<script setup>
import { computed } from 'vue';
import { AlertCircle, AlertTriangle, Check, Clock } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiTag from '../Components/ui/UiTag.vue';

// Bill status as text plus icon (never colour alone). The status itself is derived on the server.
const props = defineProps({
    // 'upcoming' | 'paid' | 'overdue' | 'paid_differently'
    status: { type: String, required: true },
});
const { t } = useI18n();

const SPEC = {
    upcoming: { variant: 'neutral', icon: Clock, key: 'finance.status.upcoming' },
    paid: { variant: 'success', icon: Check, key: 'finance.status.paid' },
    overdue: { variant: 'danger', icon: AlertCircle, key: 'finance.status.overdue' },
    paid_differently: { variant: 'warn', icon: AlertTriangle, key: 'finance.status.paid_different' },
};
const spec = computed(() => SPEC[props.status] ?? SPEC.upcoming);
</script>

<template>
    <UiTag :variant="spec.variant" :icon="spec.icon" :data-status="status">{{ t(spec.key) }}</UiTag>
</template>
