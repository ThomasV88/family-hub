<script setup>
import { useI18n } from 'vue-i18n';
import StyleSection from '../StyleSection.vue';

const { t } = useI18n();
// Served from public/; bound dynamically so the Vue compiler does not try to bundle them.
const LOGO = '/icons/familyhub-logo.svg';
const LOGO_DARK = '/icons/familyhub-logo-dark.svg';
const MARK = '/icons/familyhub-mark.svg';
</script>

<template>
    <StyleSection id="brand" title-key="style.section.brand">
        <div class="brand-demo">
            <figure class="brand-demo__figure">
                <div class="brand-demo__frame" style="background: #ffffff">
                    <img :src="LOGO" alt="FamilyHUB" height="64">
                </div>
                <figcaption>{{ t('style.brand_light') }}</figcaption>
            </figure>
            <figure class="brand-demo__figure">
                <div class="brand-demo__frame" style="background: #222220">
                    <img :src="LOGO_DARK" alt="FamilyHUB" height="64">
                </div>
                <figcaption>{{ t('style.brand_dark') }}</figcaption>
            </figure>
            <figure class="brand-demo__figure">
                <div class="brand-demo__frame" style="background: #ffffff">
                    <img :src="MARK" alt="FamilyHUB" height="48">
                </div>
                <figcaption>{{ t('style.brand_mark') }}</figcaption>
            </figure>
        </div>
    </StyleSection>
</template>

<style scoped>
.brand-demo { display: flex; flex-wrap: wrap; gap: var(--space-6); align-items: flex-start; }
.brand-demo__figure { margin: 0; display: flex; flex-direction: column; gap: var(--space-2); }
.brand-demo__frame {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--space-6);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
}
.brand-demo__frame img { display: block; width: auto; }
.brand-demo figcaption { font-size: var(--text-sm); color: var(--color-text-muted); }
</style>
