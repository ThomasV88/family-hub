<script setup>
import { computed, ref, watch } from 'vue';
import { useAvatarUrl } from './useAvatarUrl.js';

const props = defineProps({
    name: { type: String, required: true },
    tint: { type: Number, default: 1, validator: (v) => v >= 1 && v <= 6 },
    size: { type: Number, default: 40, validator: (v) => [32, 40, 96].includes(v) },
    decorative: { type: Boolean, default: false },
    // The member whose photo to show (looked up in the shared avatars map), or an explicit photo URL (wins).
    userId: { type: Number, default: null },
    src: { type: String, default: null },
});

const initial = computed(() => {
    const first = Array.from(props.name.trim())[0] ?? '';
    return first.toLocaleUpperCase();
});

// Resolved once per mount; the avatars map changes only with a new page visit, which remounts the call sites
// that matter, and a changed userId or size recomputes below.
const url = computed(() => props.src ?? useAvatarUrl(props.userId, props.size));
const failed = ref(false);
watch(url, () => { failed.value = false; });
const showPhoto = computed(() => Boolean(url.value) && !failed.value);
</script>

<template>
    <span
        class="ui-avatar"
        :class="{ 'ui-avatar--large': size === 96 }"
        :style="{ '--ui-avatar-size': `${size}px`, background: `var(--avatar-${tint})` }"
        :role="decorative ? undefined : 'img'"
        :aria-label="decorative ? undefined : name"
        :aria-hidden="decorative ? 'true' : undefined"
    >
        <img v-if="showPhoto" class="ui-avatar__img" :src="url" alt="" loading="lazy" @error="failed = true" />
        <template v-else>{{ initial }}</template>
    </span>
</template>

<style scoped>
.ui-avatar {
    display: inline-flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: var(--ui-avatar-size);
    height: var(--ui-avatar-size);
    overflow: hidden;
    border-radius: var(--radius-full);
    color: var(--color-text);
    font-size: var(--text-sm);
    font-weight: 600;
    line-height: 1;
}
.ui-avatar--large { font-size: var(--text-2xl); }
.ui-avatar__img { width: 100%; height: 100%; object-fit: cover; }
</style>
