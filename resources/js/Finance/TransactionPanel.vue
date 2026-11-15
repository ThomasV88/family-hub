<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../Components/ui/UiButton.vue';
import UiConfirmDialog from '../Components/ui/UiConfirmDialog.vue';
import UiDetailPanel from '../Components/ui/UiDetailPanel.vue';
import UiTag from '../Components/ui/UiTag.vue';
import { INTL_LOCALES } from '../i18n.js';
import MoneyAmount from './MoneyAmount.vue';
import TransactionForm from './TransactionForm.vue';
import { categoryLabel, formatDay } from './labels.js';

// Create, edit and read-only view of one transaction. State comes only from page props (`transaction` is the
// server's panelTransaction); there is no separate fetch.
const props = defineProps({
    open: { type: Boolean, default: false },
    finance: { type: Object, required: true },
    // null | { mode: 'new' } | Panel (Row + note, editable, match)
    transaction: { type: Object, default: null },
});
const emit = defineEmits(['close']);
const { t, locale } = useI18n();

const creating = computed(() => !props.transaction || props.transaction.mode === 'new');
const readOnly = computed(() => !creating.value && (props.finance.canEdit !== true || props.transaction.editable !== true));
const heading = computed(() => (creating.value ? t('finance.panel.tx_new') : t('finance.panel.tx_edit')));
const initial = computed(() => (creating.value ? null : props.transaction));
const submitUrl = computed(() => (creating.value ? '/finance/transactions' : `/finance/transactions/${props.transaction.id}`));

const form = ref(null);
const dirty = ref(false);
const discardOpen = ref(false);
const deleteOpen = ref(false);
let pendingAfterDiscard = null;

const canSave = computed(() => form.value?.canSave === true);
const processing = computed(() => form.value?.processing === true);

watch(() => props.open && creating.value, async (focus) => {
    if (focus) {
        await nextTick();
        setTimeout(() => form.value?.focusAmount(), 50);
    }
}, { immediate: true });
watch(() => props.transaction?.id, () => { dirty.value = false; });

function guard(then) {
    if (props.open && dirty.value && !readOnly.value) {
        pendingAfterDiscard = then;
        discardOpen.value = true;
    } else {
        then();
    }
}
const requestClose = () => guard(() => emit('close'));
function discard() {
    const then = pendingAfterDiscard;
    pendingAfterDiscard = null;
    dirty.value = false;
    form.value?.reset();
    then?.();
}
const keepEditing = () => { pendingAfterDiscard = null; };

function saved() {
    dirty.value = false;
    if (creating.value) emit('close');
}
function confirmDelete() {
    router.delete(`/finance/transactions/${props.transaction.id}`, { preserveScroll: true, onSuccess: () => emit('close') });
}

const intl = computed(() => INTL_LOCALES[locale.value] ?? locale.value);
const category = computed(() => (props.finance.categories ?? []).find((c) => c.id === props.transaction?.categoryId) ?? null);
const day = computed(() => (props.transaction?.bookedOn ? formatDay(props.transaction.bookedOn, intl.value, { dateStyle: 'long' }) : ''));
const signed = computed(() => props.transaction?.kind !== 'transfer');

defineExpose({ guard, dirty });
</script>

<template>
    <UiDetailPanel :open="open" :title="heading" @update:open="(v) => (v ? null : requestClose())">
        <div v-if="readOnly" data-testid="transaction-readonly">
            <dl class="tx-ro">
                <div><dt>{{ t('finance.field.kind') }}</dt><dd>{{ t(`finance.kind.${transaction.kind}`) }}</dd></div>
                <div><dt>{{ t('finance.field.amount') }}</dt><dd><MoneyAmount :minor="transaction.amountMinor" :currency="transaction.currency" :signed="signed" /></dd></div>
                <div><dt>{{ t('finance.field.date') }}</dt><dd><time :datetime="transaction.bookedOn">{{ day }}</time></dd></div>
                <div><dt>{{ t('finance.field.description') }}</dt><dd>{{ transaction.description }}</dd></div>
                <div v-if="transaction.kind !== 'transfer'"><dt>{{ t('finance.field.category') }}</dt><dd>{{ categoryLabel(category, t) }}</dd></div>
                <div>
                    <dt>{{ t('finance.field.account') }}</dt>
                    <dd v-if="transaction.transfer">{{ t('finance.kind.transfer_between', { from: transaction.transfer.fromName, to: transaction.transfer.toName }) }}</dd>
                    <dd v-else>{{ transaction.accountName }}</dd>
                </div>
                <div v-if="transaction.note"><dt>{{ t('finance.field.note') }}</dt><dd class="tx-ro__note">{{ transaction.note }}</dd></div>
                <div v-if="transaction.source === 'import'"><dt>{{ t('finance.tx.imported') }}</dt><dd><UiTag>{{ t('finance.tx.imported') }}</UiTag></dd></div>
            </dl>
        </div>
        <template v-else>
            <TransactionForm
                :key="transaction?.id ?? 'new'"
                ref="form"
                :finance="finance"
                :initial="initial"
                :mode="creating ? 'new' : 'edit'"
                :submit-url="submitUrl"
                :method="creating ? 'post' : 'patch'"
                @saved="saved"
                @dirty-change="(v) => (dirty = v)"
            />
            <p v-if="!creating && transaction.source === 'import'" class="tx-ro__imported"><UiTag>{{ t('finance.tx.imported') }}</UiTag></p>
        </template>

        <template #footer>
            <UiButton v-if="!creating && !readOnly" variant="ghost" :icon="Trash2" class="tx-panel__delete" @click="deleteOpen = true">{{ t('finance.tx.delete') }}</UiButton>
            <UiButton variant="secondary" @click="requestClose">{{ t('finance.close') }}</UiButton>
            <UiButton v-if="!readOnly" variant="primary" :disabled="!canSave" :loading="processing" @click="form?.save()">{{ t('finance.tx.save') }}</UiButton>
        </template>
    </UiDetailPanel>

    <UiConfirmDialog
        v-model:open="discardOpen"
        :title="t('finance.discard.title')"
        :body="t('finance.discard.body')"
        :confirm-label="t('finance.discard.confirm')"
        :cancel-label="t('finance.discard.keep')"
        @confirm="discard"
        @cancel="keepEditing"
    />
    <UiConfirmDialog
        v-model:open="deleteOpen"
        :title="t('finance.tx.delete')"
        :body="t('finance.tx.delete_body', { description: transaction?.description ?? '' })"
        :confirm-label="t('finance.tx.delete')"
        :cancel-label="t('finance.tx.delete_keep')"
        @confirm="confirmDelete"
    />
</template>

<style scoped>
.tx-ro { display: flex; flex-direction: column; gap: var(--space-4); margin: 0; }
.tx-ro dt { font-size: var(--text-sm); font-weight: 600; line-height: 1.4; }
.tx-ro dd { margin: 0; overflow-wrap: anywhere; }
.tx-ro__note { white-space: pre-line; }
.tx-ro__imported { margin: 0; }
.tx-panel__delete { margin-inline-end: auto; color: var(--color-danger); }
</style>
