<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import BaseLayout from '../Layouts/BaseLayout.vue';

const { t, d, n } = useI18n();
const page = usePage();
const heartbeat = computed(() => page.props.heartbeat ?? { last_recorded_at: null, last_source: null, count_24h: 0, expected_24h: 1440 });

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
    <BaseLayout>
        <div data-testid="home">
            <h1>{{ t('home.title') }}</h1>
            <p>{{ t('home.intro') }}</p>

            <section class="status-card" data-testid="heartbeat">
                <h2>{{ t('home.status_title') }}</h2>
                <p v-if="heartbeat.last_recorded_at" data-testid="heartbeat-last">
                    {{ t('home.last_check', { time: lastTime }) }} ({{ t('home.source.' + heartbeat.last_source) }})
                </p>
                <p v-else data-testid="heartbeat-none">{{ t('home.no_checks') }}</p>
                <p data-testid="heartbeat-count">
                    {{ t('home.checks_24h', { checks: checksLabel, expected: n(heartbeat.expected_24h, 'integer') }) }}
                </p>
                <p data-testid="heartbeat-coverage">{{ t('home.coverage', { percent: coverage }) }}</p>
                <button type="button" data-testid="heartbeat-run" :disabled="processing" @click="runCheck">
                    {{ processing ? t('home.running') : t('home.run_now') }}
                </button>
            </section>
        </div>
    </BaseLayout>
</template>
