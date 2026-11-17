<script setup>
import { computed, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiAlert from '../ui/UiAlert.vue';
import UiButton from '../ui/UiButton.vue';
import UiField from '../ui/UiField.vue';
import UiInput from '../ui/UiInput.vue';

// "Make a normal member": an invitation to an e-mail address for the same account (nothing changes until accepted).
const props = defineProps({
    member: { type: Object, required: true },
});
const { t, locale } = useI18n();

const LANGUAGES = ['sk', 'pl', 'en'];
const current = usePage().props.locale;
const form = useForm({ email: '', locale: LANGUAGES.includes(current) ? current : 'en' });
const failed = ref(false);

const invitation = computed(() => props.member.invitation ?? null);
const date = computed(() => (invitation.value
    ? new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(new Date(invitation.value.expiresAt))
    : ''));
const emailError = computed(() => {
    const message = form.errors?.email;
    if (!message) return '';

    return /^[a-z_]+\.[a-z_.]+$/i.test(String(message)) ? t(message) : String(message);
});

function submit() {
    failed.value = false;
    form.post(`/settings/members/${props.member.id}/convert`, {
        preserveScroll: true,
        onSuccess: () => form.reset('email'),
        onError: (errors) => { failed.value = !errors.email; },
    });
}
</script>

<template>
    <section class="convert-card" aria-labelledby="convert-card-title">
        <h3 id="convert-card-title" class="convert-card__title">{{ t('settings.members.convert.title') }}</h3>
        <p class="convert-card__hint">{{ t('settings.members.convert.hint') }}</p>
        <UiAlert v-if="invitation" variant="info" data-testid="convert-pending">
            {{ t('settings.members.convert.pending', { email: invitation.email, date }) }}
            {{ t('settings.members.convert.pending_more') }}
        </UiAlert>
        <UiAlert v-if="failed" variant="danger" live data-testid="convert-failed">{{ t('settings.members.convert.failed') }}</UiAlert>
        <form class="convert-card__form" novalidate @submit.prevent="submit">
            <UiField :label="t('settings.members.convert.email')" :error="emailError" required help="members.convert">
                <UiInput v-model="form.email" type="email" autocomplete="off" inputmode="email" :disabled="form.processing" />
            </UiField>
            <div>
                <UiButton type="submit" variant="primary" :disabled="form.processing" :loading="form.processing">{{ t('settings.members.convert.submit') }}</UiButton>
            </div>
        </form>
    </section>
</template>

<style scoped>
.convert-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: var(--space-4); }
.convert-card__title { margin: 0 0 var(--space-2); font-size: var(--text-md, 1rem); }
.convert-card__hint { margin: 0 0 var(--space-3); color: var(--color-text-muted); font-size: var(--text-sm); }
.convert-card__form { display: flex; flex-direction: column; gap: var(--space-3); margin-block-start: var(--space-3); }
</style>
