<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Bell, ChevronLeft } from '@lucide/vue';
import UiAvatar from '../Components/ui/UiAvatar.vue';
import UiTag from '../Components/ui/UiTag.vue';
import CatalogImage from './CatalogImage.vue';
import { formatListDate, reminderTag, statusTag } from './listStatus.js';

// Header of the list page: back link, name, state tags and the meta line (store, responsible person, reminder).
// list: the `list` prop of Shopping/Show. The action area is the default slot.
const props = defineProps({ list: { type: Object, required: true } });
const { t, locale } = useI18n();

const tag = computed(() => statusTag(props.list.status));
const warn = computed(() => reminderTag(props.list));
const reminder = computed(() => (props.list.remindAt ? formatListDate(props.list.remindAt, locale.value, { time: true }) : ''));
const storeName = computed(() => props.list.store?.name ?? props.list.storeName ?? '');
</script>

<template>
    <header class="shop-head">
        <Link href="/shopping" class="shop-head__back"><ChevronLeft :size="20" :stroke-width="1.75" aria-hidden="true" />{{ t('shopping.overview.title') }}</Link>
        <div class="shop-head__row">
            <div class="shop-head__main">
                <h1 class="shop-head__title">{{ list.name }}</h1>
                <div class="shop-head__tags">
                    <UiTag :icon="tag.icon" :variant="tag.variant">{{ t(tag.key) }}</UiTag>
                    <UiTag v-if="warn" :variant="warn.variant">{{ t(warn.key) }}</UiTag>
                </div>
                <div class="shop-head__meta">
                    <span v-if="storeName" class="shop-head__meta-item" data-testid="header-store">
                        <CatalogImage v-if="list.store" kind="store" :id="list.store.id" :version="list.store.logoVersion" :size="40" />
                        <span>{{ storeName }}</span>
                    </span>
                    <span class="shop-head__meta-item" data-testid="header-responsible">
                        <UiAvatar :name="list.responsible.name" :user-id="list.responsible.id" :size="32" decorative />
                        <span>{{ t('shopping.overview.responsible', { name: list.responsible.name }) }}</span>
                    </span>
                    <span v-if="reminder" class="shop-head__meta-item" data-testid="header-reminder">
                        <Bell :size="18" :stroke-width="1.75" aria-hidden="true" />
                        <span>{{ t('shopping.detail.reminder', { date: reminder }) }}</span>
                    </span>
                </div>
            </div>
            <div v-if="$slots.default" class="shop-head__actions"><slot /></div>
        </div>
    </header>
</template>

<style scoped>
.shop-head { display: flex; flex-direction: column; gap: var(--space-2); }
.shop-head__back { display: inline-flex; align-items: center; align-self: flex-start; gap: var(--space-1); min-height: 44px; color: var(--color-text-muted); font-size: var(--text-sm); font-weight: 600; text-decoration: none; }
.shop-head__back:hover { color: var(--color-text); }
.shop-head__back:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; border-radius: var(--radius-sm); }
.shop-head__row { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: var(--space-4); }
.shop-head__main { display: flex; flex: 1 1 320px; flex-direction: column; gap: var(--space-2); min-width: 0; }
.shop-head__title { margin: 0; font-size: var(--text-2xl); font-weight: 600; line-height: 1.2; overflow-wrap: anywhere; }
.shop-head__tags { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.shop-head__meta { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2) var(--space-4); color: var(--color-text-muted); font-size: var(--text-sm); }
.shop-head__meta-item { display: inline-flex; align-items: center; gap: var(--space-2); min-width: 0; overflow-wrap: anywhere; }
.shop-head__actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: var(--space-2); }
</style>
