<script setup>
import { computed, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Copy } from '@lucide/vue';
import UiDialog from '../ui/UiDialog.vue';
import UiField from '../ui/UiField.vue';
import UiInput from '../ui/UiInput.vue';
import UiSelect from '../ui/UiSelect.vue';
import UiButton from '../ui/UiButton.vue';
import UiAlert from '../ui/UiAlert.vue';
import { useToast } from '../ui/useToast.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    presets: { type: Object, default: () => ({}) },
    // One-shot result of an invite, resend or copy-link: { email, link, mailQueued, rotated }. Cleared by the parent on close.
    result: { type: Object, default: null },
});
const emit = defineEmits(['update:open', 'result', 'another']);
const { t } = useI18n();
const toast = useToast();

const LANGUAGES = [
    { value: 'sk', label: 'Slovenčina' },
    { value: 'pl', label: 'Polski' },
    { value: 'en', label: 'English' },
];
const presetKeys = computed(() => Object.keys(props.presets));
// The admin's interface language (sk|pl|en) from the shared page props.
const defaultLocale = () => {
    const current = usePage().props.locale;
    return LANGUAGES.some((l) => l.value === current) ? current : 'en';
};
const defaultPreset = () => (presetKeys.value.includes('child') ? 'child' : (presetKeys.value[0] ?? 'child'));
const form = useForm({ email: '', preset: defaultPreset(), locale: defaultLocale() });
const emailInput = ref(null);
// UiInput's root is a wrapper, so hand the trap the real <input>.
const initialFocus = () => (props.result ? null : (emailInput.value?.$el?.querySelector?.('input') ?? null));

const presetOptions = computed(() => presetKeys.value.map((key) => ({ value: key, label: t(`permissions.preset.${key}`) })));
const presetHint = computed(() => t(`permissions.preset.${form.preset}_hint`));
const emailError = computed(() => (form.errors?.email ? t(form.errors.email) : ''));

watch(() => props.open, (open) => {
    if (open && !props.result) form.reset();
});

function submit() {
    form.post('/settings/invitations', {
        preserveScroll: true,
        onSuccess: () => {
            const invite = usePage().props.flash?.data?.invite;
            if (invite) emit('result', invite);
            form.reset('email');
        },
    });
}

async function copy() {
    try {
        await navigator.clipboard.writeText(props.result.link);
        toast.show(t('settings.invites.copied'));
    } catch {
        // Clipboard unavailable: the field below is selectable so the link can still be copied by hand.
    }
}
</script>

<template>
    <UiDialog :open="open" :title="t('invite.dialog.title')" :initial-focus="initialFocus" @update:open="emit('update:open', $event)">
        <template v-if="result">
            <UiAlert :variant="result.mailQueued ? 'success' : 'warning'" live>
                {{ result.mailQueued ? t('invite.dialog.sent', { email: result.email }) : t('invite.dialog.mail_failed') }}
            </UiAlert>
            <p v-if="result.rotated" class="invite-dialog__note">{{ t('settings.invites.rotated_note') }}</p>
            <div class="invite-dialog__link">
                <UiField :label="t('invite.dialog.link_label')">
                    <UiInput :model-value="result.link" readonly @focus="$event.target.select?.()" />
                </UiField>
                <UiButton variant="secondary" :icon="Copy" @click="copy">{{ t('settings.invites.copy') }}</UiButton>
            </div>
        </template>
        <form v-else id="invite-form" class="invite-dialog__form" novalidate @submit.prevent="submit">
            <UiField :label="t('auth.email')" :error="emailError" required>
                <UiInput ref="emailInput" v-model="form.email" type="email" autocomplete="off" inputmode="email" />
            </UiField>
            <UiField :label="t('invite.dialog.preset')" :hint="presetHint">
                <UiSelect v-model="form.preset" :options="presetOptions" />
            </UiField>
            <UiField :label="t('invite.dialog.language')">
                <UiSelect v-model="form.locale" :options="LANGUAGES" />
            </UiField>
            <p class="invite-dialog__note">{{ t('invite.dialog.help') }}</p>
        </form>
        <template #footer>
            <template v-if="result">
                <UiButton variant="secondary" @click="emit('another')">{{ t('invite.dialog.another') }}</UiButton>
                <UiButton variant="primary" @click="emit('update:open', false)">{{ t('invite.dialog.done') }}</UiButton>
            </template>
            <template v-else>
                <UiButton variant="ghost" @click="emit('update:open', false)">{{ t('invite.dialog.cancel') }}</UiButton>
                <UiButton type="submit" form="invite-form" variant="primary" :loading="form.processing">{{ t('invite.dialog.submit') }}</UiButton>
            </template>
        </template>
    </UiDialog>
</template>

<style scoped>
.invite-dialog__form { display: flex; flex-direction: column; gap: var(--space-4); }
.invite-dialog__note { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.invite-dialog__link { display: flex; flex-direction: column; gap: var(--space-3); margin-block-start: var(--space-4); }
.invite-dialog__link :deep(.ui-button) { align-self: flex-start; }
</style>
