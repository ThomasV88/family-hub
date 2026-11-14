<script setup>
import { computed, ref, useId } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShell from '../../Layouts/AppShell.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import UiCheckbox from '../../Components/ui/UiCheckbox.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiRadio from '../../Components/ui/UiRadio.vue';
import UiSelect from '../../Components/ui/UiSelect.vue';
import { useToast } from '../../Components/ui/useToast.js';
import { securityDownload } from '../../Security/securityTransfer.js';
import { handleHealthRefusal } from '../../Health/healthRefusal.js';

// Export form. Nothing is kept: the choices live in this component only, downloads are blobs in memory that are
// handed to the browser and revoked at once, and the print option is a plain visit.
const props = defineProps({
    person: { type: Object, required: true },
    specialtiesWithVisits: { type: Array, default: () => [] },
    hasUnverified: { type: Boolean, default: false },
});
const { t } = useI18n();
const { show } = useToast();
const uid = useId();

const scope = ref('all');
const specialty = ref(props.specialtiesWithVisits[0]?.value ?? '');
const format = ref('print');
const includeUnverified = ref(false);
const busy = ref(false);
const failed = ref(false);

const base = computed(() => `/health/people/${props.person.id}/export`);
const specialtyOptions = computed(() => props.specialtiesWithVisits.map((s) => ({ value: s.value, label: s.label })));

const ids = { scope: `${uid}-scope`, format: `${uid}-format`, warning: `${uid}-ai-warning`, unverified: `${uid}-unverified-hint` };

function queryString(extra = {}) {
    const query = new URLSearchParams({ scope: scope.value });
    if (scope.value === 'specialty') query.set('specialty', specialty.value);
    if (props.hasUnverified && includeUnverified.value) query.set('include_unverified', '1');
    for (const [key, value] of Object.entries(extra)) query.set(key, value);
    return query.toString();
}

function saveBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = window.document.createElement('a');
    link.href = url;
    link.download = filename ?? 'health-export';
    link.rel = 'noopener';
    window.document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

async function create() {
    if (busy.value) return;
    failed.value = false;
    if (format.value === 'print') {
        router.visit(`${base.value}/print?${queryString()}`);
        return;
    }
    busy.value = true;
    const result = await securityDownload(`${base.value}/download?${queryString({ format: format.value })}`);
    busy.value = false;
    if (!result.ok) {
        if (handleHealthRefusal(result.error)) return;
        failed.value = true;
        return;
    }
    saveBlob(result.blob, result.filename);
    show(t('health.export.downloaded'));
}
</script>

<template>
    <AppShell current="health">
        <Head :title="t('health.title')" />
        <div class="export" data-testid="export-form">
            <h1 class="export__title">{{ t('health.export.title') }}</h1>
            <p class="export__person">{{ t('health.export.person', { name: person.name }) }}</p>

            <UiCard>
                <form class="export__form" novalidate @submit.prevent="create">
                    <UiAlert v-if="failed" variant="danger" live data-testid="export-failed">{{ t('health.export.failed') }}</UiAlert>

                    <div class="export__group">
                        <p :id="ids.scope" class="export__legend">{{ t('health.export.scope_label') }}</p>
                        <div role="radiogroup" :aria-labelledby="ids.scope" class="export__options">
                            <UiRadio v-model="scope" value="all" :name="`${uid}-scope`" data-testid="scope-all">{{ t('health.export.scope_all') }}</UiRadio>
                            <UiRadio v-model="scope" value="specialty" :name="`${uid}-scope`" :disabled="specialtyOptions.length === 0" data-testid="scope-specialty">{{ t('health.export.scope_specialty') }}</UiRadio>
                        </div>
                        <UiField v-if="scope === 'specialty'" :label="t('health.export.specialty')">
                            <UiSelect v-model="specialty" :options="specialtyOptions" data-testid="specialty-select" />
                        </UiField>
                    </div>

                    <div class="export__group">
                        <p :id="ids.format" class="export__legend">{{ t('health.export.format_label') }}</p>
                        <div role="radiogroup" :aria-labelledby="ids.format" class="export__options">
                            <div class="export__option">
                                <UiRadio v-model="format" value="print" :name="`${uid}-format`" :aria-describedby="`${ids.format}-print`" data-testid="format-print">{{ t('health.export.format_doctor') }}</UiRadio>
                                <p :id="`${ids.format}-print`" class="export__hint">{{ t('health.export.format_doctor_hint') }}</p>
                            </div>
                            <div class="export__option">
                                <UiRadio v-model="format" value="json" :name="`${uid}-format`" :aria-describedby="`${ids.format}-json`" data-testid="format-json">{{ t('health.export.format_data') }}</UiRadio>
                                <p :id="`${ids.format}-json`" class="export__hint">{{ t('health.export.format_data_hint') }}</p>
                            </div>
                            <div class="export__option">
                                <UiRadio v-model="format" value="md" :name="`${uid}-format`" :aria-describedby="`${ids.format}-md${format === 'md' ? ` ${ids.warning}` : ''}`" data-testid="format-md">{{ t('health.export.format_ai') }}</UiRadio>
                                <p :id="`${ids.format}-md`" class="export__hint">{{ t('health.export.format_ai_hint') }}</p>
                                <UiAlert v-if="format === 'md'" :id="ids.warning" variant="warning" role="note" data-testid="ai-warning">{{ t('health.export.format_ai_warning') }}</UiAlert>
                            </div>
                        </div>
                    </div>

                    <div v-if="hasUnverified" class="export__option" data-testid="unverified-option">
                        <UiCheckbox v-model="includeUnverified" :aria-describedby="ids.unverified" data-testid="include-unverified">{{ t('health.export.include_unverified') }}</UiCheckbox>
                        <p :id="ids.unverified" class="export__hint">{{ t('health.export.include_unverified_hint') }}</p>
                    </div>

                    <div class="export__actions">
                        <UiButton variant="primary" type="submit" :loading="busy" data-testid="export-create">{{ t('health.export.create') }}</UiButton>
                        <p class="export__hint">{{ t('health.export.not_kept') }}</p>
                    </div>
                </form>
            </UiCard>
        </div>
    </AppShell>
</template>

<style scoped>
.export { display: flex; flex-direction: column; gap: var(--space-4); min-width: 0; max-width: 720px; }
.export__title { margin: 0; font-size: var(--text-2xl); font-weight: 600; line-height: 1.2; }
.export__person { margin: 0; color: var(--color-text-muted); overflow-wrap: anywhere; }
.export__form { display: flex; flex-direction: column; gap: var(--space-6); }
.export__group { display: flex; flex-direction: column; gap: var(--space-3); }
.export__legend { margin: 0; font-weight: 600; }
.export__options { display: flex; flex-direction: column; gap: var(--space-3); }
.export__option { display: flex; flex-direction: column; gap: var(--space-1); }
.export__hint { margin: 0; padding-inline-start: 28px; font-size: var(--text-sm); color: var(--color-text-muted); }
.export__actions { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-2); }
.export__actions .export__hint { padding-inline-start: 0; }
</style>
