<script setup>
import { useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { Pencil, X } from '@lucide/vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiTag from '../../Components/ui/UiTag.vue';
import HelpTextEditor from '../../Help/HelpTextEditor.vue';

// One registered "?" in the admin list: where it sits, its key, the texts in every language and the inline editor.
const props = defineProps({
    topic: { type: Object, required: true },
    locales: { type: Array, required: true },
    maxLength: { type: Number, required: true },
    open: { type: Boolean, default: false },
    activeLocale: { type: String, default: 'en' },
});
const emit = defineEmits(['toggle', 'saved']);
const { t } = useI18n();
const uid = useId();
const anyEdited = () => props.locales.some((l) => props.topic.edited[l]);
</script>

<template>
    <article class="help-row" :data-key="topic.key" :aria-labelledby="`${uid}-title`">
        <header class="help-row__head">
            <div class="help-row__place">
                <h2 :id="`${uid}-title`" class="help-row__title">{{ topic.location.field }}</h2>
                <p class="help-row__path">{{ topic.location.area }} &rsaquo; {{ topic.location.screen }}</p>
                <p class="help-row__key"><span class="help-row__key-label">{{ t('settings.help.key') }}</span> <code>{{ topic.key }}</code></p>
            </div>
            <div class="help-row__actions">
                <UiTag v-if="anyEdited()" variant="success" data-testid="help-edited-badge">{{ t('help.editor.edited') }}</UiTag>
                <UiButton
                    variant="secondary"
                    size="sm"
                    :icon="open ? X : Pencil"
                    :aria-expanded="open ? 'true' : 'false'"
                    :aria-controls="`${uid}-editor`"
                    :aria-label="t(open ? 'settings.help.close_editor_name' : 'settings.help.edit_name', { topic: topic.location.field })"
                    data-testid="help-edit"
                    @click="emit('toggle')"
                >{{ t(open ? 'settings.help.close_editor' : 'settings.help.edit') }}</UiButton>
            </div>
        </header>

        <dl class="help-row__texts">
            <div v-for="l in locales" :key="l" class="help-row__text" :data-locale="l">
                <dt>
                    {{ t(`help.editor.language.${l}`) }}
                    <UiTag v-if="topic.edited[l]">{{ t('help.editor.edited') }}</UiTag>
                </dt>
                <dd>{{ topic.texts[l] }}</dd>
            </div>
        </dl>

        <div v-if="open" :id="`${uid}-editor`" class="help-row__editor">
            <HelpTextEditor :topic="topic" :locales="locales" :max-length="maxLength" :active-locale="activeLocale" @saved="(data) => emit('saved', data)" @cancel="emit('toggle')" />
        </div>
    </article>
</template>

<style scoped>
.help-row { padding: var(--space-4); border: 1px solid var(--color-border); border-radius: var(--radius-lg); background: var(--color-surface); min-width: 0; container: help-row / inline-size; }
.help-row__head { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: var(--space-3); }
.help-row__place { min-width: 0; flex: 1 1 14rem; }
.help-row__title { margin: 0; font-size: var(--text-base); font-weight: 600; line-height: 1.3; overflow-wrap: anywhere; }
.help-row__path, .help-row__key { margin: var(--space-1) 0 0; font-size: var(--text-sm); color: var(--color-text-muted); overflow-wrap: anywhere; }
.help-row__key code { font-family: var(--font-mono); font-size: var(--text-sm); color: var(--color-text); }
.help-row__actions { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }
.help-row__texts { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--space-3); margin: var(--space-3) 0 0; }
.help-row__text dt { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); font-size: var(--text-sm); font-weight: 600; }
.help-row__text dd { margin: var(--space-1) 0 0; font-size: var(--text-sm); line-height: 1.45; overflow-wrap: anywhere; white-space: pre-line; }
.help-row__editor { margin-top: var(--space-4); padding-top: var(--space-4); border-top: 1px solid var(--color-border); }
@container help-row (min-width: 720px) {
    .help-row__texts { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
</style>
