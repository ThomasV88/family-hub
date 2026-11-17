<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Send, X } from '@lucide/vue';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiDialog from '../Components/ui/UiDialog.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import HelpTip from '../Components/ui/HelpTip.vue';

// Send a shopping list by e-mail (plain text) to up to 5 typed addresses. Posts { addresses } to /shopping/lists/{id}/mail; the
// server answers 303 with the toast "List sent to {n} address(es)", 422 with per-address errors, or 429 when a limit is reached.
// list: { id, name }.
const MAX = 5;
const PATTERN = /^[^\s@,;]+@[^\s@,;]+\.[^\s@,;]+$/;
const props = defineProps({
    open: { type: Boolean, default: false },
    list: { type: Object, default: null },
});
const emit = defineEmits(['update:open']);
const { t } = useI18n();

const form = useForm({ addresses: [] });
const tokens = ref([]);
const draft = ref('');
const draftError = ref('');
const serverErrors = ref({ list: '', items: {} });
const limited = ref(false);
const failed = ref(false);
const sending = ref(false);
const full = computed(() => tokens.value.length >= MAX);
const input = ref(null);
const initialFocus = () => input.value?.$el?.querySelector?.('input') ?? null;

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        tokens.value = [];
        draft.value = '';
        draftError.value = '';
        serverErrors.value = { list: '', items: {} };
        limited.value = false;
        failed.value = false;
        sending.value = false;
        form.clearErrors();
    },
    { immediate: true },
);

const isValid = (text) => PATTERN.test(text) && text.length <= 254;
const has = (value) => tokens.value.some((v) => v.toLowerCase() === value.toLowerCase());

// Turns typed or pasted text into chips. Invalid text is never dropped: it stays in the field with an error so it can be fixed.
function commit(text) {
    const parts = String(text).split(/[\s,;]+/).filter(Boolean);
    const rejected = [];
    for (const part of parts) {
        if (!isValid(part)) {
            rejected.push(part);
        } else if (!has(part) && tokens.value.length < MAX) {
            tokens.value = [...tokens.value, part];
        }
    }
    serverErrors.value = { list: '', items: {} };
    limited.value = false;
    failed.value = false;
    draft.value = rejected.join(' ');
    draftError.value = rejected.length > 0 ? t('shopping.mail.invalid_token', { text: rejected[0] }) : '';
}

function onKeydown(event) {
    if (['Enter', ',', ' ', ';'].includes(event.key) && draft.value.trim() !== '') {
        event.preventDefault();
        commit(draft.value);
    } else if (event.key === 'Enter') {
        // An empty field plus Enter must not submit by accident.
        event.preventDefault();
    }
}
function onPaste(event) {
    const text = event.clipboardData?.getData('text') ?? '';
    if (text === '') return;
    event.preventDefault();
    commit(`${draft.value} ${text}`);
}
function onBlur() {
    if (draft.value.trim() !== '') commit(draft.value);
}
function removeToken(index) {
    tokens.value = tokens.value.filter((_, i) => i !== index);
    serverErrors.value = { list: '', items: {} };
    limited.value = false;
}

const draftValid = computed(() => draft.value.trim() !== '' && draft.value.trim().split(/[\s,;]+/).every(isValid));
const canSend = computed(() => (tokens.value.length > 0 || draftValid.value) && !sending.value);
const translate = (message, params = {}) => (String(message).startsWith('shopping.') ? t(message, params) : String(message));
const fieldError = computed(() => draftError.value || serverErrors.value.list);
const hint = computed(() => (full.value ? t('shopping.mail.hint_max') : ''));
const close = () => emit('update:open', false);

function readErrors(errors) {
    const items = {};
    let list = '';
    for (const [key, message] of Object.entries(errors ?? {})) {
        const match = /^addresses\.(\d+)$/.exec(key);
        if (match) items[Number(match[1])] = true;
        else if (key === 'addresses') list = translate(message);
    }
    const first = Object.keys(items).map(Number)[0];
    if (first !== undefined && tokens.value[first] !== undefined) list = t('shopping.mail.invalid_token', { text: tokens.value[first] });
    serverErrors.value = { list, items };
    failed.value = list === '' && Object.keys(errors ?? {}).length > 0;
}

function submit() {
    if (!props.list || sending.value) return;
    if (draft.value.trim() !== '') commit(draft.value);
    if (tokens.value.length === 0 || draftError.value) return;
    sending.value = true;
    limited.value = false;
    failed.value = false;
    form.addresses = [...tokens.value];
    form.post(`/shopping/lists/${props.list.id}/mail`, {
        preserveScroll: true,
        onSuccess: close,
        onError: readErrors,
        onHttpException: (response) => {
            // The send limit answers 429 JSON, not a page: show the message in the dialog instead of Inertia's error window.
            if (response?.status === 429) limited.value = true;
            else failed.value = true;

            return false;
        },
        onNetworkError: () => { failed.value = true; },
        onFinish: () => { sending.value = false; },
    });
}
</script>

<template>
    <UiDialog :open="open" :title="t('shopping.mail.title')" :initial-focus="initialFocus" @update:open="emit('update:open', $event)">
        <form id="email-list-form" class="mail" novalidate @submit.prevent="submit">
            <p class="mail__lead">
                <span>{{ t('shopping.mail.preview') }}</span>
                <HelpTip topic="shopping.email" :label="t('shopping.mail.title')" />
            </p>
            <div aria-live="polite">
                <UiAlert v-if="limited" variant="warning" data-testid="mail-limited">{{ t('shopping.mail.err_limit') }}</UiAlert>
                <UiAlert v-else-if="failed" variant="danger" data-testid="mail-failed">{{ t('shopping.mail.failed') }}</UiAlert>
            </div>
            <UiField :label="t('shopping.mail.field')" :hint="hint" :error="fieldError">
                <ul v-if="tokens.length > 0" class="mail__chips" :aria-label="t('shopping.mail.field')" data-testid="mail-chips">
                    <li v-for="(address, index) in tokens" :key="address" class="mail__chip" :class="{ 'mail__chip--bad': serverErrors.items[index] }" data-testid="mail-chip">
                        <span class="mail__address">{{ address }}</span>
                        <UiButton class="mail__remove" variant="ghost" size="sm" icon-only :icon="X" :label="t('shopping.mail.remove', { address })" @click="removeToken(index)" />
                    </li>
                </ul>
                <UiInput
                    v-if="!full"
                    ref="input"
                    v-model="draft"
                    type="text"
                    inputmode="email"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    :placeholder="t('shopping.mail.placeholder')"
                    :disabled="sending"
                    data-testid="mail-input"
                    @keydown="onKeydown"
                    @paste="onPaste"
                    @blur="onBlur"
                />
            </UiField>
        </form>
        <template #footer>
            <UiButton variant="ghost" @click="close">{{ t('shopping.mail.cancel') }}</UiButton>
            <UiButton type="submit" form="email-list-form" variant="primary" :icon="Send" :disabled="!canSend" :loading="sending" data-testid="mail-send">{{ t('shopping.mail.send') }}</UiButton>
        </template>
    </UiDialog>
</template>

<style scoped>
.mail { display: flex; flex-direction: column; gap: var(--space-3); }
.mail__lead { display: flex; align-items: center; gap: var(--space-2); margin: 0; color: var(--color-text-muted); font-size: var(--text-sm); }
.mail__chips { display: flex; flex-wrap: wrap; gap: var(--space-2); margin: 0 0 var(--space-2); padding: 0; list-style: none; }
.mail__chip { display: inline-flex; align-items: center; gap: var(--space-1); max-width: 100%; min-height: 44px; padding: 0 var(--space-1) 0 var(--space-3); border: 1px solid var(--color-border-strong); border-radius: var(--radius-full); background: var(--color-surface-2); font-size: var(--text-sm); font-weight: 600; }
.mail__chip--bad { border-color: var(--color-danger); }
.mail__address { min-width: 0; overflow-wrap: anywhere; }
.mail__remove { flex: none; min-width: 44px; min-height: 44px; }
</style>
