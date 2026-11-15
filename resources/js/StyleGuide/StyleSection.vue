<script setup>
import { useI18n } from 'vue-i18n';

defineProps({
    id: { type: String, required: true },
    titleKey: { type: String, required: true },
});
const { t } = useI18n();
</script>

<template>
    <section :id="id" :aria-labelledby="`${id}-title`" class="style-section">
        <h2 :id="`${id}-title`" class="style-section__title">{{ t(titleKey) }}</h2>
        <slot />
    </section>
</template>

<style scoped>
.style-section {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-4);
    margin-bottom: var(--space-6);
}
@media (min-width: 768px) {
    .style-section { padding: var(--space-6); }
}
.style-section__title { margin: 0 0 var(--space-4); font-size: var(--text-xl); font-weight: 600; line-height: 1.3; }
</style>
