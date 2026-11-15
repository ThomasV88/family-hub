<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { IdCard } from '@lucide/vue';
import UiButton from '../Components/ui/UiButton.vue';

// First side card: what the emergency card will show, and the way to open it. Shown to view and edit members.
const props = defineProps({
    person: { type: Object, required: true },
    allergyCount: { type: Number, default: 0 },
});
const { t } = useI18n();

const blood = computed(() => {
    const type = props.person.bloodType;
    return type ? t(`health.blood.${type.toLowerCase().replace('+', '_pos').replace('-', '_neg')}`) : t('health.blood.unknown');
});
const line = computed(() => `${t('health.blood.label')} ${blood.value} · ${t('health.allergies.count', { n: props.allergyCount }, props.allergyCount)}`);
</script>

<template>
    <section class="emergency-summary" data-testid="emergency-summary" aria-labelledby="emergency-summary-title">
        <h2 id="emergency-summary-title" class="emergency-summary__title">{{ t('health.emergency.summary_title') }}</h2>
        <p class="emergency-summary__line" data-testid="emergency-summary-line">{{ line }}</p>
        <UiButton variant="ghost" :icon="IdCard" :href="`/health/people/${person.id}/emergency-card`" data-testid="emergency-summary-open">{{ t('health.emergency.open') }}</UiButton>
    </section>
</template>

<style scoped>
.emergency-summary { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-2); padding: var(--space-4); border: 1px solid var(--color-border); border-radius: var(--radius-lg); background: var(--color-surface); min-width: 0; }
.emergency-summary__title { margin: 0; font-size: var(--text-lg); font-weight: 600; }
.emergency-summary__line { margin: 0; color: var(--color-text-muted); }
</style>
