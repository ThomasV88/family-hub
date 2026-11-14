<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { TriangleAlert } from '@lucide/vue';
import PrintLayout from '../../Layouts/PrintLayout.vue';
import UiTag from '../../Components/ui/UiTag.vue';

// Printable doctor view. Every value is interpolated as text (never HTML). Document files are not embedded: the list
// shows title and date only. The footer date is taken in the browser, in the family time zone, when the page is shown.
const props = defineProps({
    person: { type: Object, required: true },
    export: { type: Object, required: true },
});
const { t, locale } = useI18n();

const data = computed(() => props.export);
const subject = computed(() => data.value.subject ?? {});
const list = (key) => data.value[key] ?? [];

const dateText = (iso) => {
    if (!iso) return '';
    const [y, m, d] = String(iso).slice(0, 10).split('-').map(Number);
    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(new Date(y, m - 1, d));
};
const period = (m) => [dateText(m.started_on), dateText(m.ended_on)].filter(Boolean).join(' – ');
const generated = computed(() => {
    let text;
    try {
        text = new Intl.DateTimeFormat(locale.value, { dateStyle: 'long', timeStyle: 'short', timeZone: data.value.timezone }).format(new Date());
    } catch {
        text = new Intl.DateTimeFormat(locale.value, { dateStyle: 'long', timeStyle: 'short' }).format(new Date());
    }
    return t('health.export.generated', { date: text });
});
const severity = (s) => t(`health.severity.${['mild', 'moderate', 'severe'].includes(s) ? s : 'unknown'}`);
const bloodLabel = computed(() => {
    const type = subject.value.blood_type;
    return type ? t(`health.blood.${type.toLowerCase().replace('+', '_pos').replace('-', '_neg')}`) : '';
});
const personLine = computed(() => {
    const parts = [];
    if (subject.value.birth_date) parts.push(t('health.emergency.born', { date: dateText(subject.value.birth_date) }));
    if (subject.value.sex) parts.push(t(`health.sex.${subject.value.sex}`));
    if (bloodLabel.value) parts.push(`${t('health.blood.label')} ${bloodLabel.value}`);
    return parts.join(' · ');
});
const unverified = (item) => item.verification === 'unverified';
// Printed as words so it survives black and white: "AI-extracted, Unverified".
const provenanceWords = computed(() => `${t('health.ai.provenance.ai_extracted')}, ${t('health.ai.provenance.unverified')}`);
</script>

<template>
    <PrintLayout :back-href="`/health/people/${person.id}`" :back-label="t('health.emergency.back')" :print-label="t('health.export.print')">
        <Head :title="t('health.export.print_title')" />
        <article class="xp" data-testid="export-print">
            <h1 class="xp__title">{{ t('health.export.print_title') }}</h1>
            <p class="xp__name" data-testid="export-person">{{ subject.name }}</p>
            <p v-if="personLine" class="xp__line">{{ personLine }}</p>

            <section v-if="list('allergies').length" class="xp__section" aria-labelledby="xp-allergies" data-testid="export-allergies">
                <h2 id="xp-allergies">{{ t('health.export.section_allergies') }}</h2>
                <table class="xp__table">
                    <thead><tr><th scope="col">{{ t('health.export.col_substance') }}</th><th scope="col">{{ t('health.export.col_reaction') }}</th><th scope="col">{{ t('health.export.col_severity') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="(a, i) in list('allergies')" :key="i">
                            <td :data-label="t('health.export.col_substance')">{{ a.substance }}<UiTag v-if="unverified(a)" variant="warn" :icon="TriangleAlert" class="xp__tag" data-testid="unverified-tag">{{ provenanceWords }}</UiTag></td>
                            <td :data-label="t('health.export.col_reaction')">{{ a.reaction }}</td>
                            <td :data-label="t('health.export.col_severity')">{{ severity(a.severity) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section v-if="list('medications').length" class="xp__section" aria-labelledby="xp-medications" data-testid="export-medications">
                <h2 id="xp-medications">{{ t('health.export.section_medications') }}</h2>
                <table class="xp__table">
                    <thead><tr><th scope="col">{{ t('health.export.col_name') }}</th><th scope="col">{{ t('health.export.col_dose') }}</th><th scope="col">{{ t('health.export.col_schedule') }}</th><th scope="col">{{ t('health.export.col_period') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="(m, i) in list('medications')" :key="i">
                            <td :data-label="t('health.export.col_name')">{{ m.name }}<UiTag v-if="unverified(m)" variant="warn" :icon="TriangleAlert" class="xp__tag" data-testid="unverified-tag">{{ provenanceWords }}</UiTag></td>
                            <td :data-label="t('health.export.col_dose')">{{ m.dose }}</td>
                            <td :data-label="t('health.export.col_schedule')">{{ m.schedule }}</td>
                            <td :data-label="t('health.export.col_period')">{{ period(m) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section v-if="list('vaccinations').length" class="xp__section" aria-labelledby="xp-vaccinations" data-testid="export-vaccinations">
                <h2 id="xp-vaccinations">{{ t('health.export.section_vaccinations') }}</h2>
                <table class="xp__table">
                    <thead><tr><th scope="col">{{ t('health.export.col_vaccine') }}</th><th scope="col">{{ t('health.export.col_date') }}</th><th scope="col">{{ t('health.export.col_next_due') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="(v, i) in list('vaccinations')" :key="i">
                            <td :data-label="t('health.export.col_vaccine')">{{ v.vaccine }}<UiTag v-if="unverified(v)" variant="warn" :icon="TriangleAlert" class="xp__tag" data-testid="unverified-tag">{{ provenanceWords }}</UiTag></td>
                            <td :data-label="t('health.export.col_date')">{{ dateText(v.given_on) }}</td>
                            <td :data-label="t('health.export.col_next_due')">{{ dateText(v.next_due_on) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section v-if="list('visits').length" class="xp__section" aria-labelledby="xp-visits" data-testid="export-visits">
                <h2 id="xp-visits">{{ t('health.export.section_visits') }}</h2>
                <table class="xp__table">
                    <thead><tr><th scope="col">{{ t('health.export.col_date') }}</th><th scope="col">{{ t('health.export.col_doctor') }}</th><th scope="col">{{ t('health.export.col_specialty') }}</th><th scope="col">{{ t('health.export.col_diagnosis') }}</th><th scope="col">{{ t('health.export.col_notes') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="(v, i) in list('visits')" :key="i">
                            <td :data-label="t('health.export.col_date')">{{ dateText(v.visited_on) }}<UiTag v-if="unverified(v)" variant="warn" :icon="TriangleAlert" class="xp__tag" data-testid="unverified-tag">{{ provenanceWords }}</UiTag></td>
                            <td :data-label="t('health.export.col_doctor')">{{ v.doctor }}</td>
                            <td :data-label="t('health.export.col_specialty')">{{ v.specialty_label }}</td>
                            <td :data-label="t('health.export.col_diagnosis')" class="xp__text">{{ v.diagnosis_text }}</td>
                            <td :data-label="t('health.export.col_notes')" class="xp__text">{{ v.notes }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section v-if="list('documents').length" class="xp__section" aria-labelledby="xp-documents" data-testid="export-documents">
                <h2 id="xp-documents">{{ t('health.export.section_documents') }}</h2>
                <ul class="xp__docs">
                    <li v-for="(d, i) in list('documents')" :key="i">
                        <span>{{ d.title }}</span>
                        <UiTag v-if="unverified(d)" variant="warn" :icon="TriangleAlert" class="xp__tag" data-testid="unverified-tag">{{ provenanceWords }}</UiTag>
                        <span class="xp__date">{{ d.uploaded_on ? dateText(d.uploaded_on) : '' }}</span>
                    </li>
                </ul>
            </section>

            <footer class="xp__footer" data-testid="export-footer">
                <p v-if="data.unverified_notice" data-testid="unverified-legend">{{ t('health.export.unverified_legend') }}</p>
                <p>{{ generated }}</p>
            </footer>
        </article>
    </PrintLayout>
</template>

<style scoped>
.xp { display: flex; flex-direction: column; gap: var(--space-4); min-width: 0; container-type: inline-size; }
.xp__title { margin: 0; font-size: var(--text-2xl); font-weight: 600; line-height: 1.2; }
.xp__name { margin: 0; font-size: var(--text-xl); font-weight: 600; overflow-wrap: anywhere; }
.xp__line { margin: 0; color: var(--color-text-muted); }
.xp__section h2 { margin: 0 0 var(--space-2); font-size: var(--text-xl); font-weight: 600; }
.xp__table { width: 100%; border-collapse: collapse; }
.xp__table th, .xp__table td { padding: var(--space-2); border: 0.5pt solid var(--color-border-strong); text-align: start; vertical-align: top; overflow-wrap: anywhere; }
.xp__text { white-space: pre-wrap; }
.xp__tag { margin-inline-start: var(--space-2); border: 0.5pt solid currentColor; white-space: normal; }
.xp__docs { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: var(--space-1); }
.xp__docs li { display: flex; flex-wrap: wrap; gap: var(--space-2); align-items: baseline; border-bottom: 0.5pt solid var(--color-border-strong); padding-block: var(--space-1); }
.xp__date { margin-inline-start: auto; color: var(--color-text-muted); }
.xp__footer { border-top: 0.5pt solid var(--color-border-strong); padding-top: var(--space-2); font-size: var(--text-sm); color: var(--color-text-muted); }
.xp__footer p { margin: 0 0 var(--space-1); }

/* On screen the tables stack label/value below 560px; print always uses the real table. */
@media screen {
    @container (max-width: 560px) {
        .xp__table thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
        .xp__table, .xp__table tbody, .xp__table tr, .xp__table td { display: block; }
        .xp__table tr { padding-block: var(--space-2); border-bottom: 0.5pt solid var(--color-border-strong); }
        .xp__table td { border: 0; padding: var(--space-1) 0; }
        .xp__table td:empty { display: none; }
        .xp__table td::before { content: attr(data-label) ': '; font-weight: 600; }
    }
}
@media print {
    .xp__title { font-size: 20pt; }
    .xp__section h2 { font-size: 14pt; }
    .xp, .xp__line, .xp__footer, .xp__date { color: #000; }
    .xp__table th { font-weight: 600; }
}
</style>
