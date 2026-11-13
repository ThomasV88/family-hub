<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { PanelLeft, Plus } from '@lucide/vue';
import UiButton from '../Components/ui/UiButton.vue';
import AccountMenu from './AccountMenu.vue';
import { useShell } from './shellState.js';

defineProps({ open: { type: Boolean, default: true }, showToggle: { type: Boolean, default: true } });
defineEmits(['toggle']);
const { t } = useI18n();
const page = usePage();
const shell = useShell();
const canAdd = computed(() => page.props.auth?.access?.todo === 'edit');
const appName = computed(() => page.props.app?.name ?? t('app.name'));
</script>

<template>
    <header class="topbar">
        <UiButton
            v-if="showToggle"
            variant="ghost"
            icon-only
            :icon="PanelLeft"
            :label="t(open ? 'nav.close_menu' : 'nav.open_menu')"
            aria-controls="fh-sidebar"
            :aria-expanded="open ? 'true' : 'false'"
            @click="$emit('toggle')"
        />
        <span class="topbar__name">{{ appName }}</span>
        <div class="topbar__end">
            <UiButton v-if="showToggle && canAdd" variant="primary" :icon="Plus" @click="shell.triggerAdd()">{{ t('nav.add_task') }}</UiButton>
            <AccountMenu />
        </div>
    </header>
</template>

<style scoped>
.topbar {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-height: var(--shell-topbar);
    padding-inline: var(--space-4);
    background: var(--color-surface);
    border-block-end: 1px solid var(--color-border);
}
.topbar__name { font-size: var(--text-lg); font-weight: 600; overflow-wrap: anywhere; }
.topbar__end { margin-inline-start: auto; display: flex; align-items: center; gap: var(--space-2); }
</style>
