<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiInput from '../../Components/ui/UiInput.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiAvatar from '../../Components/ui/UiAvatar.vue';
import HelpTip from '../../Components/ui/HelpTip.vue';
import ProfilePhotoEditor from '../../Components/Profile/ProfilePhotoEditor.vue';

const props = defineProps({
    sections: { type: Array, default: () => [] },
    // { id, name, email|null, photoVersion|null, isSelf, isChild }
    subject: { type: Object, required: true },
    nameMax: { type: Number, default: 60 },
    // Children whose wish list the viewer manages: [{ id, name, photoVersion|null }]
    managedPeople: { type: Array, default: () => [] },
    // Names of the members who manage the viewer's own wish list
    wishlistManagers: { type: Array, default: () => [] },
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
    <Head :title="t(subject.isSelf ? 'profile.title' : 'profile.people.title')" />
    <SettingsLayout :sections="sections" current="" :title="subject.isSelf ? 'profile.title' : 'profile.people.title'">
        <div :key="subject.id" class="profile">
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
                    <UiAlert v-if="subject.isChild && !subject.email" variant="info" class="profile__alert" data-testid="child-info">{{ t('profile.people.child_info') }}</UiAlert>
                    <UiField v-else-if="subject.email" class="profile__email" :label="t('profile.details.email_label')" :hint="t('profile.details.email_hint')" :error="emailError">
                        <UiInput :model-value="subject.email" type="email" readonly aria-readonly="true" autocomplete="email" />
                    </UiField>
                    <div>
                        <UiButton type="submit" variant="primary" :disabled="!dirty || form.processing" :loading="form.processing">{{ t('profile.save') }}</UiButton>
                    </div>
                </form>
            </UiCard>

            <UiAlert v-if="subject.isSelf && wishlistManagers.length" variant="info" class="profile__alert" data-testid="managers-notice">{{ t('profile.people.managers_notice', { names: wishlistManagers.join(', ') }) }}</UiAlert>

            <UiCard v-if="subject.isSelf && managedPeople.length" :title="t('profile.people.card_title')" data-testid="people-card">
                <p class="profile__hint">{{ t('profile.people.card_hint') }}</p>
                <ul class="profile__people" role="list">
                    <li v-for="person in managedPeople" :key="person.id" class="profile__person">
                        <UiAvatar :name="person.name" :user-id="person.id" :tint="(person.id % 6) + 1" decorative />
                        <span class="profile__person-name">{{ person.name }}</span>
                        <UiButton size="sm" :href="`/profile/people/${person.id}`" :aria-label="t('profile.people.edit_aria', { name: person.name })" @click.prevent="router.visit(`/profile/people/${person.id}`)">{{ t('profile.people.edit') }}</UiButton>
                    </li>
                </ul>
            </UiCard>
        </div>
    </SettingsLayout>
</template>

<style scoped>
.profile { display: flex; flex-direction: column; gap: var(--space-6); max-width: 560px; }
.profile__form { display: flex; flex-direction: column; gap: var(--space-4); }
.profile__alert { margin: 0; }
.profile__hint { margin: 0 0 var(--space-3); color: var(--color-text-muted); font-size: var(--text-sm); }
.profile__people { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: var(--space-3); }
.profile__person { display: flex; align-items: center; gap: var(--space-3); }
.profile__person-name { flex: 1 1 auto; min-width: 0; overflow-wrap: anywhere; }
.profile__email :deep(.ui-control) { background: var(--color-surface-2); color: var(--color-text); }
</style>
