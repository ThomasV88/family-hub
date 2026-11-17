import { describe, it, expect } from 'vitest';
import { formatQuantityInput, parseQuantity, quantityForRequest } from '../../../resources/js/Components/Recipes/quantityInput.js';

describe('parseQuantity', () => {
    it.each([
        ['0,5', 0.5], ['0.5', 0.5], ['1/2', 0.5], ['1 1/2', 1.5], ['½', 0.5], ['1¼', 1.25], ['1 ½', 1.5], ['⅓', 0.33],
        ['2.25', 2.25], ['  3  ', 3], ['9999', 9999], ['2/3', 0.67], ['0,01', 0.01], ['1.005', 1.01],
    ])('%s -> %s', (text, value) => {
        expect(parseQuantity(text)).toEqual({ value, valid: true });
    });

    it.each([[''], ['   '], [null], [undefined]])('empty %j is valid with no value', (text) => {
        expect(parseQuantity(text)).toEqual({ value: null, valid: true });
    });

    it.each([['1e3'], ['0'], ['0,00'], ['-1'], ['10000'], ['9999.01'], ['1/0'], ['abc'], ['1,2,3'], ['1/2/3'], ['Infinity'], ['0.001'], ['1 1/0']])(
        '%s is invalid and never NaN',
        (text) => {
            expect(parseQuantity(text)).toEqual({ value: null, valid: false });
        },
    );
});

describe('formatQuantityInput', () => {
    it('uses the locale decimal separator', () => {
        expect(formatQuantityInput(1.5, 'sk')).toBe('1,5');
        expect(formatQuantityInput('0.25', 'pl')).toBe('0,25');
        expect(formatQuantityInput(1.5, 'en')).toBe('1.5');
        expect(formatQuantityInput(2, 'en')).toBe('2');
        expect(formatQuantityInput(1200, 'sk')).toBe('1200');
    });
    it('gives an empty string for nothing', () => {
        expect(formatQuantityInput(null, 'en')).toBe('');
        expect(formatQuantityInput('', 'sk')).toBe('');
    });
});

describe('quantityForRequest', () => {
    it('is a dot decimal string or null', () => {
        expect(quantityForRequest('1 1/2')).toBe('1.5');
        expect(quantityForRequest('0,5')).toBe('0.5');
        expect(quantityForRequest('')).toBeNull();
        expect(quantityForRequest('x')).toBeNull();
    });
});

describe('parseQuantity: "1,000" by the language of the reader', () => {
    it.each([['1,000', 1000], ['9,999', 9999], ['2,500', 2500], ['12,345', null]])('en %s -> %s (a thousands comma)', (text, value) => {
        expect(parseQuantity(text, 'en')).toEqual(value === null ? { value: null, valid: false } : { value, valid: true });
    });

    it.each(['sk', 'pl'])('%s reads "1,000" as a decimal comma = 1, and "0,250" as 0.25', (locale) => {
        expect(parseQuantity('1,000', locale)).toEqual({ value: 1, valid: true });
        expect(parseQuantity('0,250', locale)).toEqual({ value: 0.25, valid: true });
    });

    it('keeps every other comma form a decimal comma in all languages and the dot a decimal point', () => {
        for (const locale of ['en', 'sk', 'pl']) {
            expect(parseQuantity('1,5', locale)).toEqual({ value: 1.5, valid: true });
            expect(parseQuantity('1,25', locale)).toEqual({ value: 1.25, valid: true });
            expect(parseQuantity('1.250', locale)).toEqual({ value: 1.25, valid: true });
        }
    });

    it('sends 1000 for "1,000" typed in English', () => {
        expect(quantityForRequest('1,000', 'en')).toBe('1000');
        expect(quantityForRequest('1,000', 'sk')).toBe('1');
    });
});
