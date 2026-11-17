<script setup>
import { computed } from 'vue';

const props = defineProps({
    value: { type: Number, default: 0 },
    // Unknown duration: a moving bar without a value (aria-valuenow is left out). Still when the member prefers reduced motion.
    indeterminate: { type: Boolean, default: false },
    label: { type: String, required: true },
    max: { type: Number, default: 100 },
});
const pct = computed(() => Math.min(100, Math.max(0, (props.value / props.max) * 100)));
</script>

<template>
    <div :class="['ui-progress', { 'ui-progress--indeterminate': indeterminate }]" role="progressbar" :aria-valuenow="indeterminate ? undefined : value" :aria-valuemin="indeterminate ? undefined : 0" :aria-valuemax="indeterminate ? undefined : max" :aria-label="label">
        <div class="ui-progress__fill" :style="indeterminate ? undefined : { width: `${pct}%` }" />
    </div>
</template>

<style scoped>
.ui-progress { height: 8px; overflow: hidden; background: var(--color-surface-2); border-radius: var(--radius-full); }
.ui-progress__fill { height: 100%; background: var(--color-primary); border-radius: var(--radius-full); }
.ui-progress--indeterminate { position: relative; }
.ui-progress--indeterminate .ui-progress__fill { position: absolute; inset-block: 0; width: 40%; animation: ui-progress-slide 1.4s ease-in-out infinite; }
@keyframes ui-progress-slide { from { inset-inline-start: -40%; } to { inset-inline-start: 100%; } }
@media (prefers-reduced-motion: reduce) {
    .ui-progress--indeterminate .ui-progress__fill { animation: none; inset-inline-start: 30%; }
}
</style>
