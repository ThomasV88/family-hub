// Pure helper for the due tag on a task card. Due dates are family wall-clock values (no instant),
// so everything is compared as local strings in the family time zone; "today" comes from the server.

const pad = (n) => String(n).padStart(2, '0');

function addDays(ymd, days) {
    const [y, m, d] = ymd.split('-').map(Number);
    const date = new Date(Date.UTC(y, m - 1, d + days));
    return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`;
}

/** Local wall-clock "YYYY-MM-DDTHH:mm" of an instant in the given zone. */
function wallClock(nowIso, timezone) {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(new Date(nowIso));
    const get = (type) => parts.find((p) => p.type === type).value;

    return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
}

/** The calendar date itself has no zone: format it as UTC so the day never shifts. */
export function formatDate(ymd, locale) {
    const [y, m, d] = ymd.split('-').map(Number);

    return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(y, m - 1, d)));
}

/**
 * @returns {{kind: 'none'|'today'|'tomorrow'|'on'|'overdue', tone: 'none'|'neutral'|'warn'|'danger', date: string|null, time: string|null}}
 * A date-only task is overdue only after the end of its local day; a timed task after its minute.
 */
export function dueInfo({ dueDate, dueTime }, today, timezone, nowIso, locale) {
    if (!dueDate) return { kind: 'none', tone: 'none', date: null, time: null };

    const time = dueTime ? dueTime.slice(0, 5) : null;
    const date = formatDate(dueDate, locale);
    const now = wallClock(nowIso, timezone);
    const overdue = time ? `${dueDate}T${time}` < now : dueDate < today;

    if (overdue) return { kind: 'overdue', tone: 'danger', date, time };
    if (dueDate === today) return { kind: 'today', tone: 'warn', date, time };
    if (dueDate === addDays(today, 1)) return { kind: 'tomorrow', tone: 'neutral', date, time };

    return { kind: 'on', tone: 'neutral', date, time };
}
