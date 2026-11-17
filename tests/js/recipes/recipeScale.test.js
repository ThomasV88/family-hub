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

describe('friendly fractions after scaling (thirds, sixths, eighths snap to what they stand for)', () => {
    const show = (stored, base, target, key = 'tsp', locale = 'en') => displayQuantity(scaleQuantity(stored, base, target), key ? unit(key) : null, locale, tFor(locale === 'sk' ? 'sk' : 'en'));

    it.each([
        [0.33, 1, 3, 'tsp', '1 tsp'], // 1/3 x 3 was "0.99 tsp"
        [0.67, 1, 3, 'tbsp', '2 tbsp'], // 2/3 x 3 was "2.01 tbsp"
        [0.33, 1, 12, 'tsp', '4 tsp'], // rounding drift of the stored 0.33 does not grow with the factor
        [0.33, 1, 2, 'tsp', '2/3 tsp'],
        [0.17, 1, 3, 'tsp', '1/2 tsp'], // 1/6 x 3
        [0.83, 1, 6, null, '5'],
        [0.13, 1, 2, 'tsp', '1/4 tsp'], // 1/8 x 2
        [0.38, 1, 2, 'tbsp', '3/4 tbsp'], // 3/8 x 2
        [0.88, 1, 1, 'tsp', '0.88 tsp'], // the recipe's own servings: exactly what the author wrote
        [0.88, 2, 4, 'tsp', '1 3/4 tsp'], // 7/8 x 2
        [0.33, 1, 3, 'pcs', '1 pcs'], // no fractions for pieces, but the whole number still snaps
        [0.33, 1, 3, 'g', '1 g'],
    ])('%s x %s -> %s %s = "%s"', (stored, base, target, key, expected) => {
        expect(show(stored, base, target, key)).toBe(expected);
    });

    it('keeps real decimals and large numbers untouched', () => {
        expect(show(0.36, 4, 4, 'tsp')).toBe('0.36 tsp');
        expect(show(125, 4, 6, 'g')).toBe('187.5 g');
        expect(show(250, 4, 5, 'g')).toBe('312.5 g');
        expect(displayNumber(249.995, unit('g'), 'en')).toBe('250');
    });
});
