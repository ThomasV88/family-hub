<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../Components/ui/UiButton.vue';
import UiDetailPanel from '../Components/ui/UiDetailPanel.vue';
import UiList from '../Components/ui/UiList.vue';
import UiListRow from '../Components/ui/UiListRow.vue';
import { INTL_LOCALES } from '../i18n.js';
import MoneyAmount from './MoneyAmount.vue';
import StatusTag from './StatusTag.vue';
import { formatDay } from './labels.js';

// The payments due on one day (server `dayPanel`). Rows open the bill panel.
const props = defineProps({
    open: { type: Boolean, default: false },
    // { date, occurrences: Occ[] } | null
    day: { type: Object, default: null },
});
const emit = defineEmits(['close', 'open-bill']);
const { t, locale } = useI18n();
const intl = computed(() => INTL_LOCALES[locale.value] ?? locale.value);
const title = computed(() => (props.day ? formatDay(props.day.date, intl.value, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) : ''));
</script>

<template>
    <UiDetailPanel :open="open && day !== null" :title="title" @update:open="(v) => (v ? null : emit('close'))">
        <template v-if="day">
            <UiList v-if="day.occurrences.length" data-testid="day-rows">
                <UiListRow v-for="occ in day.occurrences" :key="occ.key" as="button" class="day-row" @select="emit('open-bill', occ.key)">
                    <span class="day-row__name">{{ occ.name }}</span>
                    <template #trailing>
                        <span class="day-row__end">
                            <MoneyAmount :minor="occ.expectedMinor" :currency="occ.currency" />
                            <StatusTag :status="occ.status" />
                        </span>
                    </template>
                </UiListRow>
            </UiList>
            <p v-else class="day-panel__empty">{{ t('finance.cal.day_empty') }}</p>
        </template>
        <template #footer>
            <UiButton variant="secondary" @click="emit('close')">{{ t('finance.close') }}</UiButton>
        </template>
    </UiDetailPanel>
</template>

<style scoped>
.day-row { min-height: 56px; }
.day-row__name { font-weight: 600; white-space: normal; overflow-wrap: anywhere; }
.day-row__end { display: flex; flex-direction: column; align-items: flex-end; gap: var(--space-1); font-weight: 600; }
.day-panel__empty { margin: 0; color: var(--color-text-muted); }
</style>
