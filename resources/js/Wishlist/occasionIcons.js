import { Cake, CalendarHeart, Sparkles, TreePine } from '@lucide/vue';

// Type to icon component and accessible label key. The icon always travels with a text label (never colour alone).
export const OCCASION_ICONS = {
    birthday: Cake,
    nameday: Sparkles,
    christmas: TreePine,
    custom: CalendarHeart,
};

export const occasionIcon = (type) => OCCASION_ICONS[type] ?? CalendarHeart;
export const occasionTypeKey = (type) => `occasions.type.${OCCASION_ICONS[type] ? type : 'custom'}`;

/** A calendar date has no zone: format it as UTC so the day never shifts. */
export function formatYmd(ymd, locale, options = { weekday: 'short', day: 'numeric', month: 'short' }) {
    const [y, m, d] = ymd.split('-').map(Number);

    return new Intl.DateTimeFormat(locale, { ...options, timeZone: 'UTC' }).format(new Date(Date.UTC(y, m - 1, d)));
}

/** Day and month only (no year), for name days and birth dates seen by other members. */
export function formatDayMonth(month, day, locale) {
    return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, month - 1, day)));
}
