<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppShell from '../../Layouts/AppShell.vue';
import ImportDoneStep from '../../Finance/import/ImportDoneStep.vue';
import ImportReviewStep from '../../Finance/import/ImportReviewStep.vue';
import ImportStepper from '../../Finance/import/ImportStepper.vue';

// Steps Review (draft batch) and Done (committed or undone batch). The server decides the state of every row.
const props = defineProps({
    finance: { type: Object, required: true },
    batch: { type: Object, required: true },
});
const { t } = useI18n();
const draft = computed(() => props.batch.status === 'draft');
</script>

<template>
    <AppShell current="finances">
        <div class="imp-page" data-testid="import-batch-page">
            <header class="imp-page__header">
                <h1 class="imp-page__title">{{ t('finance.imp.title') }}</h1>
                <ImportStepper :current="draft ? 'review' : 'done'" />
            </header>
            <ImportReviewStep v-if="draft" :key="batch.id" :batch="batch" :categories="finance.categories ?? []" />
            <ImportDoneStep v-else :batch="batch" :can-edit="finance.canEdit" />
        </div>
    </AppShell>
</template>

<style scoped>
.imp-page { display: flex; flex-direction: column; gap: var(--space-5); max-width: 960px; }
.imp-page__header { display: flex; flex-direction: column; gap: var(--space-3); }
.imp-page__title { margin: 0; font-size: var(--text-2xl, 1.75rem); font-weight: 600; line-height: 1.2; }
</style>
