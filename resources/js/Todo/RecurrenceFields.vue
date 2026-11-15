<script setup>
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import UiChip from '../Components/ui/UiChip.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import UiRadio from '../Components/ui/UiRadio.vue';
import UiSelect from '../Components/ui/UiSelect.vue';
import { WEEKDAYS } from './recurrenceState.js';

const props = defineProps({
    // { kind, weekdays, monthKind, monthDay, interval, unit, mode } (see recurrenceState.js)
    modelValue: { type: Object, required: true },
    dueDate: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
    // Finance recurring expenses are schedule-only and always repeat.
    showMode: { type: Boolean, default: true },
    allowNone: { type: Boolean, default: true },
});
const emit = defineEmits(['update:modelValue']);
const { t, locale } = useI18n();
const uid = useId();

const noDate = computed(() => !props.dueDate);
const set = (patch) => emit('update:modelValue', { ...props.modelValue, ...patch });

// 2024-01-01 is a Monday; all three supported locales start the week on Monday.
const weekdayNames = (style) => {
    const fmt = new Intl.DateTimeFormat(locale.value, { weekday: style, timeZone: 'UTC' });
    return WEEKDAYS.map((_, i) => fmt.format(new Date(Date.UTC(2024, 0, 1 + i))));
};
const shortNames = computed(() => weekdayNames('short'));
const longNames = computed(() => weekdayNames('long'));

function toggleDay(code, on) {
    const next = on ? [...new Set([...props.modelValue.weekdays, code])] : props.modelValue.weekdays.filter((d) => d !== code);
    set({ weekdays: next });
}

// Display only: the server computes every date. Monthly repeats anchor on the due date's day (or the stored day).
const dayOfMonth = computed(() => props.modelValue.monthDay ?? (props.dueDate ? Number(props.dueDate.slice(8, 10)) : null));

const kindOptions = computed(() => ['none', 'daily', 'weekly', 'monthly', 'custom'].filter((value) => props.allowNone || value !== 'none').map((value) => ({ value, label: t(`todo.repeat.${value}`) })));
const monthOptions = computed(() => [
    { value: 'day', label: dayOfMonth.value ? t('todo.repeat.on_day', { n: dayOfMonth.value }) : t('todo.repeat.month_day') },
    { value: 'last', label: t('todo.repeat.last_day') },
]);
const unitOptions = computed(() => ['day', 'week', 'month'].map((value) => ({ value, label: t(`todo.repeat.unit_${value}`) })));

const summary = computed(() => {
    const r = props.modelValue;
    if (r.kind === 'daily') return t('todo.repeat.summary_daily');
    if (r.kind === 'weekly') {
        const days = WEEKDAYS.map((code, i) => (r.weekdays.includes(code) ? longNames.value[i] : null)).filter(Boolean);
        return days.length ? t('todo.repeat.summary_weekdays', { days: new Intl.ListFormat(locale.value, { style: 'long', type: 'conjunction' }).format(days) }) : '';
    }
    if (r.kind === 'monthly') {
        if (r.monthKind === 'last') return t('todo.repeat.summary_last_day');
        return dayOfMonth.value ? t('todo.repeat.summary_month_day', { n: dayOfMonth.value }) : '';
    }
    if (r.kind === 'custom') {
        const n = Number(r.interval);
        if (!Number.isInteger(n) || n < 1 || n > 99) return '';
        return t({ day: 'todo.repeat.summary_days', week: 'todo.repeat.summary_weeks', month: 'todo.repeat.summary_months' }[r.unit], n);
    }
    return '';
});
</script>

<template>
    <div class="repeat" data-testid="repeat-fields">
        <UiField :label="t('todo.repeat.label')" :hint="noDate ? t('todo.repeat.need_date') : ''" :error="errors.repeat">
            <UiSelect :model-value="modelValue.kind" :options="kindOptions" :disabled="disabled || noDate" @update:model-value="(v) => set({ kind: v })" />
        </UiField>

        <template v-if="modelValue.kind !== 'none' && !noDate">
            <fieldset v-if="modelValue.kind === 'weekly'" class="repeat__weekdays" :aria-describedby="errors.weekdays ? `${uid}-weekdays-error` : undefined">
                <legend class="repeat__legend">{{ t('todo.repeat.weekdays') }}</legend>
                <div class="repeat__chips">
                    <UiChip
                        v-for="(code, i) in WEEKDAYS"
                        :key="code"
                        class="repeat__chip"
                        :model-value="modelValue.weekdays.includes(code)"
                        :disabled="disabled"
                        :aria-label="longNames[i]"
                        @update:model-value="(on) => toggleDay(code, on)"
                    >{{ shortNames[i] }}</UiChip>
                </div>
                <p v-if="errors.weekdays" :id="`${uid}-weekdays-error`" class="repeat__error" role="alert">{{ errors.weekdays }}</p>
            </fieldset>

            <UiField v-if="modelValue.kind === 'monthly'" :label="t('todo.repeat.month_day')" :hint="t('todo.repeat.month_hint')">
                <UiSelect :model-value="modelValue.monthKind" :options="monthOptions" :disabled="disabled" @update:model-value="(v) => set({ monthKind: v })" />
            </UiField>

            <div v-if="modelValue.kind === 'custom'" class="repeat__custom">
                <UiField :label="t('todo.repeat.interval')" :error="errors.interval">
                    <UiInput :model-value="String(modelValue.interval)" type="number" inputmode="numeric" min="1" max="99" step="1" :readonly="disabled" @update:model-value="(v) => set({ interval: v })" />
                </UiField>
                <UiField :label="t('todo.repeat.unit')">
                    <UiSelect :model-value="modelValue.unit" :options="unitOptions" :disabled="disabled" @update:model-value="(v) => set({ unit: v })" />
                </UiField>
            </div>

            <fieldset v-if="showMode" class="repeat__from">
                <legend class="repeat__legend">{{ t('todo.repeat.from') }}</legend>
                <div class="repeat__radios">
                    <UiRadio :model-value="modelValue.mode" value="schedule" :name="`${uid}-from`" :disabled="disabled" @update:model-value="(v) => set({ mode: v })">{{ t('todo.repeat.from_schedule') }}</UiRadio>
                    <UiRadio :model-value="modelValue.mode" value="completion" :name="`${uid}-from`" :disabled="disabled" @update:model-value="(v) => set({ mode: v })">{{ t('todo.repeat.from_completion') }}</UiRadio>
                </div>
                <p class="repeat__hint">{{ t('todo.repeat.from_hint') }}</p>
            </fieldset>

            <p class="repeat__summary" aria-live="polite" data-testid="repeat-summary">{{ summary }}</p>
        </template>
    </div>
</template>

<style scoped>
.repeat { margin-bottom: var(--space-4); }
.repeat > :deep(.ui-field) { margin-bottom: var(--space-3); }
.repeat__weekdays, .repeat__from { border: 0; margin: 0 0 var(--space-3); padding: 0; min-width: 0; }
.repeat__legend { font-size: var(--text-sm); font-weight: 600; line-height: 1.4; padding: 0; margin-bottom: var(--space-1); }
.repeat__chips { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.repeat__chip { justify-content: center; min-width: 44px; min-height: 44px; }
.repeat__custom { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: var(--space-3); margin-bottom: var(--space-3); }
.repeat__radios { display: flex; flex-direction: column; gap: var(--space-2); }
.repeat__hint { margin: var(--space-1) 0 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.repeat__error { margin: var(--space-2) 0 0; font-size: var(--text-sm); color: var(--color-danger); }
.repeat__summary { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); min-height: 1.4em; }
</style>
