<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { TriangleAlert } from '@lucide/vue';
import PrintLayout from '../../Layouts/PrintLayout.vue';
import UiTag from '../../Components/ui/UiTag.vue';

// One-screen emergency card, printable on one A4 page. Every value is interpolated as text (never HTML). It has no
// public link or code. The severity is always printed as a word next to its icon.
const props = defineProps({
    person: { type: Object, required: true },
    allergies: { type: Array, default: () => [] },
    medications: { type: Array, default: () => [] },
    contacts: { type: Array, default: () => [] },
    printedOn: { type: String, default: '' },
});
const { t, locale } = useI18n();

const VARIANT = { mild: 'neutral', moderate: 'warn', severe: 'danger' };
const severity = (s) => t(`health.severity.${VARIANT[s] ? s : 'unknown'}`);
const variant = (s) => VARIANT[s] ?? 'neutral';
const dateText = (iso) => {
    if (!iso) return '';
    const [y, m, d] = String(iso).slice(0, 10).split('-').map(Number);
    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(new Date(y, m - 1, d));
};
const blood = computed(() => {
    const type = props.person.bloodType;
    return type ? t(`health.blood.${type.toLowerCase().replace('+', '_pos').replace('-', '_neg')}`) : t('health.blood.unknown');
});
const personLine = computed(() => {
    const parts = [];
    if (props.person.ageYears !== null && props.person.ageYears !== undefined) parts.push(t('health.age', { n: props.person.ageYears }, props.person.ageYears));
    if (props.person.birthDate) parts.push(t('health.emergency.born', { date: dateText(props.person.birthDate) }));
    return parts.join(' · ');
});
const phoneHref = (phone) => `tel:${String(phone).replace(/[^0-9+]/g, '')}`;
const contactName = (c) => (c.relation ? `${c.name} (${c.relation})` : c.name);
const detail = (parts) => parts.filter(Boolean).join(', ');
</script>

<template>
    <PrintLayout :back-href="`/health/people/${person.id}`" :back-label="t('health.emergency.back')" :print-label="t('health.emergency.print')">
        <Head :title="t('health.emergency.title')" />
        <article class="ec" data-testid="emergency-card">
            <h1 class="ec__title">{{ t('health.emergency.title') }}</h1>
            <header class="ec__head">
                <div class="ec__who">
                    <p class="ec__name" data-testid="emergency-name">{{ person.name }}</p>
                    <p v-if="personLine" class="ec__line">{{ personLine }}</p>
                </div>
                <div class="ec__blood" data-testid="emergency-blood">
                    <span class="ec__blood-label">{{ t('health.blood.label') }}</span>
                    <span class="ec__blood-value">{{ blood }}</span>
                </div>
            </header>

            <section class="ec__section" aria-labelledby="ec-allergies" data-testid="emergency-allergies">
                <h2 id="ec-allergies">{{ t('health.emergency.allergies') }}</h2>
                <p v-if="allergies.length === 0" class="ec__empty">{{ t('health.emergency.no_allergies') }}</p>
                <ul v-else class="ec__list">
                    <li v-for="(a, i) in allergies" :key="i" class="ec__item">
                        <span class="ec__text">{{ detail([a.substance, a.reaction]) }}</span>
                        <UiTag :variant="variant(a.severity)" :icon="TriangleAlert" :data-severity="a.severity">{{ severity(a.severity) }}</UiTag>
                    </li>
                </ul>
            </section>

            <section class="ec__section" aria-labelledby="ec-medications" data-testid="emergency-medications">
                <h2 id="ec-medications">{{ t('health.emergency.medications') }}</h2>
                <p v-if="medications.length === 0" class="ec__empty">{{ t('health.emergency.no_medications') }}</p>
                <ul v-else class="ec__list">
                    <li v-for="(m, i) in medications" :key="i" class="ec__item">
                        <span class="ec__text">{{ detail([m.name, m.dose, m.schedule]) }}</span>
                    </li>
                </ul>
            </section>

            <section class="ec__section" aria-labelledby="ec-contacts" data-testid="emergency-contacts">
                <h2 id="ec-contacts">{{ t('health.emergency.contacts') }}</h2>
                <p v-if="contacts.length === 0" class="ec__empty">{{ t('health.emergency.no_contacts') }}</p>
                <ul v-else class="ec__list">
                    <li v-for="(c, i) in contacts" :key="i" class="ec__item">
                        <span class="ec__text">{{ contactName(c) }}</span>
                        <a class="ec__phone" :href="phoneHref(c.phone)">{{ c.phone }}</a>
                    </li>
                </ul>
            </section>

            <footer class="ec__footer" data-testid="emergency-footer">
                <p>{{ t('health.emergency.printed', { date: dateText(printedOn) }) }}</p>
            </footer>
        </article>
    </PrintLayout>
</template>

<style scoped>
.ec { display: flex; flex-direction: column; gap: var(--space-4); min-width: 0; }
.ec__title { margin: 0; font-size: var(--text-2xl); font-weight: 600; line-height: 1.2; }
.ec__head { display: flex; flex-wrap: wrap; gap: var(--space-4); align-items: flex-start; justify-content: space-between; }
.ec__who { min-width: 0; }
.ec__name { margin: 0; font-size: 24px; font-weight: 600; line-height: 1.2; overflow-wrap: anywhere; }
.ec__line { margin: 0; color: var(--color-text-muted); }
.ec__blood { display: flex; flex-direction: column; padding: var(--space-2) var(--space-4); border: 2px solid var(--color-border-strong); border-radius: var(--radius-md); }
.ec__blood-label { font-size: var(--text-sm); }
.ec__blood-value { font-size: var(--text-2xl); font-weight: 600; }
.ec__section h2 { margin: 0 0 var(--space-2); font-size: var(--text-xl); font-weight: 600; }
.ec__empty { margin: 0; color: var(--color-text-muted); }
.ec__list { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; }
.ec__item { display: flex; flex-wrap: wrap; gap: var(--space-2); align-items: baseline; justify-content: space-between; padding-block: var(--space-2); border-bottom: 0.5pt solid var(--color-border-strong); }
.ec__text { min-width: 0; overflow-wrap: anywhere; }
.ec__phone { color: inherit; font-weight: 600; }
.ec__footer { border-top: 0.5pt solid var(--color-border-strong); padding-top: var(--space-2); font-size: var(--text-sm); color: var(--color-text-muted); }
.ec__footer p { margin: 0; }

@media print {
    .ec { gap: 10pt; color: #000; }
    .ec__title { font-size: 20pt; }
    .ec__name { font-size: 18pt; }
    .ec__section h2 { font-size: 14pt; }
    .ec__line, .ec__empty, .ec__footer { color: #000; }
    .ec__item { break-inside: avoid; padding-block: 3pt; }
    .ec__phone { text-decoration: none; }
}
</style>
