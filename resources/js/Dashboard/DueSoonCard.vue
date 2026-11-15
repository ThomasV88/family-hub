<script setup>
import { computed, useId } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { CalendarDays, CircleAlert, Clock, ListTodo, Lock, Users } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiCard from '../Components/ui/UiCard.vue';
import UiList from '../Components/ui/UiList.vue';
import UiListRow from '../Components/ui/UiListRow.vue';
import UiTag from '../Components/ui/UiTag.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiEmptyState from '../Components/ui/UiEmptyState.vue';
import { dueInfo } from '../Todo/dueLabel.js';

// dueSoon is the server prop { items, more, hasAnyTasks, today, timezone }; a missing prop renders the empty state.
const props = defineProps({ dueSoon: { type: Object, default: null } });
const { t, locale } = useI18n();
const page = usePage();
const headingId = `ds-${useId()}`;

const canEdit = computed(() => page.props.auth?.access?.todo === 'edit');
const items = computed(() => props.dueSoon?.items ?? []);
const more = computed(() => props.dueSoon?.more ?? 0);
const hasAny = computed(() => props.dueSoon?.hasAnyTasks === true);
const today = computed(() => props.dueSoon?.today ?? new Date().toISOString().slice(0, 10));
const timezone = computed(() => props.dueSoon?.timezone ?? 'UTC');

const ICONS = { today: Clock, overdue: CircleAlert, on: CalendarDays, tomorrow: CalendarDays };
const rows = computed(() => items.value.map((task) => {
    const due = dueInfo(task, today.value, timezone.value, new Date().toISOString(), locale.value);
    const when = due.time ? `${due.date} ${due.time}` : due.date;
    let text = t('todo.due.on', { date: when });
    if (due.kind === 'today') text = due.time ? `${t('todo.due.today')} ${due.time}` : t('todo.due.today');
    else if (due.kind === 'tomorrow') text = due.time ? `${t('todo.due.tomorrow')} ${due.time}` : t('todo.due.tomorrow');
    else if (due.kind === 'overdue') text = t('todo.due.overdue', { date: when });

    return { task, due, text, icon: ICONS[due.kind] };
}));

// Plain anchors keep the links usable without script; a normal click navigates through Inertia.
function go(event, href) {
    if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button > 0) return;
    event.preventDefault();
    router.visit(href);
}
</script>

<template>
    <UiCard :title="t('todo.card.title')" :heading-level="2" :title-id="headingId" data-testid="due-soon">
        <template #action>
            <a href="/todo" class="due-soon__open" :aria-describedby="headingId" @click="go($event, '/todo')">{{ t('todo.card.open') }}</a>
        </template>
        <UiList v-if="rows.length">
            <UiListRow v-for="row in rows" :key="row.task.id" as="link" :href="`/todo?task=${row.task.id}`" @click="go($event, `/todo?task=${row.task.id}`)">
                {{ row.task.title }}
                <template #meta>
                    <span class="due-soon__meta">
                        <UiTag :variant="row.due.tone" :icon="row.icon"><time :datetime="row.task.dueDate + (row.task.dueTime ? 'T' + row.task.dueTime : '')">{{ row.text }}</time></UiTag>
                        <Lock v-if="row.task.visibility === 'personal'" :size="16" :stroke-width="1.75" role="img" :aria-label="t('todo.icon.personal')" />
                        <Users v-else-if="row.task.visibility === 'selected'" :size="16" :stroke-width="1.75" role="img" :aria-label="t('todo.icon.selected')" />
                    </span>
                </template>
            </UiListRow>
        </UiList>
        <p v-if="rows.length && more > 0" class="due-soon__more" data-testid="due-soon-more">{{ t('todo.card.more', { n: more }) }}</p>
        <UiEmptyState v-else-if="!hasAny" :icon="ListTodo" :title="t('empty.tasks.title')" :body="t('empty.tasks.body')" :heading-level="3">
            <template v-if="canEdit" #action>
                <UiButton variant="secondary" @click="router.visit('/todo?task=new')">{{ t('todo.add') }}</UiButton>
            </template>
        </UiEmptyState>
        <p v-else-if="!rows.length" class="due-soon__none" data-testid="due-soon-none">
            {{ t('todo.card.none_due') }}
            <a href="/todo" class="due-soon__open" @click="go($event, '/todo')">{{ t('todo.card.open') }}</a>
        </p>
    </UiCard>
</template>

<style scoped>
.due-soon__open {
    display: inline-flex;
    align-items: center;
    min-height: 44px;
    padding: 0 var(--space-2);
    color: var(--color-primary);
    font-weight: 600;
    text-decoration: none;
}
.due-soon__open:hover { text-decoration: underline; }
.due-soon__open:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 1px; border-radius: var(--radius-md); }
.due-soon__meta { display: inline-flex; align-items: center; gap: var(--space-2); margin-top: var(--space-1); }
.due-soon__more, .due-soon__none { margin: var(--space-3) 0 0; color: var(--color-text-muted); font-size: var(--text-sm); }
</style>
