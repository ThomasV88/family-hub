<script setup>
import { computed, ref, watch } from 'vue';
import { ChefHat, ChevronLeft, ChevronRight } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../ui/UiButton.vue';
import UiDialog from '../ui/UiDialog.vue';

// Photos of the detail: the main photo first, a thumbnail strip, previous/next buttons with "{i} of {n}", swipe on touch screens
// and a full-screen viewer. `compact` (cooking mode) hides the strip. Images come from the authenticated photo routes.
const props = defineProps({
    photos: { type: Array, default: () => [] },
    title: { type: String, required: true },
    compact: { type: Boolean, default: false },
});
const { t } = useI18n();

const SWIPE_PX = 40;
const ordered = computed(() => [...props.photos].sort((a, b) => (Number(b.isMain) - Number(a.isMain)) || (a.position - b.position)));
const index = ref(0);
const viewer = ref(false);
const current = computed(() => ordered.value[index.value] ?? null);
const many = computed(() => ordered.value.length > 1);
watch(ordered, (list) => { if (index.value >= list.length) index.value = 0; });

const go = (step) => {
    const count = ordered.value.length;
    index.value = (index.value + step + count) % count;
};

let startX = null;
const onTouchStart = (event) => { startX = event.changedTouches?.[0]?.clientX ?? null; };
function onTouchEnd(event) {
    if (startX === null || !many.value) return;
    const delta = (event.changedTouches?.[0]?.clientX ?? startX) - startX;
    startX = null;
    if (Math.abs(delta) >= SWIPE_PX) go(delta < 0 ? 1 : -1);
}
</script>

<template>
    <div class="gallery" data-testid="recipe-gallery">
        <div v-if="!current" class="gallery__placeholder" data-testid="gallery-placeholder" aria-hidden="true"><ChefHat :size="32" :stroke-width="1.5" /></div>
        <template v-else>
            <div class="gallery__hero" @touchstart.passive="onTouchStart" @touchend.passive="onTouchEnd">
                <button type="button" class="gallery__open" :aria-label="t('recipes.gallery.open')" data-testid="gallery-open" @click="viewer = true">
                    <img :src="current.fullUrl" :alt="title" class="gallery__img" data-testid="gallery-hero" />
                </button>
                <template v-if="many">
                    <UiButton class="gallery__nav gallery__nav--prev" variant="secondary" :icon="ChevronLeft" icon-only :label="t('recipes.gallery.previous')" data-testid="gallery-prev" @click="go(-1)" />
                    <UiButton class="gallery__nav gallery__nav--next" variant="secondary" :icon="ChevronRight" icon-only :label="t('recipes.gallery.next')" data-testid="gallery-next" @click="go(1)" />
                </template>
            </div>
            <p v-if="many" class="gallery__pos" aria-live="polite" data-testid="gallery-position">{{ t('recipes.gallery.position', { i: index + 1, n: ordered.length }) }}</p>
            <ul v-if="many && !compact" class="gallery__strip" role="list" data-testid="gallery-strip">
                <li v-for="(photo, i) in ordered" :key="photo.id">
                    <button type="button" :class="['gallery__thumb', { 'is-active': i === index }]" :aria-label="t('recipes.gallery.show', { i: i + 1 })" :aria-current="i === index ? 'true' : undefined" @click="index = i">
                        <img :src="photo.thumbUrl" alt="" loading="lazy" />
                    </button>
                </li>
            </ul>
        </template>

        <UiDialog v-if="current" :open="viewer" :title="title" @update:open="(v) => (viewer = v)">
            <div class="gallery__viewer" @touchstart.passive="onTouchStart" @touchend.passive="onTouchEnd">
                <img :src="current.fullUrl" :alt="title" class="gallery__full" data-testid="gallery-full" />
            </div>
            <template #footer>
                <UiButton v-if="many" variant="secondary" :icon="ChevronLeft" icon-only :label="t('recipes.gallery.previous')" @click="go(-1)" />
                <span v-if="many" class="gallery__pos">{{ t('recipes.gallery.position', { i: index + 1, n: ordered.length }) }}</span>
                <UiButton v-if="many" variant="secondary" :icon="ChevronRight" icon-only :label="t('recipes.gallery.next')" @click="go(1)" />
            </template>
        </UiDialog>
    </div>
</template>

<style scoped>
.gallery { display: flex; flex-direction: column; gap: var(--space-2); max-width: 360px; width: 100%; }
.gallery__placeholder { display: flex; align-items: center; justify-content: center; aspect-ratio: 16 / 10; background: var(--color-surface-2); color: var(--color-text-muted); border-radius: var(--radius-md); }
.gallery__hero { position: relative; aspect-ratio: 16 / 10; border-radius: var(--radius-md); overflow: hidden; background: var(--color-surface-2); }
.gallery__open { display: block; width: 100%; height: 100%; padding: 0; border: 0; background: none; cursor: zoom-in; }
.gallery__open:focus-visible { outline: 2px solid var(--color-focus); outline-offset: -2px; }
.gallery__img { display: block; width: 100%; height: 100%; object-fit: cover; }
.gallery__nav { position: absolute; inset-block-start: 50%; transform: translateY(-50%); min-width: var(--control-touch); min-height: var(--control-touch); }
.gallery__nav--prev { inset-inline-start: var(--space-2); }
.gallery__nav--next { inset-inline-end: var(--space-2); }
.gallery__pos { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); text-align: center; }
.gallery__strip { display: flex; gap: var(--space-2); margin: 0; padding: 0; list-style: none; overflow-x: auto; }
.gallery__thumb { display: block; width: 64px; height: 64px; padding: 0; border: 2px solid transparent; border-radius: var(--radius-sm); overflow: hidden; background: var(--color-surface-2); cursor: pointer; }
.gallery__thumb.is-active { border-color: var(--color-primary); }
.gallery__thumb:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.gallery__thumb img { display: block; width: 100%; height: 100%; object-fit: cover; }
.gallery__viewer { touch-action: pinch-zoom; }
.gallery__full { display: block; width: 100%; max-height: 70vh; object-fit: contain; }
@media (max-width: 639px) { .gallery { max-width: none; } .gallery__hero, .gallery__placeholder { max-height: 240px; } }
</style>
