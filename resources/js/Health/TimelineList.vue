<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Paperclip, Stethoscope, Syringe } from '@lucide/vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiChip from '../Components/ui/UiChip.vue';

// Chronological <ol> of entries (newest first, order comes from the server). With `filters` it also shows the chip
// row; without (inside a specialty folder) it is only the list. Free text is interpolated, never rendered as HTML.
const props = defineProps({
    entries: { type: Array, default: () => [] },
    visits: { type: Array, default: () => [] },
    vaccinations: { type: Array, default: () => [] },
    filters: { type: Array, default: null },
    filter: { type: String, default: 'all' },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['open', 'open-vaccination', 'filter']);
const { t, locale, te } = useI18n();

const byId = computed(() => new Map(props.visits.map((v) => [v.id, v])));
const vaccById = computed(() => new Map(props.vaccinations.map((v) => [v.id, v])));
const rows = computed(() => props.entries
    .map((e) => ({ ...e, visit: e.type === 'visit' ? byId.value.get(e.id) : null, vaccination: e.type === 'vaccination' ? vaccById.value.get(e.id) : null }))
    .filter((r) => r.visit || r.vaccination));

const specialtyName = (visit) => (visit.specialtyKey === 'other' && visit.specialtyOther ? visit.specialtyOther : t(`health.specialty.${visit.specialtyKey}`));
const chipLabel = (key) => (te(`health.specialty.${key}`) ? t(`health.specialty.${key}`) : t(`health.timeline.filter_${key}`));
const dateText = (iso) => {
    const [y, m, day] = iso.split('-').map(Number);
    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(new Date(y, m - 1, day));
};
</script>

<template>
    <div class="timeline" :aria-busy="busy ? 'true' : 'false'" :class="{ 'timeline--busy': busy }">
        <div v-if="filters !== null" class="timeline__filters" role="group" :aria-label="t('health.timeline.filter_label')" data-testid="timeline-filters">
            <UiChip :model-value="filter === 'all'" @update:model-value="emit('filter', 'all')">{{ t('health.timeline.filter_all') }}</UiChip>
            <UiChip v-for="key in filters" :key="key" :model-value="filter === key" @update:model-value="emit('filter', filter === key ? 'all' : key)">{{ chipLabel(key) }}</UiChip>
        </div>
        <p v-if="filters !== null" class="visually-hidden" role="status" aria-live="polite" data-testid="timeline-results">{{ t('health.results', { n: rows.length }, rows.length) }}</p>

        <div v-if="filters !== null && rows.length === 0 && filter !== 'all'" class="timeline__empty" data-testid="timeline-empty">
            <p>{{ t('health.timeline.empty_filter') }}</p>
            <UiButton variant="ghost" @click="emit('filter', 'all')">{{ t('health.timeline.clear_filter') }}</UiButton>
        </div>
        <ol v-else class="timeline__list">
            <li v-for="row in rows" :key="`${row.type}-${row.id}`" class="timeline__item">
                <span class="timeline__rail" aria-hidden="true"><span class="timeline__dot" /></span>
                <button v-if="row.vaccination" type="button" class="timeline__row" data-testid="timeline-entry" @click="emit('open-vaccination', row.vaccination.id)">
                    <time class="timeline__date" :datetime="row.date">{{ dateText(row.date) }}</time>
                    <span class="timeline__title">
                        <Syringe :size="20" :stroke-width="1.75" aria-hidden="true" />
                        <span>{{ row.vaccination.vaccine }}</span>
                    </span>
                </button>
                <button v-else type="button" class="timeline__row" data-testid="timeline-entry" @click="emit('open', row.visit.id)">
                    <time class="timeline__date" :datetime="row.date">{{ dateText(row.date) }}</time>
                    <span class="timeline__title">
                        <Stethoscope :size="20" :stroke-width="1.75" aria-hidden="true" />
                        <span>{{ row.visit.doctor || specialtyName(row.visit) }}</span>
                    </span>
                    <span v-if="row.visit.diagnosis || row.visit.documentCount > 0" class="timeline__meta">
                        <span v-if="row.visit.diagnosis" class="timeline__excerpt">{{ row.visit.diagnosis }}</span>
                        <span v-if="row.visit.documentCount > 0" class="timeline__docs">
                            <Paperclip :size="16" :stroke-width="1.75" aria-hidden="true" />{{ t('health.docs.attached', { n: row.visit.documentCount }, row.visit.documentCount) }}
                        </span>
                    </span>
                </button>
            </li>
        </ol>
    </div>
</template>

<style scoped>
.timeline { display: flex; flex-direction: column; gap: var(--space-3); min-width: 0; }
.timeline--busy .timeline__list { opacity: 0.6; }
.timeline__filters { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.timeline__empty { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-2); color: var(--color-text-muted); }
.timeline__empty p { margin: 0; }
.timeline__list { list-style: none; margin: 0; padding: 0; }
.timeline__item { display: flex; gap: var(--space-3); }
.timeline__rail { flex: none; position: relative; width: 16px; display: flex; justify-content: center; }
.timeline__rail::before { content: ''; position: absolute; inset-block: 0; width: 2px; background: var(--color-border); }
.timeline__dot { position: relative; margin-top: var(--space-4); width: 12px; height: 12px; border-radius: var(--radius-full); background: var(--color-primary); }
.timeline__row { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: var(--space-1); align-items: flex-start; min-height: 44px; padding: var(--space-2) var(--space-3); border: 0; border-radius: var(--radius-md); background: transparent; color: inherit; font: inherit; text-align: start; cursor: pointer; }
.timeline__row:hover { background: var(--color-surface-2); }
.timeline__row:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.timeline__date { font-size: var(--text-sm); font-weight: 600; }
.timeline__title { display: flex; align-items: flex-start; gap: var(--space-2); font-size: var(--text-base); font-weight: 600; overflow-wrap: anywhere; }
.timeline__title svg { flex: none; margin-top: 2px; }
.timeline__meta { display: flex; flex-direction: column; gap: var(--space-1); font-size: var(--text-sm); color: var(--color-text-muted); }
.timeline__excerpt { display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; overflow-wrap: anywhere; }
.timeline__docs { display: inline-flex; align-items: center; gap: var(--space-1); }
</style>
