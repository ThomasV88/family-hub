<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Bot } from '@lucide/vue';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import UiErrorState from '../../Components/ui/UiErrorState.vue';
import UiSkeleton from '../../Components/ui/UiSkeleton.vue';
import { useToast } from '../../Components/ui/useToast.js';
import { securityFetch } from '../../Security/securityFetch.js';
import AgentCard from '../../Settings/AiAgents/AgentCard.vue';
import { agentName } from '../../Settings/AiAgents/agentNames.js';

const props = defineProps({
    sections: { type: Array, default: () => [] },
    // null while the list has not arrived yet (loading skeleton).
    agents: { type: Array, default: null },
    providers: { type: Array, default: () => [] },
    // Route name of the login check; the card posts to the matching URL.
    checkRoute: { type: String, default: 'settings.ai_calls.check' },
});

const { t } = useI18n();
const toast = useToast();

const list = ref(props.agents === null ? null : [...props.agents]);
const busy = ref(null); // { key, control }
const problems = ref({}); // key -> '' | 'save' | 'last_enabled' | 'invalid_model'
const announcement = ref('');
const loadFailed = ref(false);
const root = ref(null);

watch(() => props.agents, (next) => { list.value = next === null ? null : [...next]; });

const ordered = computed(() => [...(list.value ?? [])].sort((a, b) => a.position - b.position));
const enabledCount = computed(() => ordered.value.filter((a) => a.enabled).length);

async function say(text) {
    announcement.value = '';
    await nextTick();
    announcement.value = text;
    toast.show(text);
}

function adopt(data) {
    if (Array.isArray(data?.agents)) list.value = data.agents;
}

function focusControl(key, control) {
    nextTick(() => root.value?.querySelector(`[data-agent="${key}"][data-control="${control}"]`)?.focus());
}

async function save(agent, control, url, request, onOk) {
    if (busy.value) return;
    busy.value = { key: agent.key, control };
    problems.value = { ...problems.value, [agent.key]: '' };
    const result = await securityFetch(url, request);
    busy.value = null;
    if (result.ok) {
        adopt(result.data);
        await onOk(result.data);
        return;
    }
    adopt(result.data);
    if (result.status === 409) {
        say(t('settings.ai_agents.stale_order'));
        return;
    }
    const reason = result.data?.reason;
    const problem = result.status === 422 && (reason === 'last_enabled' || reason === 'invalid_model') ? reason : 'save';
    problems.value = { ...problems.value, [agent.key]: problem };
}

const total = computed(() => ordered.value.length);
const find = (key) => ordered.value.find((a) => a.key === key);
const endpoint = (agent) => `/settings/ai-agents/${encodeURIComponent(agent.key)}`;

function toggle(agent, enabled) {
    return save(agent, 'switch', endpoint(agent), { method: 'PATCH', body: { enabled } }, () =>
        say(t(enabled ? 'settings.ai_agents.now_on' : 'settings.ai_agents.now_off', { agent: agentName(agent.key) })));
}

function move(agent, direction) {
    return save(agent, direction, `${endpoint(agent)}/move`, { method: 'POST', body: { direction, expectedPosition: agent.position } }, () => {
        const now = find(agent.key);
        const position = now?.position ?? agent.position;
        say(t('settings.ai_agents.moved_to', { agent: agentName(agent.key), n: position, total: total.value }));
        // Same-direction button, or the opposite one when the agent reached an end.
        const atEnd = direction === 'up' ? position <= 1 : position >= total.value;
        focusControl(agent.key, atEnd ? (direction === 'up' ? 'down' : 'up') : direction);
    });
}

function saveModel(agent, model) {
    return save(agent, 'model', endpoint(agent), { method: 'PATCH', body: { model } }, () =>
        say(t('settings.ai_agents.model_saved', { agent: agentName(agent.key) })));
}

function reload() {
    loadFailed.value = false;
    router.reload({
        only: ['agents'],
        preserveScroll: true,
        preserveState: true,
        onError: () => { loadFailed.value = true; },
    });
}
</script>

<template>
    <Head :title="t('settings.section.ai_agents')" />
    <SettingsLayout :sections="sections" current="ai_agents" title="settings.section.ai_agents">
        <div ref="root" class="agents">
            <h2 class="visually-hidden">{{ t('settings.section.ai_agents') }}</h2>
            <p class="agents__intro">{{ t('settings.ai_agents.intro') }}</p>

            <div v-if="list === null && !loadFailed" class="agents__loading" aria-busy="true">
                <UiCard v-for="n in 2" :key="n">
                    <UiSkeleton width="40%" />
                    <div class="agents__skeleton-row"><UiSkeleton /></div>
                </UiCard>
            </div>
            <UiErrorState v-else-if="loadFailed" :heading-level="2" @retry="reload">
                <template #action>
                    <UiButton @click="reload">{{ t('health.error.retry') }}</UiButton>
                </template>
            </UiErrorState>
            <UiEmptyState
                v-else-if="!ordered.length"
                :heading-level="2"
                :icon="Bot"
                :title="t('settings.ai_agents.empty_heading')"
                :body="t('settings.ai_agents.empty_body')"
            />
            <template v-else>
                <ol class="agents__list" :aria-label="t('settings.ai_agents.list_label')">
                    <AgentCard
                        v-for="agent in ordered"
                        :key="agent.key"
                        :agent="agent"
                        :total="total"
                        :enabled-count="enabledCount"
                        :busy="busy?.key === agent.key ? busy.control : null"
                        :locked="Boolean(busy) && busy.key !== agent.key"
                        :problem="problems[agent.key] ?? ''"
                        @toggle="toggle(agent, $event)"
                        @move="move(agent, $event)"
                        @save-model="saveModel(agent, $event)"
                    />
                </ol>
                <p class="agents__note">
                    {{ t('settings.ai_agents.apply_note') }}
                    <Link href="/settings/ai-calls">{{ t('settings.ai_agents.open_calls') }}</Link>
                </p>
            </template>

            <p class="visually-hidden" role="status">{{ announcement }}</p>
        </div>
    </SettingsLayout>
</template>

<style scoped>
.agents { max-width: 720px; }
.agents__intro, .agents__note { margin: 0 0 var(--space-4); font-size: var(--text-sm); color: var(--color-text-muted); }
.agents__list { display: flex; flex-direction: column; gap: var(--space-4); margin: 0 0 var(--space-4); padding: 0; list-style: none; }
.agents__loading { display: flex; flex-direction: column; gap: var(--space-4); }
.agents__skeleton-row { margin-top: var(--space-4); }
.agents__note a { color: var(--color-primary); }
</style>
