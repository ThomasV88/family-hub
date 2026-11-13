<script setup>
import { computed } from 'vue';

const props = defineProps({
    value: { type: Number, required: true },
    label: { type: String, required: true },
    max: { type: Number, default: 100 },
});
const pct = computed(() => Math.min(100, Math.max(0, (props.value / props.max) * 100)));
</script>

<template>
    <div class="ui-progress" role="progressbar" :aria-valuenow="value" aria-valuemin="0" :aria-valuemax="max" :aria-label="label">
        <div class="ui-progress__fill" :style="{ width: `${pct}%` }" />
    </div>
</template>

<style scoped>
.ui-progress { height: 8px; overflow: hidden; background: var(--color-surface-2); border-radius: var(--radius-full); }
.ui-progress__fill { height: 100%; background: var(--color-primary); border-radius: var(--radius-full); }
</style>
