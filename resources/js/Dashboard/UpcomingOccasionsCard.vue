<script setup>
import { computed, useId } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiCard from '../Components/ui/UiCard.vue';
import UiList from '../Components/ui/UiList.vue';
import UiListRow from '../Components/ui/UiListRow.vue';
import UiTag from '../Components/ui/UiTag.vue';
import { formatYmd, occasionIcon, occasionTypeKey } from '../Wishlist/occasionIcons.js';

// occasions is the server prop (up to 3, reservation-blind). Names and titles are user content: text only.
const props = defineProps({ occasions: { type: Array, default: () => [] } });
const { t, locale } = useI18n();
const headingId = `uo-${useId()}`;

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
const rows = computed(() => props.occasions.slice(0, 3).map((o) => ({
    key: o.key,
    icon: occasionIcon(o.type),
    typeLabel: t(occasionTypeKey(o.type)),
    text: label(o),
    date: o.date,
    dateText: formatYmd(o.date, locale.value),
    away: away(o),
    warn: o.days_away <= 3,
})));

// Plain anchors keep the links usable without script; a normal click navigates through Inertia.
function go(event, href) {
    if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button > 0) return;
    event.preventDefault();
    router.visit(href);
}
</script>

<template>
    <UiCard :title="t('occasions.card.title')" :heading-level="2" :title-id="headingId" data-testid="upcoming-occasions">
        <template v-if="rows.length" #action>
            <a href="/wishlist" class="uo__open" :aria-describedby="headingId" @click="go($event, '/wishlist')">{{ t('occasions.card.open') }}</a>
        </template>
        <UiList v-if="rows.length">
            <UiListRow v-for="row in rows" :key="row.key">
                <template #leading>
                    <component :is="row.icon" :size="20" :stroke-width="1.75" role="img" :aria-label="row.typeLabel" />
                </template>
                {{ row.text }}
                <template #meta>
                    <time :datetime="row.date">{{ row.dateText }}</time>
                </template>
                <template #trailing>
                    <UiTag :variant="row.warn ? 'warn' : 'neutral'">{{ row.away }}</UiTag>
                </template>
            </UiListRow>
        </UiList>
        <p v-else class="uo__none" data-testid="upcoming-occasions-none">
            {{ t('occasions.card.none') }}
            <a href="/wishlist/occasions" class="uo__open" @click="go($event, '/wishlist/occasions')">{{ t('occasions.card.open_occasions') }}</a>
        </p>
    </UiCard>
</template>

<style scoped>
.uo__open {
    display: inline-flex;
    align-items: center;
    min-height: 44px;
    padding: 0 var(--space-2);
    color: var(--color-primary);
    font-weight: 600;
    text-decoration: none;
}
.uo__open:hover { text-decoration: underline; }
.uo__open:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 1px; border-radius: var(--radius-md); }
.uo__none { margin: var(--space-3) 0 0; color: var(--color-text-muted); font-size: var(--text-sm); }
</style>
