<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import LanguageSwitcher from '../Components/LanguageSwitcher.vue';

const { t } = useI18n();
const page = usePage();
const appName = computed(() => page.props.app?.name ?? t('app.name'));
const isStaging = computed(() => page.props.app?.env === 'staging');
</script>

<template>
    <div class="layout">
        <div v-if="isStaging" class="staging-banner" data-testid="staging-banner">
            {{ t('banner.staging') }}
        </div>
        <header class="layout__header">
            <span>{{ appName }}</span>
            <LanguageSwitcher />
        </header>
        <main class="layout__main">
            <slot />
        </main>
    </div>
</template>
