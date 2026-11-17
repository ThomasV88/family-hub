<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../ui/UiButton.vue';
import UiField from '../ui/UiField.vue';
import UiTextarea from '../ui/UiTextarea.vue';

// The member's own notes on a recipe. Autosaved 800 ms after typing stops through `save(note|null)`, which resolves { ok }.
// Nobody else sees them, not even on the author's own recipe (the server stores them per member).
const SAVE_DELAY_MS = 800;
const MAX = 2000;
const props = defineProps({
    modelValue: { type: String, default: '' },
    save: { type: Function, required: true },
});
const { t } = useI18n();

const text = ref(props.modelValue ?? '');
const status = ref('idle'); // idle | saving | saved | failed
const area = ref(null);
let timer = null;
let latest = 0;

function grow() {
    const el = area.value?.$el ?? null;
    const textarea = el && el.tagName === 'TEXTAREA' ? el : el?.querySelector?.('textarea');
    if (!textarea) return;
    textarea.style.height = 'auto';
    textarea.style.height = `${textarea.scrollHeight}px`;
}
onMounted(grow);
watch(text, () => nextTick(grow));

async function flush() {
    if (timer !== null) { clearTimeout(timer); timer = null; }
    const ticket = ++latest;
    status.value = 'saving';
    const value = text.value.trim() === '' ? null : text.value;
    const result = await props.save(value);
    if (ticket !== latest) return;
    status.value = result?.ok ? 'saved' : 'failed';
}
function onInput(value) {
    text.value = value.slice(0, MAX);
    if (timer !== null) clearTimeout(timer);
    status.value = 'idle';
    timer = setTimeout(flush, SAVE_DELAY_MS);
}
// A reload after a refused write brings fresh server text: take it unless the member has unsaved typing or a save is running.
watch(() => props.modelValue, (value) => {
    if (timer === null && status.value !== 'saving') text.value = value ?? '';
});
onBeforeUnmount(() => { if (timer !== null) { clearTimeout(timer); props.save(text.value.trim() === '' ? null : text.value); } });
</script>

<template>
    <div class="notes" data-testid="recipe-notes">
        <UiField :label="t('recipes.notes.label')" help="recipes.notes">
            <UiTextarea ref="area" :model-value="text" :rows="4" :maxlength="MAX" :placeholder="t('recipes.notes.placeholder')" data-testid="notes-input" @update:model-value="onInput" />
        </UiField>
        <p class="notes__status" aria-live="polite" data-testid="notes-status">
            <template v-if="status === 'saving'">{{ t('recipes.notes.saving') }}</template>
            <template v-else-if="status === 'saved'">{{ t('recipes.notes.saved') }}</template>
            <template v-else-if="status === 'failed'">
                <span class="notes__failed">{{ t('recipes.notes.failed') }}</span>
                <UiButton variant="ghost" size="sm" data-testid="notes-retry" @click="flush">{{ t('recipes.notes.retry') }}</UiButton>
            </template>
        </p>
    </div>
</template>

<style scoped>
.notes { display: flex; flex-direction: column; gap: var(--space-1); }
.notes__status { display: flex; align-items: center; gap: var(--space-2); min-height: 20px; margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.notes__failed { color: var(--color-danger); }
.notes :deep(textarea) { resize: none; }
</style>
