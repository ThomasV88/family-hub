<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiConfirmDialog from '../../Components/ui/UiConfirmDialog.vue';

// Step 4. Only counts are shown: no file name or row text exists after the commit.
const props = defineProps({
    batch: { type: Object, required: true },
    canEdit: { type: Boolean, default: false },
});
const { t } = useI18n();

const committed = computed(() => props.batch.status === 'committed');
const added = computed(() => props.batch.result?.added ?? 0);
const matched = computed(() => props.batch.result?.matched ?? 0);
const kept = computed(() => props.batch.result?.kept ?? 0);
const transactionsHref = computed(() => `/finance/transactions?account=${encodeURIComponent(props.batch.accountId)}`);
const anotherHref = computed(() => `/finance/import?account=${encodeURIComponent(props.batch.accountId)}`);

const confirmOpen = ref(false);
function undo() {
    router.post(`/finance/import/${props.batch.id}/undo`, {}, { preserveScroll: true });
}
</script>

<template>
    <div class="imp-done" data-testid="import-step-done">
        <UiAlert v-if="committed" variant="success" live>
            <p class="imp-done__line">{{ t('finance.imp.done_body', { n: added, account: batch.accountName }, added) }}</p>
            <p v-if="matched > 0" class="imp-done__line">{{ t('finance.imp.done_matched', { n: matched }, matched) }}</p>
        </UiAlert>
        <UiAlert v-else variant="info" live>
            <p class="imp-done__line">{{ t('finance.toast.imp_undone') }}</p>
            <p v-if="kept > 0" class="imp-done__line">{{ t('finance.toast.imp_undone_kept', { n: kept }, kept) }}</p>
        </UiAlert>
        <div class="imp-done__actions">
            <UiButton v-if="committed" variant="primary" :href="transactionsHref">{{ t('finance.imp.view_tx') }}</UiButton>
            <UiButton variant="secondary" :href="anotherHref">{{ t('finance.imp.another') }}</UiButton>
            <UiButton v-if="committed && canEdit" variant="ghost" class="imp-done__undo" @click="confirmOpen = true">{{ t('finance.imp.undo') }}</UiButton>
        </div>
        <UiConfirmDialog
            v-model:open="confirmOpen"
            :title="t('finance.imp.undo_title')"
            :body="t('finance.imp.undo_body', { n: added }, added)"
            :confirm-label="t('finance.imp.undo')"
            :cancel-label="t('finance.imp.undo_keep')"
            @confirm="undo"
        />
    </div>
</template>

<style scoped>
.imp-done { display: flex; flex-direction: column; gap: var(--space-4); max-width: 640px; }
.imp-done__line { margin: 0; }
.imp-done__actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.imp-done__undo { color: var(--color-danger-text, var(--color-text)); }
</style>
