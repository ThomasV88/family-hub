<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ArchiveRestore } from '@lucide/vue';
import UiButton from '../Components/ui/UiButton.vue';

// Archived people, under the person switcher, for members who can change them (the server sends none to anyone else).
// Names are interpolated, never rendered as HTML.
defineProps({
    people: { type: Array, default: () => [] },
});
const { t } = useI18n();
const busyId = ref(null);

function restore(person) {
    if (busyId.value) return;
    router.post(`/health/people/${person.id}/restore`, {}, {
        onStart: () => { busyId.value = person.id; },
        onFinish: () => { busyId.value = null; },
    });
}
</script>

<template>
    <section v-if="people.length > 0" class="archived-people" data-testid="archived-people" aria-labelledby="archived-people-title">
        <h2 id="archived-people-title" class="archived-people__title">{{ t('health.archive.people_title') }}</h2>
        <ul class="archived-people__list">
            <li v-for="person in people" :key="person.id" class="archived-people__row">
                <span class="archived-people__name">{{ person.name }}</span>
                <UiButton variant="secondary" size="sm" :icon="ArchiveRestore" :loading="busyId === person.id" :aria-label="t('health.archive.restore_person', { name: person.name })" data-testid="archived-person-restore" @click="restore(person)">{{ t('health.archive.restore') }}</UiButton>
            </li>
        </ul>
    </section>
</template>

<style scoped>
.archived-people { display: flex; flex-direction: column; gap: var(--space-2); }
.archived-people__title { margin: 0; font-size: var(--text-sm); font-weight: 600; color: var(--color-text-muted); }
.archived-people__list { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: var(--space-2); }
.archived-people__row { display: inline-flex; align-items: center; gap: var(--space-2); padding: var(--space-1) var(--space-3); border: 1px dashed var(--color-border-strong); border-radius: var(--radius-full); max-width: 100%; }
.archived-people__name { overflow-wrap: anywhere; font-size: var(--text-sm); }
</style>
