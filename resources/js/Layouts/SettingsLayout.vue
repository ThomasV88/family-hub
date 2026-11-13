<script setup>
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ChevronLeft, Palette, ScrollText, Users } from '@lucide/vue';
import AppShell from './AppShell.vue';

defineProps({
    sections: { type: Array, default: () => [] },
    // Key of the active section.
    current: { type: String, default: '' },
    // i18n key of the page h1.
    title: { type: String, required: true },
});

const { t } = useI18n();
const ICONS = { users: Users, 'scroll-text': ScrollText, palette: Palette };
</script>

<template>
    <AppShell current="">
        <div class="settings" data-testid="settings-layout">
            <nav class="settings__nav" :aria-label="t('settings.nav_label')">
                <ul class="settings__list">
                    <li v-for="section in sections" :key="section.key">
                        <Link
                            :href="section.href"
                            :class="['settings__item', { 'is-current': section.key === current }]"
                            :aria-current="section.key === current ? 'page' : undefined"
                        >
                            <component :is="ICONS[section.icon]" :size="20" :stroke-width="1.75" aria-hidden="true" />
                            <span>{{ t(section.labelKey) }}</span>
                        </Link>
                    </li>
                </ul>
            </nav>
            <div class="settings__content">
                <Link href="/settings" class="settings__back" data-testid="settings-back">
                    <ChevronLeft :size="20" :stroke-width="1.75" aria-hidden="true" />
                    {{ t('settings.back') }}
                </Link>
                <h1 class="settings__title">{{ t(title) }}</h1>
                <slot />
            </div>
        </div>
        <template #panel><slot name="panel" /></template>
    </AppShell>
</template>

<style scoped>
.settings { container: settings / inline-size; }
.settings__nav { display: none; }
.settings__list { margin: 0; padding: 0; list-style: none; }
.settings__item {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-height: 44px;
    padding-inline: var(--space-3);
    border-radius: var(--radius-md);
    color: var(--color-text);
    text-decoration: none;
}
.settings__item:hover { background: var(--color-surface-2); }
.settings__item.is-current { background: var(--color-primary-soft); font-weight: 600; }
.settings__item:focus-visible { outline: 2px solid var(--color-focus); outline-offset: -2px; }
.settings__back { display: inline-flex; align-items: center; gap: var(--space-1); min-height: 44px; margin-bottom: var(--space-2); color: var(--color-primary); text-decoration: none; }
.settings__title { margin: 0 0 var(--space-4); font-size: var(--text-2xl); font-weight: 600; line-height: 1.25; }
.settings__content { max-width: 880px; min-width: 0; }

@container settings (min-width: 640px) {
    .settings { display: grid; grid-template-columns: 240px minmax(0, 1fr); gap: 32px; }
    .settings__nav { display: block; }
    .settings__back { display: none; }
}
</style>
