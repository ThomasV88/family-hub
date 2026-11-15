<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Gift } from '@lucide/vue';
import UiProgress from '../Components/ui/UiProgress.vue';
import UiSkeleton from '../Components/ui/UiSkeleton.vue';
import { useI18n } from 'vue-i18n';

// Display only. The browser loads images from the app's authenticated image route (URLs from props) and never from a wish link.
const props = defineProps({
    image: { type: Object, default: () => ({ status: 'none' }) },
    size: { type: String, default: 'card', validator: (v) => ['card', 'panel'].includes(v) },
    // A File being uploaded: shown at once as a local preview with an indeterminate progress bar.
    uploading: { type: [Object, null], default: null },
});
const { t } = useI18n();

// Local preview of the file being uploaded; the object URL is revoked as soon as it is replaced and on unmount.
const preview = ref(null);
function revoke() {
    if (preview.value) URL.revokeObjectURL(preview.value);
    preview.value = null;
}
watch(() => props.uploading, (file) => {
    revoke();
    if (file && typeof URL.createObjectURL === 'function') preview.value = URL.createObjectURL(file);
}, { immediate: true });
onBeforeUnmount(revoke);

const src = computed(() => {
    if (props.image?.status !== 'ready') return null;
    const url = props.size === 'panel' ? (props.image.full_url ?? props.image.thumb_url) : props.image.thumb_url;
    return typeof url === 'string' && url !== '' ? url : null;
});
const pending = computed(() => props.image?.status === 'pending');
</script>

<template>
    <div :class="['wish-image', `wish-image--${size}`]">
        <template v-if="uploading">
            <img v-if="preview" :src="preview" alt="" class="wish-image__img" />
            <UiProgress :value="45" :label="t('wishlist.image.uploading')" class="wish-image__progress" />
        </template>
        <img v-else-if="src" :src="src" alt="" loading="lazy" decoding="async" class="wish-image__img" />
        <UiSkeleton v-else-if="pending" width="100%" height="100%" />
        <span v-else class="wish-image__none"><Gift :size="32" :stroke-width="1.75" aria-hidden="true" /></span>
    </div>
</template>

<style scoped>
.wish-image {
    position: relative;
    aspect-ratio: 4 / 3;
    width: 100%;
    overflow: hidden;
    background: var(--color-surface-2);
    border-radius: var(--radius-lg);
}
.wish-image--card { border-end-start-radius: 0; border-end-end-radius: 0; }
.wish-image__img { display: block; width: 100%; height: 100%; object-fit: cover; box-shadow: inset 0 0 0 1px var(--color-border); }
.wish-image__none { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; color: var(--color-text-muted); }
.wish-image__progress { position: absolute; inset-inline: var(--space-3); inset-block-end: var(--space-3); animation: wish-image-pulse 1.2s ease-in-out infinite; }
@keyframes wish-image-pulse { 50% { opacity: 0.45; } }
@media (prefers-reduced-motion: reduce) { .wish-image__progress { animation: none; } }
.wish-image :deep(.ui-skeleton) { width: 100%; height: 100%; border-radius: 0; }
</style>
