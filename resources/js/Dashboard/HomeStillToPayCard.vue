<script setup>
import { useId } from 'vue';
import { router } from '@inertiajs/vue3';
import { AlertCircle } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiCard from '../Components/ui/UiCard.vue';
import UiTag from '../Components/ui/UiTag.vue';

// Counts only, no amounts (a sum would mix currencies). The server sends the card only when the viewer has finance
// access and at least one visible recurring item, and counts only visible accounts.
const props = defineProps({
    // { paymentsCount, overdueCount }
    card: { type: Object, required: true },
});
const { t } = useI18n();
const headingId = `hs-${useId()}`;

function go(event) {
    if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button > 0) return;
    event.preventDefault();
    router.visit('/finance/recurring');
}
</script>

<template>
    <UiCard :title="t('finance.still.title')" :heading-level="2" :title-id="headingId" data-testid="home-still-to-pay">
        <template #action>
            <a href="/finance/recurring" class="hs__open" :aria-describedby="headingId" @click="go">{{ t('finance.still.open') }}</a>
        </template>
        <p class="hs__count">{{ t('finance.still.count', { n: props.card.paymentsCount }, props.card.paymentsCount) }}</p>
        <p v-if="props.card.overdueCount > 0" class="hs__overdue">
            <UiTag variant="danger" :icon="AlertCircle">{{ t('finance.still.overdue', { n: props.card.overdueCount }) }}</UiTag>
        </p>
    </UiCard>
</template>

<style scoped>
.hs__open { display: inline-flex; align-items: center; min-height: 44px; padding: 0 var(--space-2); color: var(--color-primary); font-weight: 600; text-decoration: none; }
.hs__open:hover { text-decoration: underline; }
.hs__open:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 1px; border-radius: var(--radius-md); }
.hs__count { margin: var(--space-2) 0 0; font-size: var(--text-xl); font-weight: 600; }
.hs__overdue { margin: var(--space-2) 0 0; }
</style>
