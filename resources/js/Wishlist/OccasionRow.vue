<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiListRow from '../Components/ui/UiListRow.vue';
import UiTag from '../Components/ui/UiTag.vue';
import { formatYmd, occasionIcon, occasionTypeKey } from './occasionIcons.js';

// One upcoming occasion. Names and titles are user content and are only ever rendered as text.
const props = defineProps({
    occasion: { type: Object, required: true },
});
const { t, locale } = useI18n();

const name = computed(() => props.occasion.owner?.name ?? '');
const title = computed(() => {
    const o = props.occasion;
    if (o.type === 'birthday') return t('occasions.birthday', { name: name.value });
    if (o.type === 'nameday') return t('occasions.nameday', { name: name.value });
    if (o.type === 'christmas') return t('occasions.christmas');
    return o.title ?? '';
});
const date = computed(() => formatYmd(props.occasion.date, locale.value));
const away = computed(() => {
    const n = props.occasion.days_away;
    if (n <= 0) return t('occasions.today');
    if (n === 1) return t('occasions.tomorrow');
    return t('occasions.in_days', { n }, n);
});
const warn = computed(() => props.occasion.days_away <= 3);
const icon = computed(() => occasionIcon(props.occasion.type));
const href = computed(() => (props.occasion.owner ? `/wishlist?member=${props.occasion.owner.id}` : '/wishlist'));
const linkText = computed(() => (props.occasion.owner ? t('occasions.open_list', { name: name.value }) : t('occasions.open_lists')));
</script>

<template>
    <UiListRow class="occasion-row">
        <template #leading>
            <component :is="icon" :size="20" :stroke-width="1.75" role="img" :aria-label="t(occasionTypeKey(occasion.type))" />
        </template>
        {{ title }}
        <template #meta>
            <time :datetime="occasion.date">{{ date }}</time>
        </template>
        <template #trailing>
            <span class="occasion-row__end">
                <UiTag :variant="warn ? 'warn' : 'neutral'"><time :datetime="occasion.date">{{ away }}</time></UiTag>
                <Link :href="href" class="occasion-row__link">{{ linkText }}</Link>
            </span>
        </template>
    </UiListRow>
</template>

<style scoped>
.occasion-row { min-height: 56px; }
.occasion-row__end { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: var(--space-2) var(--space-3); min-width: 0; }
.occasion-row__link { display: inline-flex; align-items: center; min-height: 44px; color: var(--color-link, var(--color-primary)); font-size: var(--text-sm); text-decoration: underline; overflow-wrap: anywhere; }
</style>
