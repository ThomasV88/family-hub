<script setup>
import { ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { ChevronDown, Folder, FolderOpen } from '@lucide/vue';
import TimelineList from './TimelineList.vue';

// Disclosure folder of one specialty. Expansion is client state per page load.
const props = defineProps({
    folder: { type: Object, required: true },
    visits: { type: Array, default: () => [] },
    defaultOpen: { type: Boolean, default: false },
});
const emit = defineEmits(['open']);
const { t } = useI18n();
const open = ref(props.defaultOpen);
const uid = useId();
const headerId = `folder-h-${uid}`;
const bodyId = `folder-b-${uid}`;

const title = () => (props.folder.key === 'other' && props.folder.otherLabel ? props.folder.otherLabel : t(`health.specialty.${props.folder.key}`));
const entries = () => props.folder.visitIds.map((id) => ({ type: 'visit', id, date: props.visits.find((v) => v.id === id)?.visitedOn }));
</script>

<template>
    <section class="folder" data-testid="specialty-folder">
        <h3 class="folder__heading">
            <button :id="headerId" type="button" class="folder__header" :aria-expanded="open ? 'true' : 'false'" :aria-controls="bodyId" @click="open = !open">
                <component :is="open ? FolderOpen : Folder" :size="20" :stroke-width="1.75" aria-hidden="true" />
                <span class="folder__name">{{ title() }}</span>
                <span class="folder__count">{{ t('health.folder.visits', { n: folder.count }, folder.count) }}</span>
                <ChevronDown :size="20" :stroke-width="1.75" aria-hidden="true" class="folder__chevron" :class="{ 'folder__chevron--open': open }" />
            </button>
        </h3>
        <div v-show="open" :id="bodyId" role="region" :aria-labelledby="headerId" class="folder__body">
            <TimelineList :entries="entries()" :visits="visits" @open="(id) => emit('open', id)" />
        </div>
    </section>
</template>

<style scoped>
.folder { border: 1px solid var(--color-border); border-radius: var(--radius-lg); background: var(--color-surface); }
.folder__heading { margin: 0; font: inherit; }
.folder__header { display: flex; align-items: center; gap: var(--space-3); width: 100%; min-height: 44px; padding: var(--space-2) var(--space-4); border: 0; background: transparent; color: inherit; font: inherit; text-align: start; cursor: pointer; border-radius: var(--radius-lg); }
.folder__header:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.folder__name { flex: 1; min-width: 0; font-size: var(--text-base); font-weight: 600; overflow-wrap: anywhere; }
.folder__count { font-size: var(--text-sm); color: var(--color-text-muted); }
.folder__chevron { flex: none; transition: transform 150ms; }
.folder__chevron--open { transform: rotate(180deg); }
.folder__body { padding: var(--space-2) var(--space-4) var(--space-4); }
</style>
