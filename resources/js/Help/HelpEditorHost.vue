<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../Components/ui/UiButton.vue';
import UiDialog from '../Components/ui/UiDialog.vue';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiSkeleton from '../Components/ui/UiSkeleton.vue';
import { useToast } from '../Components/ui/useToast.js';
import { securityFetch } from '../Security/securityFetch.js';
import { closeHelpEditor, editing, help, setTopicText } from './helpStore.js';
import HelpTextEditor from './HelpTextEditor.vue';

// The dialog behind Ctrl+click on a "?" (administrators only). Mounted once at the app root next to the stale-gate dialog.
// It loads the topic with all languages, hosts the shared editor and, after a save, updates the visible tip in place.
const { t } = useI18n();
const toast = useToast();

const topic = ref(null);
const status = ref('idle'); // idle | loading | ready | failed
const open = ref(false);

async function load(key) {
    status.value = 'loading';
    topic.value = null;
    const result = await securityFetch(`/settings/help/${encodeURIComponent(key)}`);
    if (editing.value !== key) return;
    if (result.ok && result.data?.topic) {
        topic.value = result.data.topic;
        status.value = 'ready';
    } else {
        status.value = 'failed';
    }
}

watch(
    editing,
    (key) => {
        open.value = key !== null;
        if (key !== null && help.admin) load(key);
        else if (key !== null) closeHelpEditor();
    },
    { immediate: true },
);

function onOpen(value) {
    if (!value) closeHelpEditor();
}
function onSaved(data) {
    if (data?.current) setTopicText(editing.value, data.current);
    toast.show(t('help.editor.saved'));
    closeHelpEditor();
}
</script>

<template>
    <UiDialog :open="open && help.admin" :title="t('help.editor.title')" @update:open="onOpen">
        <div v-if="status === 'loading'" aria-busy="true" :aria-label="t('help.editor.loading')">
            <UiSkeleton width="60%" />
            <UiSkeleton />
            <UiSkeleton />
        </div>
        <div v-else-if="status === 'failed'" class="help-host__failed">
            <UiAlert variant="danger" live>{{ t('help.editor.load_failed') }}</UiAlert>
            <UiButton variant="secondary" @click="load(editing)">{{ t('ui.retry') }}</UiButton>
        </div>
        <HelpTextEditor
            v-else-if="status === 'ready' && topic"
            :topic="topic"
            :active-locale="help.locale"
            @saved="onSaved"
            @cancel="closeHelpEditor"
        />
    </UiDialog>
</template>

<style scoped>
.help-host__failed { display: flex; flex-direction: column; gap: var(--space-3); align-items: flex-start; }
</style>
