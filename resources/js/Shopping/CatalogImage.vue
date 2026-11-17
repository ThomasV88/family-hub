<script setup>
import { computed, ref, watch } from 'vue';
import { ShoppingBasket, Store } from '@lucide/vue';

// Display only: the picture of a catalog item or the logo of a store, loaded from the authenticated image route.
// Decorative (the name is always shown beside it). Without a picture, or when it fails to load, a placeholder icon shows.
const props = defineProps({
    kind: { type: String, default: 'catalog', validator: (v) => ['catalog', 'store'].includes(v) },
    id: { type: Number, default: null },
    // Version of the stored picture, or null when there is none (used as a cache-buster).
    version: { type: Number, default: null },
    size: { type: Number, default: 48 },
    variant: { type: String, default: 'thumb', validator: (v) => ['thumb', 'full'].includes(v) },
});

const failed = ref(false);
watch(() => [props.id, props.version], () => { failed.value = false; });

const src = computed(() => {
    if (props.id === null || props.version === null || props.version === undefined || failed.value) return null;

    return `/shopping/${props.kind === 'store' ? 'stores' : 'catalog'}/${props.id}/image/${props.variant}?v=${props.version}`;
});
const px = computed(() => `${props.size}px`);
</script>

<template>
    <span :class="['catalog-image', `catalog-image--${kind}`]" :style="{ width: px, height: px }">
        <img v-if="src" :src="src" alt="" loading="lazy" decoding="async" class="catalog-image__img" @error="failed = true" />
        <component :is="kind === 'store' ? Store : ShoppingBasket" v-else :size="Math.round(size / 2)" :stroke-width="1.75" aria-hidden="true" class="catalog-image__none" />
    </span>
</template>

<style scoped>
.catalog-image {
    display: inline-flex;
    flex: none;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    border-radius: var(--radius-md);
    background: var(--color-surface-2);
    box-shadow: inset 0 0 0 1px var(--color-border);
}
.catalog-image__img { display: block; width: 100%; height: 100%; object-fit: cover; }
/* Logos are shown whole, on the surface colour. */
.catalog-image--store .catalog-image__img { object-fit: contain; }
.catalog-image__none { color: var(--color-text-muted); }
</style>
