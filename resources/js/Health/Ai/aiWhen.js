import { INTL_LOCALES } from '../../i18n.js';

// The family time zone, the same one the rest of the app formats times in.
export const FAMILY_TIME_ZONE = 'Europe/Bratislava';

const dayKey = (date, timeZone) => new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);

// "{when}" of a waiting run: time only when it is today in the family time zone, otherwise a short weekday plus the
// time ("Tue 03:10"). The caller always wraps the result in <time datetime>. `now` is injectable for tests.
export function formatWhen(iso, locale = 'en', timeZone = FAMILY_TIME_ZONE, now = new Date()) {
    const date = new Date(iso ?? '');
    if (Number.isNaN(date.getTime())) return '';
    const tag = INTL_LOCALES[locale] ?? locale;
    const timeOptions = { hour: '2-digit', minute: '2-digit', timeZone, ...(locale === 'sk' || locale === 'pl' ? { hourCycle: 'h23' } : {}) };
    const today = dayKey(now, timeZone) === dayKey(date, timeZone);
    return new Intl.DateTimeFormat(tag, today ? timeOptions : { weekday: 'short', ...timeOptions }).format(date);
}

// True once the given resume time has passed (waiting with a past time reads "retrying soon").
export const isPast = (iso, now = new Date()) => {
    const t = new Date(iso ?? '').getTime();
    return !Number.isNaN(t) && t <= now.getTime();
};
