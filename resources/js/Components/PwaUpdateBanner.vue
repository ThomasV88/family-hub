<script setup>
import { nextTick, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiAlert from './ui/UiAlert.vue';
import UiButton from './ui/UiButton.vue';

const { t } = useI18n();
const visible = ref(false);
let update = null;

function onNeedRefresh(event) {
    update = event.detail?.update ?? null;
    visible.value = true;
}

const banner = ref(null);
const LIFT = '--fh-banner-lift';

// The toast region sits at the same bottom offset, so while the banner is visible publish its height and
// let the toast region stack above it instead of overlapping.
watch(visible, async (isVisible) => {
    if (!isVisible) {
        document.documentElement.style.removeProperty(LIFT);
        return;
    }
    await nextTick();
    const height = banner.value?.$el?.offsetHeight ?? banner.value?.offsetHeight ?? 0;
    document.documentElement.style.setProperty(LIFT, `${height + 8}px`);
});

onMounted(() => window.addEventListener('fh:pwa-need-refresh', onNeedRefresh));
onBeforeUnmount(() => {
    window.removeEventListener('fh:pwa-need-refresh', onNeedRefresh);
    document.documentElement.style.removeProperty(LIFT);
});

function reload() {
    if (update) update();
}
</script>

<template>
    <UiAlert v-if="visible" ref="banner" variant="info" live class="pwa-banner">
        <span>{{ t('pwa.update_available') }}</span>
        <template #actions>
            <UiButton variant="primary" size="sm" data-testid="pwa-reload" @click="reload">{{ t('pwa.reload') }}</UiButton>
            <UiButton variant="ghost" size="sm" data-testid="pwa-dismiss" @click="visible = false">{{ t('pwa.dismiss') }}</UiButton>
        </template>
    </UiAlert>
</template>

<style scoped>
.pwa-banner {
    position: fixed;
    inset-inline: 0;
    margin-inline: auto;
    width: fit-content;
    inset-block-end: var(--fh-toast-offset, var(--space-4));
    z-index: 50;
    max-width: calc(100vw - 2rem);
    box-shadow: var(--shadow-md, 0 2px 8px rgb(0 0 0 / 0.08));
}
</style>
