// Form state for the Repeat fields and its mapping to and from the server pattern.
// The UI never builds or reads an RRULE: the server owns the pattern shape (kind, interval, unit, weekdays, monthDay).

export const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

export const emptyRepeat = () => ({ kind: 'none', weekdays: [], monthKind: 'day', monthDay: null, interval: '2', unit: 'day', mode: 'schedule' });

/** @param {object|null|undefined} pattern board prop `recurrence` */
export function repeatFromTask(pattern, mode) {
    const state = { ...emptyRepeat(), mode: mode ?? 'schedule' };
    if (!pattern) return state;
    switch (pattern.kind) {
        case 'daily': return { ...state, kind: 'daily' };
        case 'weekly': return { ...state, kind: 'weekly', weekdays: [...(pattern.weekdays ?? [])] };
        case 'monthly_day': return { ...state, kind: 'monthly', monthKind: 'day', monthDay: pattern.monthDay ?? null };
        case 'monthly_last': return { ...state, kind: 'monthly', monthKind: 'last' };
        case 'custom': return { ...state, kind: 'custom', interval: String(pattern.interval), unit: pattern.unit, monthDay: pattern.monthDay ?? null };
        default: return state;
    }
}

export const intervalValid = (value) => /^\d{1,2}$/.test(String(value).trim()) && Number(value) >= 1 && Number(value) <= 99;

/** Pattern in the RecurrencePattern::toArray shape, or null for "does not repeat". dueDate is Y-m-d. */
export function patternFromRepeat(repeat, dueDate) {
    const day = dueDate ? Number(dueDate.slice(8, 10)) : null;
    switch (repeat.kind) {
        case 'daily': return { kind: 'daily', interval: 1 };
        case 'weekly': return { kind: 'weekly', interval: 1, weekdays: WEEKDAYS.filter((d) => repeat.weekdays.includes(d)) };
        case 'monthly': return repeat.monthKind === 'last' ? { kind: 'monthly_last', interval: 1 } : { kind: 'monthly_day', interval: 1, monthDay: day };
        case 'custom': return {
            kind: 'custom',
            interval: intervalValid(repeat.interval) ? Number(repeat.interval) : repeat.interval,
            unit: repeat.unit,
            ...(repeat.unit === 'month' ? { monthDay: day } : {}),
        };
        default: return null;
    }
}
