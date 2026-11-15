<script setup>
import { computed, onMounted, ref, useId } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ShoppingBag } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../Components/ui/UiButton.vue';
import UiCard from '../Components/ui/UiCard.vue';
import { formatMoney } from './money.js';

// "Your shopping": the viewer's own reservations on other people's lists. Data comes from the JSON endpoint
// GET /wishlist/shopping (never from page props). Renders nothing when empty or when the request fails.
const { t, locale } = useI18n();
const groups = ref([]);
const expanded = ref(false);
const uid = useId();
const regionId = `shopping-${uid}`;

onMounted(async () => {
    try {
        const response = await fetch('/wishlist/shopping', {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) return;
        const body = await response.json();
        groups.value = Array.isArray(body?.groups) ? body.groups.filter((g) => Array.isArray(g.items) && g.items.length > 0) : [];
    } catch {
        groups.value = [];
    }
});

const visible = computed(() => groups.value.length > 0);
const money = (value, currency) => formatMoney(value, currency, locale.value);
const href = (owner, item) => `/wishlist?member=${owner.id}&wish=${item.wish_id}`;
const share = (item) => (item.cobuy_state && item.cobuy_state !== 'none' && item.my_amount ? t('wishlist.shopping.share', { amount: money(item.my_amount, item.currency) }) : '');
</script>

<template>
    <UiCard v-if="visible" class="shopping" data-testid="shopping-summary">
        <div class="shopping__head">
            <h3 class="shopping__title"><ShoppingBag :size="20" :stroke-width="1.75" aria-hidden="true" />{{ t('wishlist.shopping.title') }}</h3>
            <UiButton variant="ghost" size="sm" :aria-expanded="expanded ? 'true' : 'false'" :aria-controls="regionId" @click="expanded = !expanded">{{ expanded ? t('wishlist.shopping.hide') : t('wishlist.shopping.show') }}</UiButton>
        </div>
        <div v-show="expanded" :id="regionId" class="shopping__body">
            <section v-for="group in groups" :key="group.owner.id" class="shopping__group">
                <h4 class="shopping__owner">{{ group.owner.name }}</h4>
                <ul class="shopping__items">
                    <li v-for="item in group.items" :key="item.wish_id" class="shopping__item">
                        <Link :href="href(group.owner, item)" class="shopping__link">{{ item.title }}</Link>
                        <span v-if="money(item.price, item.currency)" class="shopping__meta">{{ money(item.price, item.currency) }}</span>
                        <span v-if="share(item)" class="shopping__meta">{{ share(item) }}</span>
                    </li>
                </ul>
            </section>
        </div>
    </UiCard>
</template>

<style scoped>
.shopping__head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: var(--space-2); }
.shopping__title { display: inline-flex; align-items: center; gap: var(--space-2); margin: 0; font-size: var(--text-base); font-weight: 600; }
.shopping__body { display: flex; flex-direction: column; gap: var(--space-3); margin-top: var(--space-3); }
.shopping__group { display: flex; flex-direction: column; gap: var(--space-1); }
.shopping__owner { margin: 0; font-size: var(--text-sm); font-weight: 600; color: var(--color-text-muted); overflow-wrap: anywhere; }
.shopping__items { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; }
.shopping__item { display: flex; flex-wrap: wrap; align-items: baseline; gap: var(--space-1) var(--space-3); min-height: 44px; padding-block: var(--space-2); align-content: center; }
.shopping__link { color: var(--color-primary); overflow-wrap: anywhere; }
.shopping__link:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; border-radius: var(--radius-sm); }
.shopping__meta { font-size: var(--text-sm); color: var(--color-text-muted); }
</style>
