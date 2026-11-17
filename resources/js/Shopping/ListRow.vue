<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Bell, Copy } from '@lucide/vue';
import UiAvatar from '../Components/ui/UiAvatar.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiListRow from '../Components/ui/UiListRow.vue';
import UiProgress from '../Components/ui/UiProgress.vue';
import UiTag from '../Components/ui/UiTag.vue';
import CatalogImage from './CatalogImage.vue';
import { firstName, formatListDate, reminderTag, statusTag } from './listStatus.js';

// One row of the overview. open: { id, name, status, store, responsible, remindAt, reminderPassed, itemsCount, boughtCount, abilities }
// archive: { id, name, doneAt, storeName, abilities }. The whole row is a link to the list; the copy button sits beside it.
const props = defineProps({
    list: { type: Object, required: true },
    kind: { type: String, default: 'open', validator: (v) => ['open', 'archive'].includes(v) },
});
const emit = defineEmits(['copy']);
const { t, locale } = useI18n();

const archived = computed(() => props.kind === 'archive');
const locked = computed(() => props.list.status === 'locked');
const tag = computed(() => (locked.value ? statusTag('locked') : null));
const warn = computed(() => (archived.value ? null : reminderTag(props.list)));
const reminder = computed(() => (archived.value || !props.list.remindAt ? '' : formatListDate(props.list.remindAt, locale.value, { time: true })));
const storeName = computed(() => (archived.value ? props.list.storeName : props.list.store?.name) ?? '');
const person = computed(() => (archived.value ? null : props.list.responsible ?? null));

const meta = computed(() => {
    const parts = [];
    if (archived.value) parts.push(t('shopping.overview.finished', { date: formatListDate(props.list.doneAt, locale.value) }));
    if (locked.value) {
        parts.push(t('shopping.overview.items', { n: props.list.itemsCount }, props.list.itemsCount));
        parts.push(t('shopping.overview.bought', { n: props.list.boughtCount }));
    }
    if (storeName.value) parts.push(storeName.value);
    if (person.value) parts.push(firstName(person.value.name));

    return parts.join(' · ');
});
const progressLabel = computed(() => t('shopping.overview.progress', { bought: props.list.boughtCount, total: props.list.itemsCount }));
</script>

<template>
    <UiListRow as="link" :href="`/shopping/lists/${list.id}`" class="shop-list-row" data-testid="list-row">
        <template #leading><CatalogImage kind="store" :id="archived ? null : (list.store?.id ?? null)" :version="archived ? null : (list.store?.logoVersion ?? null)" :size="40" /></template>
        {{ list.name }}
        <template #meta>
            <span class="shop-list-row__meta">{{ meta }}</span>
            <UiProgress v-if="locked && list.itemsCount > 0" :value="list.boughtCount" :max="list.itemsCount" :label="progressLabel" class="shop-list-row__progress" />
        </template>
        <template #trailing>
            <UiAvatar v-if="person" :name="person.name" :user-id="person.id" :size="32" decorative />
            <UiTag v-if="tag" :icon="tag.icon" :variant="tag.variant">{{ t(tag.key) }}</UiTag>
            <UiTag v-if="warn" :variant="warn.variant">{{ t(warn.key) }}</UiTag>
            <span v-if="reminder" class="shop-list-row__reminder"><Bell :size="16" :stroke-width="1.75" aria-hidden="true" />{{ reminder }}</span>
            <UiButton v-if="list.abilities?.copy" variant="ghost" icon-only :icon="Copy" :label="t('shopping.overview.copy_named', { name: list.name })" @click="emit('copy', list)" />
        </template>
    </UiListRow>
</template>

<style scoped>
.shop-list-row { min-height: 64px; }
.shop-list-row :deep(.ui-row__title) { overflow-wrap: anywhere; font-weight: 600; }
.shop-list-row__meta { display: block; overflow-wrap: anywhere; }
.shop-list-row__progress { margin-block-start: var(--space-1); }
.shop-list-row :deep(.ui-row__trailing) { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: var(--space-2); }
.shop-list-row__reminder { display: inline-flex; align-items: center; gap: var(--space-1); color: var(--color-text-muted); font-size: var(--text-sm); }
</style>
