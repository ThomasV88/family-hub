<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import UiList from '../Components/ui/UiList.vue';
import UiListRow from '../Components/ui/UiListRow.vue';
import { INTL_LOCALES } from '../i18n.js';
import MoneyAmount from './MoneyAmount.vue';
import StatusTag from './StatusTag.vue';
import { formatDay } from './labels.js';

// Overdue, then the next 30 days. Rows only display server values (status, amounts, dates).
const props = defineProps({
    overdue: { type: Array, default: () => [] },
    next30: { type: Array, default: () => [] },
    selectedKey: { type: String, default: null },
});
const emit = defineEmits(['open']);
const { t, locale } = useI18n();
const intl = computed(() => INTL_LOCALES[locale.value] ?? locale.value);
const day = (iso) => formatDay(iso, intl.value, { weekday: 'short', day: 'numeric', month: 'long' });
const sections = computed(() => [
    { key: 'overdue', title: t('finance.rec.section_overdue'), items: props.overdue },
    { key: 'next', title: t('finance.rec.section_next'), items: props.next30 },
].filter((s) => s.items.length > 0 || (s.key === 'next' && props.overdue.length === 0)));
</script>

<template>
    <div class="rec-list" data-testid="recurring-list">
        <section v-for="section in sections" :key="section.key" :aria-labelledby="`rec-sec-${section.key}`" :data-section="section.key">
            <h2 :id="`rec-sec-${section.key}`" class="rec-list__title">{{ section.title }}</h2>
            <UiList v-if="section.items.length">
                <UiListRow v-for="occ in section.items" :key="occ.key" as="button" :selected="occ.key === selectedKey" class="rec-row" @select="emit('open', occ.key)">
                    <span class="rec-row__name">{{ occ.name }}</span>
                    <template #meta>
                        <span class="rec-row__meta"><time :datetime="occ.dueDate">{{ t('finance.rec.due', { date: day(occ.dueDate) }) }}</time></span>
                    </template>
                    <template #trailing>
                        <span class="rec-row__end">
                            <MoneyAmount :minor="occ.expectedMinor" :currency="occ.currency" class="rec-row__amount" />
                            <StatusTag :status="occ.status" />
                        </span>
                    </template>
                </UiListRow>
            </UiList>
            <p v-else class="rec-list__none">{{ t('finance.still.none') }}</p>
        </section>
    </div>
</template>

<style scoped>
.rec-list { display: flex; flex-direction: column; gap: var(--space-4); }
.rec-list__title { margin: 0 0 var(--space-1); font-size: var(--text-lg); font-weight: 600; }
.rec-list__none { margin: 0; color: var(--color-text-muted); }
.rec-row { min-height: 56px; }
.rec-row__name { font-weight: 600; white-space: normal; overflow-wrap: anywhere; }
.rec-row__end { display: flex; flex-direction: column; align-items: flex-end; gap: var(--space-1); }
.rec-row__amount { font-size: var(--text-base); font-weight: 600; white-space: nowrap; }
</style>
