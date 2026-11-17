<script setup>
import { computed, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiDialog from '../ui/UiDialog.vue';
import UiField from '../ui/UiField.vue';
import UiInput from '../ui/UiInput.vue';
import UiButton from '../ui/UiButton.vue';
import UiAlert from '../ui/UiAlert.vue';
import UiCheckbox from '../ui/UiCheckbox.vue';
import HelpTip from '../ui/HelpTip.vue';
import ProfilePhotoEditor from './ProfilePhotoEditor.vue';

// Admin adds a child: name, optional photo (kept here until the child exists), and the parents who manage the wish list.
// The create request carries only JSON; the photo is sent by the parent through /people/{id}/photo after it succeeded.
const props = defineProps({
    open: { type: Boolean, default: false },
    // Members who can sign in and hold Wishlist Edit: [{ id, name }]
    parents: { type: Array, default: () => [] },
    // false when the acting admin is not in the list above (they cannot be chosen).
    selfEligible: { type: Boolean, default: true },
    nameMax: { type: Number, default: 60 },
});
const emit = defineEmits(['update:open', 'created']);
const { t } = useI18n();

const form = useForm({ name: '', parent_ids: [] });
const photo = ref(null);
const nameInput = ref(null);
// UiInput's root is a wrapper, so hand the trap the real <input>.
const initialFocus = () => nameInput.value?.$el?.querySelector?.('input') ?? null;

const ready = computed(() => form.name.trim().length > 0 && form.parent_ids.length > 0);
const translate = (message) => (String(message).startsWith('profile.') || String(message).startsWith('settings.') ? t(message) : String(message));
const nameError = computed(() => (form.errors?.name ? translate(form.errors.name) : ''));
const parentError = computed(() => {
    const key = Object.keys(form.errors ?? {}).find((k) => k === 'parent_ids' || k.startsWith('parent_ids.'));

    return key ? translate(form.errors[key]) : '';
});
const failed = computed(() => Boolean(nameError.value || parentError.value));

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
        photo.value = null;
    }
});

const checked = (id) => form.parent_ids.includes(id);
function toggle(id, on) {
    const without = form.parent_ids.filter((x) => x !== id);
    form.parent_ids = on ? [...without, id] : without;
}

function submit() {
    if (!ready.value) return;
    form.post('/settings/members/children', {
        preserveScroll: true,
        onSuccess: () => {
            const id = usePage().props.flash?.data?.created_child_id ?? null;
            emit('created', { id, photo: photo.value });
            emit('update:open', false);
        },
    });
}
</script>

<template>
    <UiDialog :open="open" :title="t('settings.members.child.dialog_title')" :initial-focus="initialFocus" @update:open="emit('update:open', $event)">
        <form id="child-form" class="child-dialog__form" novalidate @submit.prevent="submit">
            <UiAlert variant="info">{{ t('settings.members.child.info') }}</UiAlert>
            <div aria-live="polite">
                <UiAlert v-if="failed" variant="danger" data-testid="create-failed">{{ t('settings.members.child.create_failed') }}</UiAlert>
            </div>
            <UiField :label="t('settings.members.child.name_label')" :error="nameError" required>
                <UiInput ref="nameInput" v-model="form.name" autocomplete="off" :maxlength="nameMax" :disabled="form.processing" />
            </UiField>
            <div class="child-dialog__photo">
                <span class="child-dialog__label">{{ t('settings.members.child.photo_label') }}</span>
                <ProfilePhotoEditor :user-id="0" :name="form.name || '?'" compact deferred @select="photo = $event" @clear="photo = null" />
            </div>
            <fieldset class="child-dialog__parents" :aria-describedby="'child-parents-hint'" :aria-invalid="parentError ? 'true' : undefined">
                <legend class="child-dialog__legend">
                    <span>{{ t('settings.members.child.parents_label') }}</span>
                    <HelpTip topic="members.child" :label="t('settings.members.child.parents_label')" />
                </legend>
                <p id="child-parents-hint" class="child-dialog__hint">{{ t('settings.members.child.parents_hint') }}</p>
                <p v-if="!selfEligible" class="child-dialog__hint" data-testid="self-hint">{{ t('settings.members.child.parents_self_hint') }}</p>
                <p v-if="parents.length === 0" class="child-dialog__hint" data-testid="no-parents">{{ t('settings.members.child.parents_none') }}</p>
                <ul v-else class="child-dialog__list" role="list">
                    <li v-for="parent in parents" :key="parent.id">
                        <UiCheckbox :model-value="checked(parent.id)" :disabled="form.processing" @update:model-value="toggle(parent.id, $event)">{{ parent.name }}</UiCheckbox>
                    </li>
                </ul>
                <p v-if="parentError" class="child-dialog__error" role="alert">{{ parentError }}</p>
            </fieldset>
        </form>
        <template #footer>
            <UiButton variant="ghost" @click="emit('update:open', false)">{{ t('settings.members.child.cancel') }}</UiButton>
            <UiButton type="submit" form="child-form" variant="primary" :disabled="!ready || form.processing" :loading="form.processing">{{ t('settings.members.child.submit') }}</UiButton>
        </template>
    </UiDialog>
</template>

<style scoped>
.child-dialog__form { display: flex; flex-direction: column; gap: var(--space-4); }
.child-dialog__label { display: block; margin-block-end: var(--space-2); font-weight: 600; font-size: var(--text-sm); }
.child-dialog__parents { margin: 0; padding: 0; border: 0; min-width: 0; }
.child-dialog__legend { display: flex; align-items: center; gap: var(--space-2); padding: 0; margin-block-end: var(--space-1); font-weight: 600; font-size: var(--text-sm); }
.child-dialog__hint { margin: 0 0 var(--space-2); color: var(--color-text-muted); font-size: var(--text-sm); }
.child-dialog__list { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: var(--space-1); }
.child-dialog__error { margin: var(--space-2) 0 0; color: var(--color-danger-text, var(--color-text)); font-size: var(--text-sm); }
</style>
