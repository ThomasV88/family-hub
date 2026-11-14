<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { HeartPulse, Plus } from '@lucide/vue';
import AppShell from '../../Layouts/AppShell.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import AllergyList from '../../Health/AllergyList.vue';
import EmergencySummaryCard from '../../Health/EmergencySummaryCard.vue';
import DocumentPanel from '../../Health/DocumentPanel.vue';
import AllergyPanel from '../../Health/AllergyPanel.vue';
import MedicationList from '../../Health/MedicationList.vue';
import MedicationPanel from '../../Health/MedicationPanel.vue';
import PersonHeader from '../../Health/PersonHeader.vue';
import PersonPanel from '../../Health/PersonPanel.vue';
import PersonSwitcher from '../../Health/PersonSwitcher.vue';
import SpecialtyFolder from '../../Health/SpecialtyFolder.vue';
import TimelineList from '../../Health/TimelineList.vue';
import VaccinationList from '../../Health/VaccinationList.vue';
import VaccinationPanel from '../../Health/VaccinationPanel.vue';
import ViewSwitch from '../../Health/ViewSwitch.vue';
import VisitPanel from '../../Health/VisitPanel.vue';
import ShellAdd from '../../Wishlist/ShellAdd.vue';

// Person page. The server decides who is shown; no selection or draft is kept on the client (no Inertia
// history state, no storage), and the title is always the module name, never a person.
const props = defineProps({
    people: { type: Array, default: () => [] },
    person: { type: Object, default: null },
    canCreate: { type: Boolean, default: false },
    panel: { type: Object, default: null },
    visits: { type: Array, default: () => [] },
    folders: { type: Array, default: () => [] },
    timeline: { type: Array, default: () => [] },
    timelineFilters: { type: Array, default: () => [] },
    view: { type: String, default: 'folders' },
    filter: { type: String, default: 'all' },
    specialties: { type: Array, default: () => [] },
    allergies: { type: Array, default: () => [] },
    medications: { type: Array, default: () => [] },
    vaccinations: { type: Array, default: () => [] },
    vaccinationAttention: { type: Number, default: 0 },
    documents: { type: Array, default: () => [] },
    unattachedDocumentIds: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({ maxMb: 10, filesAtOnce: 10 }) },
});
const { t } = useI18n();

const busy = ref(false);
// The view and filter live in the URL; a panel change keeps them and an id-less query drops the panel.
function go(params, only) {
    const query = {};
    if (props.view !== 'folders') query.view = props.view;
    if (props.filter !== 'all') query.filter = props.filter;
    router.get(window.location.pathname, { ...query, ...params }, {
        preserveState: true, preserveScroll: true, replace: true, only,
        onStart: () => { busy.value = true; },
        onFinish: () => { busy.value = false; },
    });
}
const openNew = () => go({ panel: 'new-person' }, ['panel']);
const openEditPerson = () => go({ panel: 'edit-person' }, ['panel']);
const openNewVisit = () => go({ panel: 'new-visit' }, ['panel']);
const openVisit = (id) => go({ panel: 'visit', id }, ['panel']);
const openNewAllergy = () => go({ panel: 'new-allergy' }, ['panel']);
const openAllergy = (id) => go({ panel: 'allergy', id }, ['panel']);
const openNewMedication = () => go({ panel: 'new-medication' }, ['panel']);
const openMedication = (id) => go({ panel: 'medication', id }, ['panel']);
const openNewVaccination = () => go({ panel: 'new-vaccination' }, ['panel']);
const openVaccination = (id) => go({ panel: 'vaccination', id }, ['panel']);
const openNewDocument = (visit) => go({ panel: 'new-document', ...(typeof visit === 'string' ? { visit } : {}) }, ['panel']);
const openDocument = (id) => go({ panel: 'document', id }, ['panel']);
const closePanel = () => go({}, ['panel']);
const setView = (view) => go({ view: view === 'folders' ? undefined : view, filter: undefined }, ['view', 'filter', 'timeline']);
const setFilter = (filter) => go({ filter: filter === 'all' ? undefined : filter }, ['timeline', 'filter']);

const canAddVisit = computed(() => Boolean(props.person?.canEdit));
const activeVisit = computed(() => (props.panel?.kind === 'visit' ? props.visits.find((v) => v.id === props.panel.id) ?? null : null));
const visitPanelOpen = computed(() => props.panel?.kind === 'new-visit' || (props.panel?.kind === 'visit' && activeVisit.value !== null));
const activeAllergy = computed(() => (props.panel?.kind === 'allergy' ? props.allergies.find((a) => a.id === props.panel.id) ?? null : null));
const allergyPanelOpen = computed(() => props.panel?.kind === 'new-allergy' || (props.panel?.kind === 'allergy' && activeAllergy.value !== null));
const activeMedication = computed(() => (props.panel?.kind === 'medication' ? props.medications.find((m) => m.id === props.panel.id) ?? null : null));
const medicationPanelOpen = computed(() => props.panel?.kind === 'new-medication' || (props.panel?.kind === 'medication' && activeMedication.value !== null));
const activeVaccination = computed(() => (props.panel?.kind === 'vaccination' ? props.vaccinations.find((v) => v.id === props.panel.id) ?? null : null));
const vaccinationPanelOpen = computed(() => props.panel?.kind === 'new-vaccination' || (props.panel?.kind === 'vaccination' && activeVaccination.value !== null));
const activeDocument = computed(() => (props.panel?.kind === 'document' ? props.documents.find((d) => d.id === props.panel.id) ?? null : null));
const documentPanelOpen = computed(() => props.panel?.kind === 'new-document' || (props.panel?.kind === 'document' && activeDocument.value !== null));
const unattachedDocuments = computed(() => props.documents.filter((d) => props.unattachedDocumentIds.includes(d.id)));
const folderVisits = (folder) => props.visits.filter((v) => folder.visitIds.includes(v.id));
</script>

<template>
    <AppShell current="health">
        <Head :title="t('health.title')" />
        <div class="health-person" data-testid="health-person">
            <h1 class="health-person__title">{{ t('health.title') }}</h1>

            <template v-if="person">
                <PersonSwitcher :people="people" :current-id="person.id" :can-create="canCreate" @add="openNew" />
                <PersonHeader :person="person" @add-visit="openNewVisit" @add-document="openNewDocument" @edit-person="openEditPerson" />
                <UiAlert v-if="vaccinationAttention > 0" variant="warning" data-testid="vaccination-alert">
                    <strong>{{ t('health.vaccination.attention_title') }}</strong>
                    {{ t('health.vacc.attention', { n: vaccinationAttention }, vaccinationAttention) }}
                </UiAlert>
                <div class="health-person__area">
                    <div class="health-person__side" data-testid="person-side">
                        <EmergencySummaryCard :person="person" :allergy-count="allergies.length" />
                        <AllergyList :allergies="allergies" :can-edit="canAddVisit" @add="openNewAllergy" @open="openAllergy" />
                        <MedicationList :medications="medications" :can-edit="canAddVisit" @add="openNewMedication" @open="openMedication" />
                        <VaccinationList :vaccinations="vaccinations" :can-edit="canAddVisit" @add="openNewVaccination" @open="openVaccination" />
                    </div>
                    <section class="health-person__main" data-testid="person-main">
                        <UiEmptyState v-if="visits.length === 0 && documents.length === 0" :title="t('health.visits.empty_title')" :body="t('health.visits.empty_body')" :icon="HeartPulse">
                            <template v-if="canAddVisit" #action>
                                <UiButton variant="primary" :icon="Plus" data-testid="empty-add-visit" @click="openNewVisit">{{ t('health.visit.add') }}</UiButton>
                            </template>
                        </UiEmptyState>
                        <template v-else>
                            <ViewSwitch :view="view" @change="setView" />
                            <div v-if="view === 'folders'" class="health-person__folders" data-testid="folders">
                                <SpecialtyFolder
                                    v-for="(folder, index) in folders"
                                    :key="folder.key"
                                    :folder="folder"
                                    :visits="folderVisits(folder)"
                                    :default-open="index === 0"
                                    @open="openVisit"
                                />
                                <SpecialtyFolder v-if="unattachedDocuments.length > 0" :documents="unattachedDocuments" :default-open="folders.length === 0" @open-document="openDocument" />
                            </div>
                            <TimelineList v-else :entries="timeline" :visits="visits" :vaccinations="vaccinations" :documents="documents" :filters="timelineFilters" :filter="filter" :busy="busy" @open="openVisit" @open-vaccination="openVaccination" @open-document="openDocument" @filter="setFilter" />
                        </template>
                    </section>
                </div>
            </template>
            <UiEmptyState v-else :title="t('health.empty.title')" :body="t('health.empty.body')" :icon="HeartPulse" :heading-level="2">
                <template v-if="canCreate" #action>
                    <UiButton variant="primary" data-testid="empty-add" @click="openNew">{{ t('health.empty.add_person') }}</UiButton>
                </template>
            </UiEmptyState>
        </div>
        <PersonPanel :open="panel?.kind === 'new-person' || (panel?.kind === 'edit-person' && Boolean(person))" :person="panel?.kind === 'edit-person' ? person : null" @close="closePanel" />
        <VisitPanel v-if="person" :open="visitPanelOpen" :person-id="person.id" :visit="activeVisit" :can-edit="canAddVisit" :specialties="specialties" :documents="documents" @close="closePanel" @open-document="openDocument" @upload-document="openNewDocument" />
        <AllergyPanel v-if="person" :open="allergyPanelOpen" :person-id="person.id" :allergy="activeAllergy" :can-edit="canAddVisit" @close="closePanel" />
        <MedicationPanel v-if="person" :open="medicationPanelOpen" :person-id="person.id" :medication="activeMedication" :can-edit="canAddVisit" @close="closePanel" />
        <VaccinationPanel v-if="person" :open="vaccinationPanelOpen" :person-id="person.id" :vaccination="activeVaccination" :can-edit="canAddVisit" @close="closePanel" />
        <DocumentPanel v-if="person" :open="documentPanelOpen" :person-id="person.id" :document="activeDocument" :visits="visits" :limits="limits" :can-edit="canAddVisit" :initial-visit="panel?.visit ?? null" @close="closePanel" />
        <ShellAdd :enabled="canAddVisit" :label="t('health.fab.add_visit')" @add="openNewVisit" />
    </AppShell>
</template>

<style scoped>
.health-person { display: flex; flex-direction: column; gap: var(--space-4); min-width: 0; container-type: inline-size; }
.health-person__title { margin: 0; font-size: var(--text-2xl); font-weight: 600; line-height: 1.2; }
.health-person__area { display: flex; flex-direction: column; gap: var(--space-8); }
.health-person__side { order: -1; display: flex; flex-direction: column; gap: var(--space-4); }
.health-person__side:empty { display: none; }
.health-person__main { min-width: 0; display: flex; flex-direction: column; gap: var(--space-4); }
.health-person__folders { display: flex; flex-direction: column; gap: var(--space-3); }
@container (min-width: 900px) {
    .health-person__area { flex-direction: row; align-items: flex-start; }
    .health-person__main { flex: 1; order: 0; }
    .health-person__side { order: 1; flex: none; width: 320px; display: flex; }
    .health-person__side:empty { display: flex; }
}
</style>
