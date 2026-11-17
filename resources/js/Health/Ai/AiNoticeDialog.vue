<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../../Components/ui/UiButton.vue';
import UiDialog from '../../Components/ui/UiDialog.vue';

// One-time notice before the first document leaves the server. The safe choice ("Keep document private") has the
// default focus; Esc, the scrim and the close button all mean the same as it.
// `variant` follows the enabled providers: codex (the Phase 8 text), multi (OpenAI or GitHub) or github.
// `text` selects the bodies that also say the text read from the document is stored in this app (full-text reading on).
// `providers` is the provider key list the text was chosen for; confirming hands it back, so the server can check
// that what the member agreed to is still what is enabled.
const props = defineProps({ open: { type: Boolean, default: false }, providers: { type: Array, default: () => [] }, variant: { type: String, default: 'codex', validator: (v) => ['codex', 'multi', 'github'].includes(v) }, text: { type: Boolean, default: false } });
const emit = defineEmits(['confirm', 'cancel']);
const { t } = useI18n();
const keep = ref(null);
const keys = computed(() => {
    const body = props.text
        ? { codex: 'run.notice_text_body', multi: 'notice.multi_text_body', github: 'notice.github_text_body' }
        : { codex: 'run.notice_body', multi: 'notice.multi_body', github: 'notice.github_body' };
    const title = { codex: 'run.notice_title', multi: 'notice.multi_title', github: 'notice.github_title' };
    const variant = Object.hasOwn(body, props.variant) ? props.variant : 'codex';
    return [title[variant], body[variant]];
});
</script>

<template>
    <UiDialog :open="open" :title="t(`health.ai.${keys[0]}`)" :initial-focus="() => keep" @update:open="(v) => { if (!v) emit('cancel'); }">
        <p class="ai-notice__body">{{ t(`health.ai.${keys[1]}`) }}</p>
        <template #footer>
            <UiButton ref="keep" variant="secondary" data-testid="ai-notice-cancel" @click="emit('cancel')">{{ t('health.ai.run.notice_cancel') }}</UiButton>
            <UiButton variant="primary" data-testid="ai-notice-confirm" @click="emit('confirm', [...providers])">{{ t('health.ai.run.notice_confirm') }}</UiButton>
        </template>
    </UiDialog>
</template>

<style scoped>
.ai-notice__body { margin: 0; font-size: var(--text-base); }
</style>
