<script setup>
defineProps({
    title: { type: String, default: '' },
    headingLevel: { type: Number, default: 2, validator: (v) => [2, 3].includes(v) },
    interactive: { type: Boolean, default: false },
    titleId: { type: String, default: undefined },
});
</script>

<template>
    <div :class="['ui-card', { 'ui-card--interactive': interactive }]">
        <div class="ui-card__inner">
            <header v-if="title || $slots.action" class="ui-card__header">
                <component :is="`h${headingLevel}`" v-if="title" :id="titleId" class="ui-card__title">{{ title }}</component>
                <div v-if="$slots.action" class="ui-card__action"><slot name="action" /></div>
            </header>
            <slot />
        </div>
    </div>
</template>

<style scoped>
.ui-card {
    container-type: inline-size;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
}
.ui-card--interactive:hover { border-color: var(--color-border-strong); }
.ui-card__inner { padding: var(--space-4); }
@container (min-width: 480px) {
    .ui-card__inner { padding: var(--space-6); }
}
.ui-card__header { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); margin-bottom: var(--space-3); }
.ui-card__title { margin: 0; font-size: var(--text-xl); font-weight: 600; line-height: 1.3; }
</style>
