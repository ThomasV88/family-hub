<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import NavItem from './NavItem.vue';
import { visibleModules } from './modules.js';

defineProps({
    open: { type: Boolean, default: true },
    current: { type: String, default: 'overview' },
});
const { t } = useI18n();
const page = usePage();
const modules = computed(() => visibleModules(page.props.auth?.access));
</script>

<template>
    <nav id="fh-sidebar" :aria-label="t('nav.main')" :class="['sidebar', { 'is-open': open }]">
        <ul class="sidebar__list">
            <NavItem v-for="m in modules" :key="m.key" :module="m" :current="m.key === current" variant="side" :rail="!open" />
        </ul>
    </nav>
</template>

<style scoped>
.sidebar {
    flex: none;
    width: var(--shell-sidebar-collapsed);
    padding: var(--space-2);
    background: var(--color-surface);
    border-inline-end: 1px solid var(--color-border);
    transition: width var(--duration) ease;
}
.sidebar.is-open { width: var(--shell-sidebar-expanded); }
.sidebar__list { margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--space-1); }
</style>
