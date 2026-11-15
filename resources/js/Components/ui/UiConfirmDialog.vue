<script setup>
import { ref } from 'vue';
import UiDialog from './UiDialog.vue';
import UiButton from './UiButton.vue';

// Destructive confirmation. Consumers must pass action-named labels (for example "Delete task" / "Keep task").
// A generic "Cancel" must not be used, which is why cancelLabel has no default. Focus starts on the safe button.
defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    body: { type: String, required: true },
    confirmLabel: { type: String, required: true },
    cancelLabel: { type: String, required: true },
});
const emit = defineEmits(['update:open', 'confirm', 'cancel']);
const safe = ref(null);

function confirm() {
    emit('confirm');
    emit('update:open', false);
}
function cancel() {
    emit('cancel');
    emit('update:open', false);
}
</script>

<template>
    <UiDialog :open="open" :title="title" :initial-focus="() => safe" @update:open="(v) => (v ? emit('update:open', v) : cancel())">
        <p class="ui-confirm__body">{{ body }}</p>
        <template #footer>
            <UiButton ref="safe" variant="secondary" @click="cancel">{{ cancelLabel }}</UiButton>
            <UiButton variant="danger" @click="confirm">{{ confirmLabel }}</UiButton>
        </template>
    </UiDialog>
</template>

<style scoped>
.ui-confirm__body { margin: 0; font-size: var(--text-base); }
</style>
