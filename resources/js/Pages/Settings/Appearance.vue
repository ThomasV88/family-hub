<script setup>
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import ThemeSwitch from '../../Components/ThemeSwitch.vue';
import LanguageSwitcher from '../../Components/LanguageSwitcher.vue';

defineProps({ sections: { type: Array, default: () => [] } });

const { t } = useI18n();
</script>

<template>
    <Head :title="t('settings.section.appearance')" />
    <SettingsLayout :sections="sections" current="appearance" title="settings.section.appearance">
        <UiCard class="appearance__card">
            <h2 class="appearance__heading">{{ t('theme.label') }}</h2>
            <ThemeSwitch />
        </UiCard>
        <UiCard class="appearance__card">
            <h2 class="appearance__heading">{{ t('nav.language') }}</h2>
            <LanguageSwitcher />
        </UiCard>
    </SettingsLayout>
</template>

<style scoped>
.appearance__card { margin-bottom: var(--space-4); }
.appearance__heading { margin: 0 0 var(--space-3); font-size: var(--text-lg); font-weight: 600; line-height: 1.3; }
</style>
