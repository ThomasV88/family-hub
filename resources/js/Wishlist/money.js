import { INTL_LOCALES } from '../i18n.js';

// Prices arrive from the server as decimal strings ("12.50"); the UI only formats them, it never sums floats.
export function formatMoney(price, currency, locale = 'en') {
    if (price === null || price === undefined || price === '') return '';
    const amount = Number(price);
    if (!Number.isFinite(amount)) return '';
    try {
        return new Intl.NumberFormat(INTL_LOCALES[locale] ?? locale, { style: 'currency', currency }).format(amount);
    } catch {
        return `${price} ${currency}`;
    }
}

// Same rule as the server (config wishlist price regex): up to 9 digits, optional comma or dot with 1 or 2 decimals.
const PRICE = /^\d{1,9}([.,]\d{1,2})?$/;
export const isValidPrice = (value) => PRICE.test(String(value ?? '').trim());

// "12,5" becomes "12.5" for the request; the server converts to minor units.
export const normalizePrice = (value) => String(value ?? '').trim().replace(',', '.');

export function hostOf(url) {
    try {
        return new URL(url).host.toLowerCase();
    } catch {
        return '';
    }
}

export const isHttpUrl = (value) => {
    try {
        const { protocol } = new URL(String(value).trim());
        return protocol === 'http:' || protocol === 'https:';
    } catch {
        return false;
    }
};
