<script setup>
import { computed, nextTick, ref, useId } from 'vue';
import { I18nT, useI18n } from 'vue-i18n';
import { Ban, Check, CircleX, Clock, Hourglass, Loader, Pause, Play } from '@lucide/vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiDialog from '../../Components/ui/UiDialog.vue';
import UiProgress from '../../Components/ui/UiProgress.vue';
import UiTag from '../../Components/ui/UiTag.vue';

// Active bulk run: state tag, progress, counts and Pause / Resume / Cancel. Reads only the contract fields
// (numbers, state, times); nothing about people or documents exists in this data.
const props = defineProps({
    // { id, status, state, scope, dailyCap, total, done, read, failed, skipped, waiting, left, todayUsed, nextBatchAfter, availableAt }
    run: { type: Object, required: true },
    // 'pause' | 'resume' | 'cancel' | null: the request in flight (controls stay focusable, inert).
    pending: { type: String, default: null },
    pollFailed: { type: Boolean, default: false },
    timezone: { type: String, default: 'Europe/Bratislava' },
});
const emit = defineEmits(['pause', 'resume', 'cancel', 'retry']);

const { t, locale } = useI18n();
const uid = useId();
const headingId = `bulk-progress-${uid}`;
const heading = ref(null);
const confirming = ref(false);
const keep = ref(null);

const TAGS = {
    running: { icon: Loader, variant: 'neutral' },
    paused: { icon: Pause, variant: 'neutral' },
    next_batch: { icon: Clock, variant: 'neutral' },
    waiting_quota: { icon: Hourglass, variant: 'warn' },
    completed: { icon: Check, variant: 'success' },
    cancelled: { icon: Ban, variant: 'neutral' },
    stopped: { icon: CircleX, variant: 'danger' },
};
const state = computed(() => (TAGS[props.run.state] ? props.run.state : 'running'));
const tag = computed(() => TAGS[state.value]);
const paused = computed(() => state.value === 'paused');

const num = (v) => (Number.isFinite(Number(v)) ? Math.max(0, Math.trunc(Number(v))) : 0);
const total = computed(() => num(props.run.total));
const done = computed(() => num(props.run.done));
const sentence = computed(() => t('settings.ai_bulk.progress', { done: done.value, n: total.value }, total.value));
const counts = computed(() => [
    ['read', 'count_read'], ['failed', 'count_failed'], ['skipped', 'count_skipped'], ['waiting', 'count_waiting'], ['left', 'count_left'],
].map(([key, label]) => ({ key, label: t(`settings.ai_bulk.${label}`), value: num(props.run[key]) })));

function stamp(iso) {
    const date = iso ? new Date(iso) : null;
    if (!date || Number.isNaN(date.getTime())) return null;
    const day = (d) => new Intl.DateTimeFormat('en-CA', { timeZone: props.timezone }).format(d);
    const options = day(date) === day(new Date()) ? { timeStyle: 'short' } : { weekday: 'short', hour: 'numeric', minute: '2-digit' };
    return { iso: date.toISOString(), text: new Intl.DateTimeFormat(locale.value, { ...options, timeZone: props.timezone }).format(date) };
}
const nextBatch = computed(() => stamp(props.run.nextBatchAfter));
const availableAt = computed(() => stamp(props.run.availableAt));

const inert = computed(() => Boolean(props.pending));
const toggleName = computed(() => t(paused.value ? 'settings.ai_bulk.resume_name' : 'settings.ai_bulk.pause_name'));

function toggle() {
    if (inert.value) return;
    emit(paused.value ? 'resume' : 'pause');
}
function askCancel() {
    if (inert.value) return;
    confirming.value = true;
}
function confirmCancel() {
    confirming.value = false;
    emit('cancel');
}

defineExpose({ focusHeading: () => nextTick(() => heading.value?.focus()) });
</script>

<template>
    <section class="bulk-progress" :aria-labelledby="headingId" :aria-busy="pending ? 'true' : undefined">
        <div class="bulk-progress__top">
            <UiTag :variant="tag.variant" :icon="tag.icon" class="bulk-tag" data-testid="bulk-state">
                <I18nT v-if="state === 'next_batch' && nextBatch" keypath="settings.ai_bulk.tag_next_batch" tag="span" scope="global">
                    <template #when><time :datetime="nextBatch.iso">{{ nextBatch.text }}</time></template>
                </I18nT>
                <I18nT v-else-if="state === 'waiting_quota' && availableAt" keypath="health.ai.status.waiting" tag="span" scope="global">
                    <template #when><time :datetime="availableAt.iso">{{ availableAt.text }}</time></template>
                </I18nT>
                <template v-else>{{ t(`settings.ai_bulk.tag_${state === 'next_batch' ? 'running' : state === 'waiting_quota' ? 'waiting' : state}`) }}</template>
            </UiTag>
        </div>

        <h3 :id="headingId" ref="heading" tabindex="-1" class="bulk-progress__heading">{{ t('settings.ai_bulk.progress_heading') }}</h3>

        <UiProgress :value="done" :max="Math.max(1, total)" :label="t('settings.ai_bulk.progress_heading')" :aria-valuetext="sentence" />
        <p class="bulk-progress__sentence" aria-hidden="true">{{ sentence }}</p>

        <dl class="bulk-counts">
            <div v-for="row in counts" :key="row.key" class="bulk-counts__row">
                <dt>{{ row.label }}</dt>
                <dd>{{ row.value }}</dd>
            </div>
        </dl>
        <p class="bulk-progress__today">{{ t('settings.ai_bulk.today_used', { used: num(run.todayUsed), cap: num(run.dailyCap) }) }}</p>

        <UiAlert v-if="pollFailed" variant="warning">
            {{ t('settings.ai_bulk.poll_error') }}
            <template #actions>
                <UiButton variant="ghost" size="sm" @click="emit('retry')">{{ t('settings.ai_bulk.try_again') }}</UiButton>
            </template>
        </UiAlert>

        <div class="bulk-progress__controls">
            <UiButton
                :icon="paused ? Play : Pause"
                data-control="toggle"
                :aria-label="toggleName"
                :aria-disabled="inert ? 'true' : undefined"
                :aria-busy="pending === 'pause' || pending === 'resume' ? 'true' : undefined"
                @click="toggle"
            >{{ t(paused ? 'settings.ai_bulk.resume' : 'settings.ai_bulk.pause') }}</UiButton>
            <UiButton :icon="Ban" data-control="cancel" :aria-disabled="inert ? 'true' : undefined" @click="askCancel">{{ t('settings.ai_bulk.cancel') }}</UiButton>
        </div>

        <UiDialog :open="confirming" :title="t('settings.ai_bulk.cancel_title')" :initial-focus="() => keep" @update:open="(v) => (confirming = v)">
            <p class="bulk-dialog__body">{{ t('settings.ai_bulk.cancel_body') }}</p>
            <template #footer>
                <UiButton ref="keep" variant="secondary" @click="confirming = false">{{ t('settings.ai_bulk.cancel_safe') }}</UiButton>
                <UiButton variant="primary" @click="confirmCancel">{{ t('settings.ai_bulk.cancel') }}</UiButton>
            </template>
        </UiDialog>
    </section>
</template>

<style scoped>
.bulk-progress { display: flex; flex-direction: column; gap: var(--space-3); }
.bulk-progress__top { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.bulk-progress__heading { margin: 0; font-size: var(--text-lg); font-weight: 600; }
.bulk-progress__heading:focus { outline: none; }
.bulk-progress__heading:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.bulk-progress__sentence, .bulk-progress__today { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.ui-tag.bulk-tag { max-width: 100%; align-items: flex-start; min-height: 44px; white-space: normal; overflow-wrap: anywhere; }
.bulk-counts { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: var(--space-3); margin: 0; }
.bulk-counts__row { display: flex; flex-direction: column; gap: var(--space-1); min-width: 0; padding: var(--space-2) var(--space-3); border-radius: var(--radius-md); background: var(--color-surface-2); }
.bulk-counts dt { font-size: var(--text-sm); color: var(--color-text-muted); overflow-wrap: anywhere; }
.bulk-counts dd { margin: 0; font-size: var(--text-lg); font-weight: 600; font-variant-numeric: tabular-nums; }
.bulk-progress__controls { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.bulk-progress__controls :deep(.ui-button) { min-height: 44px; }
.bulk-progress__controls :deep(.ui-button__label) { overflow-wrap: anywhere; }
.bulk-dialog__body { margin: 0; font-size: var(--text-base); }
</style>
