<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import NavItem from './NavItem.vue';
import { visibleModules } from './modules.js';

defineProps({ current: { type: String, default: 'overview' } });
const { t } = useI18n();
const page = usePage();
const modules = computed(() => visibleModules(page.props.auth?.access));
</script>

<template>
    <nav :aria-label="t('nav.main')" class="bottom-nav">
        <ul class="bottom-nav__list">
            <NavItem v-for="m in modules" :key="m.key" :module="m" :current="m.key === current" variant="bottom" />
        </ul>
    </nav>
</template>

<style scoped>
.bottom-nav {
    position: fixed;
    inset-inline: 0;
    inset-block-end: 0;
    z-index: 20;
    background: var(--color-surface);
    border-block-start: 1px solid var(--color-border);
    padding: var(--space-1) var(--space-1) calc(var(--space-1) + env(safe-area-inset-bottom));
}
.bottom-nav__list { margin: 0; padding: 0; display: grid; grid-auto-flow: column; grid-auto-columns: minmax(0, 1fr); gap: 2px; align-items: stretch; }
.bottom-nav__list > :deep(li) { display: flex; }
.bottom-nav__list > :deep(li > *) { flex: 1; }
</style>
