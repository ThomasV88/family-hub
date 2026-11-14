<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShell from '../../Layouts/AppShell.vue';
import UiErrorState from '../../Components/ui/UiErrorState.vue';
import UiButton from '../../Components/ui/UiButton.vue';

const { t } = useI18n();
const page = usePage();
// Derived from the URL path alone (no server prop), so hidden, unknown, malformed and module-denied refusals stay identical.
const isHealth = computed(() => {
    const path = String(page.url ?? '').split(/[?#]/)[0];
    return path === '/health' || path.startsWith('/health/');
});
</script>

<template>
    <AppShell current="">
        <div data-testid="forbidden">
            <UiErrorState :title="t('access.denied_title')" :body="t('access.denied_body')" :heading-level="1">
                <template #action>
                    <UiButton v-if="isHealth" variant="secondary" href="/health">{{ t('health.forbidden_back') }}</UiButton>
                    <UiButton v-else variant="secondary" href="/">{{ t('access.denied_action') }}</UiButton>
                </template>
            </UiErrorState>
        </div>
    </AppShell>
</template>
