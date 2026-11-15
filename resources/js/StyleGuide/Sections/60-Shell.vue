<script setup>
import { useI18n } from 'vue-i18n';
import StyleSection from '../StyleSection.vue';
import ThemeSwitch from '../../Components/ThemeSwitch.vue';
import LanguageSwitcher from '../../Components/LanguageSwitcher.vue';
import SidebarNav from '../../Shell/SidebarNav.vue';
import BottomNav from '../../Shell/BottomNav.vue';
import FabAdd from '../../Shell/FabAdd.vue';

const { t } = useI18n();
</script>

<template>
    <StyleSection id="shell" title-key="style.section.shell">
        <div class="shell-demo__frames" inert aria-hidden="true">
            <figure class="shell-demo__figure">
                <div class="shell-demo__frame shell-demo__frame--expanded"><SidebarNav :open="true" current="overview" /></div>
                <figcaption>{{ t('nav.main') }} 232 px</figcaption>
            </figure>
            <figure class="shell-demo__figure">
                <div class="shell-demo__frame shell-demo__frame--rail"><SidebarNav :open="false" current="overview" /></div>
                <figcaption>{{ t('nav.main') }} 64 px</figcaption>
            </figure>
            <figure class="shell-demo__figure">
                <div class="shell-demo__frame shell-demo__frame--phone">
                    <BottomNav current="overview" />
                    <FabAdd />
                </div>
                <figcaption>{{ t('nav.main') }} 390 px, {{ t('nav.add_task') }}</figcaption>
            </figure>
        </div>
        <div class="shell-demo__live">
            <ThemeSwitch />
            <LanguageSwitcher />
        </div>
    </StyleSection>
</template>

<style scoped>
.shell-demo__frames { display: flex; flex-wrap: wrap; gap: var(--space-6); align-items: flex-start; margin-bottom: var(--space-6); }
.shell-demo__figure { margin: 0; }
.shell-demo__figure figcaption { margin-top: var(--space-2); font-size: var(--text-sm); color: var(--color-text-muted); }
.shell-demo__frame {
    container: frame / inline-size;
    position: relative;
    overflow: hidden;
    transform: translateZ(0); /* contains the fixed-position bottom bar and FAB inside the frame */
    height: 320px;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-bg);
}
.shell-demo__frame--expanded { width: 232px; }
.shell-demo__frame--rail { width: 64px; }
.shell-demo__frame--phone { width: 390px; max-width: 100%; }
.shell-demo__live { display: flex; flex-wrap: wrap; gap: var(--space-6); align-items: flex-end; }
</style>
