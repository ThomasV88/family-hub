<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShell from '../Layouts/AppShell.vue';
import UiCard from '../Components/ui/UiCard.vue';
import UiButton from '../Components/ui/UiButton.vue';
import QuickAdd from '../Dashboard/QuickAdd.vue';
import ModuleCard from '../Dashboard/ModuleCard.vue';

const { t, d, n } = useI18n();
const page = usePage();
const heartbeat = computed(() => page.props.heartbeat ?? { last_recorded_at: null, last_source: null, count_24h: 0, expected_24h: 1440 });
const quickAdd = ref(null);
// The shell lives below this page, so QuickAdd calls useShell().registerAddHandler (Add task focuses it).
const focusQuickAdd = () => quickAdd.value?.focus();

const processing = ref(false);
const lastTime = computed(() =>
    heartbeat.value.last_recorded_at ? d(new Date(heartbeat.value.last_recorded_at), 'short') : null,
);
const checksLabel = computed(() =>
    t('home.checks_count', heartbeat.value.count_24h, { named: { count: n(heartbeat.value.count_24h, 'integer') } }),
);
const coverage = computed(() => n(heartbeat.value.count_24h / heartbeat.value.expected_24h, 'percent'));

function runCheck() {
    router.post('/heartbeat', {}, {
        preserveScroll: true,
        onStart: () => { processing.value = true; },
        onFinish: () => { processing.value = false; },
    });
}
</script>

<template>
    <AppShell current="overview">
        <div data-testid="home">
            <h1 class="home__title">{{ t('dashboard.title') }}</h1>
            <QuickAdd ref="quickAdd" />
            <div class="home__grid">
                <ModuleCard module-key="tasks" @add="focusQuickAdd" />
                <ModuleCard module-key="wishlist" />
                <ModuleCard module-key="finances" />
                <ModuleCard module-key="health" />
            </div>
            <UiCard :title="t('dashboard.system_status')" class="home__status">
                <section data-testid="heartbeat">
                    <h3 class="home__status-title">{{ t('home.status_title') }}</h3>
                    <p v-if="heartbeat.last_recorded_at" data-testid="heartbeat-last">
                        {{ t('home.last_check', { time: lastTime }) }} ({{ t('home.source.' + heartbeat.last_source) }})
                    </p>
                    <p v-else data-testid="heartbeat-none">{{ t('home.no_checks') }}</p>
                    <p data-testid="heartbeat-count">
                        {{ t('home.checks_24h', { checks: checksLabel, expected: n(heartbeat.expected_24h, 'integer') }) }}
                    </p>
                    <p data-testid="heartbeat-coverage">{{ t('home.coverage', { percent: coverage }) }}</p>
                    <UiButton variant="secondary" data-testid="heartbeat-run" :loading="processing" @click="runCheck">
                        {{ processing ? t('home.running') : t('home.run_now') }}
                    </UiButton>
                </section>
            </UiCard>
        </div>
    </AppShell>
</template>

<style scoped>
.home__title { margin: 0 0 var(--space-4); font-size: var(--text-2xl); font-weight: 600; line-height: 1.25; }
.home__grid { display: grid; grid-template-columns: 1fr; gap: var(--space-4); margin-block: var(--space-4); }
@container content (min-width: 700px) {
    .home__grid { grid-template-columns: 1fr 1fr; gap: var(--space-6); margin-block: var(--space-6); }
}
.home__status-title { margin: 0 0 var(--space-2); font-size: var(--text-base); font-weight: 600; }
.home__status p { margin: 0 0 var(--space-2); }
</style>
