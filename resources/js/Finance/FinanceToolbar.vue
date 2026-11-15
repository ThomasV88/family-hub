<script setup>
import { Plus, Upload } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../Components/ui/UiButton.vue';
import AccountChips from './AccountChips.vue';
import MonthSwitcher from './MonthSwitcher.vue';
import { financeUrl } from './financeQuery.js';

// Chips, month and actions. Rendered only when the viewer has at least one visible account (the shell decides).
const props = defineProps({
    finance: { type: Object, required: true },
    showAddFab: { type: Boolean, default: false },
    monthDisabled: { type: Boolean, default: false },
});
const emit = defineEmits(['add']);
const { t } = useI18n();

const importHref = () => financeUrl('/finance/import', { account: props.finance.selectedAccount });
</script>

<template>
    <div class="fin-toolbar" data-testid="finance-toolbar">
        <AccountChips :accounts="finance.accounts" :selected="finance.selectedAccount" />
        <div class="fin-toolbar__row">
            <MonthSwitcher :month="finance.month" :today="finance.today" :disabled="monthDisabled" />
            <div v-if="finance.canEdit" class="fin-toolbar__actions">
                <slot name="actions" />
                <UiButton variant="secondary" :icon="Upload" :href="importHref()">{{ t('finance.import') }}</UiButton>
                <UiButton variant="primary" :icon="Plus" :class="['fin-toolbar__add', { 'fin-toolbar__add--fab': showAddFab }]" @click="emit('add')">{{ t('finance.add_tx') }}</UiButton>
            </div>
        </div>
    </div>
</template>

<style scoped>
.fin-toolbar { container-type: inline-size; display: flex; flex-direction: column; gap: var(--space-3); min-width: 0; }
.fin-toolbar__row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3); }
.fin-toolbar__actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
@container (max-width: 639px) {
    .fin-toolbar__add--fab { display: none; }
}
</style>
