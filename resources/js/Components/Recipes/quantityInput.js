import { INTL_LOCALES } from '../../i18n.js';
import { localeCode } from '../../Shopping/units.js';

// Quantity text of an ingredient row: decimal comma or dot, "a/b", "n a/b" and vulgar fractions ("1 1/2", "1¼", "½").
// The server normalises the same forms again (StoreRecipeRequest::normaliseQuantity); this only gives the member an
// instant answer and keeps the request clean.

export const QUANTITY_MAX = 9999;

const VULGAR = {
    '½': 1 / 2, '⅓': 1 / 3, '⅔': 2 / 3, '¼': 1 / 4, '¾': 3 / 4, '⅕': 1 / 5, '⅖': 2 / 5, '⅗': 3 / 5, '⅘': 4 / 5,
    '⅙': 1 / 6, '⅚': 5 / 6, '⅛': 1 / 8, '⅜': 3 / 8, '⅝': 5 / 8, '⅞': 7 / 8,
};
const VULGAR_CLASS = Object.keys(VULGAR).join('');

const round2 = (n) => Math.round((n + Number.EPSILON) * 100) / 100;
const INVALID = Object.freeze({ value: null, valid: false });

/**
 * { value, valid }: empty text is { value: null, valid: true } ("to taste"); a number is rounded to 2 decimals; anything
 * else (exponent, zero, negative, over 9999, 1/0, words) is { value: null, valid: false }. Never NaN.
 *
 * "1,000": a comma followed by exactly three digits is ambiguous. Quantities never exceed 9999, so it is read by the
 * language of the reader: English uses the comma as a thousands separator ("1,000 g" = 1000 g, "9,999" = 9999), Slovak
 * and Polish use it as the decimal comma ("1,000" = 1). Any other comma form ("0,5", "1,25", "1,5") is a decimal comma in
 * every language. A dot is always a decimal point.
 */
export function parseQuantity(text, locale = 'en') {
    const raw = String(text ?? '').trim().replace(/\s+/g, ' ');
    if (raw === '') return { value: null, valid: true };

    let n;
    let m;
    if (localeCode(locale) === 'en' && (m = raw.match(/^(\d{1,3}),(\d{3})$/))) {
        n = Number(`${m[1]}${m[2]}`);
    } else if ((m = raw.match(/^(\d+)(?:[.,](\d+))?$/))) {
        n = Number(`${m[1]}.${m[2] ?? '0'}`);
    } else if ((m = raw.match(/^(\d+)\/(\d+)$/))) {
        n = Number(m[2]) === 0 ? NaN : Number(m[1]) / Number(m[2]);
    } else if ((m = raw.match(/^(\d+) (\d+)\/(\d+)$/))) {
        n = Number(m[3]) === 0 ? NaN : Number(m[1]) + Number(m[2]) / Number(m[3]);
    } else if ((m = raw.match(new RegExp(`^(?:(\\d+) ?)?([${VULGAR_CLASS}])$`)))) {
        n = Number(m[1] ?? 0) + VULGAR[m[2]];
    } else {
        return INVALID;
    }
    if (!Number.isFinite(n)) return INVALID;
    const value = round2(n);

    return value > 0 && value <= QUANTITY_MAX ? { value, valid: true } : INVALID;
}

/** 1.5 -> "1,5" in Slovak and Polish, "1.5" in English; whole numbers without decimals; null or empty -> "". */
export function formatQuantityInput(value, locale) {
    if (value === null || value === undefined || value === '') return '';
    const n = Number(value);
    if (!Number.isFinite(n)) return '';

    return new Intl.NumberFormat(INTL_LOCALES[localeCode(locale)], { maximumFractionDigits: 2, useGrouping: false }).format(n);
}

/** The value sent to the server: a plain dot decimal string, or null for an empty or invalid text. */
export function quantityForRequest(text, locale = 'en') {
    const { value, valid } = parseQuantity(text, locale);

    return valid && value !== null ? String(value) : null;
}
