<script setup>
import { computed } from 'vue';
import { Check, Gift, Link, Star, Users } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiTag from '../Components/ui/UiTag.vue';
import WishImage from './WishImage.vue';
import { formatMoney } from './money.js';

// Reads only the contract keys of a wish; nothing else from the object reaches the DOM.
const props = defineProps({
    wish: { type: Object, required: true },
    selected: { type: Boolean, default: false },
});
defineEmits(['open']);
const { t, locale } = useI18n();

const price = computed(() => formatMoney(props.wish.price, props.wish.currency, locale.value));
const linkCount = computed(() => (Array.isArray(props.wish.urls) ? props.wish.urls.length : 0));
const isTop = computed(() => props.wish.priority === 'top');

// Exactly one status tag per giver card: available, reserved by you, co-buy, or reserved by name.
const status = computed(() => {
    const r = props.wish.reservation ?? null;
    if (!r) return { variant: 'success', icon: null, text: t('wishlist.status.available'), strong: false };
    if (r.is_mine) return { variant: 'neutral', icon: Check, text: t('wishlist.status.reserved_you'), strong: true };
    if (r.cobuy_state === 'open') return { variant: 'neutral', icon: Users, text: t('wishlist.status.cobuy', { people: t('wishlist.people', { n: r.people }, r.people) }), strong: false };
    return { variant: 'neutral', icon: Gift, text: t('wishlist.status.reserved_by', { name: r.organizer?.name ?? '' }), strong: false };
});
</script>

<template>
    <button type="button" :class="['wish-card', { 'is-selected': selected }]" :aria-label="wish.title" :aria-current="selected ? 'true' : undefined" @click="$emit('open', wish.id)">
        <WishImage :image="wish.image" size="card" />
        <span class="wish-card__body">
            <span class="wish-card__title">{{ wish.title }}</span>
            <span v-if="price" class="wish-card__price">{{ price }}</span>
            <span class="wish-card__status" data-testid="card-status"><UiTag :variant="status.variant" :icon="status.icon" :class="{ 'is-strong': status.strong }">{{ status.text }}</UiTag></span>
            <span v-if="linkCount >= 1 || isTop || wish.received" class="wish-card__meta">
                <span v-if="linkCount >= 1" class="wish-card__links"><Link :size="20" :stroke-width="1.75" aria-hidden="true" />{{ t('wishlist.card.links', { n: linkCount }, linkCount) }}</span>
                <UiTag v-if="isTop" :icon="Star">{{ t('wishlist.priority.top') }}</UiTag>
                <UiTag v-if="wish.received" variant="success" :icon="Check">{{ t('wishlist.received.tag') }}</UiTag>
            </span>
        </span>
    </button>
</template>

<style scoped>
.wish-card {
    display: flex;
    flex-direction: column;
    width: 100%;
    height: 100%;
    padding: 0;
    overflow: hidden;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    color: var(--color-text);
    font: inherit;
    text-align: start;
    cursor: pointer;
}
.wish-card:hover { background: var(--color-surface-2); }
.wish-card.is-selected { background: var(--color-surface-2); border: 2px solid var(--color-border-strong); }
.wish-card:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.wish-card__body { display: flex; flex-direction: column; gap: var(--space-2); padding: var(--space-3); min-width: 0; }
.wish-card__title {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    overflow: hidden;
    font-weight: 600;
    overflow-wrap: anywhere;
}
.wish-card__status { display: flex; min-width: 0; min-height: 1.75rem; }
.wish-card__status :deep(.ui-tag) { max-width: 100%; overflow: hidden; text-overflow: ellipsis; }
.wish-card__status :deep(.ui-tag.is-strong) { font-weight: 700; }
.wish-card__price { font-size: var(--text-sm); font-weight: 600; }
.wish-card__meta { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); font-size: var(--text-sm); color: var(--color-text-muted); }
.wish-card__links { display: inline-flex; align-items: center; gap: var(--space-1); }
@media (pointer: coarse) {
    .wish-card { min-height: var(--control-touch); }
}
</style>
