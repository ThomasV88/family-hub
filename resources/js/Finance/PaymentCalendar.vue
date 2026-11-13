<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { AlertCircle, AlertTriangle, Check, Clock } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { INTL_LOCALES } from '../i18n.js';
import { formatDay } from './labels.js';

// Month grid of payments. Days, counts and statuses come from the server (`recurring.calendar`); nothing is computed
// here beyond layout. One Tab stop (roving tabindex); arrows move by day and week, Home/End within the week,
// PageUp/PageDown ask the parent for the previous or next month.
const props = defineProps({
    // 'YYYY-MM'
    month: { type: String, required: true },
    today: { type: String, required: true },
    // [{ date, occurrences: Occ[] }] for every day of the month
    days: { type: Array, default: () => [] },
    selected: { type: String, default: null },
});
const emit = defineEmits(['open', 'month']);
const { t, locale } = useI18n();

const intl = computed(() => INTL_LOCALES[locale.value] ?? locale.value);
const byDate = computed(() => Object.fromEntries(props.days.map((d) => [d.date, d.occurrences ?? []])));

// Week start (0 = Monday .. 6 = Sunday) from Intl week info, Monday when unavailable.
const weekStart = computed(() => {
    try {
        const info = new Intl.Locale(intl.value);
        const first = (typeof info.getWeekInfo === 'function' ? info.getWeekInfo() : info.weekInfo)?.firstDay;
        return Number.isInteger(first) ? (first - 1 + 7) % 7 : 0;
    } catch {
        return 0;
    }
});

const weekdays = computed(() => {
    const short = new Intl.DateTimeFormat(intl.value, { weekday: 'short', timeZone: 'UTC' });
    const long = new Intl.DateTimeFormat(intl.value, { weekday: 'long', timeZone: 'UTC' });
    return Array.from({ length: 7 }, (_, i) => {
        const date = new Date(Date.UTC(2024, 0, 1 + ((weekStart.value + i) % 7)));
        return { short: short.format(date), long: long.format(date) };
    });
});

const weeks = computed(() => {
    const [y, m] = props.month.split('-').map(Number);
    const first = (new Date(Date.UTC(y, m - 1, 1)).getUTCDay() + 6) % 7;
    const lead = (first - weekStart.value + 7) % 7;
    const count = new Date(Date.UTC(y, m, 0)).getUTCDate();
    const cells = [...Array.from({ length: lead }, () => null)];
    for (let d = 1; d <= count; d += 1) cells.push(`${props.month}-${String(d).padStart(2, '0')}`);
    while (cells.length % 7 !== 0) cells.push(null);
    return Array.from({ length: cells.length / 7 }, (_, i) => cells.slice(i * 7, i * 7 + 7));
});
const allDates = computed(() => weeks.value.flat().filter(Boolean));

const ORDER = ['overdue', 'upcoming', 'paid_differently', 'paid'];
const ICONS = { overdue: AlertCircle, upcoming: Clock, paid_differently: AlertTriangle, paid: Check };
const worst = (list) => ORDER.find((s) => list.some((o) => o.status === s)) ?? null;

const focusDate = ref(null);
function defaultFocus() {
    if (props.selected && allDates.value.includes(props.selected)) return props.selected;
    if (allDates.value.includes(props.today)) return props.today;
    return allDates.value[0] ?? null;
}
watch(() => [props.month, props.selected], () => { focusDate.value = defaultFocus(); }, { immediate: true });
const tabbable = (date) => (focusDate.value ?? defaultFocus()) === date;

const buttons = {};
const setButton = (date) => (el) => { if (el) buttons[date] = el; else delete buttons[date]; };

function label(date) {
    const list = byDate.value[date] ?? [];
    const day = formatDay(date, intl.value, { weekday: 'long', day: 'numeric', month: 'long' });
    if (list.length === 0) return t('finance.cal.cell_none', { date: day });
    const overdue = list.filter((o) => o.status === 'overdue').length;
    return t('finance.cal.cell', { date: day }, list.length) + (overdue > 0 ? t('finance.cal.cell_overdue', { n: overdue }) : '');
}

function move(date, key) {
    const index = allDates.value.indexOf(date);
    const rowStart = allDates.value.indexOf(weeks.value.find((w) => w.includes(date)).find(Boolean));
    const rowEnd = allDates.value.indexOf([...weeks.value.find((w) => w.includes(date))].reverse().find(Boolean));
    const targets = {
        ArrowRight: index + 1, ArrowLeft: index - 1, ArrowDown: index + 7, ArrowUp: index - 7, Home: rowStart, End: rowEnd,
    };
    if (!(key in targets)) return null;
    return allDates.value[Math.min(allDates.value.length - 1, Math.max(0, targets[key]))];
}
function onKeydown(event, date) {
    if (event.key === 'PageUp' || event.key === 'PageDown') {
        event.preventDefault();
        emit('month', event.key === 'PageUp' ? -1 : 1);
        return;
    }
    const target = move(date, event.key);
    if (!target) return;
    event.preventDefault();
    focusDate.value = target;
    nextTick(() => buttons[target]?.focus());
}

const dayNumber = (date) => Number(date.slice(8, 10));
</script>

<template>
    <div class="cal" data-testid="payment-calendar">
        <table class="cal__table" :aria-label="t('finance.cal.label', { month: formatDay(`${month}-01`, intl, { month: 'long', year: 'numeric' }) })">
            <thead>
                <tr>
                    <th v-for="w in weekdays" :key="w.long" scope="col" class="cal__head"><abbr :title="w.long">{{ w.short }}</abbr></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(week, wi) in weeks" :key="wi">
                    <td v-for="(date, di) in week" :key="di" class="cal__td">
                        <button
                            v-if="date"
                            :ref="setButton(date)"
                            type="button"
                            :class="['cal__day', { 'is-today': date === today, 'is-selected': date === selected, 'is-overdue': (byDate[date] ?? []).some((o) => o.status === 'overdue') }]"
                            :tabindex="tabbable(date) ? 0 : -1"
                            :aria-label="label(date)"
                            :aria-current="date === today ? 'date' : undefined"
                            :data-date="date"
                            @click="focusDate = date; emit('open', date)"
                            @keydown="onKeydown($event, date)"
                        >
                            <span class="cal__num">{{ dayNumber(date) }}</span>
                            <template v-if="(byDate[date] ?? []).length">
                                <span :class="['cal__chip', `cal__chip--${worst(byDate[date])}`]" aria-hidden="true">
                                    <component :is="ICONS[worst(byDate[date])]" :size="14" :stroke-width="1.75" />
                                    {{ byDate[date].length }}
                                </span>
                                <span class="cal__names" aria-hidden="true">
                                    <span class="cal__name">{{ byDate[date][0].name }}</span>
                                    <span v-if="byDate[date].length > 1" class="cal__more">{{ t('finance.cal.more', { n: byDate[date].length - 1 }) }}</span>
                                </span>
                            </template>
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
.cal { container-type: inline-size; min-width: 0; }
.cal__table { width: 100%; table-layout: fixed; border-collapse: separate; border-spacing: 2px; }
.cal__head { padding: var(--space-1); font-size: var(--text-sm); font-weight: 600; text-align: center; }
.cal__head abbr { text-decoration: none; }
.cal__td { padding: 0; vertical-align: top; }
.cal__day {
    display: flex; flex-direction: column; align-items: flex-start; gap: 2px; width: 100%; min-height: 44px; padding: var(--space-1);
    border: 1px solid var(--color-border); border-radius: var(--radius-md); background: var(--color-surface); color: var(--color-text);
    font: inherit; font-size: var(--text-sm); text-align: start; cursor: pointer; overflow: hidden;
}
.cal__day:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 1px; }
.cal__day.is-selected { background: var(--color-surface-2); border: 2px solid var(--color-border-strong); }
.cal__num { display: inline-flex; align-items: center; justify-content: center; min-width: 24px; height: 24px; border-radius: var(--radius-full); font-weight: 600; }
.is-today .cal__num { border: 1px solid var(--color-border-strong); }
.cal__chip { display: inline-flex; align-items: center; gap: 2px; font-weight: 600; color: var(--color-text-muted); }
.cal__chip--overdue { color: var(--color-danger); }
.cal__chip--paid_differently { color: var(--color-warn-text); }
.cal__names { display: none; flex-direction: column; min-width: 0; max-width: 100%; font-size: var(--text-sm); }
.cal__name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cal__more { color: var(--color-text-muted); }
@container (min-width: 560px) {
    .cal__day { min-height: 76px; }
    .cal__names { display: flex; }
}
</style>
