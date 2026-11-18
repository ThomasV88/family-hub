<script setup>
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiSwitch from '../../Components/ui/UiSwitch.vue';
import { securityFetch } from '../../Security/securityFetch.js';
import { handleHealthRefusal } from '../healthRefusal.js';

// Brightness, contrast and black-and-white for the copy of the document that is sent to AI on the next re-scan.
// The preview is a CSS filter in the same order as the server maths (brightness, contrast, grayscale); the stored
// document is never changed. Values are saved 500 ms after the last change and come back from the server on reload.
// The ranges here are a convenience, the server clamps. Nothing is kept in the browser.
const props = defineProps({
    personId: { type: String, required: true },
    batchId: { type: String, required: true },
    itemId: { type: String, required: true },
    adjustments: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:filter']);
const { t } = useI18n();
const ids = { brightness: useId(), contrast: useId(), note: useId() };

const DEFAULTS = { brightness: 100, contrast: 100, bw: false };
const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, Number.isFinite(Number(v)) ? Number(v) : 100));
function seed() {
    return {
        brightness: clamp(props.adjustments?.brightness ?? 100, 50, 150),
        contrast: clamp(props.adjustments?.contrast ?? 100, 50, 200),
        bw: Boolean(props.adjustments?.bw),
    };
}
const values = ref(seed());
const failed = ref(false);

const filter = computed(() => `brightness(${values.value.brightness}%) contrast(${values.value.contrast}%) grayscale(${values.value.bw ? 1 : 0})`);
const isDefault = computed(() => values.value.brightness === 100 && values.value.contrast === 100 && !values.value.bw);
watch(filter, (value) => emit('update:filter', value), { immediate: true });
// The saved values of another document (or a fresh server answer after a reload) replace the local ones.
watch(() => props.itemId, () => {
    clearTimer();
    values.value = seed();
});
watch(() => [props.adjustments?.brightness, props.adjustments?.contrast, props.adjustments?.bw], () => {
    if (!dirty) values.value = seed();
});

let timer = null;
let dirty = false;
function clearTimer() {
    if (timer !== null) clearTimeout(timer);
    timer = null;
    dirty = false;
}
async function save() {
    timer = null;
    dirty = false;
    failed.value = false;
    const result = await securityFetch(`/health/people/${props.personId}/upload-batches/${props.batchId}/items/${props.itemId}/adjustments`, {
        method: 'PUT', body: { ...values.value }, replay: true,
    });
    if (result.ok || result.error === 'cancelled') return;
    if (result.status === 423 && handleHealthRefusal(result.error)) return;
    failed.value = true;
}
function schedule() {
    dirty = true;
    if (timer !== null) clearTimeout(timer);
    timer = setTimeout(save, 500);
}
function set(key, value) {
    values.value = { ...values.value, [key]: value };
    schedule();
}
function reset() {
    values.value = { ...DEFAULTS };
    schedule();
}
// A change made right before leaving is still sent.
onBeforeUnmount(() => {
    if (timer !== null) clearTimeout(timer);
    if (dirty) save();
});
</script>

<template>
    <div class="adjust" role="group" :aria-labelledby="ids.note + '-title'" data-testid="adjust-panel">
        <h4 :id="ids.note + '-title'" class="adjust__title">{{ t('health.batch.adjust_title') }}</h4>
        <div class="adjust__row">
            <label :for="ids.brightness" class="adjust__label">{{ t('health.batch.brightness') }}</label>
            <input
                :id="ids.brightness"
                class="adjust__range"
                type="range"
                min="50"
                max="150"
                step="5"
                :value="values.brightness"
                :disabled="disabled"
                data-testid="adjust-brightness"
                @input="set('brightness', Number($event.target.value))"
            >
            <output class="adjust__value" :for="ids.brightness" data-testid="adjust-brightness-value">{{ values.brightness }}%</output>
        </div>
        <div class="adjust__row">
            <label :for="ids.contrast" class="adjust__label">{{ t('health.batch.contrast') }}</label>
            <input
                :id="ids.contrast"
                class="adjust__range"
                type="range"
                min="50"
                max="200"
                step="5"
                :value="values.contrast"
                :disabled="disabled"
                data-testid="adjust-contrast"
                @input="set('contrast', Number($event.target.value))"
            >
            <output class="adjust__value" :for="ids.contrast" data-testid="adjust-contrast-value">{{ values.contrast }}%</output>
        </div>
        <UiSwitch :model-value="values.bw" :disabled="disabled" data-testid="adjust-bw" @update:model-value="(v) => set('bw', v)">{{ t('health.batch.black_white') }}</UiSwitch>
        <div>
            <UiButton variant="ghost" :disabled="disabled || isDefault" data-testid="adjust-reset" @click="reset">{{ t('health.batch.adjust_reset') }}</UiButton>
        </div>
        <p :id="ids.note" class="adjust__note" data-testid="adjust-note">{{ t('health.batch.adjust_note') }}</p>
        <UiAlert v-if="failed" variant="danger" live data-testid="adjust-failed">{{ t('health.ai.review.action_failed') }}</UiAlert>
    </div>
</template>

<style scoped>
.adjust { display: flex; flex-direction: column; gap: var(--space-3); min-width: 0; padding: var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-md); background: var(--color-surface); }
.adjust__title { margin: 0; font-size: var(--text-base); font-weight: 600; }
.adjust__row { display: grid; grid-template-columns: minmax(6rem, 9rem) minmax(0, 1fr) 4rem; align-items: center; gap: var(--space-2); }
.adjust__label { font-weight: 600; }
.adjust__range { width: 100%; min-height: 40px; margin: 0; accent-color: var(--color-primary); }
.adjust__value { text-align: end; font-variant-numeric: tabular-nums; color: var(--color-text-muted); }
.adjust__note { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
@container (max-width: 420px) {
    .adjust__row { grid-template-columns: minmax(0, 1fr) 4rem; }
    .adjust__label { grid-column: 1 / -1; }
}
</style>
