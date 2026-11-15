<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Ban, Bot, Check, CircleX, Clock, Hourglass, Layers, Loader } from '@lucide/vue';
import UiTag from '../../Components/ui/UiTag.vue';
import { formatWhen, isPast } from './aiWhen.js';

// Status of one AI run as a tag: always an icon plus text, never colour alone. Model-free: it renders only the
// status word, never anything the AI produced.
const props = defineProps({
    status: { type: String, required: true },
    cancelReason: { type: String, default: null },
    entryCount: { type: Number, default: null },
    // Waiting runs: resume time (ISO) and, for tests, the clock. failureCode is accepted so callers can pass the
    // whole status entry; the quota-timeout difference is the detail line, the tag stays the Phase 8 one.
    availableAt: { type: String, default: null },
    failureCode: { type: String, default: null },
    now: { type: Date, default: null },
});
const { t, locale } = useI18n();
const appLocale = computed(() => ({ 'sk-SK': 'sk', 'pl-PL': 'pl', 'en-GB': 'en' }[locale.value] ?? locale.value));
const waitingWhen = computed(() => (props.availableAt && !isPast(props.availableAt, props.now ?? new Date()) ? formatWhen(props.availableAt, appLocale.value, undefined, props.now ?? new Date()) : null));

// The sentence is split around the time so the time itself can sit inside <time datetime>.
const whenParts = computed(() => {
    const [before = '', after = ''] = t('health.ai.status.waiting', { when: '\u0000' }).split('\u0000');
    return [before, after];
});

const view = computed(() => {
    switch (props.status) {
        case 'queued': return { icon: Clock, variant: 'neutral', text: t('health.ai.status.queued') };
        case 'running': return { icon: Loader, variant: 'neutral', text: t('health.ai.status.running'), spin: true };
        case 'ready':
            return props.entryCount === 0
                ? { icon: Check, variant: 'neutral', text: t('health.ai.status.ready_empty') }
                : { icon: Check, variant: 'success', text: t('health.ai.status.ready') };
        case 'waiting':
            if (!props.availableAt) return { icon: Hourglass, variant: 'neutral', text: t('health.ai.status.waiting_short') };
            return waitingWhen.value
                ? { icon: Hourglass, variant: 'neutral', when: waitingWhen.value, before: whenParts.value[0], after: whenParts.value[1] }
                : { icon: Hourglass, variant: 'neutral', text: t('health.ai.status.waiting_soon') };
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
    <UiTag v-if="view" :variant="view.variant" :icon="view.icon" :class="['ai-tag', { 'ai-tag--spin': view.spin }]" :data-status="status" data-testid="ai-status-tag"><span v-if="view.when" class="ai-tag__text">{{ view.before }}<time :datetime="availableAt">{{ view.when }}</time>{{ view.after }}</span><span v-else class="ai-tag__text">{{ view.text }}</span></UiTag>
</template>

<style scoped>
.ai-tag__text { min-width: 0; }
.ai-tag { white-space: normal; overflow-wrap: anywhere; max-width: 100%; }
/* The loader only turns when motion is allowed; otherwise it stays a static icon. */
@media (prefers-reduced-motion: no-preference) {
    .ai-tag--spin :deep(svg) { animation: ai-tag-spin 1.2s linear infinite; }
}
@keyframes ai-tag-spin { to { transform: rotate(360deg); } }
</style>
