<script setup>
import { CircleAlert } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiButton from './UiButton.vue';

defineProps({
    title: { type: String, default: '' },
    body: { type: String, default: '' },
    live: { type: Boolean, default: false },
    headingLevel: { type: Number, default: 3 },
});
defineEmits(['retry']);
const { t } = useI18n();
</script>

<template>
    <div class="ui-error" :role="live ? 'alert' : undefined">
        <CircleAlert :size="32" :stroke-width="1.75" aria-hidden="true" class="ui-error__icon" />
        <component :is="`h${headingLevel}`" class="ui-error__title">{{ title || t('error.generic.title') }}</component>
        <p class="ui-error__body">{{ body || t('error.generic.body') }}</p>
        <UiButton variant="secondary" @click="$emit('retry')">{{ t('ui.retry') }}</UiButton>
    </div>
</template>

<style scoped>
.ui-error { display: flex; flex-direction: column; align-items: center; gap: var(--space-2); padding-block: var(--space-12); text-align: center; }
.ui-error__icon { color: var(--color-danger); }
.ui-error__title { margin: 0; font-size: var(--text-xl); font-weight: 600; line-height: 1.3; }
.ui-error__body { margin: 0 0 var(--space-2); max-width: 40ch; color: var(--color-text-muted); font-size: var(--text-base); line-height: 1.5; }
</style>
