<script setup>
import { computed, ref } from 'vue';
import { Archive, ChevronDown, Plus } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiEmptyState from '../Components/ui/UiEmptyState.vue';
import OwnWishCard from './OwnWishCard.vue';

// The recipient side of a list (owner or designated manager). Its props are the list and the selected wish id and
// nothing else: there is deliberately no slot, prop or branch here where giver data could be shown.
const props = defineProps({
    list: { type: Object, required: true },
    selectedId: { type: Number, default: null },
});
const emit = defineEmits(['open', 'add']);
const { t } = useI18n();

const BANNER_KEY = 'fh-wishlist-own-banner';
const readDismissed = () => {
    try {
        return sessionStorage.getItem(BANNER_KEY) === '1';
    } catch {
        return false;
    }
};
const dismissed = ref(readDismissed());
function dismissBanner() {
    dismissed.value = true;
    try {
        sessionStorage.setItem(BANNER_KEY, '1');
    } catch {
        // storage unavailable: the banner just returns next visit
    }
}

const name = computed(() => props.list.owner.name);
const wishes = computed(() => props.list.wishes ?? []);
const received = computed(() => props.list.received ?? []);
const managing = computed(() => props.list.viewer_is_manager === true);
const empty = computed(() => wishes.value.length === 0 && received.value.length === 0);
const receivedOpen = ref(false);
</script>

<template>
    <div class="list-view" data-testid="own-list">
        <slot name="context" />

        <UiAlert v-if="managing" variant="info" class="list-view__block">{{ t('wishlist.banner.managing', { name }) }}</UiAlert>
        <UiAlert v-else-if="!dismissed" variant="info" dismissible class="list-view__block" @dismiss="dismissBanner">{{ t('wishlist.banner.own') }}</UiAlert>
        <p v-if="!list.can_edit" class="list-view__hint">{{ t('wishlist.viewonly') }}</p>

        <UiEmptyState v-if="empty" :title="t('wishlist.empty_own.title')" :body="t('wishlist.empty_own.body')" :heading-level="2">
            <template v-if="list.can_edit" #action>
                <UiButton variant="primary" :icon="Plus" @click="emit('add')">{{ managing ? t('wishlist.add_for', { name }) : t('wishlist.add') }}</UiButton>
            </template>
        </UiEmptyState>
        <ul v-else class="gallery" data-testid="gallery">
            <li v-if="list.can_edit">
                <button type="button" class="gallery__add" data-testid="add-tile" @click="emit('add')">
                    <Plus :size="20" :stroke-width="1.75" aria-hidden="true" />
                    {{ managing ? t('wishlist.add_for', { name }) : t('wishlist.add') }}
                </button>
            </li>
            <li v-for="wish in wishes" :key="wish.id">
                <OwnWishCard :wish="wish" :selected="wish.id === selectedId" @open="emit('open', $event)" />
            </li>
        </ul>

        <section v-if="received.length > 0" class="received">
            <h2 class="received__heading">
                <button type="button" class="received__toggle" :aria-expanded="receivedOpen ? 'true' : 'false'" aria-controls="wishlist-received" @click="receivedOpen = !receivedOpen">
                    <Archive :size="20" :stroke-width="1.75" aria-hidden="true" />
                    {{ receivedOpen ? t('wishlist.received.hide', { n: received.length }) : t('wishlist.received.show', { n: received.length }) }}
                    <ChevronDown :size="20" :stroke-width="1.75" aria-hidden="true" :class="{ 'is-open': receivedOpen }" />
                </button>
            </h2>
            <ul v-show="receivedOpen" id="wishlist-received" class="gallery">
                <li v-for="wish in received" :key="wish.id">
                    <OwnWishCard :wish="wish" :selected="wish.id === selectedId" @open="emit('open', $event)" />
                </li>
            </ul>
        </section>
    </div>
</template>

<style scoped>
.list-view { container-type: inline-size; display: flex; flex-direction: column; gap: var(--space-4); min-width: 0; }
.list-view__hint { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.gallery { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-4); margin: 0; padding: 0; list-style: none; }
.gallery > li { min-width: 0; }
.gallery__add {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
    width: 100%;
    height: 100%;
    min-height: 160px;
    padding: var(--space-3);
    background: transparent;
    border: 1px dashed var(--color-border-strong);
    border-radius: var(--radius-lg);
    color: var(--color-text);
    font: inherit;
    font-weight: 600;
    cursor: pointer;
}
.gallery__add:hover { background: var(--color-surface-2); }
.gallery__add:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.received__heading { margin: 0 0 var(--space-3); font-size: var(--text-base); }
.received__toggle { display: flex; align-items: center; gap: var(--space-2); min-height: 44px; padding: 0; border: 0; background: transparent; color: var(--color-text); font: inherit; font-weight: 600; cursor: pointer; }
.received__toggle:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.received__toggle .is-open { transform: rotate(180deg); }
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
