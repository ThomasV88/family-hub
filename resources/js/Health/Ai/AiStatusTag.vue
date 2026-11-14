<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Ban, Bot, Check, CircleX, Clock, Layers, Loader } from '@lucide/vue';
import UiTag from '../../Components/ui/UiTag.vue';

// Status of one AI run as a tag: always an icon plus text, never colour alone. Model-free: it renders only the
// status word, never anything the AI produced.
const props = defineProps({
    status: { type: String, required: true },
    cancelReason: { type: String, default: null },
    entryCount: { type: Number, default: null },
});
const { t } = useI18n();

const view = computed(() => {
    switch (props.status) {
        case 'queued': return { icon: Clock, variant: 'neutral', text: t('health.ai.status.queued') };
        case 'running': return { icon: Loader, variant: 'neutral', text: t('health.ai.status.running'), spin: true };
        case 'ready':
            return props.entryCount === 0
                ? { icon: Check, variant: 'neutral', text: t('health.ai.status.ready_empty') }
                : { icon: Check, variant: 'success', text: t('health.ai.status.ready') };
        case 'failed': return { icon: CircleX, variant: 'danger', text: t('health.ai.status.failed') };
        case 'cancelled':
            return props.cancelReason === 'consent'
                ? { icon: Bot, variant: 'neutral', text: t('health.ai.status.cancelled_consent') }
                : { icon: Ban, variant: 'neutral', text: t('health.ai.status.cancelled') };
        case 'superseded': return { icon: Layers, variant: 'neutral', text: t('health.ai.status.superseded') };
        default: return null;
    }
});
</script>

<template>
    <UiTag v-if="view" :variant="view.variant" :icon="view.icon" :class="['ai-tag', { 'ai-tag--spin': view.spin }]" :data-status="status" data-testid="ai-status-tag">{{ view.text }}</UiTag>
</template>

<style scoped>
.ai-tag { white-space: normal; overflow-wrap: anywhere; max-width: 100%; }
/* The loader only turns when motion is allowed; otherwise it stays a static icon. */
@media (prefers-reduced-motion: no-preference) {
    .ai-tag--spin :deep(svg) { animation: ai-tag-spin 1.2s linear infinite; }
}
@keyframes ai-tag-spin { to { transform: rotate(360deg); } }
</style>
