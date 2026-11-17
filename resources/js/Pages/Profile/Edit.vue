<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiInput from '../../Components/ui/UiInput.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import HelpTip from '../../Components/ui/HelpTip.vue';
import ProfilePhotoEditor from '../../Components/Profile/ProfilePhotoEditor.vue';

const props = defineProps({
    sections: { type: Array, default: () => [] },
    // { id, name, email|null, photoVersion|null, isSelf, isChild }
    subject: { type: Object, required: true },
    nameMax: { type: Number, default: 60 },
});
const { t } = useI18n();

const form = useForm({ name: props.subject.name });
const nameInput = ref(null);
const dirty = computed(() => form.name !== props.subject.name);
// The server may normalize the name (trim, collapse spaces); follow it so Save is only active for a real change.
watch(() => props.subject.name, (name) => { form.name = name; });

const fieldError = (key) => {
    const message = form.errors?.[key];
    if (!message) return '';

    return String(message).startsWith('profile.') ? t(message) : String(message);
};
const nameError = computed(() => fieldError('name'));
const emailError = computed(() => fieldError('email'));
const failed = computed(() => Boolean(nameError.value || emailError.value));

function submit() {
    form.put(`/people/${props.subject.id}/name`, {
        preserveScroll: true,
        onError: async () => {
            await nextTick();
            nameInput.value?.focus();
        },
    });
}
</script>

<template>
    <Head :title="t('profile.title')" />
    <SettingsLayout :sections="sections" current="" title="profile.title">
        <div class="profile">
            <UiCard :title="t('profile.photo.card_title')">
                <template #action><HelpTip topic="profile.photo" :label="t('profile.photo.card_title')" /></template>
                <ProfilePhotoEditor :user-id="subject.id" :name="subject.name" :photo-version="subject.photoVersion" />
            </UiCard>

            <UiCard :title="t('profile.details.card_title')">
                <form class="profile__form" novalidate @submit.prevent="submit">
                    <div aria-live="polite">
                        <UiAlert v-if="failed" variant="danger" class="profile__alert" data-testid="save-failed">{{ t('profile.save_failed') }}</UiAlert>
                    </div>
                    <UiField :label="t('profile.details.name_label')" :error="nameError" required help="profile.name">
                        <UiInput ref="nameInput" v-model="form.name" autocomplete="name" :maxlength="Math.max(nameMax, subject.name.length)" :disabled="form.processing" />
                    </UiField>
                    <UiField v-if="subject.email" class="profile__email" :label="t('profile.details.email_label')" :hint="t('profile.details.email_hint')" :error="emailError">
                        <UiInput :model-value="subject.email" type="email" readonly aria-readonly="true" autocomplete="email" />
                    </UiField>
                    <div>
                        <UiButton type="submit" variant="primary" :disabled="!dirty || form.processing" :loading="form.processing">{{ t('profile.save') }}</UiButton>
                    </div>
                </form>
            </UiCard>
        </div>
    </SettingsLayout>
</template>

<style scoped>
.profile { display: flex; flex-direction: column; gap: var(--space-6); max-width: 560px; }
.profile__form { display: flex; flex-direction: column; gap: var(--space-4); }
.profile__alert { margin: 0; }
.profile__email :deep(.ui-control) { background: var(--color-surface-2); color: var(--color-text); }
</style>
