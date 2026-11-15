<script setup>
import { Inbox } from '@lucide/vue';

defineProps({
    title: { type: String, required: true },
    body: { type: String, default: '' },
    headingLevel: { type: Number, default: 3 },
    size: { type: String, default: 'md', validator: (v) => ['md', 'lg'].includes(v) },
    icon: { type: [Object, Function], default: null },
});
</script>

<template>
    <div class="ui-empty">
        <component :is="icon ?? Inbox" :size="32" :stroke-width="1.75" aria-hidden="true" class="ui-empty__icon" />
        <component :is="`h${headingLevel}`" :class="['ui-empty__title', { 'ui-empty__title--lg': size === 'lg' }]">{{ title }}</component>
        <p v-if="body" class="ui-empty__body">{{ body }}</p>
        <div v-if="$slots.action" class="ui-empty__action"><slot name="action" /></div>
    </div>
</template>

<style scoped>
.ui-empty { display: flex; flex-direction: column; align-items: center; gap: var(--space-2); padding-block: var(--space-12); text-align: center; }
.ui-empty__icon { color: var(--color-text-muted); }
.ui-empty__title { margin: 0; font-size: var(--text-xl); font-weight: 600; line-height: 1.3; }
.ui-empty__title--lg { font-size: var(--text-2xl); line-height: 1.2; }
.ui-empty__body { margin: 0; max-width: 40ch; color: var(--color-text-muted); font-size: var(--text-base); line-height: 1.5; }
.ui-empty__action { margin-top: var(--space-2); }
</style>
