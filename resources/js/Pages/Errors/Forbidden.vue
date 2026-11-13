<script setup>
import { useI18n } from 'vue-i18n';
import AppShell from '../../Layouts/AppShell.vue';
import UiErrorState from '../../Components/ui/UiErrorState.vue';
import UiButton from '../../Components/ui/UiButton.vue';

const { t } = useI18n();
</script>

<template>
    <AppShell current="">
        <div data-testid="forbidden">
            <UiErrorState :title="t('access.denied_title')" :body="t('access.denied_body')" :heading-level="1">
                <template #action>
                    <UiButton variant="secondary" href="/">{{ t('access.denied_action') }}</UiButton>
                </template>
            </UiErrorState>
        </div>
    </AppShell>
</template>
