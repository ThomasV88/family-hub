<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import UiAvatar from '../Components/ui/UiAvatar.vue';
import UiProgress from '../Components/ui/UiProgress.vue';
import { formatMoney } from './money.js';

// Contributors and pledge progress, straight from the server contract. Sums are never computed here.
const props = defineProps({
    reservation: { type: Object, required: true },
    price: { type: String, default: null },
    currency: { type: String, default: 'EUR' },
});
const { t, locale } = useI18n();

const contributors = computed(() => props.reservation.contributors ?? []);
const money = (value) => formatMoney(value, props.currency, locale.value);
const hasProgress = computed(() => props.price !== null && props.price !== undefined && props.price !== '' && Number(props.price) > 0 && props.reservation.pledged !== null && props.reservation.pledged !== undefined);
const label = computed(() => t('wishlist.res.pledged', { sum: money(props.reservation.pledged), price: money(props.price) }));
</script>

<template>
    <div class="contributors">
        <ul class="contributors__list" data-testid="contributors">
            <li v-for="c in contributors" :key="c.id" class="contributors__row">
                <UiAvatar :name="c.name" :size="32" decorative class="contributors__avatar" />
                <span class="contributors__name">{{ c.name }}<span v-if="c.is_organizer" class="contributors__muted"> {{ t('wishlist.res.organizer') }}</span></span>
                <span v-if="c.amount !== null && c.amount !== undefined" class="contributors__amount">{{ money(c.amount) }}</span>
                <span v-else class="contributors__muted">{{ t('wishlist.res.no_amount') }}</span>
            </li>
        </ul>
        <div v-if="hasProgress" class="pledge" data-testid="pledge">
            <p class="pledge__text">{{ label }}</p>
            <UiProgress :value="Number(reservation.pledged)" :max="Number(price)" :label="label" />
        </div>
        <p v-else-if="price === null || price === undefined || price === ''" class="pledge__text" data-testid="people-in">{{ t('wishlist.res.people_in', { people: t('wishlist.people', { n: reservation.people }, reservation.people) }) }}</p>
    </div>
</template>

<style scoped>
.contributors { display: flex; flex-direction: column; gap: var(--space-3); min-width: 0; }
.contributors__list { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: var(--space-2); font-size: var(--text-sm); }
.contributors__row { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); min-width: 0; }
.contributors__name { flex: 1; min-width: 0; overflow-wrap: anywhere; }
.contributors__muted { color: var(--color-text-muted); }
.contributors__amount { font-weight: 600; }
.pledge { display: flex; flex-direction: column; gap: var(--space-1); }
.pledge__text { margin: 0; font-size: var(--text-sm); overflow-wrap: anywhere; }
.pledge :deep(.ui-progress__fill) { background: var(--color-border-strong); }
</style>
