<script setup>
import { onMounted, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';

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
    <div v-if="visible" class="pwa-banner" role="status">
        <span>{{ t('pwa.update_available') }}</span>
        <button type="button" data-testid="pwa-reload" @click="reload">{{ t('pwa.reload') }}</button>
        <button type="button" data-testid="pwa-dismiss" @click="visible = false">{{ t('pwa.dismiss') }}</button>
    </div>
</template>

<style scoped>
.pwa-banner {
    position: fixed;
    left: 50%;
    bottom: calc(1rem + env(safe-area-inset-bottom));
    transform: translateX(-50%);
    z-index: 50;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    max-width: calc(100vw - 2rem);
    padding: 0.6rem 0.9rem;
    border: 1px solid #d9dadc;
    border-radius: 10px;
    background: #fff;
    color: #1f2328;
    box-shadow: 0 2px 8px rgb(0 0 0 / 0.08);
}
.pwa-banner button {
    min-height: 36px;
    padding: 0 0.8rem;
    border: 1px solid #d9dadc;
    border-radius: 8px;
    background: transparent;
    color: inherit;
    cursor: pointer;
}
.pwa-banner button[data-testid='pwa-reload'] {
    background: #3b6ea8;
    border-color: #3b6ea8;
    color: #fff;
}
</style>
