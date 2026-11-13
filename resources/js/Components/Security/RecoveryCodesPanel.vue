<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Copy, Download } from '@lucide/vue';
import UiButton from '../ui/UiButton.vue';
import UiCheckbox from '../ui/UiCheckbox.vue';
import { useToast } from '../ui/useToast.js';

// Shows the recovery codes once. The codes live only in the `codes` prop passed from the caller's local state.
// A beforeunload warning is registered while the panel is visible; the only exit is the acknowledged finish button.
const props = defineProps({
    codes: { type: Array, required: true },
    finishLabel: { type: String, default: '' },
});
const emit = defineEmits(['finish']);
const { t } = useI18n();
const toast = useToast();
const acknowledged = ref(false);

function warn(event) {
    event.preventDefault();
    event.returnValue = '';
}
onMounted(() => window.addEventListener('beforeunload', warn));
onBeforeUnmount(() => window.removeEventListener('beforeunload', warn));

async function copy() {
    try {
        await navigator.clipboard.writeText(props.codes.join('\n'));
        toast.show(t('twofactor.recovery.copied'));
    } catch {
        // Clipboard unavailable: the codes are still selectable on screen.
    }
}

// Client-built file with the title, the date and the codes only (no name, no email).
function download() {
    const text = `${t('twofactor.recovery.file_title')}\n${new Date().toISOString().slice(0, 10)}\n\n${props.codes.join('\n')}\n`;
    const url = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = 'family-hub-recovery-codes.txt';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}
</script>

<template>
    <div class="codes" data-testid="recovery-panel">
        <p class="codes__body">{{ t('twofactor.recovery.body') }}</p>
        <ul class="codes__list" :aria-label="t('twofactor.recovery.list_label')">
            <li v-for="code in codes" :key="code" class="codes__item">{{ code }}</li>
        </ul>
        <div class="codes__actions">
            <UiButton variant="secondary" :icon="Copy" data-testid="copy-codes" @click="copy">{{ t('twofactor.cta.copy_codes') }}</UiButton>
            <UiButton variant="secondary" :icon="Download" data-testid="download-codes" @click="download">{{ t('twofactor.cta.download_codes') }}</UiButton>
        </div>
        <UiCheckbox v-model="acknowledged" data-testid="ack">{{ t('twofactor.recovery.ack') }}</UiCheckbox>
        <div class="codes__finish">
            <UiButton variant="primary" :disabled="!acknowledged" data-testid="finish" @click="emit('finish')">
                {{ finishLabel || t('twofactor.cta.finish') }}
            </UiButton>
        </div>
    </div>
</template>

<style scoped>
.codes__body { margin: 0 0 var(--space-4); }
.codes__list {
    display: grid;
    grid-template-columns: 1fr;
    gap: var(--space-2) var(--space-4);
    margin: 0 0 var(--space-4);
    padding: var(--space-3);
    list-style: none;
    background: var(--color-surface-2);
    border-radius: var(--radius-md);
    font-family: var(--font-mono);
    font-size: var(--text-base);
}
@container (min-width: 360px) { .codes__list { grid-template-columns: 1fr 1fr; } }
@media (min-width: 400px) { .codes__list { grid-template-columns: 1fr 1fr; } }
.codes__item { user-select: all; }
.codes__actions { display: flex; flex-wrap: wrap; gap: var(--space-3); margin-bottom: var(--space-4); }
.codes__finish { display: flex; justify-content: flex-end; margin-top: var(--space-4); }
</style>
