<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import UiProgress from '../Components/ui/UiProgress.vue';

// Locked-mode progress: a bar (resolved items of all) and the text "Bought x · Unavailable y · Left z".
const props = defineProps({
    bought: { type: Number, default: 0 },
    unavailable: { type: Number, default: 0 },
    left: { type: Number, default: 0 },
});
const { t } = useI18n();
const total = computed(() => props.bought + props.unavailable + props.left);
</script>

<template>
    <div class="shop-progress" data-testid="shopping-progress">
        <UiProgress :value="bought + unavailable" :max="Math.max(1, total)" :label="t('shopping.progress.label')" />
        <p class="shop-progress__text">{{ t('shopping.progress.summary', { bought, unavailable, left }) }}</p>
    </div>
</template>

<style scoped>
.shop-progress { display: flex; flex-direction: column; gap: var(--space-2); }
.shop-progress__text { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
</style>
