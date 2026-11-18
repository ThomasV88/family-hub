<script setup>
import { computed, ref, useId, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Ellipsis, Trash2 } from '@lucide/vue';
import AppShell from '../../Layouts/AppShell.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import UiConfirmDialog from '../../Components/ui/UiConfirmDialog.vue';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import UiMenu from '../../Components/ui/UiMenu.vue';
import UiProgress from '../../Components/ui/UiProgress.vue';
import { securityFetch } from '../../Security/securityFetch.js';
import { useFrameWidth } from '../../Shell/shellState.js';
import { INTL_LOCALES } from '../../i18n.js';
import { handleHealthRefusal } from '../../Health/healthRefusal.js';
import BatchItemList from '../../Health/Batch/BatchItemList.vue';
import BatchUploader from '../../Health/Batch/BatchUploader.vue';
import { itemView } from '../../Health/Batch/batchState.js';
import { useBatchStatus } from '../../Health/Batch/useBatchStatus.js';

// The resumable page of one upload batch. Everything shown comes from the server (page props, then the status route):
// reloading it, or opening its URL on another device, shows the same documents and statuses and the same current
// document. Nothing is kept in any browser storage; the picked files live in the uploader's memory only.
const props = defineProps({
    batch: { type: Object, required: true },
    items: { type: Array, default: () => [] },
    // {item, document, review, choices, adjustments} for the current document, or null (filled further in the review area).
    current: { type: Object, default: null },
    limits: { type: Object, default: () => ({ maxFiles: 50, maxMb: 10, maxRescans: 5 }) },
    ai: { type: Object, default: () => ({}) },
    person: { type: Object, required: true },
});
const { t, locale } = useI18n();
const appLocale = computed(() => ({ 'sk-SK': 'sk', 'pl-PL': 'pl', 'en-GB': 'en' }[locale.value] ?? locale.value));

const WIDE = 720;
const root = ref(null);
const width = useFrameWidth(root);
const wide = computed(() => width.value >= WIDE);
const ids = { review: useId() };

const status = useBatchStatus(() => props.person.id, () => props.batch.id, () => ({ batch: props.batch, items: props.items }));
const live = computed(() => status.batch.value ?? props.batch);
const liveItems = computed(() => status.items.value);
const counts = computed(() => live.value.counts ?? { total: liveItems.value.length, done: 0 });
const currentId = computed(() => live.value.currentItemId ?? null);
const currentItem = computed(() => liveItems.value.find((i) => i.id === currentId.value) ?? null);
const percent = computed(() => (counts.value.total > 0 ? Math.round((counts.value.done / counts.value.total) * 100) : 0));
const finished = computed(() => counts.value.total > 0 && counts.value.done === counts.value.total);
const hasRoom = computed(() => counts.value.total < Number(props.limits?.maxFiles ?? 50));
const expires = computed(() => {
    const d = new Date(live.value.expiresAt ?? '');
    return Number.isNaN(d.getTime()) ? '' : new Intl.DateTimeFormat(INTL_LOCALES[appLocale.value] ?? appLocale.value, { dateStyle: 'medium' }).format(d);
});
const personUrl = computed(() => `/health/people/${props.person.id}`);
const currentText = computed(() => {
    if (!currentItem.value) return '';
    const view = itemView(currentItem.value, appLocale.value);
    return view.plural === null ? t(view.labelKey, view.params) : t(view.labelKey, view.params, view.plural);
});

// When the polled state of the current document differs from the one the page props were rendered with, ask the server
// for fresh page props (the review area reads them). Only the three props, no full navigation.
const RELOAD = ['batch', 'items', 'current'];
watch(() => currentItem.value?.state, (next) => {
    if (next && props.current?.item && props.current.item.id === currentItem.value.id && props.current.item.state !== next) {
        router.reload({ only: RELOAD, preserveScroll: true });
    }
});

const error = ref(false);
async function select(itemId) {
    if (itemId === currentId.value) return;
    error.value = false;
    const result = await securityFetch(`/health/people/${props.person.id}/upload-batches/${props.batch.id}/current`, { method: 'PUT', body: { itemId }, replay: true });
    if (result.ok) {
        if (status.batch.value) status.batch.value = { ...status.batch.value, currentItemId: itemId };
        router.reload({ only: RELOAD, preserveScroll: true });
        return;
    }
    if (result.status === 423 && handleHealthRefusal(result.error)) return;
    error.value = true;
}

const uploading = ref(false);
function uploaded() {
    status.refresh();
}

const deleteOpen = ref(false);
const menuItems = computed(() => [{ type: 'item', key: 'delete', label: t('health.batch.delete'), icon: Trash2 }]);
const onMenu = ({ key }) => { if (key === 'delete') deleteOpen.value = true; };
async function remove() {
    error.value = false;
    const result = await securityFetch(`/health/people/${props.person.id}/upload-batches/${props.batch.id}`, { method: 'DELETE', replay: true });
    if (result.ok) {
        router.visit(personUrl.value);
        return;
    }
    if (result.status === 423 && handleHealthRefusal(result.error)) return;
    error.value = true;
}
</script>

<template>
    <AppShell current="health">
        <Head :title="t('health.batch.title')" />
        <div ref="root" class="batch-page" data-testid="upload-batch-page">
            <header class="batch-page__header">
                <div><UiButton variant="ghost" :icon="ArrowLeft" :href="personUrl" data-testid="batch-back">{{ t('health.batch.back') }}</UiButton></div>
                <div class="batch-page__titles">
                    <div>
                        <h1 class="batch-page__title">{{ t('health.batch.title') }}</h1>
                        <p class="batch-page__person">{{ person.name }}</p>
                    </div>
                    <UiMenu :label="t('health.person.more_actions')" :icon="Ellipsis" :items="menuItems" icon-only @select="onMenu" />
                </div>
                <div class="batch-page__progress" data-testid="batch-progress">
                    <p class="batch-page__count" role="status">{{ t('health.batch.progress', { done: counts.done, total: counts.total }) }}</p>
                    <UiProgress :value="percent" :label="t('health.batch.progress', { done: counts.done, total: counts.total })" />
                    <p v-if="expires" class="batch-page__expires" data-testid="batch-expires">{{ t('health.batch.expires', { date: expires }) }}</p>
                </div>
                <UiAlert v-if="error" variant="danger" live data-testid="batch-error">{{ t('health.ai.run.queue_failed') }}</UiAlert>
                <UiAlert v-if="status.failedPolls.value >= 3" variant="info" data-testid="batch-poll-failed">
                    {{ t('health.ai.run.poll_failed') }}
                    <template #actions><UiButton variant="ghost" size="sm" @click="status.refresh()">{{ t('health.ai.run.try_again') }}</UiButton></template>
                </UiAlert>
            </header>

            <div :class="['batch-page__body', { 'batch-page__body--wide': wide }]">
                <aside class="batch-page__side" data-testid="batch-side">
                    <details v-if="!wide" class="batch-page__disclosure" data-testid="batch-disclosure">
                        <summary class="batch-page__summary">{{ t('health.batch.list_title') }} ({{ counts.total }})</summary>
                        <BatchItemList :items="liveItems" :current-id="currentId" @select="select" />
                    </details>
                    <section v-else class="batch-page__list" aria-labelledby="batch-list-title">
                        <h2 id="batch-list-title" class="batch-page__heading">{{ t('health.batch.list_title') }}</h2>
                        <BatchItemList :items="liveItems" :current-id="currentId" @select="select" />
                    </section>
                    <UiCard v-if="hasRoom && !finished" :title="t('health.batch.add_files')" class="batch-page__uploader">
                        <BatchUploader :person-id="person.id" :batch-id="batch.id" :existing="counts.total" :limits="{ maxMb: limits.maxMb, batchMaxFiles: limits.maxFiles }" @uploaded="uploaded" @uploading="(v) => (uploading = v)" />
                    </UiCard>
                </aside>

                <!-- The review area (15-07 plugs the review, adjustments, re-scan and Continue controls in here). -->
                <section class="batch-page__review" :aria-labelledby="ids.review" data-testid="batch-review">
                    <h2 :id="ids.review" class="batch-page__heading">{{ t('health.batch.review_title') }}</h2>
                    <UiEmptyState v-if="liveItems.length === 0" :title="t('health.batch.empty')" data-testid="batch-empty" />
                    <p v-else-if="finished" class="batch-page__finished" role="status" data-testid="batch-finished">{{ t('health.batch.finished') }}</p>
                    <div v-else-if="currentItem" class="batch-page__current" data-testid="batch-current">
                        <p class="batch-page__current-title">{{ currentItem.title }}</p>
                        <p class="batch-page__current-state">{{ currentText }}</p>
                    </div>
                </section>
            </div>

            <UiConfirmDialog
                v-model:open="deleteOpen"
                :title="t('health.batch.delete_title')"
                :body="t('health.batch.delete_body')"
                :confirm-label="t('health.batch.delete_confirm')"
                :cancel-label="t('health.batch.delete_keep')"
                @confirm="remove"
            />
        </div>
    </AppShell>
</template>

<style scoped>
.batch-page { container-type: inline-size; display: flex; flex-direction: column; gap: var(--space-4); }
.batch-page__header { display: flex; flex-direction: column; gap: var(--space-3); }
.batch-page__titles { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--space-3); }
.batch-page__title { margin: 0; font-size: var(--text-xl); font-weight: 600; line-height: 1.2; }
.batch-page__person { margin: 0; color: var(--color-text-muted); overflow-wrap: anywhere; }
.batch-page__progress { display: flex; flex-direction: column; gap: var(--space-2); max-width: 520px; }
.batch-page__count { margin: 0; font-weight: 600; }
.batch-page__expires { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.batch-page__body { display: flex; flex-direction: column; gap: var(--space-4); min-width: 0; }
.batch-page__body--wide { display: grid; grid-template-columns: 280px minmax(0, 1fr); gap: var(--space-6); align-items: start; }
.batch-page__side { display: flex; flex-direction: column; gap: var(--space-4); min-width: 0; }
.batch-page__list, .batch-page__disclosure { border: 1px solid var(--color-border); border-radius: var(--radius-md); background: var(--color-surface); overflow: hidden; }
.batch-page__summary { min-height: 44px; display: flex; align-items: center; padding: 0 var(--space-3); font-weight: 600; cursor: pointer; }
.batch-page__heading { margin: 0; padding: var(--space-3); font-size: var(--text-base); font-weight: 600; }
.batch-page__review { min-width: 0; }
.batch-page__review > .batch-page__heading { padding-inline: 0; }
.batch-page__finished { margin: 0; font-weight: 600; }
.batch-page__current-title { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
.batch-page__current-state { margin: var(--space-1) 0 0; color: var(--color-text-muted); }
</style>
