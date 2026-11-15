<script setup>
import { useI18n } from 'vue-i18n';
import UiCard from '../Components/ui/UiCard.vue';
import LanguageSwitcher from '../Components/LanguageSwitcher.vue';
import ThemeSwitch from '../Components/ThemeSwitch.vue';

defineProps({
    // Card title; rendered as the page's single h1.
    title: { type: String, required: true },
    // Optional extra line under the card (for example "invitation only").
    footnote: { type: String, default: '' },
});

const { t } = useI18n();
</script>

<template>
    <div class="auth-frame">
        <a href="#main-content" class="auth-skip">{{ t('nav.skip') }}</a>
        <main id="main-content" tabindex="-1" class="auth-main">
            <p class="auth-wordmark">Family Hub</p>
            <UiCard class="auth-card">
                <h1 class="auth-title">{{ title }}</h1>
                <slot />
            </UiCard>
            <p v-if="footnote" class="auth-footnote">{{ footnote }}</p>
            <div class="auth-switches">
                <LanguageSwitcher />
                <ThemeSwitch />
            </div>
        </main>
    </div>
</template>

<style scoped>
.auth-frame { min-height: 100dvh; background: var(--color-bg); color: var(--color-text); }
.auth-skip {
    position: absolute;
    inset-inline-start: var(--space-2);
    inset-block-start: var(--space-2);
    z-index: 100;
    padding: var(--space-2) var(--space-4);
    border-radius: var(--radius-md);
    background: var(--color-surface);
    color: var(--color-primary);
    font-weight: 600;
    transform: translateY(-200%);
}
.auth-skip:focus { transform: none; }
.auth-main {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: var(--space-4);
    max-width: 440px;
    margin-inline: auto;
    padding: var(--space-6) var(--space-4);
}
.auth-main:focus { outline: none; }
@media (min-width: 640px) {
    .auth-main { padding-block: var(--space-12); }
}
.auth-wordmark { margin: 0; font-size: var(--text-2xl); font-weight: 600; line-height: 1.25; text-align: center; }
.auth-title { margin: 0 0 var(--space-4); font-size: var(--text-xl); font-weight: 600; line-height: 1.3; }
.auth-footnote { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); text-align: center; }
.auth-switches { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: center; gap: var(--space-4); }
.auth-switches :deep(.ui-field) { margin-bottom: 0; }
</style>
