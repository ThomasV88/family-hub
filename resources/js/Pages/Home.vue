<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import BaseLayout from '../Layouts/BaseLayout.vue';

const page = usePage();
const heartbeat = computed(() => page.props.heartbeat ?? { last_recorded_at: null, last_source: null, count_24h: 0, expected_24h: 1440 });

const processing = ref(false);
// Temporary English formatting; plan 01-07 replaces it with vue-i18n.
const lastLabel = computed(() =>
    heartbeat.value.last_recorded_at ? new Date(heartbeat.value.last_recorded_at).toLocaleString() : null,
);

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
            <h1>Family Hub</h1>
            <p>One trusted place for your family's everyday life.</p>

            <section class="status-card" data-testid="heartbeat">
                <h2>System status</h2>
                <p v-if="heartbeat.last_recorded_at" data-testid="heartbeat-last">
                    Last background check: {{ lastLabel }} ({{ heartbeat.last_source }})
                </p>
                <p v-else data-testid="heartbeat-none">No background check has run yet.</p>
                <p data-testid="heartbeat-count">
                    Checks in the last 24 hours: {{ heartbeat.count_24h }} of {{ heartbeat.expected_24h }}
                </p>
                <button type="button" data-testid="heartbeat-run" :disabled="processing" @click="runCheck">
                    Run check now
                </button>
            </section>
        </div>
    </BaseLayout>
</template>
