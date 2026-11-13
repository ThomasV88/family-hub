<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiTag from '../Components/ui/UiTag.vue';
import { formatYmd, occasionIcon, occasionTypeKey } from './occasionIcons.js';

// The next few family occasions above the gallery. Server-supplied, reservation-blind; names and titles render as text only.
const props = defineProps({ occasions: { type: Array, default: () => [] } });
const { t, locale } = useI18n();

const label = (o) => {
    const name = o.owner?.name ?? '';
    if (o.type === 'birthday') return t('occasions.birthday', { name });
    if (o.type === 'nameday') return t('occasions.nameday', { name });
    if (o.type === 'christmas') return t('occasions.christmas');
    return o.title ?? '';
};
const away = (o) => {
    if (o.days_away <= 0) return t('occasions.today');
    if (o.days_away === 1) return t('occasions.tomorrow');
    return t('occasions.in_days', { n: o.days_away }, o.days_away);
};

const items = computed(() => props.occasions.slice(0, 3).map((o) => ({
    key: o.key,
    icon: occasionIcon(o.type),
    typeLabel: t(occasionTypeKey(o.type)),
    text: label(o),
    date: o.date,
    dateText: formatYmd(o.date, locale.value),
    away: away(o),
    warn: o.days_away <= 3,
})));
</script>

<template>
    <div class="occasion-strip" data-testid="occasion-strip">
        <ul v-if="items.length" class="occasion-strip__list">
            <li v-for="item in items" :key="item.key" class="occasion-strip__pill" data-testid="occasion-pill">
                <component :is="item.icon" :size="20" :stroke-width="1.75" role="img" :aria-label="item.typeLabel" />
                <span class="occasion-strip__text">{{ item.text }}</span>
                <time class="occasion-strip__date" :datetime="item.date">{{ item.dateText }}</time>
                <UiTag :variant="item.warn ? 'warn' : 'neutral'">{{ item.away }}</UiTag>
            </li>
        </ul>
        <Link href="/wishlist/occasions" class="occasion-strip__all">{{ t('occasions.all') }}</Link>
    </div>
</template>

<style scoped>
.occasion-strip { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2) var(--space-3); min-width: 0; }
.occasion-strip__list { display: flex; flex-wrap: wrap; gap: var(--space-2); margin: 0; padding: 0; list-style: none; min-width: 0; }
.occasion-strip__pill {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-1) var(--space-2);
    max-width: 100%;
    min-width: 0;
    padding: var(--space-1) var(--space-3);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-full);
    background: var(--color-surface);
    font-size: var(--text-sm);
}
.occasion-strip__text { font-weight: 600; overflow-wrap: anywhere; min-width: 0; }
.occasion-strip__date { color: var(--color-text-muted); }
.occasion-strip__all { display: inline-flex; align-items: center; min-height: 44px; color: var(--color-link, var(--color-primary)); font-size: var(--text-sm); font-weight: 600; text-decoration: underline; }
.occasion-strip__all:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 1px; border-radius: var(--radius-md); }
</style>
