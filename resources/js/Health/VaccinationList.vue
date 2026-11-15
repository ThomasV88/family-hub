<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Clock, Plus } from '@lucide/vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiTag from '../Components/ui/UiTag.vue';
import ProvenanceTags from './ProvenanceTags.vue';

// Vaccinations side card. Due status (overdue, due soon, later) and the day count are decided by the server in the
// family time zone; the client only formats them. Due items come first, then past vaccinations.
const props = defineProps({
    vaccinations: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
});
const emit = defineEmits(['add', 'open']);
const { t, locale } = useI18n();

const due = computed(() => props.vaccinations.filter((v) => v.status !== 'none'));
const past = computed(() => props.vaccinations.filter((v) => v.status === 'none'));
const hasAttention = computed(() => props.vaccinations.some((v) => v.status === 'overdue' || v.status === 'due_soon'));
const empty = computed(() => props.vaccinations.length === 0);

const dateText = (iso) => {
    const [y, m, d] = iso.split('-').map(Number);
    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(new Date(y, m - 1, d));
};
</script>

<template>
    <section class="vacc" :class="{ 'vacc--due': hasAttention }" data-testid="vaccination-card" aria-labelledby="vaccination-card-title">
        <h2 id="vaccination-card-title" class="vacc__title">{{ t('health.vaccination.card_title') }}</h2>
        <p v-if="empty" class="vacc__empty" data-testid="vaccination-empty">{{ t('health.vaccination.empty') }}</p>
        <template v-else>
            <ul v-if="due.length" class="vacc__list" data-testid="vaccination-due">
                <li v-for="v in due" :key="v.id">
                    <button type="button" class="vacc__row" data-testid="vaccination-row" @click="emit('open', v.id)">
                        <span class="vacc__text">
                            <span class="vacc__name">{{ v.vaccine }}</span>
                            <span class="vacc__detail">{{ t('health.vaccination.given', { date: dateText(v.givenOn) }) }}</span>
                            <ProvenanceTags :provenance="v.provenance" class="vacc__prov" />
                        </span>
                        <UiTag v-if="v.status === 'overdue'" variant="danger" :icon="Clock" data-testid="vaccination-tag">{{ t('health.vaccination.overdue') }}</UiTag>
                        <UiTag v-else-if="v.status === 'due_soon'" variant="warn" :icon="Clock" data-testid="vaccination-tag">{{ t('health.vacc.due_days', { n: v.dueInDays }, v.dueInDays) }}</UiTag>
                        <UiTag v-else data-testid="vaccination-tag">{{ t('health.vaccination.due_on', { date: dateText(v.nextDueOn) }) }}</UiTag>
                    </button>
                </li>
            </ul>
            <ul v-if="past.length" class="vacc__list" data-testid="vaccination-past">
                <li v-for="v in past" :key="v.id">
                    <button type="button" class="vacc__row" data-testid="vaccination-row" @click="emit('open', v.id)">
                        <span class="vacc__text">
                            <span class="vacc__name">{{ v.vaccine }}</span>
                            <span class="vacc__detail">{{ dateText(v.givenOn) }}</span>
                            <ProvenanceTags :provenance="v.provenance" class="vacc__prov" />
                        </span>
                    </button>
                </li>
            </ul>
        </template>
        <UiButton v-if="canEdit" variant="ghost" :icon="Plus" data-testid="vaccination-add" @click="emit('add')">{{ t('health.vaccination.add') }}</UiButton>
    </section>
</template>

<style scoped>
.vacc { display: flex; flex-direction: column; gap: var(--space-2); padding: var(--space-4); border: 1px solid var(--color-border); border-radius: var(--radius-lg); background: var(--color-surface); min-width: 0; }
.vacc--due { order: -1; }
.vacc__title { margin: 0; font-size: var(--text-lg); font-weight: 600; }
.vacc__list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.vacc__row { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); width: 100%; min-height: 44px; padding: var(--space-2) 0; border: 0; background: transparent; color: inherit; font: inherit; text-align: start; cursor: pointer; border-radius: var(--radius-md); }
.vacc__row:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.vacc__text { display: flex; flex-direction: column; min-width: 0; }
.vacc__name { font-size: var(--text-base); font-weight: 600; overflow-wrap: anywhere; }
.vacc__prov { margin-top: var(--space-1); }
.vacc__detail { font-size: var(--text-sm); color: var(--color-text-muted); }
.vacc__empty { margin: 0; font-weight: 600; }
@container (min-width: 900px) {
    .vacc--due { order: 0; }
}
</style>
