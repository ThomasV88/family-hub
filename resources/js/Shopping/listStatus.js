import { Archive, Lock } from '@lucide/vue';

// Shared by every Shopping screen: the state tag of a list and the reminder helpers. Status never relies on colour alone,
// each tag has a text label (and an icon for Locked and Finished).

/** The neutral tag for a list: { key (lang key), icon|null, variant }. */
export function statusTag(status) {
    if (status === 'locked') return { key: 'shopping.status.locked', icon: Lock, variant: 'neutral' };
    if (status === 'done') return { key: 'shopping.status.finished', icon: Archive, variant: 'neutral' };

    return { key: 'shopping.status.open', icon: null, variant: 'neutral' };
}

/** The warn tag "Reminder passed", only for a list that is not finished yet. Null otherwise. */
export function reminderTag(list) {
    if (list?.reminderPassed === true && list.status !== 'done') return { key: 'shopping.status.reminder_passed', icon: null, variant: 'warn' };

    return null;
}

/** "20 Nov, 18:00" for a reminder, "20 Nov 2026" for a finish date. Null or an unparsable value gives ''. */
export function formatListDate(iso, locale, { time = false } = {}) {
    const date = iso ? new Date(iso) : null;
    if (!date || Number.isNaN(date.getTime())) return '';
    const options = time ? { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' } : { dateStyle: 'medium' };

    return new Intl.DateTimeFormat(locale, options).format(date);
}

/** Lower-case, accent-free text for searching ("Týždenný" matches "tyzdenny"). */
export function plain(text) {
    return String(text ?? '').normalize('NFD').replace(/\p{M}/gu, '').replace(/ł/gi, 'l').toLowerCase();
}

/** First word of a person's name, for the compact row. */
export const firstName = (name) => String(name ?? '').trim().split(/\s+/)[0] ?? '';
