<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Bot } from '@lucide/vue';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import UiErrorState from '../../Components/ui/UiErrorState.vue';
import UiSkeleton from '../../Components/ui/UiSkeleton.vue';
import AiCallsFilters from '../../Settings/AiCalls/AiCallsFilters.vue';
import AiCallsTable from '../../Settings/AiCalls/AiCallsTable.vue';

const URL = '/settings/ai-calls';

const props = defineProps({
    sections: { type: Array, default: () => [] },
    // null while the call list has not arrived yet (loading skeleton).
    calls: { type: Object, default: null },
    filters: { type: Object, default: () => ({ person: null, member: null, status: null }) },
    options: { type: Object, default: () => ({ people: [], members: [] }) },
});

const { t } = useI18n();
const busy = ref(false);
const failed = ref(false);

const anyFilter = computed(() => Boolean(props.filters.person || props.filters.member || props.filters.status));

function query(next, page) {
    const q = {};
    for (const key of ['person', 'member', 'status']) {
        if (next[key]) q[key] = next[key];
    }
    if (page && page > 1) q.page = page;
    return q;
}

function load(q) {
    busy.value = true;
    failed.value = false;
    router.get(URL, q, {
        only: ['calls', 'filters'],
        preserveState: true,
        preserveScroll: true,
        onError: () => { failed.value = true; },
        onFinish: () => { busy.value = false; },
    });
}

const onFilter = (next) => load(query(next));
const clear = () => load({});
const goTo = (page) => load(query(props.filters, page));
const retry = () => load(query(props.filters, props.calls?.page));
</script>

<template>
    <Head :title="t('settings.ai_calls.title')" />
    <SettingsLayout :sections="sections" current="ai_calls" title="settings.ai_calls.title">
        <p class="ai-calls__intro">{{ t('settings.ai_calls.intro') }}</p>

        <p class="ai-calls__pointer"><Link href="/settings/ai-agents">{{ t('settings.ai_calls.agents_pointer') }}</Link></p>

        <section class="ai-calls__list">
            <AiCallsFilters :filters="filters" :options="options" @change="onFilter" @clear="clear" />

            <div v-if="calls === null" class="ai-calls__loading" aria-busy="true">
                <UiCard>
                    <UiSkeleton width="40%" />
                    <div v-for="n in 5" :key="n" class="ai-calls__skeleton-row"><UiSkeleton /></div>
                </UiCard>
            </div>
            <UiErrorState v-else-if="failed" :heading-level="2" :body="t('settings.ai_calls.load_failed')" @retry="retry">
                <template #action>
                    <UiButton @click="retry">{{ t('health.error.retry') }}</UiButton>
                </template>
            </UiErrorState>
            <template v-else-if="!calls.rows.length">
                <UiEmptyState v-if="anyFilter" :heading-level="2" :icon="Bot" :title="t('settings.ai_calls.filtered_empty')">
                    <template #action>
                        <UiButton variant="ghost" @click="clear">{{ t('settings.ai_calls.clear') }}</UiButton>
                    </template>
                </UiEmptyState>
                <UiEmptyState v-else :heading-level="2" :icon="Bot" :title="t('settings.ai_calls.empty_title')" :body="t('settings.ai_calls.empty_body')" />
            </template>
            <AiCallsTable v-else :rows="calls.rows" :page="calls.page" :pages="calls.pages" :busy="busy" @page="goTo" />
        </section>
    </SettingsLayout>
</template>

<style scoped>
.ai-calls__intro { margin: 0 0 var(--space-4); font-size: var(--text-sm); color: var(--color-text-muted); }
.ai-calls__pointer { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.ai-calls__pointer a { color: var(--color-primary); }
.ai-calls__list { margin-top: var(--space-6); }
.ai-calls__skeleton-row { margin-top: var(--space-4); }
</style>
