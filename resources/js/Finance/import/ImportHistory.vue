<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';
import UiButton from '../../Components/ui/UiButton.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import UiConfirmDialog from '../../Components/ui/UiConfirmDialog.vue';
import { INTL_LOCALES } from '../../i18n.js';

// "Earlier imports": date, account and count only (no file names or row text exist after commit).
const props = defineProps({
    items: { type: Array, default: () => [] },
    timezone: { type: String, default: 'Europe/Bratislava' },
    canEdit: { type: Boolean, default: false },
});
const { t, locale } = useI18n();
const intl = computed(() => INTL_LOCALES[locale.value] ?? locale.value);
const fmt = (iso) => new Intl.DateTimeFormat(intl.value, { dateStyle: 'medium', timeZone: props.timezone }).format(new Date(iso));

const pending = ref(null);
const confirmOpen = ref(false);
function ask(item) {
    pending.value = item;
    confirmOpen.value = true;
}
function undo() {
    if (!pending.value) return;
    router.post(`/finance/import/${pending.value.id}/undo`, {}, { preserveScroll: true });
}
</script>

<template>
    <UiCard v-if="items.length" :title="t('finance.imp.history')" :heading-level="2" data-testid="import-history">
        <ul class="imp-history">
            <li v-for="item in items" :key="item.id" class="imp-history__row">
                <span class="imp-history__text">{{ t('finance.imp.history_row', { date: fmt(item.createdAt), account: item.accountName, n: item.addedCount }, item.addedCount) }}</span>
                <UiButton v-if="item.canUndo && canEdit" variant="ghost" size="sm" @click="ask(item)">{{ t('finance.imp.undo') }}</UiButton>
            </li>
        </ul>
        <UiConfirmDialog
            v-model:open="confirmOpen"
            :title="t('finance.imp.undo_title')"
            :body="t('finance.imp.undo_body', { n: pending?.addedCount ?? 0 }, pending?.addedCount ?? 0)"
            :confirm-label="t('finance.imp.undo')"
            :cancel-label="t('finance.imp.undo_keep')"
            @confirm="undo"
        />
    </UiCard>
</template>

<style scoped>
.imp-history { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.imp-history__row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-2); min-height: 48px; padding-block: var(--space-2); }
.imp-history__row + .imp-history__row { border-top: 1px solid var(--color-border); }
.imp-history__text { min-width: 0; overflow-wrap: anywhere; }
</style>
