<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';
import { I18nT, useI18n } from 'vue-i18n';
import { Ban, Check, ChevronDown, ChevronUp, Hourglass, Loader } from '@lucide/vue';
import UiCard from '../../Components/ui/UiCard.vue';
import UiTag from '../../Components/ui/UiTag.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiInput from '../../Components/ui/UiInput.vue';
import UiSwitch from '../../Components/ui/UiSwitch.vue';
import AiLoginHealthCard from '../AiCalls/AiLoginHealthCard.vue';
import { agentName } from './agentNames.js';

// Mirrors the server rule (ModelId::PATTERN, max 60). The server stays the authority.
const MODEL_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._:-]{0,59}$/;

const props = defineProps({
    // { key, enabled, position, model, health: { state, checkedAt }, cooldownUntil }
    agent: { type: Object, required: true },
    total: { type: Number, required: true },
    enabledCount: { type: Number, required: true },
    // Control being saved on this card: 'switch' | 'up' | 'down' | 'model' | null.
    busy: { type: String, default: null },
    // True while another card is saving: controls here are inert too.
    locked: { type: Boolean, default: false },
    // '' | 'save' | 'last_enabled' | 'invalid_model'
    problem: { type: String, default: '' },
});
const emit = defineEmits(['toggle', 'move', 'save-model']);

const { t, locale } = useI18n();
const uid = useId();
const health = ref(null);
const draft = ref(props.agent.model ?? '');
const clientError = ref('');

const name = computed(() => agentName(props.agent.key));
const headingId = computed(() => `agent-${props.agent.key}-name`);
const helperId = `agent-${uid}-switch-helper`;
const lockId = `agent-${uid}-lock`;
const inert = computed(() => Boolean(props.busy) || props.locked);
const lastEnabled = computed(() => props.agent.enabled && props.enabledCount <= 1);
const first = computed(() => props.agent.position <= 1);
const last = computed(() => props.agent.position >= props.total);
const unchanged = computed(() => draft.value.trim() === (props.agent.model ?? ''));
const modelError = computed(() => clientError.value || (props.problem === 'invalid_model' ? t('settings.ai_agents.model_error') : ''));
const healthState = computed(() => (['logged_in', 'expired', 'not_responding', 'unknown'].includes(props.agent.health?.state) ? props.agent.health.state : 'unknown'));
const notSignedIn = computed(() => props.agent.enabled && healthState.value !== 'logged_in');
const showReminder = computed(() => props.agent.key === 'copilot' && props.agent.enabled);
const cooldown = computed(() => {
    const until = props.agent.cooldownUntil ? new Date(props.agent.cooldownUntil) : null;
    return until && !Number.isNaN(until.getTime()) && until.getTime() > Date.now() ? until : null;
});
const alertText = computed(() => {
    if (props.problem === 'last_enabled') return t('settings.ai_agents.last_agent_lock');
    return props.problem === 'save' ? t('settings.ai_agents.save_error') : '';
});

const ZONE = 'Europe/Bratislava';
const when = computed(() => {
    if (!cooldown.value) return '';
    const day = (d) => new Intl.DateTimeFormat('en-CA', { timeZone: ZONE }).format(d);
    const sameDay = day(cooldown.value) === day(new Date());
    const options = sameDay ? { timeStyle: 'short' } : { weekday: 'short', hour: 'numeric', minute: '2-digit' };
    return new Intl.DateTimeFormat(locale.value, { ...options, timeZone: ZONE }).format(cooldown.value);
});

watch(() => props.agent.model, (value) => {
    draft.value = value ?? '';
    clientError.value = '';
});
watch(draft, () => { clientError.value = ''; });

let switchEl = null;

function onSwitch(event) {
    const input = event.target;
    // The native checkbox has already flipped. Put it back at once when the change is refused.
    if (inert.value || lastEnabled.value) {
        input.checked = props.agent.enabled;
        return;
    }
    switchEl = input;
    emit('toggle', !props.agent.enabled);
}

// When a save ends, the switch shows the saved value: a failed save reverts it, a successful one confirms it.
watch(() => props.busy, (now, was) => {
    if (was === 'switch' && !now) nextTick(() => { if (switchEl) switchEl.checked = props.agent.enabled; });
});

function onMove(direction) {
    if (inert.value) return;
    if (direction === 'up' ? first.value : last.value) return;
    emit('move', direction);
}

function submitModel() {
    if (inert.value || unchanged.value) return;
    const value = draft.value.trim();
    if (value !== '' && !MODEL_PATTERN.test(value)) {
        clientError.value = t('settings.ai_agents.model_error');
        return;
    }
    emit('save-model', value);
}

defineExpose({ check: () => health.value?.check() });
</script>

<template>
    <li class="agent" :aria-labelledby="headingId" :data-agent="agent.key">
        <UiCard>
            <header class="agent__header">
                <span class="agent__badge" aria-hidden="true">{{ agent.position }}</span>
                <h3 :id="headingId" class="agent__name">{{ name }}</h3>
                <UiTag :variant="agent.enabled ? 'success' : 'neutral'" :icon="agent.enabled ? Check : Ban">{{ t(agent.enabled ? 'settings.ai_agents.on' : 'settings.ai_agents.off') }}</UiTag>
                <span class="agent__saving" role="status">
                    <template v-if="busy"><Loader :size="16" :stroke-width="1.75" aria-hidden="true" />{{ t('settings.ai_agents.saving') }}</template>
                </span>
            </header>

            <UiAlert v-if="alertText" variant="danger" live class="agent__alert">{{ alertText }}</UiAlert>

            <div class="agent__grid">
                <div class="agent__main">
                    <div class="agent__switch">
                        <UiSwitch
                            :model-value="agent.enabled"
                            :aria-busy="busy === 'switch' ? 'true' : undefined"
                            :aria-disabled="inert || lastEnabled ? 'true' : undefined"
                            :aria-describedby="lastEnabled ? `${helperId} ${lockId}` : helperId"
                            :data-agent="agent.key"
                            data-control="switch"
                            @change="onSwitch"
                        >{{ t('settings.ai_agents.switch_label', { agent: name }) }}</UiSwitch>
                        <p :id="helperId" class="agent__muted">{{ t('settings.ai_agents.switch_helper') }}</p>
                        <p v-if="lastEnabled" :id="lockId" class="agent__muted">{{ t('settings.ai_agents.last_agent_lock') }}</p>
                        <p v-if="showReminder" class="agent__muted">{{ t('settings.ai_agents.provider_reminder') }}</p>
                    </div>

                    <div class="agent__order">
                        <div class="agent__buttons">
                            <UiButton
                                :icon="ChevronUp"
                                :aria-label="t('settings.ai_agents.move_up_name', { agent: name, n: Math.max(1, agent.position - 1), total })"
                                :aria-disabled="inert || first ? 'true' : undefined"
                                :aria-busy="busy === 'up' ? 'true' : undefined"
                                :data-agent="agent.key"
                                data-control="up"
                                @click="onMove('up')"
                            >{{ t('settings.ai_agents.move_up') }}</UiButton>
                            <UiButton
                                :icon="ChevronDown"
                                :aria-label="t('settings.ai_agents.move_down_name', { agent: name, n: Math.min(total, agent.position + 1), total })"
                                :aria-disabled="inert || last ? 'true' : undefined"
                                :aria-busy="busy === 'down' ? 'true' : undefined"
                                :data-agent="agent.key"
                                data-control="down"
                                @click="onMove('down')"
                            >{{ t('settings.ai_agents.move_down') }}</UiButton>
                        </div>
                        <p class="agent__muted">{{ t('settings.ai_agents.position_of', { n: agent.position, total }) }}</p>
                    </div>
                </div>

                <div class="agent__side">
                    <form class="agent__model" novalidate @submit.prevent="submitModel">
                        <UiField :label="t('settings.ai_agents.model_label')" :hint="t('settings.ai_agents.model_helper')" :error="modelError">
                            <UiInput
                                v-model="draft"
                                autocomplete="off"
                                spellcheck="false"
                                inputmode="text"
                                maxlength="80"
                                :aria-busy="busy === 'model' ? 'true' : undefined"
                                :aria-disabled="inert ? 'true' : undefined"
                                :data-agent="agent.key"
                                data-control="model"
                            />
                        </UiField>
                        <UiButton
                            type="submit"
                            :aria-disabled="inert || unchanged ? 'true' : undefined"
                            :aria-busy="busy === 'model' ? 'true' : undefined"
                        >{{ t('settings.ai_agents.save_model') }}</UiButton>
                    </form>

                    <AiLoginHealthCard ref="health" :agent="agent.key" :login="agent.health ?? { state: 'unknown', checkedAt: null }" />

                    <UiAlert v-if="notSignedIn" variant="warning" class="agent__warn">
                        {{ t('settings.ai_agents.not_signed_in_warning', { agent: name, state: t(`settings.ai_login.${healthState}`) }) }}
                        <template #actions>
                            <UiButton variant="ghost" @click="health?.check()">{{ t('settings.ai_agents.check_again') }}</UiButton>
                        </template>
                    </UiAlert>

                    <div v-if="cooldown" class="agent__cooldown">
                        <UiTag variant="warn" :icon="Hourglass">{{ t('settings.ai_agents.cooldown_tag') }}</UiTag>
                        <I18nT keypath="settings.ai_agents.cooldown_line" tag="p" class="agent__muted" scope="global">
                            <template #when><time :datetime="cooldown.toISOString()">{{ when }}</time></template>
                        </I18nT>
                    </div>
                </div>
            </div>
        </UiCard>
    </li>
</template>

<style scoped>
.agent { list-style: none; }
.agent__header { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2) var(--space-3); margin-bottom: var(--space-3); }
.agent__badge { display: inline-flex; align-items: center; justify-content: center; min-width: 28px; padding: var(--space-1); border-radius: var(--radius-full); background: var(--color-surface-2); font-size: var(--text-sm); font-weight: 600; font-variant-numeric: tabular-nums; }
.agent__name { margin: 0; font-size: var(--text-base); font-weight: 600; line-height: 1.3; overflow-wrap: anywhere; }
.agent__saving { display: inline-flex; align-items: center; gap: var(--space-1); font-size: var(--text-sm); color: var(--color-text-muted); }
.agent__alert, .agent__warn { margin-bottom: var(--space-3); }
.agent__warn { margin-top: var(--space-3); }
.agent__grid { display: grid; gap: var(--space-4); }
.agent__main, .agent__side { display: flex; flex-direction: column; gap: var(--space-3); min-width: 0; }
.agent__switch { display: flex; flex-direction: column; gap: var(--space-1); }
.agent__switch :deep(.ui-choice) { min-height: 44px; width: 100%; }
.agent__muted { margin: 0; font-size: var(--text-sm); line-height: 1.4; color: var(--color-text-muted); }
.agent__order { display: flex; flex-direction: column; gap: var(--space-2); }
.agent__buttons { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.agent__buttons :deep(.ui-button), .agent__model :deep(.ui-button), .agent__warn :deep(.ui-button) { min-height: 44px; }
.agent__model { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-2); }
.agent__model :deep(.ui-field) { align-self: stretch; margin-bottom: 0; }
.agent__model :deep(.ui-input) { width: 100%; }
.agent__cooldown { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2) var(--space-3); }
.agent :deep(.ui-tag) { white-space: normal; max-width: 100%; }
@container settings (min-width: 800px) {
    .agent__grid { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: var(--space-6); }
}
@media (forced-colors: active) {
    .agent :deep(.ui-tag) { border: 1px solid currentColor; }
}
</style>
