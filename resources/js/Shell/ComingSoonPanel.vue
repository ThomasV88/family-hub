<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import UiDetailPanel from '../Components/ui/UiDetailPanel.vue';
import UiEmptyState from '../Components/ui/UiEmptyState.vue';
import UiButton from '../Components/ui/UiButton.vue';
import { MODULES } from './modules.js';
import { useShell } from './shellState.js';

const { t } = useI18n();
const shell = useShell();
const module = computed(() => MODULES.find((m) => m.key === shell.panelModule.value));
</script>

<template>
    <UiDetailPanel :open="shell.panelModule.value !== null" :title="module ? t(module.titleKey) : ''" @update:open="shell.closePanel()">
        <UiEmptyState :title="t('soon.title')" :body="t('soon.body')" :heading-level="3">
            <template #action>
                <UiButton variant="secondary" @click="shell.closePanel()">{{ t('soon.back') }}</UiButton>
            </template>
        </UiEmptyState>
    </UiDetailPanel>
</template>
