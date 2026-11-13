import { INTL_LOCALES } from '../i18n.js';

// Explicit currency exponent table (HUF/JPY intentionally not allowlisted).
export const CURRENCIES = { EUR: 2, CZK: 2, PLN: 2, USD: 2, GBP: 2 };

const MINUS = '−';
const MAX_INT_DIGITS = 13;

export function exponentOf(code) {
    return CURRENCIES[code] ?? 2;
}

// Integer minor-unit string to a plain decimal string; string arithmetic only (exact above 2^53).
export function toDecimalString(minor, exponent) {
    const raw = String(minor);
    const negative = raw.startsWith('-');
    const digits = (negative ? raw.slice(1) : raw).replace(/^0+(?=\d)/, '');
    const sign = negative && /[1-9]/.test(digits) ? '-' : '';
    if (exponent === 0) return sign + digits;
    const padded = digits.padStart(exponent + 1, '0');
    return `${sign}${padded.slice(0, padded.length - exponent)}.${padded.slice(padded.length - exponent)}`;
}

function intlLocale(locale) {
    return INTL_LOCALES[locale] ?? locale;
}

export function formatMinor(minor, currency, locale, { signed = false } = {}) {
    const exp = exponentOf(currency);
    const decimal = toDecimalString(minor, exp);
    const negative = decimal.startsWith('-');
    const abs = negative ? decimal.slice(1) : decimal;
    const nf = new Intl.NumberFormat(intlLocale(locale), {
        style: 'currency',
        currency,
        minimumFractionDigits: exp,
        maximumFractionDigits: exp,
        signDisplay: 'never',
    });
    let parts;
    try {
        parts = nf.formatToParts(abs);
    } catch {
        // Fallback: format integer and fraction separately (no string support in Intl).
        const [int, frac = ''] = abs.split('.');
        const ni = new Intl.NumberFormat(intlLocale(locale), { useGrouping: true, maximumFractionDigits: 0 });
        const fi = ni.formatToParts(1.1).find((p) => p.type === 'decimal')?.value ?? '.';
        const body = ni.format(BigInt(int)) + (exp ? fi + frac : '');
        parts = [{ type: 'integer', value: body }, { type: 'literal', value: ' ' }, { type: 'currency', value: currency }];
    }
    const out = parts.map((p) => p.value).join('');
    const isZero = !/[1-9]/.test(abs);
    if (negative && !isZero) return MINUS + out;
    if (signed && !isZero) return '+' + out;
    return out;
}

// Typed amount to an integer minor string, or null. Mirrors App\Finance\MoneyParser::manual.
export function parseMoney(input, exponent, { allowNegative = false } = {}) {
    let s = String(input ?? '').replace(/[\s  ]/g, '').replace(/−/g, '-');
    let negative = false;
    if (s.startsWith('-')) {
        if (!allowNegative) return null;
        negative = true;
        s = s.slice(1);
    }
    if (!/^\d*[.,]?\d*$/.test(s) || s === '') return null;
    const hasSep = /[.,]/.test(s);
    const [intPart, frac = ''] = s.split(/[.,]/);
    if (intPart === '' && frac === '') return null;
    if (hasSep && frac === '') return null;
    if (frac.length > exponent) return null;
    const int = intPart.replace(/^0+/, '');
    if (int.length > MAX_INT_DIGITS) return null;
    const digits = (int + frac.padEnd(exponent, '0')).replace(/^0+/, '');
    if (digits === '') return null;
    if (digits.length > MAX_INT_DIGITS + exponent) return null;
    if (BigInt(digits) > 10n ** 13n) return null;
    return (negative ? '-' : '') + digits;
}

// Minor string to an editable decimal using the locale's separator, no grouping.
export function toInputString(minor, exponent, locale) {
    const decimal = toDecimalString(minor, exponent);
    const code = String(locale ?? 'en').slice(0, 2);
    return code === 'sk' || code === 'pl' ? decimal.replace('.', ',') : decimal;
}
