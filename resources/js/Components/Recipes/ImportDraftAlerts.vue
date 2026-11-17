<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiConfirmDialog from '../ui/UiConfirmDialog.vue';
import { useToast } from '../ui/useToast.js';
import { securityFetch } from '../../Security/securityFetch.js';
import { failureKey } from './recipeFailure.js';
import ImportDraftRow from './ImportDraftRow.vue';

// The member's own import drafts above the recipe list: reading, ready to review, failed. The server sends `drafts`
// ([{ id, status, host, reason }], own imports only); the page asks for a fresh copy when one changes (`changed`). Discard and
// Dismiss send DELETE and then ask for a fresh copy. Every failure of that request gets a text; a draft that is already gone
// (404) is simply reloaded away.
const ACTIVE = ['fetching', 'queued', 'running'];
const props = defineProps({ drafts: { type: Array, default: () => [] } });
const emit = defineEmits(['retry', 'changed']);
const { t } = useI18n();
const { show: showToast } = useToast();

const asking = ref(null);
const busy = ref(false);
const announcement = ref('');

async function remove(draft) {
    if (busy.value) return;
    busy.value = true;
    const result = await securityFetch(`/recipes/imports/${encodeURIComponent(draft.id)}`, { method: 'DELETE' });
    busy.value = false;
    if (!result.ok && result.status !== 404) {
        showToast(t(failureKey(result.status, 'recipes.drafts.discard_failed')));

        return;
    }
    emit('changed');
}

// A draft that moved from reading to ready or failed is announced in the live region.
let previous = new Map(props.drafts.map((d) => [d.id, d.status]));
watch(() => props.drafts, (next) => {
    for (const d of next) {
        const before = previous.get(d.id);
        if (before === undefined || !ACTIVE.includes(before) || ACTIVE.includes(d.status)) continue;
        const site = d.host || t('recipes.drafts.unknown_site');
        announcement.value = d.status === 'ready' ? t('recipes.drafts.ready', { site }) : t('recipes.drafts.failed', { site });
    }
    previous = new Map(next.map((d) => [d.id, d.status]));
}, { deep: true });
</script>

<template>
    <section v-if="drafts.length > 0" class="rec-drafts" :aria-label="t('recipes.drafts.region')" data-testid="import-drafts">
        <ImportDraftRow
            v-for="draft in drafts" :key="draft.id" :draft="draft" :busy="busy"
            @changed="emit('changed')" @discard="asking = $event" @dismiss="remove" @retry="emit('retry', $event)"
        />
        <p class="visually-hidden" role="status" aria-live="polite" data-testid="drafts-announce">{{ announcement }}</p>
        <UiConfirmDialog
            :open="asking !== null"
            :title="t('recipes.drafts.discard_title')"
            :body="t('recipes.drafts.discard_body')"
            :confirm-label="t('recipes.drafts.discard_yes')"
            :cancel-label="t('recipes.drafts.discard_no')"
            @update:open="(v) => { if (!v) asking = null; }"
            @confirm="remove(asking)"
        />
    </section>
</template>

<style scoped>
.rec-drafts { display: flex; flex-direction: column; gap: var(--space-2); }
</style>
