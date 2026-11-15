<script setup>
import { useI18n } from 'vue-i18n';
import ThemeSwitch from '../Components/ThemeSwitch.vue';
import AppShell from '../Layouts/AppShell.vue';

const { t } = useI18n();

// Sections are discovered by filename order (NN-Name.vue); later plans add files without editing this page.
const modules = import.meta.glob('../StyleGuide/Sections/*.vue', { eager: true });
const sections = Object.keys(modules)
    .sort()
    .map((path) => ({ path, component: modules[path].default }));
</script>

<template>
    <AppShell current="">
        <div data-testid="style-guide">
            <div class="style-guide__head">
                <ThemeSwitch />
                <h1 class="style-guide__title">{{ t('style.title') }}</h1>
                <p class="style-guide__intro">{{ t('style.intro') }}</p>
            </div>
            <component :is="section.component" v-for="section in sections" :key="section.path" />
        </div>
    </AppShell>
</template>

<style scoped>
.style-guide__head { margin-bottom: var(--space-6); }
.style-guide__title { margin: 0; font-size: var(--text-2xl); font-weight: 600; line-height: 1.25; }
.style-guide__intro { margin: var(--space-1) 0 0; color: var(--color-text-muted); }
</style>
