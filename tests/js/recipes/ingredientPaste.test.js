import { describe, it, expect } from 'vitest';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { MAX_LINES, parseIngredientLines } from '../../../resources/js/Components/Recipes/ingredientPaste.js';

const UNITS = [
    { id: 1, key: 'pcs', names: null }, { id: 2, key: 'g', names: null }, { id: 3, key: 'kg', names: null }, { id: 4, key: 'ml', names: null },
    { id: 5, key: 'l', names: null }, { id: 6, key: 'pack', names: null }, { id: 7, key: 'bunch', names: null }, { id: 8, key: 'tbsp', names: null },
    { id: 9, key: 'tsp', names: null }, { id: 10, key: null, names: { en: 'cup', sk: 'šálka', pl: 'szklanka' } },
];
const parse = (text, locale = 'en') => {
    const i18n = createAppI18n(locale, loadMessages(locale));

    return parseIngredientLines(text, { units: UNITS, t: i18n.global.t, locale });
};

describe('parseIngredientLines', () => {
    it('reads quantity, unit and name', () => {
        expect(parse('200 g flour')).toEqual([{ quantity: 200, unitId: 2, name: 'flour' }]);
        expect(parse('200 g múka', 'sk')).toEqual([{ quantity: 200, unitId: 2, name: 'múka' }]);
        expect(parse('200g flour')).toEqual([{ quantity: 200, unitId: 2, name: 'flour' }]);
        expect(parse('1/2 tsp salt')).toEqual([{ quantity: 0.5, unitId: 9, name: 'salt' }]);
        expect(parse('1 1/2 l milk')).toEqual([{ quantity: 1.5, unitId: 5, name: 'milk' }]);
        expect(parse('½ tbsp sugar')).toEqual([{ quantity: 0.5, unitId: 8, name: 'sugar' }]);
        expect(parse('0,5 kg mäso', 'sk')).toEqual([{ quantity: 0.5, unitId: 3, name: 'mäso' }]);
    });

    it('matches localised unit words and labels', () => {
        expect(parse('2 lyžice cukru', 'sk')).toEqual([{ quantity: 2, unitId: 8, name: 'cukru' }]);
        expect(parse('2 ks vajcia', 'sk')).toEqual([{ quantity: 2, unitId: 1, name: 'vajcia' }]);
        expect(parse('3 łyżki cukru', 'pl')).toEqual([{ quantity: 3, unitId: 8, name: 'cukru' }]);
        expect(parse('1 šálka ryže', 'sk')).toEqual([{ quantity: 1, unitId: 10, name: 'ryže' }]);
        expect(parse('2 szt. jajka', 'pl')).toEqual([{ quantity: 2, unitId: 1, name: 'jajka' }]);
    });

    it('keeps unknown parts in the name', () => {
        expect(parse('3 eggs')).toEqual([{ quantity: 3, unitId: null, name: 'eggs' }]);
        expect(parse('soľ podľa chuti', 'sk')).toEqual([{ quantity: null, unitId: null, name: 'soľ podľa chuti' }]);
        expect(parse('salt to taste')).toEqual([{ quantity: null, unitId: null, name: 'salt to taste' }]);
        expect(parse('200 g')).toEqual([{ quantity: null, unitId: null, name: '200 g' }]);
        expect(parse('0 g flour')).toEqual([{ quantity: null, unitId: null, name: '0 g flour' }]);
        expect(parse('2/0 g flour')).toEqual([{ quantity: null, unitId: null, name: '2/0 g flour' }]);
    });

    it('skips blank lines and list bullets', () => {
        expect(parse('\n- 1 l milk\r\n\n   \n* flour\n')).toEqual([
            { quantity: 1, unitId: 5, name: 'milk' },
            { quantity: null, unitId: null, name: 'flour' },
        ]);
    });

    it('caps the number of lines', () => {
        const text = Array.from({ length: MAX_LINES + 1 }, (_, i) => `${i + 1} g item ${i}`).join('\n');
        expect(parse(text)).toHaveLength(MAX_LINES);
    });

    it('cuts a name at the limit and leaves markup as text', () => {
        expect(parse(`1 g ${'x'.repeat(200)}`)[0].name).toHaveLength(120);
        expect(parse('<b>2</b> g')[0].name).toBe('<b>2</b> g');
    });
});
