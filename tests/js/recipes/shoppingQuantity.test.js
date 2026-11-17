import { describe, it, expect } from 'vitest';
import { convertTo, formatForInput, toShopping } from '../../../resources/js/Components/Recipes/shoppingQuantity.js';

describe('toShopping', () => {
    it.each([
        [[200, 'g', 4, 6], { quantity: 300, unitKey: 'g', valid: true }],
        [[750, 'g', 4, 8], { quantity: 1.5, unitKey: 'kg', valid: true }],
        [[1000, 'g', 4, 4], { quantity: 1, unitKey: 'kg', valid: true }],
        [[999, 'g', 4, 4], { quantity: 999, unitKey: 'g', valid: true }],
        [[400, 'ml', 2, 5], { quantity: 1, unitKey: 'l', valid: true }],
        [[0.333, 'tsp', 1, 1], { quantity: 0.33, unitKey: 'tsp', valid: true }],
        [[0.335, 'tsp', 1, 1], { quantity: 0.34, unitKey: 'tsp', valid: true }],
        [[0.001, 'g', 1, 1], { quantity: 0.01, unitKey: 'g', valid: true }],
        [[600, 'pcs', 1, 2], { quantity: 1200, unitKey: 'pcs', valid: false }],
        [[600000, 'g', 1, 2], { quantity: 1200, unitKey: 'kg', valid: false }],
        [[null, 'g', 4, 8], { quantity: 1, unitKey: 'pcs', valid: true }],
        [[null, null, null, 2], { quantity: 1, unitKey: 'pcs', valid: true }],
        [[3, null, null, 8], { quantity: 3, unitKey: null, valid: true }],
        [[2, 'pack', 2, 3], { quantity: 3, unitKey: 'pack', valid: true }],
        [[1500, 'kg', 1, 1], { quantity: 1500, unitKey: 'kg', valid: false }],
    ])('toShopping(%j)', (args, expected) => {
        expect(toShopping(...args)).toEqual(expected);
    });
});

describe('convertTo', () => {
    it.each([
        [[500, 'g', 'kg'], 0.5],
        [[0.3, 'kg', 'g'], 300],
        [[250, 'ml', 'l'], 0.25],
        [[2, 'l', 'ml'], 2000],
        [[5, 'g', 'g'], 5],
        [[1, 'tbsp', 'tsp'], null],
        [[1, 'g', 'ml'], null],
        [[1, 'pcs', 'g'], null],
        [['x', 'g', 'kg'], null],
    ])('convertTo(%j)', (args, expected) => {
        expect(convertTo(...args)).toBe(expected);
    });
});

describe('formatForInput', () => {
    it('uses the decimal comma of the locale', () => {
        expect(formatForInput(1.5, 'pl')).toBe('1,5');
        expect(formatForInput(1.5, 'sk')).toBe('1,5');
        expect(formatForInput(1.5, 'en')).toBe('1.5');
        expect(formatForInput(1200, 'en')).toBe('1200');
    });
});
