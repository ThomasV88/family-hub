<script setup>
import { computed, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiToastRegion from '../Components/ui/UiToastRegion.vue';
import { useToast } from '../Components/ui/useToast.js';
import PwaUpdateBanner from '../Components/PwaUpdateBanner.vue';
import SidebarNav from '../Shell/SidebarNav.vue';
import BottomNav from '../Shell/BottomNav.vue';
import TopBar from '../Shell/TopBar.vue';
import FabAdd from '../Shell/FabAdd.vue';
import ComingSoonPanel from '../Shell/ComingSoonPanel.vue';
import { provideShell, readSidebar, resolveSidebarOpen, useFrameWidth, writeSidebar } from '../Shell/shellState.js';
import { FRAME_TABLET, panelMode } from '../design/panelMode.js';

defineProps({
    // Module key marked as the current page for screen readers.
    current: { type: String, default: 'overview' },
});

const { t } = useI18n();
const page = usePage();
const { show: showToast } = useToast();
// Server flash toast: { key, params }, translated here.
watch(
    () => page.props.flash?.toast,
    (toast) => {
        if (toast?.key) showToast(t(toast.key, toast.params ?? {}));
    },
    { immediate: true },
);
const isStaging = computed(() => page.props.app?.env === 'staging');

const frame = ref(null);
const main = ref(null);
const frameWidth = useFrameWidth(frame);
const mainWidth = useFrameWidth(main);
const isPhone = computed(() => frameWidth.value < FRAME_TABLET);
const mode = computed(() => panelMode({ frameWidth: frameWidth.value, mainWidth: mainWidth.value }));
provide('fh-panel-mode', mode);
provideShell({ panelMode: mode, isPhone });

// Sidebar: stored choice wins, otherwise open from 1024px.
const stored = ref(readSidebar());
const sidebarOpen = computed(() => resolveSidebarOpen({ stored: stored.value, frameWidth: frameWidth.value }));
function toggleSidebar() {
    const next = !sidebarOpen.value;
    writeSidebar(next);
    stored.value = next ? 'open' : 'closed';
}

const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine !== false);
const setOnline = () => { online.value = true; };
const setOffline = () => { online.value = false; };
onMounted(() => {
    window.addEventListener('online', setOnline);
    window.addEventListener('offline', setOffline);
});
onBeforeUnmount(() => {
    window.removeEventListener('online', setOnline);
    window.removeEventListener('offline', setOffline);
});

const toastOffset = computed(() => (isPhone.value ? 'calc(var(--shell-bottombar) + var(--space-4) + env(safe-area-inset-bottom))' : 'var(--space-4)'));
</script>

<template>
    <div ref="frame" class="fh-frame" :style="{ '--fh-toast-offset': toastOffset }">
        <a href="#main-content" class="fh-skip">{{ t('nav.skip') }}</a>
        <div v-if="isStaging" class="fh-staging" data-testid="staging-banner">{{ t('banner.staging') }}</div>
        <UiAlert v-if="!online" variant="warning" live class="fh-offline">{{ t('offline.banner') }}</UiAlert>
        <div class="fh-row">
            <SidebarNav v-if="!isPhone" :open="sidebarOpen" :current="current" />
            <div ref="main" class="fh-main">
                <TopBar :open="sidebarOpen" :show-toggle="!isPhone" @toggle="toggleSidebar" />
                <div :class="['fh-body', { 'fh-body--phone': isPhone }]">
                    <main id="main-content" tabindex="-1" class="fh-content">
                        <slot />
                    </main>
                    <slot name="panel" />
                    <ComingSoonPanel />
                </div>
            </div>
        </div>
        <template v-if="isPhone">
            <BottomNav :current="current" />
            <FabAdd />
        </template>
        <PwaUpdateBanner />
        <UiToastRegion />
    </div>
</template>

<style scoped>
.fh-frame { container: frame / inline-size; min-height: 100dvh; background: var(--color-bg); color: var(--color-text); }
.fh-skip {
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
.fh-skip:focus { transform: none; }
.fh-staging { background: var(--color-warn-bg); color: var(--color-warn-text); padding: var(--space-2) var(--space-4); font-size: var(--text-sm); text-align: center; }
.fh-offline { margin: var(--space-2) var(--space-4) 0; }
.fh-row { display: flex; min-height: 100dvh; }
.fh-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.fh-body { flex: 1; display: flex; min-width: 0; }
.fh-body--phone { padding-block-end: calc(var(--shell-bottombar) + var(--space-16) + env(safe-area-inset-bottom)); }
.fh-content {
    container: content / inline-size;
    flex: 1;
    min-width: 0;
    max-width: var(--content-max);
    margin-inline: auto;
    padding: var(--space-4);
}
.fh-content:focus { outline: none; }
@container frame (min-width: 640px) {
    .fh-content { padding: var(--space-6); }
}
@container frame (min-width: 1024px) {
    .fh-content { padding: var(--space-8); }
}
</style>
