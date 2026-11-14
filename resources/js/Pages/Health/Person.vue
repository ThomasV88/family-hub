<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { HeartPulse, Plus } from '@lucide/vue';
import AppShell from '../../Layouts/AppShell.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import AllergyList from '../../Health/AllergyList.vue';
import AllergyPanel from '../../Health/AllergyPanel.vue';
import PersonHeader from '../../Health/PersonHeader.vue';
import PersonPanel from '../../Health/PersonPanel.vue';
import PersonSwitcher from '../../Health/PersonSwitcher.vue';
import SpecialtyFolder from '../../Health/SpecialtyFolder.vue';
import TimelineList from '../../Health/TimelineList.vue';
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
const openNewVisit = () => go({ panel: 'new-visit' }, ['panel']);
const openVisit = (id) => go({ panel: 'visit', id }, ['panel']);
const openNewAllergy = () => go({ panel: 'new-allergy' }, ['panel']);
const openAllergy = (id) => go({ panel: 'allergy', id }, ['panel']);
const closePanel = () => go({}, ['panel']);
const setView = (view) => go({ view: view === 'folders' ? undefined : view, filter: undefined }, ['view', 'filter', 'timeline']);
const setFilter = (filter) => go({ filter: filter === 'all' ? undefined : filter }, ['timeline', 'filter']);

const canAddVisit = computed(() => Boolean(props.person?.canEdit));
const activeVisit = computed(() => (props.panel?.kind === 'visit' ? props.visits.find((v) => v.id === props.panel.id) ?? null : null));
const visitPanelOpen = computed(() => props.panel?.kind === 'new-visit' || (props.panel?.kind === 'visit' && activeVisit.value !== null));
const activeAllergy = computed(() => (props.panel?.kind === 'allergy' ? props.allergies.find((a) => a.id === props.panel.id) ?? null : null));
const allergyPanelOpen = computed(() => props.panel?.kind === 'new-allergy' || (props.panel?.kind === 'allergy' && activeAllergy.value !== null));
const folderVisits = (folder) => props.visits.filter((v) => folder.visitIds.includes(v.id));
</script>

<template>
    <AppShell current="health">
        <Head :title="t('health.title')" />
        <div class="health-person" data-testid="health-person">
            <h1 class="health-person__title">{{ t('health.title') }}</h1>

            <template v-if="person">
                <PersonSwitcher :people="people" :current-id="person.id" :can-create="canCreate" @add="openNew" />
                <PersonHeader :person="person" @add-visit="openNewVisit" />
                <div class="health-person__area">
                    <div class="health-person__side" data-testid="person-side">
                        <AllergyList :allergies="allergies" :can-edit="canAddVisit" @add="openNewAllergy" @open="openAllergy" />
                    </div>
                    <section class="health-person__main" data-testid="person-main">
                        <UiEmptyState v-if="visits.length === 0" :title="t('health.visits.empty_title')" :body="t('health.visits.empty_body')" :icon="HeartPulse">
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
                            </div>
                            <TimelineList v-else :entries="timeline" :visits="visits" :filters="timelineFilters" :filter="filter" :busy="busy" @open="openVisit" @filter="setFilter" />
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
        <PersonPanel :open="panel?.kind === 'new-person'" @close="closePanel" />
        <VisitPanel v-if="person" :open="visitPanelOpen" :person-id="person.id" :visit="activeVisit" :can-edit="canAddVisit" :specialties="specialties" @close="closePanel" />
        <AllergyPanel v-if="person" :open="allergyPanelOpen" :person-id="person.id" :allergy="activeAllergy" :can-edit="canAddVisit" @close="closePanel" />
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
