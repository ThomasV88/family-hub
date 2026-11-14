<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../../Components/ui/UiButton.vue';
import UiDialog from '../../Components/ui/UiDialog.vue';

// One-time notice before the first document leaves the server. The safe choice ("Keep document private") has the
// default focus; Esc, the scrim and the close button all mean the same as it.
defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['confirm', 'cancel']);
const { t } = useI18n();
const keep = ref(null);
</script>

<template>
    <UiDialog :open="open" :title="t('health.ai.run.notice_title')" :initial-focus="() => keep" @update:open="(v) => { if (!v) emit('cancel'); }">
        <p class="ai-notice__body">{{ t('health.ai.run.notice_body') }}</p>
        <template #footer>
            <UiButton ref="keep" variant="secondary" data-testid="ai-notice-cancel" @click="emit('cancel')">{{ t('health.ai.run.notice_cancel') }}</UiButton>
            <UiButton variant="primary" data-testid="ai-notice-confirm" @click="emit('confirm')">{{ t('health.ai.run.notice_confirm') }}</UiButton>
        </template>
    </UiDialog>
</template>

<style scoped>
.ai-notice__body { margin: 0; font-size: var(--text-base); }
</style>
