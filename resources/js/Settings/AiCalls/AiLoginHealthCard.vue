<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { I18nT, useI18n } from 'vue-i18n';
import { Check, CircleHelp, RefreshCw, TriangleAlert } from '@lucide/vue';
import UiTag from '../../Components/ui/UiTag.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import { agentName } from '../AiAgents/agentNames.js';

const POLL_MS = 3000;
const POLL_CAP_MS = 30000;
const STATES = {
    logged_in: { variant: 'success', icon: Check },
    expired: { variant: 'danger', icon: TriangleAlert },
    not_responding: { variant: 'warn', icon: TriangleAlert },
    unknown: { variant: 'neutral', icon: CircleHelp },
};

const props = defineProps({
    // Agent key (codex, copilot).
    agent: { type: String, required: true },
    // { state, checkedAt }
    login: { type: Object, required: true },
    checkUrl: { type: String, default: '/settings/ai-calls/check' },
});

const { t, locale } = useI18n();
const checking = ref(false);
const noAnswer = ref(false);
const announcement = ref('');
let timer = null;
let elapsed = 0;
let baseline = null;

const name = computed(() => agentName(props.agent));
const state = computed(() => (STATES[props.login.state] ? props.login.state : 'unknown'));
const meta = computed(() => STATES[state.value]);
const formatter = computed(() => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Europe/Bratislava' }));
const checkedText = computed(() => (props.login.checkedAt ? formatter.value.format(new Date(props.login.checkedAt)) : ''));
const headingId = computed(() => `login-${props.agent}-title`);

function stop() {
    if (timer) clearInterval(timer);
    timer = null;
    checking.value = false;
}

function poll() {
    elapsed += POLL_MS;
    router.reload({ only: ['agents'], preserveScroll: true, preserveState: true });
    if (elapsed >= POLL_CAP_MS) {
        // Last reload was issued above; whatever arrives later still shows. The answer message stays if nothing changed.
        noAnswer.value = props.login.checkedAt === baseline;
        stop();
    }
}

function check() {
    if (checking.value) return;
    checking.value = true;
    noAnswer.value = false;
    elapsed = 0;
    baseline = props.login.checkedAt ?? null;
    router.post(props.checkUrl, {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            timer = setInterval(poll, POLL_MS);
        },
        onError: stop,
        onCancel: stop,
    });
}

// A fresh result ends the wait early.
watch(() => props.login.checkedAt, (value) => {
    if (checking.value && timer && value !== baseline) {
        noAnswer.value = false;
        stop();
    }
});

// Only a change of state is announced, never a poll that changed nothing.
watch(state, (next) => {
    announcement.value = `${name.value}: ${t(`settings.ai_login.${next}`)}`;
});

onBeforeUnmount(stop);
defineExpose({ check });
</script>

<template>
    <section class="login-card" :aria-labelledby="headingId">
        <h4 :id="headingId" class="login-card__title">{{ t('settings.ai_agents.login_title', { agent: name }) }}</h4>
        <div class="login-card__row">
            <UiTag :variant="meta.variant" :icon="meta.icon" class="login-card__tag">{{ t(`settings.ai_login.${state}`) }}</UiTag>
            <I18nT v-if="checkedText" keypath="settings.ai_login.last_checked" tag="p" class="login-card__checked" scope="global">
                <template #time><time :datetime="login.checkedAt">{{ checkedText }}</time></template>
            </I18nT>
        </div>

        <UiAlert v-if="state === 'expired'" variant="danger" class="login-card__alert">{{ t('settings.ai_agents.expired_help', { agent: name }) }}</UiAlert>
        <p v-else-if="state === 'not_responding'" class="login-card__help">{{ t('settings.ai_login.not_responding_help') }}</p>
        <p v-else-if="state === 'unknown'" class="login-card__help">{{ t('settings.ai_login.unknown_help') }}</p>

        <div class="login-card__actions">
            <UiButton :icon="RefreshCw" :disabled="checking" :aria-busy="checking ? 'true' : undefined" @click="check">{{ t('settings.ai_login.check_again') }}</UiButton>
            <p v-if="noAnswer" class="login-card__help" role="status">{{ t('settings.ai_login.no_answer') }}</p>
        </div>
        <span class="visually-hidden" role="status">{{ announcement }}</span>
    </section>
</template>

<style scoped>
.login-card { padding: var(--space-3) var(--space-4); background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.login-card__title { margin: 0 0 var(--space-2); font-size: var(--text-base); font-weight: 600; line-height: 1.3; }
.login-card__row { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2) var(--space-3); }
.login-card__tag { white-space: normal; max-width: 100%; }
.login-card__checked, .login-card__help { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.login-card__help { margin-top: var(--space-2); }
.login-card__alert { margin-top: var(--space-3); }
.login-card__actions { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-3); margin-top: var(--space-3); }
.login-card__actions .login-card__help { margin-top: 0; }
@media (forced-colors: active) {
    .login-card :deep(.ui-tag) { border: 1px solid currentColor; }
}
</style>
