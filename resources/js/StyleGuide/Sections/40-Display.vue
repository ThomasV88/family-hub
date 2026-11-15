<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus, Check, CircleAlert, Repeat } from '@lucide/vue';
import StyleSection from '../StyleSection.vue';
import UiTag from '../../Components/ui/UiTag.vue';
import UiChip from '../../Components/ui/UiChip.vue';
import UiAvatar from '../../Components/ui/UiAvatar.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import UiErrorState from '../../Components/ui/UiErrorState.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiSkeleton from '../../Components/ui/UiSkeleton.vue';
import UiProgress from '../../Components/ui/UiProgress.vue';
import UiButton from '../../Components/ui/UiButton.vue';

const { t } = useI18n();
const chips = ref({ all: true, mine: false, shared: false, personal: false });
const chipKeys = ['all', 'mine', 'shared', 'personal'];
const alertVariants = ['info', 'success', 'warning', 'danger'];
const dismissed = ref(false);
</script>

<template>
    <StyleSection id="display" title-key="style.section.display">
        <div class="display__row">
            <UiTag variant="warn">{{ t('style.demo.tag_due') }}</UiTag>
            <UiTag variant="danger" :icon="CircleAlert">{{ t('style.demo.tag_overdue') }}</UiTag>
            <UiTag variant="success" :icon="Check">{{ t('style.demo.tag_done') }}</UiTag>
            <UiTag :icon="Repeat">{{ t('style.demo.tag_weekly') }}</UiTag>
        </div>

        <div class="display__row">
            <UiChip v-for="key in chipKeys" :key="key" v-model="chips[key]">{{ t(`style.demo.chip_${key}`) }}</UiChip>
            <UiChip :model-value="false" disabled>{{ t('style.state.disabled') }}</UiChip>
            <UiChip :model-value="true" class="is-focus">{{ t('style.state.focus') }}</UiChip>
        </div>

        <div class="display__row">
            <UiAvatar :name="t('style.demo.person_anna')" :tint="1" :size="32" />
            <UiAvatar :name="t('style.demo.person_anna')" :tint="1" />
            <UiAvatar :name="t('style.demo.person_peter')" :tint="2" :size="32" />
            <UiAvatar :name="t('style.demo.person_peter')" :tint="2" />
            <span class="display__person"><UiAvatar :name="t('style.demo.person_anna')" :tint="3" decorative /> {{ t('style.demo.person_anna') }}</span>
        </div>

        <div class="display__grid">
            <UiCard :title="t('style.section.display')" :heading-level="3">
                <template #action><UiButton variant="ghost" size="sm" href="#display">{{ t('ui.open') }}</UiButton></template>
                <UiEmptyState :title="t('empty.tasks.title')" :body="t('empty.tasks.body')" :heading-level="4">
                    <template #action><UiButton variant="primary" :icon="Plus">{{ t('nav.add_task') }}</UiButton></template>
                </UiEmptyState>
            </UiCard>
            <UiCard>
                <UiEmptyState :title="t('empty.generic.title')" :body="t('empty.generic.body')" size="lg" :heading-level="3" />
            </UiCard>
            <UiCard>
                <UiErrorState :heading-level="3" />
            </UiCard>
        </div>

        <div class="display__stack">
            <UiAlert v-for="v in alertVariants.filter((x) => x !== 'warning')" :key="v" :variant="v">{{ t(`style.variant.${v}`) }}</UiAlert>
            <UiAlert v-if="!dismissed" variant="warning" dismissible @dismiss="dismissed = true">{{ t('offline.banner') }}</UiAlert>
        </div>

        <div class="display__stack display__skeleton">
            <UiSkeleton width="60%" />
            <UiSkeleton />
            <UiSkeleton width="80%" />
        </div>

        <UiProgress :value="40" :label="t('style.demo.progress_label')" />
    </StyleSection>
</template>

<style scoped>
.display__row { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-3); margin-bottom: var(--space-6); }
.display__person { display: inline-flex; align-items: center; gap: var(--space-2); font-size: var(--text-base); }
.display__grid { display: grid; gap: var(--space-4); grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); margin-bottom: var(--space-6); }
.display__stack { display: flex; flex-direction: column; gap: var(--space-3); margin-bottom: var(--space-6); }
.display__skeleton { max-width: 400px; gap: var(--space-2); }
</style>
