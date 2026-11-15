<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiAlert from '../ui/UiAlert.vue';
import UiButton from '../ui/UiButton.vue';
import UiCard from '../ui/UiCard.vue';
import UiField from '../ui/UiField.vue';
import UiInput from '../ui/UiInput.vue';
import UiSelect from '../ui/UiSelect.vue';
import { useToast } from '../ui/useToast.js';
import { securityFetch } from '../../Security/securityFetch.js';
import { fromDisplay, toDisplay } from '../../Settings/securityTimings.js';

// The two re-confirmation windows (issue 2). Shown in minutes or hours, stored in seconds. Saving goes through
// securityFetch with `replay`, so a stale confirmation opens "Confirm it's you" over this form and the values stay.
const props = defineProps({
    timings: { type: Object, required: true },
});
const { t } = useI18n();
const toast = useToast();

const fields = reactive({
    idle: { ...toDisplay(props.timings.idleSeconds), error: '' },
    confirm: { ...toDisplay(props.timings.confirmSeconds), error: '' },
});
const busy = ref(false);
const failed = ref(false);

watch(() => [props.timings.idleSeconds, props.timings.confirmSeconds], ([idle, confirm]) => {
    Object.assign(fields.idle, toDisplay(idle), { error: '' });
    Object.assign(fields.confirm, toDisplay(confirm), { error: '' });
});

const ranges = computed(() => ({ idle: props.timings.idle, confirm: props.timings.confirm }));

const unitOptions = computed(() => [
    { value: 'minutes', label: t('settings.security.unit_minutes') },
    { value: 'hours', label: t('settings.security.unit_hours') },
]);

function duration(seconds) {
    return seconds % 3600 === 0 && seconds >= 3600
        ? t('settings.security.duration_hours', { n: seconds / 3600 }, seconds / 3600)
        : t('settings.security.duration_minutes', { n: Math.round(seconds / 60) }, Math.round(seconds / 60));
}

function validate(key) {
    const seconds = fromDisplay(fields[key].value, fields[key].unit);
    const { min, max } = ranges.value[key];
    fields[key].error = seconds === null || seconds < min || seconds > max
        ? t('settings.security.error_range', { min: duration(min), max: duration(max) })
        : '';
    return fields[key].error === '' ? seconds : null;
}

async function save() {
    if (busy.value) return;
    failed.value = false;
    const idle = validate('idle');
    const confirm = validate('confirm');
    if (idle === null || confirm === null) return;

    busy.value = true;
    const result = await securityFetch('/settings/security/timings', {
        method: 'PUT',
        body: { idle_seconds: idle, confirm_window_seconds: confirm },
        replay: true,
    });
    busy.value = false;
    if (result.error === 'cancelled') return;
    if (result.ok) {
        toast.show(t('settings.security.saved'));
        router.reload({ only: ['securityTimings'] });
        return;
    }
    if (result.status === 422) {
        if (result.errors?.idle_seconds) fields.idle.error = t('settings.security.error_range', { min: duration(ranges.value.idle.min), max: duration(ranges.value.idle.max) });
        if (result.errors?.confirm_window_seconds) fields.confirm.error = t('settings.security.error_range', { min: duration(ranges.value.confirm.min), max: duration(ranges.value.confirm.max) });
        return;
    }
    failed.value = true;
}
</script>

<template>
    <UiCard :title="t('settings.security.title')" class="timings-card">
        <p class="timings-card__intro">{{ t('settings.security.intro') }}</p>
        <form novalidate class="timings-card__form" data-testid="timings-form" @submit.prevent="save">
            <div v-for="key in ['idle', 'confirm']" :key="key" class="timings-card__row">
                <UiField :label="t(`settings.security.${key}_label`)" :hint="t(`settings.security.${key}_help`)" :error="fields[key].error" class="timings-card__value">
                    <UiInput v-model="fields[key].value" inputmode="numeric" autocomplete="off" :disabled="busy" :data-testid="`timing-${key}`" />
                </UiField>
                <UiField :label="t('settings.security.unit_label')" label-visually-hidden class="timings-card__unit">
                    <UiSelect v-model="fields[key].unit" :options="unitOptions" :disabled="busy" :data-testid="`timing-${key}-unit`" />
                </UiField>
            </div>
            <UiAlert v-if="failed" variant="danger" live data-testid="timings-failed">{{ t('error.generic.title') }}</UiAlert>
            <UiButton type="submit" variant="primary" :loading="busy" data-testid="timings-save">{{ t('settings.security.save') }}</UiButton>
        </form>
    </UiCard>
</template>

<style scoped>
.timings-card__intro { margin: 0 0 var(--space-4); color: var(--color-text-muted); font-size: var(--text-sm); overflow-wrap: anywhere; }
.timings-card__form { display: flex; flex-direction: column; gap: var(--space-4); align-items: flex-start; }
.timings-card__row { display: flex; flex-wrap: wrap; align-items: flex-start; gap: var(--space-3); width: 100%; }
.timings-card__value { flex: 1 1 14rem; min-width: 0; }
.timings-card__unit { flex: 0 0 8rem; align-self: flex-start; margin-block-start: 1.75rem; }
</style>
