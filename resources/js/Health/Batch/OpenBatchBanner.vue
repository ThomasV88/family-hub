<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../../Components/ui/UiButton.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import { INTL_LOCALES } from '../../i18n.js';

// The person's unfinished batch: progress, the expiry date and a link back (BULK-07). Counts come from the server.
const props = defineProps({
    personId: { type: String, required: true },
    batch: { type: Object, required: true },
});
const { t, locale } = useI18n();
const appLocale = computed(() => ({ 'sk-SK': 'sk', 'pl-PL': 'pl', 'en-GB': 'en' }[locale.value] ?? locale.value));
const date = computed(() => {
    const d = new Date(props.batch.expiresAt ?? '');
    return Number.isNaN(d.getTime()) ? '' : new Intl.DateTimeFormat(INTL_LOCALES[appLocale.value] ?? appLocale.value, { dateStyle: 'medium' }).format(d);
});
</script>

<template>
    <UiCard :title="t('health.batch.open_banner_title')" class="open-batch" data-testid="open-batch-banner">
        <p class="open-batch__body">{{ t('health.batch.open_banner_body', { done: batch.done, total: batch.total, date }) }}</p>
        <div class="open-batch__actions">
            <UiButton variant="primary" :href="`/health/people/${personId}/upload-batches/${batch.id}`" data-testid="open-batch-link">{{ t('health.batch.open_banner_action') }}</UiButton>
        </div>
    </UiCard>
</template>

<style scoped>
.open-batch__body { margin: 0 0 var(--space-3); color: var(--color-text-muted); }
.open-batch__actions { display: flex; justify-content: flex-start; }
</style>
