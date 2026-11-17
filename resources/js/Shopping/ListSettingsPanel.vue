<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiDetailPanel from '../Components/ui/UiDetailPanel.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import UiSelect from '../Components/ui/UiSelect.vue';
import ListReminderField from './ListReminderField.vue';
import PeopleField from './PeopleField.vue';
import { httpHandlers } from './failure.js';
import { NAME_MAX } from './limits.js';

// List settings: name, store, responsible person, who may add and edit items, who may only view, and the reminder.
// The server decides what a person may change (`abilities`); other fields render read-only and are never sent.
// list: the `list` prop of Shopping/Show. members: { contributors, viewers }. candidates: only present for people who may manage
// members or the responsible person. responsiblePushEnabled: only present for people who may set the reminder.
const props = defineProps({
    open: { type: Boolean, default: false },
    list: { type: Object, required: true },
    abilities: { type: Object, default: () => ({}) },
    members: { type: Object, default: () => ({ contributors: [], viewers: [] }) },
    stores: { type: Array, default: () => [] },
    candidates: { type: Object, default: null },
    responsiblePushEnabled: { type: Boolean, default: null },
});
const emit = defineEmits(['update:open']);
const { t } = useI18n();

const localInput = (iso) => (iso ? String(iso).slice(0, 16) : '');
const ids = (people) => people.map((p) => p.id);
const snapshot = () => ({
    name: props.list.name,
    store_id: props.list.store?.id ?? '',
    responsible_id: props.list.responsible.id,
    contributor_ids: ids(props.members.contributors ?? []),
    viewer_ids: ids(props.members.viewers ?? []),
    remind_at: localInput(props.list.remindAt),
});

const form = useForm(snapshot());
const initial = ref(snapshot());
const hint = ref('');
const httpError = ref('');

function reset() {
    initial.value = snapshot();
    Object.assign(form, snapshot());
    form.clearErrors();
    hint.value = '';
    httpError.value = '';
}
watch(() => props.open, (open) => open && reset(), { immediate: true });

const canName = computed(() => props.abilities.editSettings === true);
const canMembers = computed(() => props.abilities.manageMembers === true);
const canResponsible = computed(() => props.abilities.changeResponsible === true);
const canReminder = computed(() => props.abilities.setReminder === true);
const editable = computed(() => canName.value || canMembers.value || canResponsible.value || canReminder.value);

// Everyone who may appear by name: the list's own members first, then the candidates.
const directory = computed(() => {
    const map = {};
    const add = (p) => { if (p) map[p.id] = map[p.id] ?? p; };
    (props.members.contributors ?? []).forEach(add);
    (props.members.viewers ?? []).forEach(add);
    ['responsible', 'contributors', 'viewers'].forEach((k) => (props.candidates?.[k] ?? []).forEach(add));
    add(props.list.creator);
    add(props.list.responsible);

    return map;
});
const nameOf = (id) => directory.value[id]?.name ?? '';

const responsibleOptions = computed(() => {
    const people = [...(props.candidates?.responsible ?? [])];
    if (!people.some((p) => p.id === props.list.responsible.id)) people.unshift(props.list.responsible);

    return people;
});
const storeOptions = computed(() => props.stores ?? []);

// The creator and the responsible person hold their role already, so they are never offered as contributor or viewer.
const reserved = computed(() => [props.list.creator?.id, Number(form.responsible_id)].filter((x) => Number.isInteger(x)));

function setResponsible(value) {
    const id = Number(value);
    form.responsible_id = id;
    form.contributor_ids = form.contributor_ids.filter((x) => x !== id);
    form.viewer_ids = form.viewer_ids.filter((x) => x !== id);
}

function addPerson(role, id) {
    const other = role === 'contributor_ids' ? 'viewer_ids' : 'contributor_ids';
    if (form[other].includes(id)) {
        form[other] = form[other].filter((x) => x !== id);
        hint.value = t(role === 'contributor_ids' ? 'shopping.panel.moved_to_contributors' : 'shopping.panel.moved_to_viewers', { name: nameOf(id) });
    } else {
        hint.value = '';
    }
    form[role] = [...form[role], id];
}
function removePerson(role, id) {
    hint.value = '';
    form[role] = form[role].filter((x) => x !== id);
}

const sameSet = (a, b) => a.length === b.length && [...a].sort().every((v, i) => v === [...b].sort()[i]);
const changed = computed(() => ({
    name: canName.value && form.name !== initial.value.name,
    store: canName.value && String(form.store_id) !== String(initial.value.store_id),
    responsible: canResponsible.value && form.responsible_id !== initial.value.responsible_id,
    members: canMembers.value && !(sameSet(form.contributor_ids, initial.value.contributor_ids) && sameSet(form.viewer_ids, initial.value.viewer_ids)),
    reminder: canReminder.value && form.remind_at !== initial.value.remind_at,
}));
const dirty = computed(() => Object.values(changed.value).some(Boolean));

const translate = (message) => (String(message).startsWith('shopping.') ? t(message) : String(message));
const err = (key) => (form.errors?.[key] ? translate(form.errors[key]) : '');
const reminderError = computed(() => err('remind_at'));
const peopleError = computed(() => err('contributor_ids') || err('contributors') || err('viewer_ids') || err('viewers'));
const failed = computed(() => Object.keys(form.errors ?? {}).length > 0 || httpError.value !== '');
const close = () => emit('update:open', false);

function save() {
    if (!dirty.value || form.processing) return;
    const c = changed.value;
    form.transform((data) => {
        const body = {};
        if (c.name) body.name = data.name;
        if (c.store) body.store_id = data.store_id === '' ? null : Number(data.store_id);
        if (c.responsible) body.responsible_id = data.responsible_id;
        if (c.members) {
            body.contributor_ids = data.contributor_ids;
            body.viewer_ids = data.viewer_ids;
        }
        if (c.reminder) body.remind_at = data.remind_at === '' ? null : data.remind_at;

        return body;
    });
    httpError.value = '';
    form.put(`/shopping/lists/${props.list.id}`, {
        preserveScroll: true,
        onSuccess: close,
        ...httpHandlers((key) => { httpError.value = t(key); }),
    });
}
</script>

<template>
    <UiDetailPanel :open="open" :title="t('shopping.panel.title')" @update:open="emit('update:open', $event)">
        <form id="list-settings-form" class="panel" novalidate @submit.prevent="save">
            <div aria-live="polite">
                <UiAlert v-if="failed" variant="danger" data-testid="panel-failed">{{ httpError || t('shopping.error.save_failed') }}</UiAlert>
            </div>
            <p v-if="!editable" class="panel__readonly" data-testid="panel-readonly">{{ t('shopping.panel.read_only') }}</p>

            <UiField :label="t('shopping.panel.name')" :error="err('name')">
                <UiInput v-model="form.name" autocomplete="off" :readonly="!canName" :maxlength="NAME_MAX" />
            </UiField>
            <UiField :label="t('shopping.panel.store')" :error="err('store_id')">
                <UiSelect v-model="form.store_id" :disabled="!canName">
                    <option value="">{{ t('shopping.panel.store_none') }}</option>
                    <option v-for="s in storeOptions" :key="s.id" :value="s.id">{{ s.name }}</option>
                </UiSelect>
            </UiField>
            <UiField :label="t('shopping.panel.responsible')" help="shopping.roles" :hint="t('shopping.panel.responsible_hint')" :error="err('responsible_id')">
                <UiSelect :model-value="form.responsible_id" :disabled="!canResponsible" @update:model-value="setResponsible">
                    <option v-for="p in responsibleOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
                </UiSelect>
            </UiField>

            <PeopleField
                :label="t('shopping.panel.contributors')"
                :model-value="form.contributor_ids"
                :directory="directory"
                :candidates="candidates?.contributors ?? []"
                :exclude="reserved"
                :readonly="!canMembers"
                :error="peopleError"
                @add="addPerson('contributor_ids', $event)"
                @remove="removePerson('contributor_ids', $event)"
            />
            <PeopleField
                :label="t('shopping.panel.viewers')"
                :model-value="form.viewer_ids"
                :directory="directory"
                :candidates="candidates?.viewers ?? []"
                :exclude="reserved"
                :readonly="!canMembers"
                @add="addPerson('viewer_ids', $event)"
                @remove="removePerson('viewer_ids', $event)"
            />
            <p v-if="hint" class="panel__hint" role="status" data-testid="panel-moved">{{ hint }}</p>

            <ListReminderField
                v-model="form.remind_at"
                :readonly="!canReminder"
                :error="reminderError"
                :sent="list.reminderSent === true"
                :push-off-name="canReminder && responsiblePushEnabled === false ? list.responsible.name : ''"
            />
        </form>
        <template v-if="editable" #footer>
            <UiButton variant="ghost" @click="close">{{ t('shopping.panel.cancel') }}</UiButton>
            <UiButton type="submit" form="list-settings-form" variant="primary" :disabled="!dirty || form.processing" :loading="form.processing" data-testid="panel-save">{{ t('shopping.panel.save') }}</UiButton>
        </template>
    </UiDetailPanel>
</template>

<style scoped>
.panel { display: flex; flex-direction: column; gap: var(--space-2); }
.panel__readonly { margin: 0 0 var(--space-2); color: var(--color-text-muted); font-size: var(--text-sm); }
.panel__hint { margin: 0 0 var(--space-3); color: var(--color-text-muted); font-size: var(--text-sm); }
</style>
