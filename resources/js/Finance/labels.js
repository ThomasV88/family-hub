import { Banknote, CreditCard, Landmark, PiggyBank } from '@lucide/vue';

const ICONS = { bank: Landmark, cash: Banknote, savings: PiggyBank, card: CreditCard };

export const accountTypeIcon = (type) => ICONS[type] ?? Landmark;

// Family category name: custom text wins, then the localized default, then "Uncategorized" for none.
export function categoryLabel(category, t) {
    if (!category) return t('finance.tx.uncategorized');
    if (category.name) return category.name;
    if (category.defaultKey) return t(`finance.category.default.${category.defaultKey}`);
    return t('finance.tx.uncategorized');
}

// Long weekday and date for a Y-m-d string, independent of the viewer's time zone.
export function formatDay(iso, intlLocale, options = { weekday: 'long', day: 'numeric', month: 'long' }) {
    const [y, m, d] = iso.split('-').map(Number);
    return new Intl.DateTimeFormat(intlLocale, { ...options, timeZone: 'UTC' }).format(new Date(Date.UTC(y, m - 1, d)));
}
