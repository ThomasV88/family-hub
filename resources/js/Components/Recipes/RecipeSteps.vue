<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import UiAlert from '../ui/UiAlert.vue';
import UiButton from '../ui/UiButton.vue';
import UiList from '../ui/UiList.vue';
import UiProgress from '../ui/UiProgress.vue';
import StepRow from './StepRow.vue';

// The "Steps" section of the detail: progress, the numbered rows (no forced order) and, when every step is done, a quiet note.
const props = defineProps({
    steps: { type: Array, required: true },
    isChecked: { type: Function, required: true },
    cooking: { type: Boolean, default: false },
});
const emit = defineEmits(['toggle', 'reset']);
const { t } = useI18n();

const total = computed(() => props.steps.length);
const done = computed(() => props.steps.filter((s) => props.isChecked(s.id)).length);
const allDone = computed(() => total.value > 0 && done.value === total.value);
</script>

<template>
    <section class="steps" aria-labelledby="steps-heading" data-testid="steps-section">
        <h2 id="steps-heading" class="steps__title">{{ t('recipes.detail.steps') }}</h2>
        <p class="steps__progress" data-testid="steps-progress">{{ t('recipes.progress.steps_done', { x: done, y: total }) }}</p>
        <UiProgress class="steps__bar" :value="done" :max="Math.max(1, total)" :label="t('recipes.progress.steps_done', { x: done, y: total })" />
        <UiAlert v-if="allDone" variant="info" live data-testid="steps-all-done">
            {{ t('recipes.progress.all_done') }}
            <template #actions>
                <UiButton variant="ghost" size="sm" data-testid="steps-reset" @click="emit('reset')">{{ t('recipes.detail.reset_progress') }}</UiButton>
            </template>
        </UiAlert>
        <UiList :class="['steps__list', { 'steps__list--cooking': cooking }]" :aria-label="t('recipes.detail.steps')">
            <StepRow v-for="(step, i) in steps" :key="step.id" :step="step" :number="i + 1" :checked="isChecked(step.id)" :cooking="cooking" @toggle="(v) => emit('toggle', step.id, v)" />
        </UiList>
    </section>
</template>

<style scoped>
.steps { display: flex; flex-direction: column; gap: var(--space-2); }
.steps__title { margin: 0; font-size: var(--text-xl); font-weight: 600; line-height: 1.2; }
.steps__progress { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.steps__bar { height: 4px; }
.steps__list--cooking { display: flex; flex-direction: column; gap: var(--space-6); }
.steps__list--cooking :deep(li + li) { border-top: 0; }
</style>
