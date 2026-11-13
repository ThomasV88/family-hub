<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { ChevronDown, SkipForward, Trash2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiConfirmDialog from '../Components/ui/UiConfirmDialog.vue';
import UiDetailPanel from '../Components/ui/UiDetailPanel.vue';
import UiErrorState from '../Components/ui/UiErrorState.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import UiSelect from '../Components/ui/UiSelect.vue';
import UiSwitch from '../Components/ui/UiSwitch.vue';
import UiTextarea from '../Components/ui/UiTextarea.vue';
import ReminderField from './ReminderField.vue';
import RecurrenceFields from './RecurrenceFields.vue';
import StatusChips from './StatusChips.vue';
import VisibilityField from './VisibilityField.vue';
import { emptyRepeat, intervalValid, patternFromRepeat, repeatFromTask } from './recurrenceState.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    // 'create' | 'edit' | 'missing'
    mode: { type: String, default: 'edit' },
    task: { type: Object, default: null },
    members: { type: Array, default: () => [] },
    me: { type: Number, default: null },
    timezone: { type: String, required: true },
    initialAssignee: { type: Number, default: null },
    // Completed and skipped occurrences of the open recurring task, newest first: [{ kind, at }]
    history: { type: Array, default: null },
});
const emit = defineEmits(['close']);
const { t, locale } = useI18n();

const creating = computed(() => props.mode === 'create');
const missing = computed(() => props.mode === 'missing' || (props.mode === 'edit' && !props.task));
const abilities = computed(() => (creating.value ? { edit: true, status: true, delete: false, visibility: true } : (props.task?.abilities ?? { edit: false, status: false, delete: false, visibility: false })));
const readonly = computed(() => !abilities.value.edit);
const title = computed(() => (creating.value ? t('todo.panel.new') : t('todo.panel.edit')));

const known = computed(() => new Set(props.members.map((m) => m.id)));
function initial() {
    const task = props.task;
    if (creating.value || !task) {
        const wanted = props.initialAssignee !== null && known.value.has(props.initialAssignee) ? props.initialAssignee : props.me;
        return { title: '', status: 'todo', assignee: wanted !== null && known.value.has(wanted) ? String(wanted) : '', dueDate: '', showTime: false, dueTime: '', notes: '', visibility: 'family', memberIds: [], repeat: emptyRepeat(), remind: 0 };
    }
    return {
        title: task.title,
        status: task.status,
        assignee: task.assigneeId !== null && known.value.has(task.assigneeId) ? String(task.assigneeId) : '',
        dueDate: task.dueDate ?? '',
        showTime: Boolean(task.dueTime),
        dueTime: task.dueTime ?? '',
        notes: task.notes ?? '',
        visibility: task.visibility ?? 'family',
        memberIds: (task.memberIds ?? []).filter((id) => id !== task.creatorId),
        repeat: repeatFromTask(task.recurrence, task.recurrenceMode),
        remind: task.remindBefore === undefined ? 0 : task.remindBefore,
    };
}

const form = ref(initial());
const snapshot = ref(JSON.stringify(form.value));
const errors = ref({});
const serverError = ref(false);
const processing = ref(false);
const body = ref(null);
const titleField = ref(null);
const discardOpen = ref(false);
const deleteOpen = ref(false);
let pendingAfterDiscard = null;

const dirty = computed(() => JSON.stringify(form.value) !== snapshot.value);
const valid = computed(() => form.value.title.trim().length > 0 && form.value.title.length <= 200);
const canSave = computed(() => !readonly.value && dirty.value && valid.value && !processing.value);

function reset() {
    form.value = initial();
    snapshot.value = JSON.stringify(form.value);
    errors.value = {};
    serverError.value = false;
}

// A different task, a new mode or fresh server data (when nothing is being edited) reloads the form.
watch(() => [props.mode, props.task?.id, props.initialAssignee], reset);
watch(() => props.task, () => { if (!dirty.value) reset(); });
watch(() => props.open, async (open) => {
    if (open && creating.value) {
        await nextTick();
        setTimeout(() => titleField.value?.focus(), 50);
    }
}, { immediate: true });

function setDueDate(value) {
    form.value.dueDate = value;
    if (!value) {
        form.value.showTime = false;
        form.value.dueTime = '';
        // No date: nothing can repeat and the reminder falls back to its default.
        form.value.repeat = { ...form.value.repeat, kind: 'none' };
        form.value.remind = 0;
    }
}
function setShowTime(on) {
    form.value.showTime = on;
    if (!on) form.value.dueTime = '';
}
const hasTime = computed(() => Boolean(form.value.dueDate && form.value.showTime && form.value.dueTime));
// Minute and hour reminders need a time: a date-only task goes back to "at due time".
watch(hasTime, (has) => {
    if (!has && (form.value.remind === 15 || form.value.remind === 60)) form.value.remind = 0;
});
const isRecurring = computed(() => !creating.value && Boolean(props.task?.isRecurring));
const closedOccurrence = computed(() => isRecurring.value && Boolean(props.task?.completedAt));
const canSkip = computed(() => isRecurring.value && !closedOccurrence.value && abilities.value.status === true);

const creatorId = computed(() => (creating.value ? props.me : (props.task?.creatorId ?? props.me)));

// Personal forces the assignee to the creator; Selected drops an assignee who is no longer a member.
function setVisibility(value) {
    form.value.visibility = value;
    if (value === 'personal' && creatorId.value !== null) form.value.assignee = String(creatorId.value);
    else if (value === 'selected') dropAssigneeIfOutside();
}
function setMemberIds(ids) {
    form.value.memberIds = ids;
    dropAssigneeIfOutside();
}
function dropAssigneeIfOutside() {
    if (form.value.visibility !== 'selected' || form.value.assignee === '') return;
    const id = Number(form.value.assignee);
    if (id !== creatorId.value && !form.value.memberIds.includes(id)) {
        form.value.assignee = creatorId.value !== null && known.value.has(creatorId.value) ? String(creatorId.value) : '';
    }
}

function payload() {
    const f = form.value;
    const visibility = {};
    if (abilities.value.visibility) {
        // Family is the server default on create, so a plain create stays title and fields only.
        if (!creating.value || f.visibility !== 'family') visibility.visibility = f.visibility;
        if (f.visibility === 'selected') visibility.member_ids = f.memberIds;
    }
    return {
        ...visibility,
        title: f.title.trim(),
        status: f.status,
        assignee_id: f.assignee === '' ? null : Number(f.assignee),
        due_date: f.dueDate || null,
        due_time: f.dueDate && f.showTime && f.dueTime ? f.dueTime : null,
        notes: f.notes.trim() === '' ? null : f.notes,
        ...repeatPayload(),
        ...reminderPayload(),
    };
}

// The client sends the structured pattern only, never an RRULE. A plain create stays free of these keys.
function repeatPayload() {
    const f = form.value;
    const pattern = f.dueDate ? patternFromRepeat(f.repeat, f.dueDate) : null;
    if (pattern) return { recurrence: pattern, recurrence_mode: f.repeat.mode };
    return creating.value ? {} : { recurrence: null };
}
function reminderPayload() {
    const f = form.value;
    if (!f.dueDate) return {};
    if (creating.value && f.remind === 0) return {};
    return { remind_before_minutes: f.remind };
}

const repeatErrors = computed(() => ({ repeat: errors.value.repeat, interval: errors.value.repeatInterval, weekdays: errors.value.repeatWeekdays }));
function validateRepeat() {
    const r = form.value.repeat;
    const next = {};
    if (form.value.dueDate && r.kind === 'weekly' && r.weekdays.length === 0) next.repeatWeekdays = t('todo.error.weekdays');
    if (form.value.dueDate && r.kind === 'custom' && !intervalValid(r.interval)) next.repeatInterval = t('todo.error.interval');
    return next;
}

const FIELD_FOR = { title: 'title', status: 'status', assignee_id: 'assignee', visibility: 'members', member_ids: 'members', due_date: 'dueDate', due_time: 'dueTime', notes: 'notes', recurrence: 'repeat', 'recurrence.interval': 'repeatInterval', 'recurrence.weekdays': 'repeatWeekdays', recurrence_mode: 'repeat', remind_before_minutes: 'reminder' };
const message = (key) => (typeof key === 'string' && key.startsWith('todo.') ? t(key) : t('todo.error.save_failed'));

function visitOptions(onOk) {
    return {
        preserveScroll: true,
        onStart: () => { processing.value = true; serverError.value = false; errors.value = {}; },
        onSuccess: onOk,
        onError: (errs) => {
            const mapped = {};
            for (const [key, value] of Object.entries(errs ?? {})) {
                const name = key.startsWith('member_ids.') ? 'member_ids' : key;
                if (FIELD_FOR[name]) mapped[FIELD_FOR[name]] = message(value);
                else serverError.value = true;
            }
            errors.value = mapped;
            if (Object.keys(mapped).length === 0) serverError.value = true;
            nextTick(() => body.value?.querySelector('[aria-invalid="true"]')?.focus());
        },
        onHttpException: () => { serverError.value = true; },
        onNetworkError: () => { serverError.value = true; },
        onFinish: () => { processing.value = false; },
    };
}

function save() {
    if (!canSave.value) {
        if (!valid.value) {
            errors.value = { title: t('todo.error.title_required') };
            nextTick(() => body.value?.querySelector('[aria-invalid="true"]')?.focus());
        }
        return;
    }
    const clientErrors = validateRepeat();
    if (Object.keys(clientErrors).length) {
        errors.value = clientErrors;
        return;
    }
    const data = payload();
    const sent = JSON.stringify(form.value);
    const done = () => {
        snapshot.value = sent;
        if (creating.value) emit('close');
    };
    if (creating.value) router.post('/todo/tasks', data, visitOptions(done));
    else router.patch(`/todo/tasks/${props.task.id}`, data, visitOptions(done));
}

// Read-only editors that may still change status save it immediately.
function setStatus(status) {
    form.value.status = status;
    if (readonly.value && abilities.value.status && props.task) {
        router.patch(`/todo/tasks/${props.task.id}/status`, { status }, visitOptions(() => { snapshot.value = JSON.stringify(form.value); }));
    }
}

// Enter in a single-line field saves; textareas, buttons and selects keep their own behaviour.
function onEnter(event) {
    if (event.isComposing || event.target?.tagName !== 'INPUT' || event.target.type === 'checkbox') return;
    event.preventDefault();
    save();
}

function cancel() {
    reset();
    emit('close');
}

function skip() {
    router.post(`/todo/tasks/${props.task.id}/skip`, {}, visitOptions(() => {}));
}

const historyOpen = ref(false);
const historyFormat = computed(() => new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'short', timeZone: props.timezone }));
const historyItems = computed(() => (props.history ?? []).map((item) => ({
    ...item,
    label: t(item.kind === 'skipped' ? 'todo.history.skipped' : 'todo.history.completed', { date: historyFormat.value.format(new Date(item.at)) }),
})));

function confirmDelete() {
    router.delete(`/todo/tasks/${props.task.id}`, visitOptions(() => emit('close')));
}

// Runs `then` now, or after the member confirms discarding unsaved edits.
function guard(then) {
    if (props.open && !readonly.value && !missing.value && dirty.value) {
        pendingAfterDiscard = then;
        discardOpen.value = true;
    } else {
        then();
    }
}
function requestClose() {
    guard(() => emit('close'));
}
function discard() {
    const then = pendingAfterDiscard;
    pendingAfterDiscard = null;
    reset();
    then?.();
}
function keepEditing() {
    pendingAfterDiscard = null;
}

const assigneeHint = computed(() => (form.value.visibility === 'personal' ? t('todo.field.assignee_personal') : ''));
const assigneeDisabled = computed(() => readonly.value || form.value.visibility === 'personal');
const memberOptions = computed(() => [
    ...props.members
        .filter((m) => form.value.visibility !== 'selected' || m.id === creatorId.value || form.value.memberIds.includes(m.id))
        .map((m) => ({ value: String(m.id), label: m.name })),
    { value: '', label: t('todo.column.unassigned') },
]);

defineExpose({ guard, dirty });
</script>

<template>
    <UiDetailPanel :open="open" :title="title" @update:open="(v) => (v ? null : requestClose())">
        <div ref="body" data-testid="task-panel">
            <UiErrorState v-if="missing" :title="t('access.denied_title')" :body="t('access.denied_body')" :heading-level="3">
                <template #action>
                    <UiButton variant="secondary" @click="emit('close')">{{ t('todo.close') }}</UiButton>
                </template>
            </UiErrorState>
            <form v-else novalidate @submit.prevent="save" @keydown.enter="onEnter">
                <UiAlert v-if="serverError" variant="danger" live class="todo-panel__alert">{{ t('todo.error.save_failed') }}</UiAlert>

                <UiField :label="t('todo.field.title')" required :error="errors.title">
                    <UiInput ref="titleField" v-model="form.title" :readonly="readonly" maxlength="400" autocomplete="off" />
                </UiField>

                <div class="todo-panel__group">
                    <span class="todo-panel__label" aria-hidden="true">{{ t('todo.status.label') }}</span>
                    <StatusChips :model-value="form.status" :disabled="!(abilities.status)" @update:model-value="setStatus" />
                </div>

                <UiField :label="t('todo.field.assignee')" :hint="assigneeHint" :error="errors.assignee">
                    <UiSelect v-model="form.assignee" :options="memberOptions" :disabled="assigneeDisabled" />
                </UiField>

                <UiField :label="t('todo.field.due')" :hint="t('todo.field.tz_hint', { tz: timezone })" :error="errors.dueDate">
                    <UiInput :model-value="form.dueDate" type="date" :readonly="readonly" @update:model-value="setDueDate" />
                </UiField>
                <div class="todo-panel__group">
                    <UiSwitch :model-value="form.showTime" :disabled="readonly || !form.dueDate" @update:model-value="setShowTime">{{ t('todo.field.add_time') }}</UiSwitch>
                </div>
                <UiField v-if="form.showTime && form.dueDate" :label="t('todo.field.time')" :error="errors.dueTime">
                    <UiInput v-model="form.dueTime" type="time" :readonly="readonly" />
                </UiField>

                <RecurrenceFields v-model="form.repeat" :due-date="form.dueDate" :disabled="readonly" :errors="repeatErrors" />
                <ReminderField v-model="form.remind" :has-date="Boolean(form.dueDate)" :has-time="hasTime" :disabled="readonly" :error="errors.reminder" />

                <VisibilityField
                    :model-value="form.visibility"
                    :member-ids="form.memberIds"
                    :members="members"
                    :creator-id="creatorId"
                    :editable="Boolean(abilities.visibility)"
                    :error="errors.members"
                    @update:model-value="setVisibility"
                    @update:member-ids="setMemberIds"
                />

                <UiField :label="t('todo.field.notes')" :error="errors.notes">
                    <UiTextarea v-model="form.notes" :rows="4" :readonly="readonly" />
                </UiField>

                <section v-if="isRecurring" class="todo-panel__history" data-testid="task-history">
                    <h3 class="todo-panel__history-heading">
                        <button type="button" class="todo-panel__history-toggle" :aria-expanded="historyOpen ? 'true' : 'false'" aria-controls="task-history-list" @click="historyOpen = !historyOpen">
                            {{ t('todo.history.title') }}
                            <ChevronDown :size="20" :stroke-width="1.75" aria-hidden="true" :class="{ 'is-open': historyOpen }" />
                        </button>
                    </h3>
                    <div v-show="historyOpen" id="task-history-list">
                        <ul v-if="historyItems.length" class="todo-panel__history-list">
                            <li v-for="(item, i) in historyItems" :key="i">{{ item.label }}</li>
                        </ul>
                        <p v-else class="todo-panel__history-empty">{{ t('todo.history.empty') }}</p>
                    </div>
                </section>
            </form>
        </div>

        <template #footer>
            <template v-if="missing || readonly">
                <UiButton v-if="canSkip" variant="secondary" :icon="SkipForward" @click="skip">{{ t('todo.skip') }}</UiButton>
                <UiButton variant="secondary" @click="requestClose">{{ t('todo.close') }}</UiButton>
            </template>
            <template v-else>
                <UiButton v-if="!creating && abilities.delete" variant="ghost" :icon="Trash2" class="todo-panel__delete" @click="deleteOpen = true">{{ t('todo.delete.action') }}</UiButton>
                <UiButton v-if="canSkip" variant="secondary" :icon="SkipForward" @click="skip">{{ t('todo.skip') }}</UiButton>
                <UiButton variant="secondary" @click="cancel">{{ t('todo.cancel') }}</UiButton>
                <UiButton variant="primary" :disabled="!canSave" :loading="processing" @click="save">{{ t('todo.save') }}</UiButton>
            </template>
        </template>
    </UiDetailPanel>

    <UiConfirmDialog
        v-model:open="discardOpen"
        :title="t('todo.discard.title')"
        :body="t('todo.discard.body')"
        :confirm-label="t('todo.discard.confirm')"
        :cancel-label="t('todo.discard.keep')"
        @confirm="discard"
        @cancel="keepEditing"
    />
    <UiConfirmDialog
        v-model:open="deleteOpen"
        :title="t('todo.delete.title')"
        :body="t(task?.isRecurring ? 'todo.delete.body_recurring' : 'todo.delete.body', { title: task?.title ?? '' })"
        :confirm-label="t('todo.delete.action')"
        :cancel-label="t('todo.delete.keep')"
        @confirm="confirmDelete"
    />
</template>

<style scoped>
.todo-panel__alert { margin-bottom: var(--space-4); }
.todo-panel__group { display: flex; flex-direction: column; gap: var(--space-1); margin-bottom: var(--space-4); }
.todo-panel__label { font-size: var(--text-sm); font-weight: 600; line-height: 1.4; }
.todo-panel__history { margin-bottom: var(--space-4); }
.todo-panel__history-heading { margin: 0; font-size: var(--text-base); }
.todo-panel__history-toggle { display: flex; align-items: center; justify-content: space-between; width: 100%; min-height: 44px; padding: 0; border: 0; background: transparent; color: var(--color-text); font: inherit; font-weight: 600; cursor: pointer; }
.todo-panel__history-toggle:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.todo-panel__history-toggle .is-open { transform: rotate(180deg); }
.todo-panel__history-list { margin: 0; padding-inline-start: var(--space-4); font-size: var(--text-sm); }
.todo-panel__history-empty { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.todo-panel__delete { margin-inline-end: auto; color: var(--color-danger); }
</style>
