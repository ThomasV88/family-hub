<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Check, ChevronDown, Plus } from '@lucide/vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiTag from '../Components/ui/UiTag.vue';
import ProvenanceTags from './ProvenanceTags.vue';

// Medications side card. Ongoing or ended is decided by the server (family time zone) and arrives as `ongoing`; the
// client never compares dates. Ended medications sit behind a disclosure.
const props = defineProps({
    medications: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
});
const emit = defineEmits(['add', 'open']);
const { t, locale } = useI18n();

const showEnded = ref(false);
const ongoing = computed(() => props.medications.filter((m) => m.ongoing));
const ended = computed(() => props.medications.filter((m) => !m.ongoing));
const empty = computed(() => props.medications.length === 0);

const detail = (m) => [m.dose, m.schedule].filter(Boolean).join(', ');
const dateText = (iso) => {
    const [y, m, d] = iso.split('-').map(Number);
    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(new Date(y, m - 1, d));
};
</script>

<template>
    <section class="meds" data-testid="medication-card" aria-labelledby="medication-card-title">
        <h2 id="medication-card-title" class="meds__title">{{ t('health.medication.card_title') }}</h2>
        <p v-if="empty" class="meds__empty" data-testid="medication-empty">{{ t('health.medication.empty') }}</p>
        <template v-else>
            <ul v-if="ongoing.length" class="meds__list" data-testid="medication-ongoing">
                <li v-for="m in ongoing" :key="m.id">
                    <button type="button" class="meds__row" data-testid="medication-row" @click="emit('open', m.id)">
                        <span class="meds__text">
                            <span class="meds__name">{{ m.name }}</span>
                            <span v-if="detail(m)" class="meds__detail">{{ detail(m) }}</span>
                            <ProvenanceTags :provenance="m.provenance" class="meds__prov" />
                        </span>
                        <UiTag variant="success" :icon="Check">{{ t('health.medication.ongoing') }}</UiTag>
                    </button>
                </li>
            </ul>
            <template v-if="ended.length">
                <button
                    type="button"
                    class="meds__toggle"
                    data-testid="medication-toggle"
                    aria-controls="medication-ended"
                    :aria-expanded="showEnded ? 'true' : 'false'"
                    @click="showEnded = !showEnded"
                >
                    <ChevronDown :size="16" :stroke-width="1.75" aria-hidden="true" :class="{ 'meds__chevron--open': showEnded }" />
                    {{ t('health.medication.show_ended') }}
                </button>
                <ul v-show="showEnded" id="medication-ended" class="meds__list" data-testid="medication-ended">
                    <li v-for="m in ended" :key="m.id">
                        <button type="button" class="meds__row" data-testid="medication-row" @click="emit('open', m.id)">
                            <span class="meds__text">
                                <span class="meds__name">{{ m.name }}</span>
                                <span v-if="detail(m)" class="meds__detail">{{ detail(m) }}</span>
                                <ProvenanceTags :provenance="m.provenance" class="meds__prov" />
                            </span>
                            <UiTag>{{ t('health.medication.ended', { date: dateText(m.endedOn) }) }}</UiTag>
                        </button>
                    </li>
                </ul>
            </template>
        </template>
        <UiButton v-if="canEdit" variant="ghost" :icon="Plus" data-testid="medication-add" @click="emit('add')">{{ t('health.medication.add') }}</UiButton>
    </section>
</template>

<style scoped>
.meds { display: flex; flex-direction: column; gap: var(--space-2); padding: var(--space-4); border: 1px solid var(--color-border); border-radius: var(--radius-lg); background: var(--color-surface); min-width: 0; }
.meds__title { margin: 0; font-size: var(--text-lg); font-weight: 600; }
.meds__list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.meds__row { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); width: 100%; min-height: 44px; padding: var(--space-2) 0; border: 0; background: transparent; color: inherit; font: inherit; text-align: start; cursor: pointer; border-radius: var(--radius-md); }
.meds__row:focus-visible, .meds__toggle:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.meds__text { display: flex; flex-direction: column; min-width: 0; }
.meds__name { font-size: var(--text-base); font-weight: 600; overflow-wrap: anywhere; }
.meds__prov { margin-top: var(--space-1); }
.meds__detail { font-size: var(--text-sm); color: var(--color-text-muted); overflow-wrap: anywhere; }
.meds__empty { margin: 0; font-weight: 600; }
.meds__toggle { display: inline-flex; align-items: center; gap: var(--space-1); min-height: 44px; padding: 0; border: 0; background: transparent; color: var(--color-text-muted); font: inherit; font-size: var(--text-sm); cursor: pointer; align-self: flex-start; }
.meds__toggle svg { transition: transform 0.15s; }
.meds__chevron--open { transform: rotate(180deg); }
</style>
