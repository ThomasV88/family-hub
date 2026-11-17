import { describe, it, expect } from 'vitest';
import { scaleQuantity, displayQuantity, displayNumber } from '../../../resources/js/Components/Recipes/recipeScale.js';

const unit = (key) => ({ key, names: null });
const LABELS = { en: { tsp: 'tsp', tbsp: 'tbsp', pcs: 'pcs', g: 'g', ml: 'ml' }, sk: { pcs: 'ks', g: 'g' } };
const tFor = (locale) => (key) => LABELS[locale][key.replace('shopping.unit.', '')];

describe('scaleQuantity', () => {
    it.each([
        [200, 4, 6, 300],
        [1, 4, 2, 0.5],
        ['200', 4, 8, 400],
        [null, 4, 6, null],
        [undefined, 4, 6, null],
        [200, null, 6, 200],
        [200, 0, 6, 200],
        [3, 4, 4, 3],
    ])('scaleQuantity(%s, %s, %s) = %s', (q, base, target, expected) => {
        expect(scaleQuantity(q, base, target)).toBe(expected);
    });
});

describe('displayQuantity', () => {
    it.each([
        [0.5, 'tsp', 'en', '1/2 tsp'],
        [1.5, null, 'en', '1 1/2'],
        [0.33, 'tbsp', 'en', '1/3 tbsp'],
        [0.25, 'tsp', 'en', '1/4 tsp'],
        [2 / 3, 'tsp', 'en', '2/3 tsp'],
        [0.75, null, 'en', '3/4'],
        [1.5, 'pcs', 'sk', '1,5 ks'],
        [1.5, 'pcs', 'en', '1.5 pcs'],
        [2, 'pcs', 'en', '2 pcs'],
        [1500, 'g', 'en', '1500 g'],
        [0.37, 'tsp', 'en', '0.37 tsp'],
        [0.5, 'g', 'en', '0.5 g'],
        [2, null, 'en', '2'],
        [0.333333, 'g', 'sk', '0,33 g'],
    ])('displayQuantity(%s, %s, %s) = %s', (value, key, locale, expected) => {
        expect(displayQuantity(value, key ? unit(key) : null, locale, tFor(locale === 'sk' ? 'sk' : 'en'))).toBe(expected);
    });

    it('shows the number alone without a translator', () => {
        expect(displayQuantity(0.5, unit('tsp'), 'en')).toBe('1/2');
    });

    it('never groups thousands and never rounds a tiny amount to zero', () => {
        expect(displayNumber(12000, unit('g'), 'en')).toBe('12000');
        expect(displayNumber(0.004, unit('g'), 'en')).toBe('0.004');
    });

    it('gives an empty text for a non-number', () => {
        expect(displayQuantity('x', unit('g'), 'en')).toBe('');
    });
});
