<script setup>
import { onMounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Bell, Bot, ChevronRight, Palette, ScrollText, Users, Wallet } from '@lucide/vue';
import AppShell from '../../Layouts/AppShell.vue';
import UiList from '../../Components/ui/UiList.vue';
import UiListRow from '../../Components/ui/UiListRow.vue';

const props = defineProps({ sections: { type: Array, default: () => [] } });

const { t } = useI18n();
const ICONS = { users: Users, 'scroll-text': ScrollText, palette: Palette, bell: Bell, wallet: Wallet, bot: Bot };

// Wide screens show the section list beside the content, so the index goes straight to the first section.
onMounted(() => {
    const wide = typeof window !== 'undefined' && window.matchMedia?.('(min-width: 640px)').matches;
    if (wide && props.sections.length) router.visit(props.sections[0].href, { replace: true });
});
</script>

<template>
    <Head :title="t('settings.nav_label')" />
    <AppShell current="">
        <h1 class="settings-index__title">{{ t('settings.nav_label') }}</h1>
        <nav :aria-label="t('settings.nav_label')">
            <UiList>
                <UiListRow v-for="section in sections" :key="section.key" as="link" :href="section.href">
                    <template #leading><component :is="ICONS[section.icon]" :size="20" :stroke-width="1.75" aria-hidden="true" /></template>
                    {{ t(section.labelKey) }}
                    <template #trailing><ChevronRight :size="20" :stroke-width="1.75" aria-hidden="true" /></template>
                </UiListRow>
            </UiList>
        </nav>
    </AppShell>
</template>

<style scoped>
.settings-index__title { margin: 0 0 var(--space-4); font-size: var(--text-2xl); font-weight: 600; line-height: 1.25; }
</style>
