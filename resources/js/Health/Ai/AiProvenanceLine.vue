<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

// "Read by {agent} {model}": registered agent names only; the model is a validated string rendered as text.
// Content-free: never shown in lists, toasts or live regions.
const props = defineProps({
    agent: { type: String, default: null },
    model: { type: String, default: null },
});
const { t } = useI18n();
const NAMES = { codex: 'OpenAI Codex', copilot: 'GitHub Copilot' };
const name = computed(() => NAMES[props.agent] ?? null);
const text = computed(() => {
    if (!name.value) return '';
    const model = typeof props.model === 'string' ? props.model.trim() : '';
    return model ? t('health.ai.provenance.with_model', { agent: name.value, model }) : t('health.ai.provenance.without_model', { agent: name.value });
});
</script>

<template>
    <p v-if="text" class="ai-provenance" data-testid="ai-provenance">{{ text }}</p>
</template>

<style scoped>
.ai-provenance { margin: 0; font-size: var(--text-sm); font-weight: 400; color: var(--color-text-muted); overflow-wrap: anywhere; max-width: 64ch; }
</style>
