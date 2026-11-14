<script setup>
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { HeartPulse } from '@lucide/vue';
import AppShell from '../../Layouts/AppShell.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import PersonHeader from '../../Health/PersonHeader.vue';
import PersonPanel from '../../Health/PersonPanel.vue';
import PersonSwitcher from '../../Health/PersonSwitcher.vue';

// Person page. The server decides who is shown; no selection or draft is kept on the client (no Inertia
// history state, no storage), and the title is always the module name, never a person.
defineProps({
    people: { type: Array, default: () => [] },
    person: { type: Object, default: null },
    canCreate: { type: Boolean, default: false },
    panel: { type: Object, default: null },
});
const { t } = useI18n();

function visit(query) {
    router.get(window.location.pathname, query, { preserveState: true, preserveScroll: true, replace: true, only: ['panel'] });
}
const openNew = () => visit({ panel: 'new-person' });
const closePanel = () => visit({});
</script>

<template>
    <AppShell current="health">
        <Head :title="t('health.title')" />
        <div class="health-person" data-testid="health-person">
            <h1 class="health-person__title">{{ t('health.title') }}</h1>

            <template v-if="person">
                <PersonSwitcher :people="people" :current-id="person.id" :can-create="canCreate" @add="openNew" />
                <PersonHeader :person="person" />
                <div class="health-person__area">
                    <div class="health-person__side" data-testid="person-side" />
                    <section class="health-person__main" data-testid="person-main">
                        <UiEmptyState :title="t('health.visits.empty_title')" :body="t('health.visits.empty_body')" :icon="HeartPulse" />
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
    </AppShell>
</template>

<style scoped>
.health-person { display: flex; flex-direction: column; gap: var(--space-4); min-width: 0; container-type: inline-size; }
.health-person__title { margin: 0; font-size: var(--text-2xl); font-weight: 600; line-height: 1.2; }
.health-person__area { display: flex; flex-direction: column; gap: var(--space-8); }
.health-person__side { order: -1; display: flex; flex-direction: column; gap: var(--space-4); }
.health-person__side:empty { display: none; }
.health-person__main { min-width: 0; }
@container (min-width: 900px) {
    .health-person__area { flex-direction: row; align-items: flex-start; }
    .health-person__main { flex: 1; order: 0; }
    .health-person__side { order: 1; flex: none; width: 320px; display: flex; }
    .health-person__side:empty { display: flex; }
}
</style>
