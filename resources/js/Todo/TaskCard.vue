<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { CalendarDays, CircleAlert, CircleDot, Clock, Lock, Repeat, Users } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiCheckbox from '../Components/ui/UiCheckbox.vue';
import UiTag from '../Components/ui/UiTag.vue';
import { useToast } from '../Components/ui/useToast.js';
import { dueInfo } from './dueLabel.js';

const props = defineProps({
    task: { type: Object, required: true },
    today: { type: String, required: true },
    timezone: { type: String, required: true },
    selected: { type: Boolean, default: false },
});
defineEmits(['open']);
const { t, locale } = useI18n();

const toast = useToast();
// Optimistic override of the server state while a status request is in flight.
const optimistic = ref(null);
const serverDone = computed(() => props.task.status === 'done' || props.task.completedAt !== null);
const done = computed(() => optimistic.value ?? serverDone.value);
const canToggle = computed(() => props.task.abilities?.status === true);

function toggle(checked) {
    if (!canToggle.value) return;
    optimistic.value = checked;
    const revert = () => {
        optimistic.value = null;
        toast.show(t('todo.error.toggle_failed'));
    };
    router.patch(`/todo/tasks/${props.task.id}/status`, { status: checked ? 'done' : 'todo' }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { optimistic.value = null; },
        onError: revert,
        onHttpException: revert,
        onNetworkError: revert,
    });
}
const due = computed(() => dueInfo(props.task, props.today, props.timezone, new Date().toISOString(), locale.value));
const dueIcon = computed(() => ({ today: Clock, overdue: CircleAlert, on: CalendarDays, tomorrow: CalendarDays })[due.value.kind]);
const dueText = computed(() => {
    const { kind, date, time } = due.value;
    const when = time ? `${date} ${time}` : date;
    if (kind === 'today') return time ? `${t('todo.due.today')} ${time}` : t('todo.due.today');
    if (kind === 'tomorrow') return time ? `${t('todo.due.tomorrow')} ${time}` : t('todo.due.tomorrow');
    if (kind === 'overdue') return t('todo.due.overdue', { date: when });
    return t('todo.due.on', { date: when });
});
</script>

<template>
    <li :class="['task-card', { 'is-selected': selected, 'is-done': done }]" data-testid="task-card">
        <UiCheckbox
            class="task-card__check"
            :model-value="done"
            :disabled="!canToggle"
            :aria-disabled="canToggle ? undefined : 'true'"
            @update:model-value="toggle"
            :aria-label="done ? t('todo.card.mark_undone', { title: task.title }) : t('todo.card.mark_done', { title: task.title })"
        />
        <div class="task-card__main">
            <button type="button" class="task-card__title" :aria-current="selected ? 'true' : undefined" @click="$emit('open', task.id)">{{ task.title }}</button>
            <div v-if="due.kind !== 'none' || task.status === 'in_progress' || task.isRecurring || task.visibility !== 'family'" class="task-card__meta">
                <UiTag v-if="due.kind !== 'none' && !done" :variant="due.tone" :icon="dueIcon"><time :datetime="task.dueDate + (task.dueTime ? 'T' + task.dueTime : '')">{{ dueText }}</time></UiTag>
                <UiTag v-if="task.status === 'in_progress' && !done" :icon="CircleDot">{{ t('todo.status.in_progress') }}</UiTag>
                <Repeat v-if="task.isRecurring" :size="16" :stroke-width="1.75" role="img" :aria-label="t('todo.icon.repeats')" class="task-card__icon" />
                <Lock v-if="task.visibility === 'personal'" :size="16" :stroke-width="1.75" role="img" :aria-label="t('todo.icon.personal')" class="task-card__icon" />
                <Users v-else-if="task.visibility === 'selected'" :size="16" :stroke-width="1.75" role="img" :aria-label="t('todo.icon.selected')" class="task-card__icon" />
            </div>
        </div>
    </li>
</template>

<style scoped>
.task-card {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    min-height: 44px;
    padding: var(--space-3);
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    list-style: none;
}
.task-card.is-selected { background: var(--color-surface-2); border-inline-start: 2px solid var(--color-border-strong); }
.task-card__check { flex: none; }
.task-card__main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: var(--space-1); }
.task-card__title {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    overflow: hidden;
    padding: 0;
    border: 0;
    background: transparent;
    color: var(--color-text);
    font: inherit;
    text-align: start;
    overflow-wrap: anywhere;
    cursor: pointer;
}
.task-card__title:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; border-radius: var(--radius-sm); }
.is-done .task-card__title { color: var(--color-text-muted); text-decoration: line-through; }
.task-card__meta { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); font-size: var(--text-sm); }
.task-card__icon { color: var(--color-text-muted); }
@media (pointer: coarse) {
    .task-card__title { min-height: var(--control-touch); align-content: center; }
}
</style>
