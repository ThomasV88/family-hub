<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { LogOut, Trash2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import HelpTip from '../Components/ui/HelpTip.vue';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiCheckbox from '../Components/ui/UiCheckbox.vue';
import UiConfirmDialog from '../Components/ui/UiConfirmDialog.vue';
import UiDetailPanel from '../Components/ui/UiDetailPanel.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import UiRadio from '../Components/ui/UiRadio.vue';

// Create or edit a group list. The server decides everything; this panel only collects and explains.
// group (edit): { id, name, mode, is_member, can_manage, members: [{ id, name }] }
const props = defineProps({
    open: { type: Boolean, default: false },
    mode: { type: String, default: 'create' },
    group: { type: Object, default: null },
    // people who can be added: [{ id, name }], the viewer first
    candidates: { type: Array, default: () => [] },
    viewerId: { type: Number, default: null },
});
const emit = defineEmits(['close']);
const { t } = useI18n();

const editing = computed(() => props.mode === 'edit' && props.group !== null);
const readOnly = computed(() => editing.value && props.group.can_manage !== true);
const heading = computed(() => (editing.value ? t('wishlist.group.panel_edit') : t('wishlist.group.panel_new')));

// Members already in the list stay pickable even when they are no longer candidates; the viewer is always in.
const people = computed(() => {
    const list = [...props.candidates];
    for (const m of editing.value ? props.group.members : []) {
        if (!list.some((c) => c.id === m.id)) list.push(m);
    }
    return list;
});

function initial() {
    const ids = editing.value ? props.group.members.map((m) => m.id) : (props.viewerId !== null ? [props.viewerId] : []);
    if (props.viewerId !== null && !ids.includes(props.viewerId)) ids.push(props.viewerId);
    return {
        name: editing.value ? props.group.name : '',
        groupMode: editing.value ? props.group.mode : 'gifts',
        member_ids: [...ids].sort((a, b) => a - b),
    };
}

const form = ref(initial());
const snapshot = ref(JSON.stringify(form.value));
const errors = ref({});
const serverError = ref('');
const processing = ref(false);
const body = ref(null);
const firstField = ref(null);
const discardOpen = ref(false);
const modeOpen = ref(false);
const leaveOpen = ref(false);
const deleteOpen = ref(false);
let pendingAfterDiscard = null;

const dirty = computed(() => !readOnly.value && JSON.stringify(form.value) !== snapshot.value);

function reset() {
    form.value = initial();
    snapshot.value = JSON.stringify(form.value);
    errors.value = {};
    serverError.value = '';
}
watch(() => [props.mode, props.group?.id, props.open], ([, , open], [, , wasOpen]) => { if (open !== wasOpen || !open) reset(); });
watch(() => props.group, () => { if (!dirty.value) reset(); }, { deep: true });
watch(() => props.open, async (open) => {
    if (open && !editing.value) {
        await nextTick();
        setTimeout(() => firstField.value?.focus(), 50);
    }
}, { immediate: true });

const modeOptions = computed(() => [
    { value: 'gifts', label: t('wishlist.group.mode_gifts'), hint: t('wishlist.group.mode_gifts_hint') },
    { value: 'shared', label: t('wishlist.group.mode_shared'), hint: t('wishlist.group.mode_shared_hint') },
]);

const message = (value) => (typeof value === 'string' && value.startsWith('wishlist.group.error.') ? t(value) : t('wishlist.group.error.save_failed'));
const focusFirstInvalid = () => nextTick(() => body.value?.querySelector('[aria-invalid="true"], [data-invalid]')?.focus());

function toggle(id, checked) {
    if (id === props.viewerId) return;
    const set = new Set(form.value.member_ids);
    if (checked) set.add(id);
    else set.delete(id);
    form.value.member_ids = [...set].sort((a, b) => a - b);
}

function validate() {
    const next = {};
    const name = form.value.name.trim();
    if (name.length === 0) next.name = t('wishlist.group.error.name_required');
    else if (name.length > 80) next.name = t('wishlist.group.error.name_too_long');
    errors.value = next;
    return Object.keys(next).length === 0;
}

function visitOptions(onOk) {
    return {
        preserveScroll: true,
        onStart: () => { processing.value = true; serverError.value = ''; errors.value = {}; },
        onSuccess: onOk,
        onError: (errs) => {
            const mapped = {};
            let general = '';
            for (const [field, value] of Object.entries(errs ?? {})) {
                if (field === 'name') mapped.name = message(value);
                else if (field === 'mode') general = message(value);
                else general = message(value);
            }
            errors.value = mapped;
            serverError.value = general || (Object.keys(mapped).length === 0 ? t('wishlist.group.error.save_failed') : '');
            focusFirstInvalid();
        },
        onHttpException: () => { serverError.value = t('wishlist.group.error.save_failed'); },
        onNetworkError: () => { serverError.value = t('wishlist.group.error.save_failed'); },
        onFinish: () => { processing.value = false; },
    };
}

const modeChanged = computed(() => editing.value && form.value.groupMode !== props.group.mode);
const membersChanged = computed(() => editing.value && JSON.stringify(form.value.member_ids) !== JSON.stringify([...props.group.members.map((m) => m.id)].sort((a, b) => a - b)));
const detailsChanged = computed(() => editing.value && (form.value.name.trim() !== props.group.name || modeChanged.value));

function finish() {
    snapshot.value = JSON.stringify(form.value);
    emit('close');
}

function create() {
    router.post('/wishlist/groups', {
        name: form.value.name.trim(),
        mode: form.value.groupMode,
        member_ids: form.value.member_ids.filter((id) => id !== props.viewerId),
    }, visitOptions(finish));
}

function saveMembers() {
    router.put(`/wishlist/groups/${props.group.id}/members`, { member_ids: form.value.member_ids }, visitOptions(finish));
}

function update(confirmed) {
    const data = { name: form.value.name.trim(), mode: form.value.groupMode };
    if (modeChanged.value && confirmed) data.confirm_mode_change = true;
    router.patch(`/wishlist/groups/${props.group.id}`, data, visitOptions(() => {
        if (membersChanged.value) saveMembers();
        else finish();
    }));
}

function save() {
    if (processing.value || readOnly.value) return;
    if (!validate()) {
        focusFirstInvalid();
        return;
    }
    if (!editing.value) return create();
    if (!detailsChanged.value) {
        if (membersChanged.value) saveMembers();
        else emit('close');
        return;
    }
    if (modeChanged.value) {
        modeOpen.value = true;
        return;
    }
    update(false);
}

const keepMode = () => { form.value.groupMode = props.group.mode; };
const leave = () => router.delete(`/wishlist/groups/${props.group.id}/membership`, visitOptions(() => emit('close')));
const remove = () => router.delete(`/wishlist/groups/${props.group.id}`, visitOptions(() => emit('close')));

// Runs `then` now, or after the member confirms discarding unsaved edits.
function guard(then) {
    if (props.open && dirty.value) {
        pendingAfterDiscard = then;
        discardOpen.value = true;
    } else {
        then();
    }
}
const requestClose = () => guard(() => emit('close'));
function discard() {
    const then = pendingAfterDiscard;
    pendingAfterDiscard = null;
    reset();
    then?.();
}
const keepEditing = () => { pendingAfterDiscard = null; };
const onEnter = (event) => { if (event.target?.tagName === 'INPUT' && event.target.type !== 'radio' && event.target.type !== 'checkbox') event.preventDefault(); };

defineExpose({ guard, dirty });
</script>

<template>
    <UiDetailPanel :open="open" :title="heading" @update:open="(v) => (v ? null : requestClose())">
        <div ref="body" data-testid="group-panel">
            <form novalidate @submit.prevent="save" @keydown.enter="onEnter">
                <UiAlert v-if="serverError" variant="danger" live class="grp-panel__alert">{{ serverError }}</UiAlert>

                <div class="grp-panel__fields">
                    <UiField :label="t('wishlist.group.name')" required :error="errors.name">
                        <UiInput ref="firstField" v-model="form.name" maxlength="80" autocomplete="off" :placeholder="t('wishlist.group.name_placeholder')" :disabled="readOnly" />
                    </UiField>

                    <fieldset class="grp-panel__set" :disabled="readOnly">
                        <legend class="grp-panel__legend">
                            {{ t('wishlist.group.mode') }}
                            <HelpTip topic="wishlist.group_mode" :label="t('wishlist.group.mode')" />
                        </legend>
                        <div v-for="option in modeOptions" :key="option.value" class="grp-panel__option">
                            <UiRadio v-model="form.groupMode" name="group-mode" :value="option.value" :disabled="readOnly">{{ option.label }}</UiRadio>
                            <p class="grp-panel__hint">{{ option.hint }}</p>
                        </div>
                    </fieldset>

                    <fieldset class="grp-panel__set" :disabled="readOnly">
                        <legend class="grp-panel__legend">
                            {{ t('wishlist.group.members') }}
                            <HelpTip topic="wishlist.group_members" :label="t('wishlist.group.members')" />
                        </legend>
                        <p class="grp-panel__hint">{{ t('wishlist.group.members_hint') }}</p>
                        <UiCheckbox
                            v-for="person in people"
                            :key="person.id"
                            :model-value="form.member_ids.includes(person.id)"
                            :disabled="readOnly || person.id === viewerId"
                            @update:model-value="(v) => toggle(person.id, v)"
                        >{{ person.id === viewerId ? t('wishlist.chips.you', { name: person.name }) : person.name }}</UiCheckbox>
                    </fieldset>
                </div>
            </form>
        </div>

        <template #footer>
            <UiButton v-if="editing && !readOnly" variant="ghost" :icon="Trash2" class="grp-panel__danger grp-panel__push" @click="deleteOpen = true">{{ t('wishlist.group.delete') }}</UiButton>
            <UiButton v-if="editing && group.is_member" variant="ghost" :icon="LogOut" :class="{ 'grp-panel__push': readOnly }" @click="leaveOpen = true">{{ t('wishlist.group.leave') }}</UiButton>
            <UiButton variant="secondary" @click="requestClose">{{ t('wishlist.close') }}</UiButton>
            <UiButton v-if="!readOnly" variant="primary" :loading="processing" @click="save">{{ editing ? t('wishlist.group.save') : t('wishlist.group.create') }}</UiButton>
        </template>
    </UiDetailPanel>

    <UiConfirmDialog
        v-model:open="discardOpen"
        :title="t('wishlist.discard.title')"
        :body="t('wishlist.discard.body')"
        :confirm-label="t('wishlist.discard.confirm')"
        :cancel-label="t('wishlist.discard.keep')"
        @confirm="discard"
        @cancel="keepEditing"
    />
    <template v-if="editing">
        <UiConfirmDialog
            v-model:open="modeOpen"
            :title="t('wishlist.group.mode_change_title')"
            :body="t('wishlist.group.mode_change_body')"
            :confirm-label="t('wishlist.group.mode_change_confirm')"
            :cancel-label="t('wishlist.group.keep')"
            @confirm="update(true)"
            @cancel="keepMode"
        />
        <UiConfirmDialog
            v-model:open="leaveOpen"
            :title="t('wishlist.group.leave_title')"
            :body="t('wishlist.group.leave_body')"
            :confirm-label="t('wishlist.group.leave_confirm')"
            :cancel-label="t('wishlist.group.keep')"
            @confirm="leave"
        />
        <UiConfirmDialog
            v-model:open="deleteOpen"
            :title="t('wishlist.group.delete_title')"
            :body="t('wishlist.group.delete_body', { name: group.name })"
            :confirm-label="t('wishlist.group.delete_confirm')"
            :cancel-label="t('wishlist.group.keep')"
            @confirm="remove"
        />
    </template>
</template>

<style scoped>
.grp-panel__alert { margin-bottom: var(--space-4); }
.grp-panel__fields { display: flex; flex-direction: column; gap: var(--space-4); }
.grp-panel__set { min-width: 0; margin: 0; padding: 0; border: 0; display: flex; flex-direction: column; gap: var(--space-2); }
.grp-panel__legend { padding: 0; display: flex; align-items: center; gap: var(--space-2); font-size: var(--text-sm); font-weight: 600; line-height: 1.4; }
.grp-panel__option { display: flex; flex-direction: column; gap: var(--space-1); }
.grp-panel__hint { margin: 0; padding-inline-start: var(--space-8, 2rem); font-size: var(--text-sm); color: var(--color-text-muted); }
.grp-panel__danger { color: var(--color-danger); }
.grp-panel__push { margin-inline-end: auto; }
</style>
