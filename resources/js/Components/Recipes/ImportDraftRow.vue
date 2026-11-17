<script setup>
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiAlert from '../ui/UiAlert.vue';
import UiButton from '../ui/UiButton.vue';
import { useImportStatus } from './useImportStatus.js';

// One import draft of the overview: still reading (polled until it ends), ready to review, or failed. The site name is plain
// text; the address itself is never part of a draft. `busy` disables the buttons while a discard request runs.
const ACTIVE = ['fetching', 'queued', 'running'];
const props = defineProps({
    draft: { type: Object, required: true },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['changed', 'discard', 'dismiss', 'retry']);
const { t } = useI18n();

const active = computed(() => ACTIVE.includes(props.draft.status));
const poll = useImportStatus(computed(() => (active.value ? props.draft.id : null)));
// A terminal answer (or a poll that cannot continue) asks the overview to reload its drafts once.
watch(() => poll.terminal.value, (done) => { if (done && active.value) emit('changed'); });

const site = computed(() => props.draft.host || t('recipes.drafts.unknown_site'));
</script>

<template>
    <UiAlert v-if="active" variant="info" data-testid="draft-active" :data-draft="draft.id">
        {{ t('recipes.drafts.reading', { site }) }}
    </UiAlert>
    <UiAlert v-else-if="draft.status === 'ready'" variant="info" data-testid="draft-ready" :data-draft="draft.id">
        {{ t('recipes.drafts.ready', { site }) }}
        <template #actions>
            <UiButton variant="primary" size="sm" :href="`/recipes/create?import=${encodeURIComponent(draft.id)}`" data-testid="draft-review">{{ t('recipes.drafts.review') }}</UiButton>
            <UiButton variant="ghost" size="sm" :disabled="busy" data-testid="draft-discard" @click="emit('discard', draft)">{{ t('recipes.drafts.discard') }}</UiButton>
        </template>
    </UiAlert>
    <UiAlert v-else variant="warning" data-testid="draft-failed" :data-draft="draft.id">
        {{ t('recipes.drafts.failed', { site }) }}
        <template #actions>
            <UiButton variant="secondary" size="sm" data-testid="draft-retry" @click="emit('retry', draft)">{{ t('recipes.drafts.try_again') }}</UiButton>
            <UiButton variant="ghost" size="sm" href="/recipes/create" data-testid="draft-type">{{ t('recipes.drafts.type_it_in') }}</UiButton>
            <UiButton variant="ghost" size="sm" :disabled="busy" data-testid="draft-dismiss" @click="emit('dismiss', draft)">{{ t('recipes.drafts.dismiss') }}</UiButton>
        </template>
    </UiAlert>
</template>
