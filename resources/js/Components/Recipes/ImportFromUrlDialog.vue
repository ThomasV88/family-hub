<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Info } from '@lucide/vue';
import UiAlert from '../ui/UiAlert.vue';
import UiButton from '../ui/UiButton.vue';
import UiDialog from '../ui/UiDialog.vue';
import UiField from '../ui/UiField.vue';
import UiInput from '../ui/UiInput.vue';
import UiProgress from '../ui/UiProgress.vue';
import HelpTip from '../ui/HelpTip.vue';
import { securityFetch } from '../../Security/securityFetch.js';
import { useImportStatus } from './useImportStatus.js';

// "Read from a web page": the URL step, the one-time notice that the page text goes to the AI provider, progress, cancel and
// the failure states. The page text and the address are never rendered as HTML. `info` is the `import` prop of the overview:
// { available, acknowledged, providers }. Closing while the page is being read keeps the import running (`background`): the
// overview shows it as a draft row.
const props = defineProps({
    open: { type: Boolean, default: false },
    info: { type: Object, default: () => ({ available: true, acknowledged: false, providers: [] }) },
});
const emit = defineEmits(['update:open', 'background']);
const { t } = useI18n();
const uid = useId();
const formId = `rec-import-form-${uid}`;

const URL_MAX = 2000;
const url = ref('');
const touched = ref(false);
const acknowledged = ref(Boolean(props.info?.acknowledged));
const providers = ref([...(props.info?.providers ?? [])]);
// 'input' | 'loading' | 'failed'
const phase = ref('input');
// Why the last attempt failed: fetch, no_recipe, unavailable, limit, generic, start, forbidden, session.
const failure = ref('');
const noticeProblem = ref('');
const cancelProblem = ref('');
const starting = ref(false);
const acking = ref(false);
const cancelling = ref(false);
const serverUrlError = ref(false);
const importId = ref(null);
const announcement = ref('');
const root = ref(null);
const gotIt = ref(null);

const poll = useImportStatus(importId);

watch(() => props.info, (info) => {
    acknowledged.value = Boolean(info?.acknowledged);
    providers.value = [...(info?.providers ?? [])];
}, { deep: true });

const trimmed = computed(() => url.value.trim());
const validUrl = computed(() => trimmed.value !== '' && trimmed.value.length <= URL_MAX && /^https?:\/\/\S+$/i.test(trimmed.value));
const urlError = computed(() => {
    if (serverUrlError.value) return t('recipes.import.err_url');

    return touched.value && trimmed.value !== '' && !validUrl.value ? t('recipes.import.err_url') : '';
});
const showNotice = computed(() => !acknowledged.value);
const busy = computed(() => starting.value || cancelling.value);
const typeItHref = computed(() => (trimmed.value === '' ? '/recipes/create' : `/recipes/create?source=${encodeURIComponent(trimmed.value)}`));
const failureText = computed(() => {
    switch (failure.value) {
        case 'fetch': return t('recipes.import.err_fetch');
        case 'no_recipe': return t('recipes.import.err_no_recipe');
        case 'unavailable': return t('recipes.import.err_unavailable');
        case 'limit': return t('recipes.import.err_limit');
        case 'forbidden': return t('recipes.error.forbidden');
        case 'session': return t('recipes.error.session_expired');
        case 'start': return t('recipes.import.err_start');
        case 'slow': return t('recipes.import.err_slow');
        default: return t('recipes.import.err_generic');
    }
});
const failureVariant = computed(() => (['unavailable', 'limit', 'slow'].includes(failure.value) ? 'warning' : 'danger'));
// The first import may still be running on the server (3 minutes passed, or the status could not be read): Cancel and Try again
// stop it first, and closing the window leaves it as a draft row, so a second import is never started next to a live one.
const mayStillRun = computed(() => phase.value === 'loading' || (phase.value === 'failed' && failure.value === 'slow' && importId.value !== null));
// An address problem is fixed by changing the address; the other failures may pass by themselves.
const changeAddress = computed(() => ['fetch', 'no_recipe'].includes(failure.value));
const canRetry = computed(() => failure.value !== 'limit');

function reset() {
    poll.stop();
    importId.value = null;
    phase.value = 'input';
    failure.value = '';
    noticeProblem.value = '';
    cancelProblem.value = '';
    serverUrlError.value = false;
    touched.value = false;
    starting.value = false;
    cancelling.value = false;
    announcement.value = '';
}

watch(() => props.open, (open) => {
    if (open) {
        reset();
        url.value = '';
        acknowledged.value = Boolean(props.info?.acknowledged);
        providers.value = [...(props.info?.providers ?? [])];
    } else {
        reset();
    }
});

const fine = () => typeof window !== 'undefined' && window.matchMedia?.('(pointer: fine)')?.matches === true;
function initialFocus() {
    if (!acknowledged.value) return gotIt.value?.$el ?? null;
    return fine() ? root.value?.querySelector('[data-testid="import-url"]') ?? null : null;
}
async function focusSoon(selector) {
    await nextTick();
    root.value?.querySelector(selector)?.focus?.();
}

function onUrl(value) {
    url.value = value;
    serverUrlError.value = false;
}

function toFailure(kind) {
    phase.value = 'failed';
    failure.value = kind;
    announcement.value = '';
    focusSoon('[data-testid="import-failure"]');
}

async function acknowledge() {
    if (acking.value) return;
    acking.value = true;
    noticeProblem.value = '';
    const result = await securityFetch('/recipes/imports/notice', { method: 'POST', body: { providers: [...providers.value] } });
    acking.value = false;
    if (result.ok) {
        acknowledged.value = true;
        focusSoon('[data-testid="import-url"]');

        return;
    }
    if (result.status === 409 && Array.isArray(result.data?.providers)) {
        providers.value = [...result.data.providers];
        noticeProblem.value = t('recipes.import.notice_changed');

        return;
    }
    noticeProblem.value = result.status === 403 ? t('recipes.error.forbidden')
        : result.status === 419 ? t('recipes.error.session_expired')
            : result.error === 'throttle' ? t('recipes.error.too_many_requests') : t('recipes.import.notice_failed');
}

async function start() {
    if (starting.value || phase.value === 'loading') return;
    touched.value = true;
    if (!validUrl.value || !acknowledged.value) {
        if (!validUrl.value) focusSoon('[data-testid="import-url"]');

        return;
    }
    starting.value = true;
    serverUrlError.value = false;
    const result = await securityFetch('/recipes/imports', { method: 'POST', body: { url: trimmed.value } });
    starting.value = false;
    if (result.ok) {
        phase.value = 'loading';
        announcement.value = t('recipes.import.loading');
        importId.value = result.data?.id ?? null;
        if (importId.value === null) toFailure('start');
        else focusSoon('[data-testid="import-status"]');

        return;
    }
    const data = result.data ?? {};
    if (result.status === 422) {
        serverUrlError.value = true;
        focusSoon('[data-testid="import-url"]');
    } else if (result.status === 409 && data.outcome === 'notice_required') {
        providers.value = Array.isArray(data.providers) ? [...data.providers] : providers.value;
        acknowledged.value = false;
        noticeProblem.value = t('recipes.import.notice_changed');
        focusSoon('[data-testid="import-notice-confirm"]');
    } else if (result.status === 409 && data.outcome === 'unavailable') {
        toFailure('unavailable');
    } else if (result.status === 429 || data.outcome === 'limit') {
        toFailure('limit');
    } else if (result.status === 403) {
        toFailure('forbidden');
    } else if (result.status === 419) {
        toFailure('session');
    } else {
        toFailure('start');
    }
}

// What the polling found. Each terminal status is handled once; leaving the page on `ready` ends the polling.
watch(() => poll.status.value, (status) => {
    if (phase.value !== 'loading' || importId.value === null) return;
    if (status === 'ready') {
        announcement.value = t('recipes.import.ready_open');
        const id = importId.value;
        poll.stop();
        router.visit(`/recipes/create?import=${encodeURIComponent(id)}`);
    } else if (status === 'failed') {
        const reason = poll.state.value?.reason;
        toFailure(reason === 'fetch' ? 'fetch' : reason === 'no_recipe' ? 'no_recipe' : reason === 'unavailable' ? 'unavailable' : 'generic');
    } else if (status === 'cancelled' || status === 'used') {
        emit('update:open', false);
    }
});
watch(() => [poll.timedOut.value, poll.error.value], ([timedOut, error]) => {
    if (phase.value !== 'loading') return;
    if (error === 403) toFailure('forbidden');
    else if (error === 419) toFailure('session');
    else if (error === 404) emit('update:open', false);
    else if (timedOut || error !== null) toFailure('slow');
});

function dismiss(value) {
    if (value) return;
    if (busy.value) return;
    if (mayStillRun.value) emit('background');
    emit('update:open', false);
}

// Footer Cancel: while reading it stops the import on the server and closes; otherwise it only closes.
async function cancel() {
    if (!mayStillRun.value) {
        emit('update:open', false);

        return;
    }
    if (cancelling.value) return;
    cancelling.value = true;
    cancelProblem.value = '';
    const id = importId.value;
    const result = await securityFetch(`/recipes/imports/${encodeURIComponent(id)}/cancel`, { method: 'POST' });
    cancelling.value = false;
    if (result.ok || result.status === 404) {
        emit('update:open', false);

        return;
    }
    cancelProblem.value = result.status === 403 ? t('recipes.error.forbidden')
        : result.status === 419 ? t('recipes.error.session_expired') : t('recipes.import.cancel_failed');
}

async function again() {
    if (cancelling.value) return;
    if (importId.value !== null && failure.value === 'slow') {
        // Stop the first import before a second one can start. A 404 means it is already gone.
        cancelling.value = true;
        cancelProblem.value = '';
        const result = await securityFetch(`/recipes/imports/${encodeURIComponent(importId.value)}/cancel`, { method: 'POST' });
        cancelling.value = false;
        if (!result.ok && result.status !== 404) {
            cancelProblem.value = result.status === 403 ? t('recipes.error.forbidden')
                : result.status === 419 ? t('recipes.error.session_expired') : t('recipes.import.cancel_failed');

            return;
        }
        emit('background');
    }
    poll.stop();
    importId.value = null;
    phase.value = 'input';
    failure.value = '';
    cancelProblem.value = '';
    serverUrlError.value = false;
    focusSoon('[data-testid="import-url"]');
}
function typeItIn(event) {
    event.preventDefault();
    emit('update:open', false);
    router.visit(typeItHref.value);
}
</script>

<template>
    <UiDialog :open="open" :title="t('recipes.import.title')" :dismissable="!busy" :initial-focus="initialFocus" @update:open="dismiss">
        <div ref="root" class="rec-import">
            <form :id="formId" class="rec-import__form" novalidate @submit.prevent="start">
                <UiAlert v-if="showNotice" variant="info" class="rec-import__notice" data-testid="import-notice">
                    <strong class="rec-import__notice-title">{{ t('recipes.import.notice_title') }}</strong>
                    <span class="rec-import__notice-body">{{ t('recipes.import.notice_body') }}</span>
                    <template #actions>
                        <UiButton ref="gotIt" variant="primary" size="sm" :loading="acking" data-testid="import-notice-confirm" @click="acknowledge">{{ t('recipes.import.notice_confirm') }}</UiButton>
                    </template>
                </UiAlert>
                <p v-if="showNotice && noticeProblem" class="rec-import__problem" role="alert" data-testid="import-notice-problem">{{ noticeProblem }}</p>

                <template v-if="!showNotice">
                    <UiField :label="t('recipes.import.field')" :hint="t('recipes.import.field_hint')" :error="phase === 'loading' ? '' : urlError" required>
                        <UiInput
                            :model-value="url" type="url" inputmode="url" placeholder="https://" autocomplete="off" :maxlength="URL_MAX"
                            :readonly="phase === 'loading'" data-testid="import-url" @update:model-value="onUrl" @blur="touched = true"
                        />
                    </UiField>
                    <p class="rec-import__sent" data-testid="import-hint">
                        <Info :size="16" :stroke-width="1.75" aria-hidden="true" />
                        <span>{{ t('recipes.import.hint') }}</span>
                        <HelpTip topic="recipes.import" :label="t('recipes.import.title')" />
                    </p>
                </template>
            </form>

            <div aria-live="polite" class="rec-import__live">
                <div v-if="phase === 'loading'" class="rec-import__status" tabindex="-1" data-testid="import-status">
                    <UiProgress indeterminate :label="t('recipes.import.progress_label')" />
                    <p class="rec-import__loading">{{ t('recipes.import.loading') }}</p>
                </div>
                <UiAlert v-else-if="phase === 'failed'" :variant="failureVariant" class="rec-import__failure" tabindex="-1" data-testid="import-failure">{{ failureText }}</UiAlert>
            </div>
            <p v-if="cancelProblem" class="rec-import__problem" role="alert" data-testid="import-cancel-problem">{{ cancelProblem }}</p>
            <p class="visually-hidden" role="status" aria-live="polite" data-testid="import-announce">{{ announcement }}</p>
        </div>

        <template #footer>
            <UiButton variant="ghost" :loading="cancelling" :disabled="starting" data-testid="import-cancel" @click="cancel">{{ t('recipes.import.cancel') }}</UiButton>
            <template v-if="phase === 'failed'">
                <UiButton variant="secondary" :href="typeItHref" data-testid="import-type-it-in" @click="typeItIn">{{ t('recipes.import.type_it_in') }}</UiButton>
                <UiButton v-if="canRetry" variant="primary" data-testid="import-again" @click="again">{{ changeAddress ? t('recipes.import.change_address') : t('recipes.import.try_again') }}</UiButton>
            </template>
            <UiButton
                v-else-if="phase === 'input'" type="submit" :form="formId" variant="primary" :loading="starting" :disabled="starting || showNotice || !validUrl"
                data-testid="import-read"
            >{{ t('recipes.import.read') }}</UiButton>
        </template>
    </UiDialog>
</template>

<style scoped>
.rec-import { display: flex; flex-direction: column; gap: var(--space-3); }
.rec-import__form { display: flex; flex-direction: column; gap: var(--space-2); }
.rec-import__notice-title { display: block; }
.rec-import__notice-body { display: block; margin-block-start: var(--space-1); }
.rec-import__sent { display: flex; align-items: center; gap: var(--space-2); margin: 0; color: var(--color-text-muted); font-size: var(--text-sm); }
.rec-import__sent svg { flex: none; }
.rec-import__status { display: flex; flex-direction: column; gap: var(--space-2); }
.rec-import__status:focus, .rec-import__failure:focus { outline: none; }
.rec-import__loading { margin: 0; color: var(--color-text-muted); font-size: var(--text-sm); }
.rec-import__problem { margin: 0; color: var(--color-danger); font-size: var(--text-sm); }
.rec-import :deep(.ui-field) { margin-bottom: 0; }
</style>
