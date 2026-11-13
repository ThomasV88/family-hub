<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiEmptyState from '../Components/ui/UiEmptyState.vue';
import { useFrameWidth } from '../Shell/shellState.js';
import FilterChips from './FilterChips.vue';
import QuickAddBar from './QuickAddBar.vue';
import TaskColumn from './TaskColumn.vue';
import { dueInfo } from './dueLabel.js';

const props = defineProps({
    timezone: { type: String, required: true },
    today: { type: String, required: true },
    canEdit: { type: Boolean, default: false },
    members: { type: Array, default: () => [] },
    tasks: { type: Array, default: () => [] },
});

const { t, locale } = useI18n();
const page = usePage();
const FILTERS = ['all', 'mine', 'shared', 'personal'];

const query = () => new URLSearchParams(
    (page.url ?? '').includes('?') ? page.url.split('?')[1] : (typeof window !== 'undefined' ? window.location.search.slice(1) : ''),
);
const filter = ref(FILTERS.includes(query().get('filter')) ? query().get('filter') : 'all');
const quickAdd = ref(null);
const root = ref(null);
const viewOnlyDismissed = ref(false);
const me = computed(() => page.props.auth?.user?.id ?? null);

// Selected task lives in the URL (?task=id) so a panel (plan 03-05) survives reload and push deep links.
const selectedTaskId = computed(() => {
    const id = Number(query().get('task'));
    return Number.isInteger(id) && id > 0 && props.tasks.some((task) => task.id === id) ? id : null;
});

function navigate(next) {
    const params = {};
    if (next.filter && next.filter !== 'all') params.filter = next.filter;
    if (next.task) params.task = next.task;
    router.get('/todo', params, { preserveState: true, preserveScroll: true, replace: true });
}
function setFilter(value) {
    filter.value = value;
    navigate({ filter: value, task: selectedTaskId.value });
}
function openTask(id) {
    navigate({ filter: filter.value, task: id });
}

const isDone = (task) => task.status === 'done' || task.completedAt !== null;
const now = () => new Date().toISOString();
const sortKey = (task) => (task.dueDate ? `${task.dueDate}T${task.dueTime ?? '23:59'}` : null);

function compareOpen(a, b) {
    const overdue = (task) => dueInfo(task, props.today, props.timezone, now(), locale.value).kind === 'overdue';
    const oa = overdue(a);
    const ob = overdue(b);
    if (oa !== ob) return oa ? -1 : 1;
    const ka = sortKey(a);
    const kb = sortKey(b);
    if (ka && kb) return ka < kb ? -1 : ka > kb ? 1 : a.id - b.id;
    if (ka) return -1;
    if (kb) return 1;
    return a.id - b.id;
}

const matches = (task) => {
    if (filter.value === 'mine') return task.assigneeId === me.value;
    if (filter.value === 'shared') return task.visibility !== 'personal';
    if (filter.value === 'personal') return task.visibility === 'personal' && task.creatorId === me.value;
    return true;
};

const filtered = computed(() => props.tasks.filter(matches));

const columns = computed(() => {
    const collator = new Intl.Collator(locale.value);
    const known = new Set(props.members.map((m) => m.id));
    const others = props.members.filter((m) => m.id !== me.value).sort((a, b) => collator.compare(a.name, b.name));
    const self = props.members.find((m) => m.id === me.value);
    const people = [...(self ? [self] : []), ...others];

    const build = (owner) => {
        const mine = filtered.value.filter((task) => (owner === null ? task.assigneeId === null || !known.has(task.assigneeId) : task.assigneeId === owner));
        return {
            open: mine.filter((task) => !isDone(task)).sort(compareOpen),
            done: mine.filter(isDone).sort((a, b) => (a.completedAt < b.completedAt ? 1 : a.completedAt > b.completedAt ? -1 : 0)),
        };
    };

    const result = people.map((m, i) => ({ key: `u${m.id}`, id: m.id, name: m.name, tint: (i % 6) + 1, isYou: m.id === me.value, unassigned: false, ...build(m.id) }));
    const unassigned = build(null);
    if (unassigned.open.length || unassigned.done.length) {
        result.push({ key: 'none', id: null, name: t('todo.column.unassigned'), tint: 1, isYou: false, unassigned: true, ...unassigned });
    }

    // Mine and Personal only ever concern the viewer's own column.
    return filter.value === 'mine' || filter.value === 'personal' ? result.filter((c) => c.isYou) : result;
});

const width = useFrameWidth(root);
const singleColumn = computed(() => width.value < 440);
const expandedOverride = ref({});
const isExpanded = (column) => expandedOverride.value[column.key] ?? (column.isYou || props.members.length <= 3);
function toggle(column) {
    expandedOverride.value = { ...expandedOverride.value, [column.key]: !isExpanded(column) };
}
</script>

<template>
    <div ref="root" class="board" data-testid="todo-board">
        <h1 class="board__title">{{ t('todo.title') }}</h1>
        <UiAlert v-if="!canEdit && !viewOnlyDismissed" variant="info" dismissible class="board__block" @dismiss="viewOnlyDismissed = true">{{ t('todo.viewonly') }}</UiAlert>
        <QuickAddBar v-if="canEdit" ref="quickAdd" class="board__block" />
        <FilterChips :model-value="filter" class="board__block" @update:model-value="setFilter" />

        <UiEmptyState v-if="tasks.length === 0" :title="t('todo.empty.title')" :body="t('todo.empty.body')" :heading-level="2">
            <template v-if="canEdit" #action>
                <UiButton variant="secondary" :icon="Plus" @click="quickAdd?.focus()">{{ t('todo.add') }}</UiButton>
            </template>
        </UiEmptyState>
        <UiEmptyState v-else-if="filtered.length === 0" :title="t('todo.filter_empty.title')" :body="t('todo.filter_empty.body')" :heading-level="2">
            <template #action>
                <UiButton variant="secondary" @click="setFilter('all')">{{ t('todo.filter_empty.action') }}</UiButton>
            </template>
        </UiEmptyState>
        <div v-else class="board__grid">
            <TaskColumn
                v-for="column in columns"
                :key="column.key"
                :name="column.name"
                :tint="column.tint"
                :is-you="column.isYou"
                :unassigned="column.unassigned"
                :open="column.open"
                :done="column.done"
                :can-edit="canEdit"
                :today="today"
                :timezone="timezone"
                :selected-id="selectedTaskId"
                :collapsible="singleColumn"
                :expanded="isExpanded(column)"
                @open="openTask"
                @toggle="toggle(column)"
            />
        </div>
    </div>
</template>

<style scoped>
.board { container-type: inline-size; container-name: board; }
.board__title { margin: 0 0 var(--space-4); font-size: var(--text-xl); font-weight: 600; line-height: 1.2; }
.board__block { margin-bottom: var(--space-4); }
.board__grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--space-4); align-items: start; margin-top: var(--space-4); }
@container board (min-width: 440px) {
    .board__grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@container board (min-width: 700px) {
    .board__grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
</style>
