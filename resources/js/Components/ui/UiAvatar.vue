<script setup>
import { computed } from 'vue';

const props = defineProps({
    name: { type: String, required: true },
    tint: { type: Number, default: 1, validator: (v) => v >= 1 && v <= 6 },
    size: { type: Number, default: 40, validator: (v) => [32, 40].includes(v) },
    decorative: { type: Boolean, default: false },
});

const initial = computed(() => {
    const first = Array.from(props.name.trim())[0] ?? '';
    return first.toLocaleUpperCase();
});
</script>

<template>
    <span
        class="ui-avatar"
        :style="{ '--ui-avatar-size': `${size}px`, background: `var(--avatar-${tint})` }"
        :role="decorative ? undefined : 'img'"
        :aria-label="decorative ? undefined : name"
        :aria-hidden="decorative ? 'true' : undefined"
    >{{ initial }}</span>
</template>

<style scoped>
.ui-avatar {
    display: inline-flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: var(--ui-avatar-size);
    height: var(--ui-avatar-size);
    border-radius: var(--radius-full);
    color: var(--color-text);
    font-size: var(--text-sm);
    font-weight: 600;
    line-height: 1;
}
</style>
