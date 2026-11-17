import { INTL_LOCALES } from '../i18n.js';

// Unit labels and quantity formatting shared by every Shopping screen.
// A unit is { key, names } as in the Settings payload: a built-in unit has a key (its label comes from the language files,
// with plural forms) and no names; an administrator unit has names { en, sk, pl } and no key.

/** 'sk-SK' (the vue-i18n locale) or 'sk' (the app locale) -> 'sk'. Anything unknown -> 'en'. */
export function localeCode(locale) {
    const code = String(locale ?? 'en').slice(0, 2).toLowerCase();

    return INTL_LOCALES[code] ? code : 'en';
}

const isWhole = (n) => Number.isInteger(n);

/** The unit's label for a quantity: ICU plural for built-in units, the name of the current locale for custom ones. */
export function unitLabel(unit, quantity, t, locale) {
    if (!unit) return '';
    if (unit.key) {
        const n = Number(quantity);
        // A fraction always takes the last plural form ("1,5 balení"), as in Slovak and Polish usage.
        return t(`shopping.unit.${unit.key}`, Number.isFinite(n) && isWhole(n) ? Math.abs(n) : 2.5);
    }
    const code = localeCode(locale);

    return unit.names?.[code] ?? unit.names?.en ?? '';
}

/**
 * "3 pcs", "1,5 l", "5 balení". Quantity 1 of "pcs" (or of no unit) gives an empty string: the list shows "Milk", not
 * "1 pcs Milk". The number follows the locale (decimal comma in Slovak and Polish), up to 2 decimals.
 */
export function formatQuantity(quantity, unit, t, locale) {
    const n = Number(quantity);
    if (!Number.isFinite(n) || n <= 0) return '';
    if (n === 1 && (!unit || unit.key === 'pcs')) return '';
    const number = new Intl.NumberFormat(INTL_LOCALES[localeCode(locale)], { maximumFractionDigits: 2 }).format(n);
    const label = unitLabel(unit, n, t, locale);

    return label ? `${number} ${label}` : number;
}
