<script setup>
import { computed, reactive, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RotateCcw } from '@lucide/vue';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiField from '../Components/ui/UiField.vue';
import UiTag from '../Components/ui/UiTag.vue';
import UiTextarea from '../Components/ui/UiTextarea.vue';
import { securityFetch } from '../Security/securityFetch.js';

// The one editor for a help text: used in the dialog opened by Ctrl+click on a "?" and inline in Settings > Contextual help.
// It edits the texts of ALL languages of one topic, resets a language to its default and saves through the admin route
// (validated and audited on the server). `topic` is the detail the server sends for a topic.
const props = defineProps({
    topic: { type: Object, required: true },
    locales: { type: Array, default: () => ['sk', 'pl', 'en'] },
    maxLength: { type: Number, default: 400 },
    // The language the app is shown in right now: highlighted, because that is the text this admin sees in the tip.
    activeLocale: { type: String, default: 'en' },
});
const emit = defineEmits(['saved', 'cancel']);

const { t } = useI18n();
const uid = useId();

const draft = reactive({});
const reset = reactive({});
const busy = ref(false);
const error = ref('');

function load() {
    for (const l of props.locales) {
        draft[l] = props.topic.texts[l] ?? '';
        reset[l] = false;
    }
    error.value = '';
}
load();
watch(() => props.topic.key, load);

const length = (l) => [...(draft[l] ?? '')].length;
const trimmed = (l) => (draft[l] ?? '').trim();
const tooLong = (l) => length(l) > props.maxLength;
const empty = (l) => trimmed(l) === '';
const forbidden = (l) => /[<>]/.test(draft[l] ?? '');
const fieldError = (l) => {
    if (empty(l)) return t('help.editor.error_empty');
    if (tooLong(l)) return t('help.editor.error_too_long', { max: props.maxLength });
    if (forbidden(l)) return t('help.editor.error_markup');
    return '';
};
const changed = (l) => reset[l] || trimmed(l) !== (props.topic.texts[l] ?? '').trim();
const canSave = computed(() => props.locales.some(changed) && props.locales.every((l) => !fieldError(l)));
const atDefault = (l) => trimmed(l) === (props.topic.defaults[l] ?? '').trim() && !props.topic.edited[l];

function onInput(l, value) {
    draft[l] = value;
    reset[l] = false;
}
function resetToDefault(l) {
    draft[l] = props.topic.defaults[l] ?? '';
    reset[l] = true;
}

async function save() {
    if (!canSave.value || busy.value) return;
    const texts = {};
    const resets = [];
    for (const l of props.locales) {
        if (reset[l]) resets.push(l);
        else if (trimmed(l) !== (props.topic.texts[l] ?? '').trim()) texts[l] = trimmed(l);
    }
    busy.value = true;
    error.value = '';
    const body = {};
    if (Object.keys(texts).length) body.texts = texts;
    if (resets.length) body.reset = resets;
    const result = await securityFetch(`/settings/help/${encodeURIComponent(props.topic.key)}`, { method: 'PUT', body, replay: true });
    busy.value = false;
    if (result.error === 'cancelled') return;
    if (result.ok) {
        emit('saved', result.data);
        return;
    }
    error.value = result.status === 422 ? t('help.editor.error_invalid', { max: props.maxLength }) : t('help.editor.error_failed');
}
</script>

<template>
    <form class="help-editor" novalidate data-testid="help-editor" @submit.prevent="save">
        <p class="help-editor__where">
            <span class="help-editor__where-label">{{ t('help.editor.where') }}</span>
            <span data-testid="help-editor-location">{{ topic.location.area }} &rsaquo; {{ topic.location.screen }} &rsaquo; {{ topic.location.field }}</span>
        </p>
        <p class="help-editor__intro">{{ t('help.editor.intro', { max: maxLength }) }}</p>

        <div v-for="l in locales" :key="l" class="help-editor__lang" :class="{ 'is-active': l === activeLocale }" :data-locale="l">
            <div class="help-editor__lang-head">
                <span class="help-editor__lang-name">{{ t(`help.editor.language.${l}`) }}</span>
                <UiTag v-if="l === activeLocale" variant="success">{{ t('help.editor.current_language') }}</UiTag>
                <UiTag v-if="topic.edited[l] && !reset[l]">{{ t('help.editor.edited') }}</UiTag>
            </div>
            <UiField
                :label="t('help.editor.text_label', { language: t(`help.editor.language.${l}`) })"
                label-visually-hidden
                :error="changed(l) ? fieldError(l) : ''"
                :hint="t('help.editor.counter', { n: length(l), max: maxLength })"
            >
                <UiTextarea :model-value="draft[l]" :rows="4" :disabled="busy" :data-testid="`help-text-${l}`" @update:model-value="(v) => onInput(l, v)" />
            </UiField>
            <UiButton
                variant="ghost"
                size="sm"
                :icon="RotateCcw"
                :disabled="busy || (atDefault(l) && !reset[l])"
                :data-testid="`help-reset-${l}`"
                :aria-label="t('help.editor.reset_name', { language: t(`help.editor.language.${l}`) })"
                @click="resetToDefault(l)"
            >{{ t('help.editor.reset') }}</UiButton>
        </div>

        <UiAlert v-if="error" variant="danger" live data-testid="help-editor-error">{{ error }}</UiAlert>

        <div class="help-editor__actions">
            <UiButton variant="secondary" :disabled="busy" data-testid="help-cancel" @click="emit('cancel')">{{ t('help.editor.cancel') }}</UiButton>
            <UiButton type="submit" variant="primary" :loading="busy" :disabled="!canSave" data-testid="help-save">{{ t('help.editor.save') }}</UiButton>
        </div>
    </form>
</template>

<style scoped>
.help-editor { display: flex; flex-direction: column; gap: var(--space-4); min-width: 0; }
.help-editor__where { display: flex; flex-direction: column; gap: var(--space-1); margin: 0; font-size: var(--text-sm); overflow-wrap: anywhere; }
.help-editor__where-label { color: var(--color-text-muted); }
.help-editor__intro { margin: 0; color: var(--color-text-muted); font-size: var(--text-sm); overflow-wrap: anywhere; }
.help-editor__lang { padding: var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-md); min-width: 0; }
.help-editor__lang.is-active { border-color: var(--color-success); border-width: 2px; padding: calc(var(--space-3) - 1px); }
.help-editor__lang-head { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); margin-block-end: var(--space-2); }
.help-editor__lang-name { font-weight: 600; font-size: var(--text-sm); }
.help-editor__lang :deep(.ui-field) { margin-bottom: var(--space-2); }
.help-editor__actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: var(--space-3); }
</style>
