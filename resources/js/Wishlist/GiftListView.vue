<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import UiAvatar from '../Components/ui/UiAvatar.vue';
import UiEmptyState from '../Components/ui/UiEmptyState.vue';
import GiftWishCard from './GiftWishCard.vue';

// The giver side of someone else's list. Plan 04-10 inserts ShoppingSummary above the context slot.
const props = defineProps({
    list: { type: Object, required: true },
    selectedId: { type: Number, default: null },
});
const emit = defineEmits(['open']);
const { t } = useI18n();

const name = computed(() => props.list.owner.name);
const wishes = computed(() => props.list.wishes ?? []);
</script>

<template>
    <div class="list-view" data-testid="gift-list">
        <slot name="context" />

        <header class="list-header">
            <UiAvatar :name="name" :size="40" decorative />
            <h2 class="list-header__title">{{ t('wishlist.list.title', { name }) }}</h2>
            <span class="list-header__count">{{ t('wishlist.list.count', { n: wishes.length }, wishes.length) }}</span>
        </header>

        <UiEmptyState v-if="wishes.length === 0" :title="t('wishlist.empty_other.title', { name })" :body="t('wishlist.empty_other.body')" :heading-level="3" />
        <ul v-else class="gallery" data-testid="gallery">
            <li v-for="wish in wishes" :key="wish.id">
                <GiftWishCard :wish="wish" :selected="wish.id === selectedId" @open="emit('open', $event)" />
            </li>
        </ul>
    </div>
</template>

<style scoped>
.list-view { container-type: inline-size; display: flex; flex-direction: column; gap: var(--space-4); min-width: 0; }
.list-header { display: flex; align-items: center; flex-wrap: wrap; gap: var(--space-3); }
.list-header__title { margin: 0; font-size: var(--text-xl); font-weight: 600; line-height: 1.2; overflow-wrap: anywhere; }
.list-header__count { color: var(--color-text-muted); }
.gallery { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-4); margin: 0; padding: 0; list-style: none; }
.gallery > li { min-width: 0; }
@container (max-width: 339px) {
    .gallery { grid-template-columns: minmax(0, 1fr); }
}
@container (min-width: 560px) {
    .gallery { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@container (min-width: 800px) {
    .gallery { grid-template-columns: repeat(4, minmax(0, 1fr)); }
}
</style>
