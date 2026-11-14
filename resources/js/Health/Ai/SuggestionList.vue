<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SuggestionEntry from './SuggestionEntry.vue';

// The proposal as sections in a fixed order. `sections` is [{type, entries: [{key, type, fields, state, recordId,
// edited}]}] already in proposal order; empty types are omitted here. Read-only text, model output never as HTML.
const props = defineProps({
    personId: { type: String, required: true },
    extractionId: { type: String, required: true },
    sections: { type: Array, default: () => [] },
    documentDate: { type: String, default: null },
    specialtyKey: { type: String, default: null },
    readOnly: { type: Boolean, default: false },
});
defineEmits(['decided', 'superseded', 'edit-requested']);
const { t, te, locale } = useI18n();

const HEADINGS = { visit: 'section_visits', medication: 'section_medications', allergy: 'section_allergies', vaccination: 'section_vaccinations' };
const visible = computed(() => props.sections.filter((s) => s.entries.length > 0));

const dateText = computed(() => {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(props.documentDate ?? '');
    return m ? new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))) : '';
});
const specialtyName = computed(() => (props.specialtyKey && te(`health.specialty.${props.specialtyKey}`) ? t(`health.specialty.${props.specialtyKey}`) : ''));
</script>

<template>
    <div class="suggestions" data-testid="suggestion-list">
        <section v-if="dateText || specialtyName" class="suggestions__section" data-testid="suggested-details">
            <h3 class="suggestions__heading">{{ t('health.ai.review.details_title') }}</h3>
            <p v-if="dateText" class="suggestions__line">{{ t('health.ai.review.document_date', { date: dateText }) }}</p>
            <p v-if="specialtyName" class="suggestions__line">{{ t('health.ai.review.specialty', { name: specialtyName }) }}</p>
        </section>
        <section v-for="section in visible" :key="section.type" class="suggestions__section" :data-section="section.type">
            <h3 class="suggestions__heading">{{ t(`health.ai.review.${HEADINGS[section.type]}`) }}<span class="suggestions__count"> ({{ t('health.ai.review.count', { n: section.entries.length }, section.entries.length) }})</span></h3>
            <ul class="suggestions__list">
                <SuggestionEntry
                    v-for="entry in section.entries"
                    :key="entry.key"
                    :person-id="personId"
                    :extraction-id="extractionId"
                    :entry="entry"
                    :read-only="readOnly"
                    @decided="$emit('decided', $event)"
                    @superseded="$emit('superseded')"
                    @edit-requested="$emit('edit-requested', $event)"
                />
            </ul>
        </section>
    </div>
</template>

<style scoped>
.suggestions { display: flex; flex-direction: column; gap: var(--space-6); min-width: 0; }
.suggestions__section { display: flex; flex-direction: column; gap: var(--space-3); }
.suggestions__heading { margin: 0; font-size: var(--text-lg); font-weight: 600; }
.suggestions__count { font-weight: 400; color: var(--color-text-muted); font-size: var(--text-base); }
.suggestions__line { margin: 0; }
.suggestions__list { display: flex; flex-direction: column; gap: var(--space-3); margin: 0; padding: 0; list-style: none; }
</style>
