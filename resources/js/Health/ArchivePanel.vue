<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ArchiveRestore } from '@lucide/vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiDetailPanel from '../Components/ui/UiDetailPanel.vue';

// The Archive section of one person: archived records grouped by type, each with a Restore action. The server sends this only
// to members who can change the person. Labels are the record's own text, interpolated, never rendered as HTML.
const props = defineProps({
    open: { type: Boolean, default: false },
    personId: { type: String, required: true },
    items: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);
const { t, d } = useI18n();
const busyId = ref(null);

const TYPES = ['visit', 'medication', 'allergy', 'vaccination', 'document'];
const groups = computed(() => TYPES
    .map((type) => ({ type, rows: props.items.filter((i) => i.type === type) }))
    .filter((g) => g.rows.length > 0));

const dateText = (iso) => d(new Date(`${iso}T12:00:00`), 'short');

function restore(item) {
    if (busyId.value) return;
    router.post(`/health/people/${props.personId}/archive/${item.type}/${item.id}/restore`, {}, {
        preserveScroll: true,
        onStart: () => { busyId.value = item.id; },
        onFinish: () => { busyId.value = null; },
    });
}
</script>

<template>
    <UiDetailPanel :open="open" :title="t('health.archive.title')" @update:open="(v) => (v ? null : emit('close'))">
        <div data-testid="archive-panel" class="archive">
            <p class="archive__intro">{{ t('health.archive.intro') }}</p>
            <p v-if="groups.length === 0" class="archive__empty" data-testid="archive-empty">{{ t('health.archive.empty') }}</p>
            <section v-for="group in groups" :key="group.type" class="archive__group" :data-testid="`archive-group-${group.type}`">
                <h3 class="archive__heading">{{ t(`health.archive.type_${group.type}`) }}</h3>
                <ul class="archive__list">
                    <li v-for="item in group.rows" :key="item.id" class="archive__row">
                        <div class="archive__text">
                            <span class="archive__label">{{ item.label || t(`health.archive.type_${group.type}`) }}</span>
                            <span class="archive__meta">
                                <template v-if="item.date">{{ dateText(item.date) }} · </template>{{ t('health.archive.archived_on', { date: dateText(item.archivedOn) }) }}
                            </span>
                        </div>
                        <UiButton variant="secondary" size="sm" :icon="ArchiveRestore" :loading="busyId === item.id" data-testid="archive-restore" @click="restore(item)">{{ t('health.archive.restore') }}</UiButton>
                    </li>
                </ul>
            </section>
        </div>
        <template #footer>
            <UiButton variant="secondary" @click="emit('close')">{{ t('ui.close') }}</UiButton>
        </template>
    </UiDetailPanel>
</template>

<style scoped>
.archive { display: flex; flex-direction: column; gap: var(--space-4); }
.archive__intro, .archive__empty { margin: 0; color: var(--color-text-muted); font-size: var(--text-sm); }
.archive__heading { margin: 0 0 var(--space-2); font-size: var(--text-base); font-weight: 600; }
.archive__list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--space-2); }
.archive__row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-2); padding: var(--space-2) var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-md); background: var(--color-surface); }
.archive__text { display: flex; flex-direction: column; min-width: 0; }
.archive__label { overflow-wrap: anywhere; font-weight: 600; }
.archive__meta { font-size: var(--text-sm); color: var(--color-text-muted); }
</style>
