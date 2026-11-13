<script setup>
import { computed, ref, useId } from 'vue';
import { ChevronDown, Plus } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../Components/ui/UiButton.vue';
import UiAvatar from '../Components/ui/UiAvatar.vue';
import UiTag from '../Components/ui/UiTag.vue';
import TaskCard from './TaskCard.vue';

const props = defineProps({
    name: { type: String, required: true },
    tint: { type: Number, default: 1 },
    isYou: { type: Boolean, default: false },
    open: { type: Array, default: () => [] },
    done: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
    today: { type: String, required: true },
    timezone: { type: String, required: true },
    selectedId: { type: Number, default: null },
    // Single-column layout: the header becomes a disclosure button.
    collapsible: { type: Boolean, default: false },
    expanded: { type: Boolean, default: true },
    unassigned: { type: Boolean, default: false },
    personId: { type: Number, default: null },
});
defineEmits(['open', 'toggle', 'add']);
const { t } = useI18n();
const uid = useId();
const headingId = `col-${uid}`;
const bodyId = `col-body-${uid}`;
const showDone = ref(false);
const shown = computed(() => !props.collapsible || props.expanded);
const doneShown = computed(() => props.done.slice(0, 20));
</script>

<template>
    <section class="column" :aria-labelledby="headingId" data-testid="task-column">
        <header class="column__header">
            <UiAvatar v-if="!unassigned" :name="name" :tint="tint" :size="32" decorative />
            <h2 :id="headingId" class="column__name">
                <button v-if="collapsible" type="button" class="column__toggle" :aria-expanded="expanded ? 'true' : 'false'" :aria-controls="bodyId" @click="$emit('toggle')">
                    <span>{{ name }}</span>
                    <ChevronDown :size="20" :stroke-width="1.75" aria-hidden="true" :class="['column__chevron', { 'is-open': expanded }]" />
                </button>
                <template v-else>{{ name }}</template>
            </h2>
            <UiTag v-if="isYou">{{ t('todo.column.you') }}</UiTag>
            <span class="column__count">{{ t('todo.column.count', open.length) }}</span>
        </header>
        <div v-show="shown" :id="bodyId" class="column__body">
            <ul v-if="open.length" class="column__list">
                <TaskCard v-for="task in open" :key="task.id" :task="task" :today="today" :timezone="timezone" :selected="selectedId === task.id" @open="$emit('open', $event)" />
            </ul>
            <p v-else class="column__empty">{{ canEdit && !unassigned ? t('todo.column.empty_add') : t('todo.column.empty') }}</p>
            <template v-if="done.length">
                <button type="button" class="column__done-toggle" :aria-expanded="showDone ? 'true' : 'false'" @click="showDone = !showDone">
                    {{ t(showDone ? 'todo.done.hide' : 'todo.done.show', { n: done.length }) }}
                </button>
                <ul v-if="showDone" class="column__list">
                    <TaskCard v-for="task in doneShown" :key="task.id" :task="task" :today="today" :timezone="timezone" :selected="selectedId === task.id" @open="$emit('open', $event)" />
                </ul>
            </template>
            <UiButton v-if="canEdit && !unassigned && personId !== null" variant="ghost" :icon="Plus" class="column__add" @click="$emit('add', personId, $event)">{{ t('todo.column.add', { name }) }}</UiButton>
        </div>
    </section>
</template>

<style scoped>
.column { min-width: 0; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); }
.column__header { display: flex; align-items: center; gap: var(--space-2); padding: var(--space-3); background: var(--color-surface-2); border-start-start-radius: var(--radius-lg); border-start-end-radius: var(--radius-lg); }
.column__name { margin: 0; flex: 1; min-width: 0; font-size: var(--text-base); font-weight: 600; line-height: 1.5; overflow-wrap: anywhere; }
.column__count { flex: none; color: var(--color-text-muted); font-size: var(--text-sm); font-weight: 400; }
.column__toggle { display: flex; align-items: center; gap: var(--space-2); width: 100%; min-height: var(--control-touch); padding: 0; border: 0; background: transparent; color: inherit; font: inherit; text-align: start; cursor: pointer; }
.column__toggle:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; border-radius: var(--radius-sm); }
.column__chevron { flex: none; transition: transform 150ms; }
.column__chevron.is-open { transform: rotate(180deg); }
.column__body { display: flex; flex-direction: column; gap: var(--space-2); padding: var(--space-3); }
.column__list { display: flex; flex-direction: column; gap: var(--space-2); margin: 0; padding: 0; }
.column__add { align-self: flex-start; }
.column__empty { margin: 0; color: var(--color-text-muted); font-size: var(--text-sm); }
.column__done-toggle { align-self: flex-start; min-height: var(--control-sm); padding: 0 var(--space-1); border: 0; background: transparent; color: var(--color-text-muted); font: inherit; font-size: var(--text-sm); font-weight: 600; cursor: pointer; text-decoration: underline; }
.column__done-toggle:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; border-radius: var(--radius-sm); }
@media (pointer: coarse) {
    .column__done-toggle { min-height: var(--control-touch); }
}
@media (prefers-reduced-motion: reduce) {
    .column__chevron { transition: none; }
}
</style>
