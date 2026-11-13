import { describe, it, expect } from 'vitest';
import { formatMoney, isValidPrice, normalizePrice, isHttpUrl } from '../../../resources/js/Wishlist/money.js';

describe('money helpers', () => {
    it('formats with the wish currency in the active locale', () => {
        expect(formatMoney('12.50', 'EUR', 'sk')).toBe(new Intl.NumberFormat('sk-SK', { style: 'currency', currency: 'EUR' }).format(12.5));
        expect(formatMoney('12.50', 'PLN', 'pl')).toContain('12,50');
        expect(formatMoney('12.50', 'EUR', 'en')).toContain('12.50');
    });
    it('returns an empty string for a missing price', () => {
        expect(formatMoney(null, 'EUR', 'en')).toBe('');
        expect(formatMoney('', 'EUR', 'en')).toBe('');
    });
    it('validates the same shape as the server', () => {
        for (const ok of ['12,5', '12.50', '0', '7']) expect(isValidPrice(ok)).toBe(true);
        for (const bad of ['12.345', '-1', '1e3', 'abc', '']) expect(isValidPrice(bad)).toBe(false);
    });
    it('normalises the decimal comma', () => {
        expect(normalizePrice(' 12,5 ')).toBe('12.5');
    });
    it('accepts only http and https URLs', () => {
        expect(isHttpUrl('https://shop.example/x')).toBe(true);
        expect(isHttpUrl('javascript:alert(1)')).toBe(false);
        expect(isHttpUrl('ftp://x.test')).toBe(false);
        expect(isHttpUrl('nope')).toBe(false);
    });
});
