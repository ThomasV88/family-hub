<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../../Components/ui/UiButton.vue';
import UiDialog from '../../Components/ui/UiDialog.vue';

// One-time notice before the first document leaves the server. The safe choice ("Keep document private") has the
// default focus; Esc, the scrim and the close button all mean the same as it.
// `variant` follows the enabled providers: codex (the Phase 8 text), multi (OpenAI or GitHub) or github.
const props = defineProps({ open: { type: Boolean, default: false }, variant: { type: String, default: 'codex', validator: (v) => ['codex', 'multi', 'github'].includes(v) } });
const emit = defineEmits(['confirm', 'cancel']);
const { t } = useI18n();
const keep = ref(null);
const keys = computed(() => ({ codex: ['run.notice_title', 'run.notice_body'], multi: ['notice.multi_title', 'notice.multi_body'], github: ['notice.github_title', 'notice.github_body'] }[props.variant] ?? ['run.notice_title', 'run.notice_body']));
</script>

<template>
    <UiDialog :open="open" :title="t(`health.ai.${keys[0]}`)" :initial-focus="() => keep" @update:open="(v) => { if (!v) emit('cancel'); }">
        <p class="ai-notice__body">{{ t(`health.ai.${keys[1]}`) }}</p>
        <template #footer>
            <UiButton ref="keep" variant="secondary" data-testid="ai-notice-cancel" @click="emit('cancel')">{{ t('health.ai.run.notice_cancel') }}</UiButton>
            <UiButton variant="primary" data-testid="ai-notice-confirm" @click="emit('confirm')">{{ t('health.ai.run.notice_confirm') }}</UiButton>
        </template>
    </UiDialog>
</template>

<style scoped>
.ai-notice__body { margin: 0; font-size: var(--text-base); }
</style>
