<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus, TriangleAlert } from '@lucide/vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiTag from '../Components/ui/UiTag.vue';
import ProvenanceTags from './ProvenanceTags.vue';

// Allergies side card. The server sends the list severe first; severity is always text plus icon, never colour alone.
const props = defineProps({
    allergies: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
});
const emit = defineEmits(['add', 'open']);
const { t } = useI18n();

const VARIANT = { mild: 'neutral', moderate: 'warn', severe: 'danger', unknown: 'neutral' };
const variant = (severity) => VARIANT[severity] ?? 'neutral';
const label = (severity) => t(`health.severity.${VARIANT[severity] ? severity : 'unknown'}`);
const empty = computed(() => props.allergies.length === 0);
</script>

<template>
    <section class="allergies" data-testid="allergy-card" aria-labelledby="allergy-card-title">
        <h2 id="allergy-card-title" class="allergies__title">{{ t('health.allergy.card_title') }}</h2>
        <div v-if="empty" class="allergies__empty" data-testid="allergy-empty">
            <p class="allergies__empty-title">{{ t('health.allergy.empty') }}</p>
            <p class="allergies__empty-body">{{ t('health.allergy.empty_body') }}</p>
        </div>
        <ul v-else class="allergies__list">
            <li v-for="allergy in allergies" :key="allergy.id">
                <button type="button" class="allergies__row" data-testid="allergy-row" @click="emit('open', allergy.id)">
                    <span class="allergies__text">
                        <span class="allergies__substance">{{ allergy.substance }}</span>
                        <span v-if="allergy.reaction" class="allergies__reaction">{{ allergy.reaction }}</span>
                    </span>
                    <span class="allergies__tags">
                        <UiTag :variant="variant(allergy.severity)" :icon="TriangleAlert" :data-severity="allergy.severity">{{ label(allergy.severity) }}</UiTag>
                        <ProvenanceTags :provenance="allergy.provenance" />
                    </span>
                </button>
            </li>
        </ul>
        <UiButton v-if="canEdit" variant="ghost" :icon="Plus" data-testid="allergy-add" @click="emit('add')">{{ t('health.allergy.add') }}</UiButton>
    </section>
</template>

<style scoped>
.allergies { display: flex; flex-direction: column; gap: var(--space-2); padding: var(--space-4); border: 1px solid var(--color-border); border-radius: var(--radius-lg); background: var(--color-surface); min-width: 0; }
.allergies__title { margin: 0; font-size: var(--text-lg); font-weight: 600; }
.allergies__list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.allergies__row { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); width: 100%; min-height: 44px; padding: var(--space-2) 0; border: 0; background: transparent; color: inherit; font: inherit; text-align: start; cursor: pointer; border-radius: var(--radius-md); }
.allergies__row:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.allergies__tags { display: flex; flex-direction: column; align-items: flex-end; gap: var(--space-2); min-width: 0; }
.allergies__text { display: flex; flex-direction: column; min-width: 0; }
.allergies__substance { font-size: var(--text-base); font-weight: 600; overflow-wrap: anywhere; }
.allergies__reaction { font-size: var(--text-sm); color: var(--color-text-muted); overflow-wrap: anywhere; }
.allergies__empty-title { margin: 0; font-weight: 600; }
.allergies__empty-body { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
</style>
