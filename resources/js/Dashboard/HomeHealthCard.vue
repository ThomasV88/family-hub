<script setup>
import { useId } from 'vue';
import { Link } from '@inertiajs/vue3';
import { HeartPulse } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiCard from '../Components/ui/UiCard.vue';

// Static on purpose: no props, no names, counts, dates or status. Identical for every viewer, fresh or stale.
const { t } = useI18n();
const headingId = `hh-${useId()}`;
</script>

<template>
    <UiCard :title="t('module.health')" :heading-level="2" :title-id="headingId" data-testid="home-health">
        <template #action>
            <Link href="/health" class="hh__open" :aria-describedby="headingId">{{ t('health.home.open') }}</Link>
        </template>
        <p class="hh__body">
            <HeartPulse class="hh__icon" :size="20" aria-hidden="true" />
            <span>{{ t('health.home.body') }}</span>
        </p>
    </UiCard>
</template>

<style scoped>
.hh__open { display: inline-flex; align-items: center; min-height: 44px; padding: 0 var(--space-2); color: var(--color-primary); font-weight: 600; text-decoration: none; }
.hh__open:hover { text-decoration: underline; }
.hh__open:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 1px; border-radius: var(--radius-md); }
.hh__body { display: flex; align-items: center; gap: var(--space-2); margin: var(--space-2) 0 0; color: var(--color-text-muted); }
.hh__icon { flex: none; color: var(--color-text-muted); }
</style>
