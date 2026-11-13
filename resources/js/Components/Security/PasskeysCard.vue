<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { KeyRound } from '@lucide/vue';
import UiAlert from '../ui/UiAlert.vue';
import UiCard from '../ui/UiCard.vue';
import UiDialog from '../ui/UiDialog.vue';
import UiEmptyState from '../ui/UiEmptyState.vue';
import UiList from '../ui/UiList.vue';
import { useToast } from '../ui/useToast.js';
import { createCredential, passkeysSupported } from '../../Security/webauthn.js';
import { securityFetch } from '../../Security/securityFetch.js';
import PasskeyButton from './PasskeyButton.vue';
import PasskeyNameDialog from './PasskeyNameDialog.vue';
import PasskeyRemoveDialog from './PasskeyRemoveDialog.vue';
import PasskeyRow from './PasskeyRow.vue';
import ReauthPrompt from './ReauthPrompt.vue';

// The Passkeys card on Settings > Security. Adding a passkey (and removing one) needs the 10-minute security
// confirmation: when it is stale the confirm form opens in a dialog BEFORE any options are fetched, and a 423 from
// the server restarts the whole add flow from fresh options and a new device prompt (options are single-use).
const props = defineProps({
    passkeys: { type: Array, default: () => [] },
    methods: { type: Object, required: true },
    confirmFresh: { type: Boolean, default: false },
    isAdmin: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    guidance: { type: Boolean, default: false },
    totp: { type: Boolean, default: false },
});
const emit = defineEmits(['update:passkeys']);
const { t } = useI18n();
const toast = useToast();

const items = ref([...props.passkeys]);
watch(() => props.passkeys, (v) => { items.value = [...v]; });
const publish = () => emit('update:passkeys', [...items.value]);

const fresh = ref(props.confirmFresh);
watch(() => props.confirmFresh, (v) => { fresh.value = v; });

const supported = passkeysSupported();
const adding = ref(false);
const error = ref('');
const confirmOpen = ref(false);
const confirmBody = ref(null);

// Pending registration: the credential is kept only until it is saved; a stale save drops it.
const nameOpen = ref(false);
const nameBusy = ref(false);
const nameError = ref('');
const defaultName = ref('');
let pendingCredential = null;

const renameTarget = ref(null);
const renameOpen = ref(false);
const renameBusy = ref(false);
const renameError = ref('');

const removeTarget = ref(null);
const removeOpen = ref(false);
const lastFactor = computed(() => props.required && items.value.length === 1 && !props.totp);
const nudge = computed(() => items.value.length > 0 && !props.totp);

function deviceGuess() {
    const ua = typeof navigator === 'undefined' ? '' : `${navigator.userAgentData?.platform ?? ''} ${navigator.userAgent ?? ''}`;
    if (/iPhone/i.test(ua)) return 'iPhone';
    if (/iPad/i.test(ua)) return 'iPad';
    if (/Android/i.test(ua)) return 'Android';
    if (/Mac/i.test(ua)) return 'Mac';
    if (/Win/i.test(ua)) return 'Windows';
    return t('passkey.default_name');
}

function askConfirmation() {
    fresh.value = false;
    nameOpen.value = false;
    pendingCredential = null;
    confirmOpen.value = true;
}

const firstField = () => confirmBody.value?.querySelector('.reauth-form input, .reauth-form button, [data-testid="sign-in-again"]') ?? null;

function start() {
    if (adding.value) return;
    error.value = '';
    if (!fresh.value) return askConfirmation();
    run();
}

async function run() {
    adding.value = true;
    error.value = '';
    const opts = await securityFetch('/settings/security/passkeys/options');
    if (!opts.ok) {
        adding.value = false;
        if (opts.error === 'confirm_required') return askConfirmation();
        error.value = 'passkey.error.failed';
        return;
    }
    let credential;
    try {
        credential = await createCredential(opts.data.options);
    } catch (e) {
        adding.value = false;
        error.value = e?.name === 'cancelled' ? 'passkey.error.cancelled' : 'passkey.error.failed';
        return;
    }
    adding.value = false;
    pendingCredential = credential;
    defaultName.value = deviceGuess();
    nameError.value = '';
    nameOpen.value = true;
}

function confirmed() {
    fresh.value = true;
    confirmOpen.value = false;
    run();
}

async function savePasskey(name) {
    if (!pendingCredential || nameBusy.value) return;
    nameBusy.value = true;
    nameError.value = '';
    const result = await securityFetch('/settings/security/passkeys', {
        method: 'POST',
        body: { name: name || defaultName.value, credential: pendingCredential },
    });
    nameBusy.value = false;
    if (result.ok) {
        pendingCredential = null;
        nameOpen.value = false;
        items.value = [...items.value, result.data.passkey];
        publish();
        toast.show(t('passkey.added_toast'));
        if (props.guidance) router.post('/settings/security/continue');
        return;
    }
    if (result.error === 'confirm_required') return askConfirmation();
    if (result.error === 'invalid' && result.errors?.name) {
        nameError.value = 'passkey.error.name';
        return;
    }
    pendingCredential = null;
    nameOpen.value = false;
    error.value = 'passkey.error.failed';
}

function openRename(passkey) {
    renameTarget.value = passkey;
    renameError.value = '';
    renameOpen.value = true;
}

async function saveRename(name) {
    if (!renameTarget.value || renameBusy.value) return;
    renameBusy.value = true;
    renameError.value = '';
    const result = await securityFetch(`/settings/security/passkeys/${encodeURIComponent(renameTarget.value.id)}`, {
        method: 'PATCH',
        body: { name },
    });
    renameBusy.value = false;
    if (result.ok) {
        const updated = result.data.passkey;
        items.value = items.value.map((p) => (p.id === updated.id ? { ...p, ...updated } : p));
        publish();
        renameOpen.value = false;
        toast.show(t('passkey.renamed_toast'));
        return;
    }
    renameError.value = 'passkey.error.name';
}

function openRemove(passkey) {
    removeTarget.value = passkey;
    removeOpen.value = true;
}

function removed(id) {
    items.value = items.value.filter((p) => p.id !== id);
    publish();
}
</script>

<template>
    <UiCard class="passkeys" data-testid="passkeys-card">
        <h2 id="security-passkeys-title" class="passkeys__heading">{{ t('passkey.title') }}</h2>
        <p class="passkeys__text">{{ t('passkey.body') }}</p>

        <UiAlert v-if="error" :variant="error === 'passkey.error.cancelled' ? 'info' : 'danger'" class="passkeys__alert" data-testid="passkey-alert">
            <span role="status">{{ t(error) }}</span>
        </UiAlert>

        <UiEmptyState v-if="items.length === 0" :title="t('passkey.empty_title')" :body="t('passkey.empty_body')" :icon="KeyRound" :heading-level="3" size="md" />
        <UiList v-else class="passkeys__list" data-testid="passkey-list">
            <PasskeyRow v-for="p in items" :key="p.id" :passkey="p" @rename="openRename" @remove="openRemove" />
        </UiList>

        <p v-if="nudge" class="passkeys__nudge" data-testid="passkey-nudge">{{ t('security.intro') }}</p>

        <div class="passkeys__actions">
            <PasskeyButton
                :label="t('passkey.cta.add')"
                :variant="items.length === 0 ? 'primary' : 'secondary'"
                :busy="adding"
                data-testid="add-passkey"
                @start="start"
            />
            <p v-if="adding" role="status" class="passkeys__waiting" data-testid="passkey-waiting">{{ t('passkey.waiting') }}</p>
        </div>

        <UiDialog :open="confirmOpen" :title="t('reauth.title')" :initial-focus="firstField" @update:open="(v) => (confirmOpen = v)">
            <div ref="confirmBody" data-testid="passkey-confirm">
                <p class="passkeys__text">{{ t('security.confirm_first') }}</p>
                <ReauthPrompt :methods="methods" :is-admin="isAdmin" @confirmed="confirmed" />
            </div>
        </UiDialog>

        <PasskeyNameDialog
            v-model:open="nameOpen"
            mode="add"
            :name="defaultName"
            :error="nameError"
            :busy="nameBusy"
            @save="savePasskey"
            @skip="savePasskey('')"
        />
        <PasskeyNameDialog
            v-model:open="renameOpen"
            mode="rename"
            :name="renameTarget?.name ?? ''"
            :error="renameError"
            :busy="renameBusy"
            @save="saveRename"
        />
        <PasskeyRemoveDialog
            v-model:open="removeOpen"
            :passkey="removeTarget"
            :methods="methods"
            :is-admin="isAdmin"
            :last-factor="lastFactor"
            @removed="removed"
            @confirmed="fresh = true"
        />
    </UiCard>
</template>

<style scoped>
.passkeys { margin-bottom: var(--space-4); }
.passkeys__heading { margin: 0 0 var(--space-2); font-size: var(--text-lg); font-weight: 600; line-height: 1.3; }
.passkeys__text { margin: 0 0 var(--space-3); }
.passkeys__alert { margin-bottom: var(--space-3); }
.passkeys__list { margin-bottom: var(--space-3); }
.passkeys__nudge { margin: 0 0 var(--space-3); color: var(--color-text-muted); font-size: var(--text-sm); }
.passkeys__actions { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-3); }
.passkeys__waiting { margin: 0; color: var(--color-text-muted); font-size: var(--text-sm); }
</style>
