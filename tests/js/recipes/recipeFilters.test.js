import { describe, it, expect } from 'vitest';
import { activeChips, activeCount, cleared, emptyFilters, normaliseFilters, toQuery, totalMinutes, withoutChip } from '../../../resources/js/Components/Recipes/recipeFilters.js';

const t = (key, p = {}) => `${key}${Object.values(p).length ? `(${Object.values(p).join(',')})` : ''}`;
const OPTIONS = { categories: [{ name: 'Soup', key: 'soup' }], authors: [{ id: 3, name: 'Anna' }] };

describe('toQuery', () => {
    it('drops empty values', () => {
        expect(toQuery(emptyFilters())).toEqual({});
        expect(toQuery({ q: '  ', ingredient: '', categories: [], author: null, show: 'all', favourites: false })).toEqual({});
    });

    it('serialises every active filter, categories as a list', () => {
        const q = toQuery({ q: ' soup ', ingredient: 'egg', categories: ['Soup', 'Dessert'], author: 3, show: 'mine', favourites: true });
        expect(q).toEqual({ q: 'soup', ingredient: 'egg', categories: ['Soup', 'Dessert'], author: 3, show: 'mine', favourites: 1 });
    });

    it('adds the page only from the second one', () => {
        expect(toQuery(emptyFilters(), 1)).toEqual({});
        expect(toQuery(emptyFilters(), 3)).toEqual({ page: 3 });
    });
});

describe('normaliseFilters', () => {
    it('fills defaults and coerces what the server echoes', () => {
        expect(normaliseFilters(undefined)).toEqual(emptyFilters());
        expect(normaliseFilters({ author: '5', show: 'weird', favourites: '1', categories: ['a'] })).toEqual({ q: '', ingredient: '', categories: ['a'], author: 5, show: 'all', favourites: true });
    });
});

describe('activeCount and chips', () => {
    it('counts categories, author, ingredient, show and favourites, not the search text', () => {
        expect(activeCount({ q: 'x' })).toBe(0);
        expect(activeCount({ categories: ['a', 'b'], author: 3, ingredient: 'egg', show: 'shared', favourites: true })).toBe(6);
    });

    it('returns one chip per active filter with a remove target', () => {
        const f = { categories: ['Soup'], author: 3, ingredient: 'egg', show: 'mine', favourites: true };
        const chips = activeChips(f, OPTIONS, t);
        expect(chips.map((c) => c.id)).toEqual(['category:Soup', 'author', 'ingredient', 'show', 'favourites']);
        expect(chips[1].label).toBe('recipes.filter.chip_author(Anna)');
        expect(chips[3].label).toBe('recipes.filter.show_mine');
    });

    it('removes exactly the chosen filter', () => {
        const f = { categories: ['Soup', 'Dessert'], author: 3, ingredient: 'egg', show: 'mine', favourites: true };
        const chips = activeChips(f, OPTIONS, t);
        expect(withoutChip(f, chips[0]).categories).toEqual(['Dessert']);
        expect(withoutChip(f, chips.find((c) => c.id === 'author')).author).toBeNull();
        expect(withoutChip(f, chips.find((c) => c.id === 'show')).show).toBe('all');
        expect(withoutChip(f, chips.find((c) => c.id === 'favourites')).favourites).toBe(false);
        expect(withoutChip(f, chips.find((c) => c.id === 'ingredient')).ingredient).toBe('');
    });

    it('clears everything', () => {
        expect(cleared()).toEqual(emptyFilters());
    });
});

describe('totalMinutes', () => {
    it('adds preparation and cooking and omits zero', () => {
        expect(totalMinutes({ prepMinutes: 10, cookMinutes: 20 })).toBe(30);
        expect(totalMinutes({ prepMinutes: null, cookMinutes: 15 })).toBe(15);
        expect(totalMinutes({ prepMinutes: null, cookMinutes: null })).toBeNull();
    });
});
