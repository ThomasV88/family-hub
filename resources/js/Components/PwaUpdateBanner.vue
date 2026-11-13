<script setup>
import { onMounted, onBeforeUnmount, ref } from 'vue';
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

onMounted(() => window.addEventListener('fh:pwa-need-refresh', onNeedRefresh));
onBeforeUnmount(() => window.removeEventListener('fh:pwa-need-refresh', onNeedRefresh));

function reload() {
    if (update) update();
}
</script>

<template>
    <UiAlert v-if="visible" variant="info" live class="pwa-banner">
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
    left: 50%;
    bottom: var(--fh-toast-offset, var(--space-4));
    transform: translateX(-50%);
    z-index: 50;
    max-width: calc(100vw - 2rem);
    box-shadow: var(--shadow-md, 0 2px 8px rgb(0 0 0 / 0.08));
}
</style>
