<script setup>
import { computed } from 'vue';
import { Check } from '@lucide/vue';
import { useI18n } from 'vue-i18n';

// Four-step progress list. Below 480px only "Step n of 4" is shown, the labelled row is for wider screens.
const props = defineProps({
    // 'file' | 'columns' | 'review' | 'done'
    current: { type: String, required: true },
});
const { t } = useI18n();
const KEYS = ['file', 'columns', 'review', 'done'];
const index = computed(() => KEYS.indexOf(props.current));
</script>

<template>
    <nav class="imp-stepper" :aria-label="t('finance.imp.title')">
        <p class="imp-stepper__count">{{ t('finance.imp.step_of', { n: index + 1, total: KEYS.length }) }}</p>
        <ol class="imp-stepper__list">
            <li v-for="(key, i) in KEYS" :key="key" class="imp-stepper__item" :class="{ 'is-current': i === index, 'is-done': i < index }" :aria-current="i === index ? 'step' : undefined">
                <span class="imp-stepper__mark" aria-hidden="true"><Check v-if="i < index" :size="16" :stroke-width="2.5" /><template v-else>{{ i + 1 }}</template></span>
                <span class="imp-stepper__label">{{ t(`finance.imp.step_${key}`) }}</span>
            </li>
        </ol>
    </nav>
</template>

<style scoped>
.imp-stepper__count { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.imp-stepper__list { display: none; list-style: none; margin: 0; padding: 0; gap: var(--space-4); flex-wrap: wrap; }
.imp-stepper__item { display: inline-flex; align-items: center; gap: var(--space-2); color: var(--color-text-muted); font-size: var(--text-sm); }
.imp-stepper__item.is-current { color: var(--color-text); font-weight: 600; }
.imp-stepper__mark { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 50%; border: 1px solid var(--color-border); font-size: var(--text-sm); }
.imp-stepper__item.is-current .imp-stepper__mark { border-color: var(--color-text); border-width: 2px; }
@media (min-width: 480px) {
    .imp-stepper__count { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
    .imp-stepper__list { display: flex; }
}
</style>
