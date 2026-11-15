<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Languages } from '@lucide/vue';
import StyleSection from '../StyleSection.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiDialog from '../../Components/ui/UiDialog.vue';
import UiConfirmDialog from '../../Components/ui/UiConfirmDialog.vue';
import UiDetailPanel from '../../Components/ui/UiDetailPanel.vue';
import UiMenu from '../../Components/ui/UiMenu.vue';
import UiTabs from '../../Components/ui/UiTabs.vue';
import UiToastRegion from '../../Components/ui/UiToastRegion.vue';
import { useToast } from '../../Components/ui/useToast.js';

const { t } = useI18n();
const { show } = useToast();

const dialogOpen = ref(false);
const confirmOpen = ref(false);
const panel = ref({ sheet: false, overlay: false, docked: false });
const theme = ref('system');
const tab = ref('overview');

const menuItems = () => [
    { type: 'item', key: 'language', label: t('nav.language'), icon: Languages },
    {
        type: 'radio-group',
        key: 'theme',
        label: t('theme.label'),
        value: theme.value,
        options: ['system', 'light', 'dark'].map((value) => ({ value, label: t(`theme.${value}`) })),
    },
];
const tabs = [
    { key: 'overview', label: t('style.demo.tab_overview') },
    { key: 'transactions', label: t('style.demo.tab_transactions') },
    { key: 'recurring', label: t('style.demo.tab_recurring') },
];
function onMenuSelect({ key, value }) {
    if (key === 'theme') theme.value = value;
}
</script>

<template>
    <StyleSection id="overlays" title-key="style.section.overlays">
        <div class="overlays__row">
            <UiButton @click="dialogOpen = true">{{ t('style.demo.open_dialog') }}</UiButton>
            <UiButton variant="danger" @click="confirmOpen = true">{{ t('style.demo.delete_example') }}</UiButton>
            <UiButton @click="panel.sheet = true">{{ t('style.demo.open_panel') }}: {{ t('style.demo.mode_sheet') }}</UiButton>
            <UiButton @click="panel.overlay = true">{{ t('style.demo.open_panel') }}: {{ t('style.demo.mode_overlay') }}</UiButton>
            <UiButton @click="panel.docked = true">{{ t('style.demo.open_panel') }}: {{ t('style.demo.mode_docked') }}</UiButton>
            <UiButton @click="show(t('style.demo.toast_saved'))">{{ t('style.demo.show_toast') }}</UiButton>
            <UiMenu :label="t('style.demo.menu_example')" :items="menuItems()" @select="onMenuSelect" />
        </div>

        <div class="overlays__tabs">
            <UiTabs v-model="tab" :tabs="tabs" :label="t('style.demo.tab_overview')">
                <template #panel-overview><p>{{ t('style.demo.tab_overview') }}</p></template>
                <template #panel-transactions><p>{{ t('style.demo.tab_transactions') }}</p></template>
                <template #panel-recurring><p>{{ t('style.demo.tab_recurring') }}</p></template>
            </UiTabs>
        </div>

        <div class="overlays__frame">
            <UiDetailPanel v-model:open="panel.docked" mode="docked" :title="t('soon.title')">
                <p>{{ t('soon.body') }}</p>
            </UiDetailPanel>
        </div>

        <UiDialog v-model:open="dialogOpen" :title="t('style.demo.dialog_title')">
            <p class="overlays__text">{{ t('style.demo.dialog_body') }}</p>
            <template #footer>
                <UiButton variant="primary" @click="dialogOpen = false">{{ t('ui.save_changes') }}</UiButton>
            </template>
        </UiDialog>
        <UiConfirmDialog
            v-model:open="confirmOpen"
            :title="t('confirm.delete_task.title')"
            :body="t('confirm.delete_task.body')"
            :confirm-label="t('confirm.delete_task.confirm')"
            :cancel-label="t('confirm.delete_task.keep')"
        />
        <UiDetailPanel v-model:open="panel.sheet" mode="sheet" :title="t('soon.title')"><p>{{ t('soon.body') }}</p></UiDetailPanel>
        <UiDetailPanel v-model:open="panel.overlay" mode="overlay" :title="t('soon.title')"><p>{{ t('soon.body') }}</p></UiDetailPanel>
        <UiToastRegion />
    </StyleSection>
</template>

<style scoped>
.overlays__row { display: flex; flex-wrap: wrap; align-items: flex-start; gap: var(--space-3); }
.overlays__tabs { margin-block-start: var(--space-6); max-width: 480px; }
.overlays__frame { margin-block-start: var(--space-6); width: 720px; max-width: none; min-height: 320px; border: 1px solid var(--color-border); border-radius: var(--radius-md); display: flex; justify-content: flex-end; }
.overlays__text { margin: 0; }
</style>
