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
// The fresh copy of the drafts (after that reload) can still say "reading": the poll gave up after 3 minutes or on a flaky
// connection while the server goes on. Poll again, so the row never stays on "Reading..." for good. A 403, 404 or 419 means the
// draft is gone or the session ended: the reload removes the row or the app signs out, so nothing is restarted for those.
watch(() => props.draft, () => {
    if (!active.value || !poll.terminal.value) return;
    if ([403, 404, 419].includes(poll.error.value)) return;
    poll.start(props.draft.id);
});

const site = computed(() => props.draft.host || t('recipes.drafts.unknown_site'));
</script>

<template>
    <UiAlert v-if="active" variant="info" data-testid="draft-active" :data-draft="draft.id">
        {{ t('recipes.drafts.reading', { site }) }}
        <template #actions>
            <UiButton variant="ghost" size="sm" :disabled="busy" data-testid="draft-cancel" @click="emit('dismiss', draft)">{{ t('recipes.import.cancel') }}</UiButton>
        </template>
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
