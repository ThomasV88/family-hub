<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import NavItem from './NavItem.vue';
import { visibleModules, bottomNavDensity } from './modules.js';

defineProps({ current: { type: String, default: 'overview' } });
const { t } = useI18n();
const page = usePage();
const modules = computed(() => visibleModules(page.props.auth?.access));
const density = computed(() => bottomNavDensity(modules.value.length));
</script>

<template>
    <nav :aria-label="t('nav.main')" :class="['bottom-nav', { [`bottom-nav--${density}`]: density !== 'normal' }]">
        <ul class="bottom-nav__list">
            <NavItem v-for="m in modules" :key="m.key" :module="m" :current="m.key === current" variant="bottom" :density="density" />
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
.bottom-nav--dense,
.bottom-nav--tight { padding-inline: 2px; }
.bottom-nav--dense .bottom-nav__list,
.bottom-nav--tight .bottom-nav__list { gap: 0; }
.bottom-nav__list > :deep(li) { display: flex; min-width: 0; }
.bottom-nav__list > :deep(li > *) { flex: 1; min-width: 0; }
/* 7+ items: equal flex items of at least 40 px (44 px targets would overflow a 320 px bar next to the current label; NavItem
   extends each icon-only target to 44 px with an invisible hit area); the current one grows to its whole label. */
.bottom-nav--tight .bottom-nav__list { display: flex; }
.bottom-nav--tight .bottom-nav__list > :deep(li) { flex: 1 1 0; min-width: 40px; }
.bottom-nav--tight .bottom-nav__list > :deep(li.nav-item--current) { flex: 1 0 auto; }
</style>
