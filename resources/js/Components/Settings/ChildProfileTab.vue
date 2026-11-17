<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiAlert from '../ui/UiAlert.vue';
import UiButton from '../ui/UiButton.vue';
import UiField from '../ui/UiField.vue';
import UiInput from '../ui/UiInput.vue';
import UiSwitch from '../ui/UiSwitch.vue';
import ProfilePhotoEditor from '../Profile/ProfilePhotoEditor.vue';

// Profile tab of a child in the member panel: name, photo, and the fixed facts (no e-mail, no direct sign-in).
const props = defineProps({
    member: { type: Object, required: true },
    // A photo upload that failed after the child was created.
    photoError: { type: String, default: '' },
    nameMax: { type: Number, default: 60 },
});
const emit = defineEmits(['dirty']);
const { t } = useI18n();

const form = useForm({ name: props.member.name });
const dirty = computed(() => form.name !== props.member.name);
watch(dirty, (value) => emit('dirty', value), { immediate: true });
watch(() => props.member.name, (name) => { if (!dirty.value) form.name = name; });

const nameError = computed(() => {
    const message = form.errors?.name;
    if (!message) return '';

    return String(message).startsWith('profile.') ? t(message) : String(message);
});
const nameInput = ref(null);

function submit() {
    form.put(`/people/${props.member.id}/name`, { preserveScroll: true });
}
</script>

<template>
    <div class="child-tab">
        <UiAlert variant="info">{{ t('settings.members.child.panel_info') }}</UiAlert>
        <UiAlert v-if="photoError" variant="danger" live data-testid="photo-failed">{{ t('settings.members.child.photo_failed') }}</UiAlert>
        <ProfilePhotoEditor :user-id="member.id" :name="member.name" :photo-version="member.photoVersion ?? null" :external-error="photoError" />
        <form class="child-tab__form" novalidate @submit.prevent="submit">
            <UiField :label="t('profile.details.name_label')" :error="nameError" required help="profile.name">
                <UiInput ref="nameInput" v-model="form.name" :maxlength="Math.max(nameMax, member.name.length)" :disabled="form.processing" />
            </UiField>
            <div>
                <UiButton type="submit" variant="primary" :disabled="!dirty || form.processing" :loading="form.processing">{{ t('profile.save') }}</UiButton>
            </div>
        </form>
        <div class="child-tab__signin">
            <UiSwitch :model-value="true" disabled aria-describedby="child-signin-hint">{{ t('settings.members.child.signin_switch') }}</UiSwitch>
            <p id="child-signin-hint" class="child-tab__hint">{{ t('settings.members.child.signin_hint') }}</p>
        </div>
    </div>
</template>

<style scoped>
.child-tab { display: flex; flex-direction: column; gap: var(--space-4); }
.child-tab__form { display: flex; flex-direction: column; gap: var(--space-4); }
.child-tab__hint { margin: var(--space-1) 0 0; color: var(--color-text-muted); font-size: var(--text-sm); }
</style>
