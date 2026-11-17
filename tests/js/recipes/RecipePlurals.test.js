import { describe, it, expect } from 'vitest';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

// Strings that carry a count must decline their noun in Slovak and Polish (1 / 2-4 / 5+), not use one fixed form.
const t = (locale, key, named, n) => createAppI18n(locale, loadMessages(locale)).global.t(key, named, n);

describe('recipe strings with a count', () => {
    it.each([
        ['sk', 'recipes.ingredient.split_offer', 2, 'Rozdeliť na 2 suroviny'],
        ['sk', 'recipes.ingredient.split_offer', 5, 'Rozdeliť na 5 surovín'],
        ['pl', 'recipes.ingredient.split_offer', 3, 'Podziel na 3 składniki'],
        ['pl', 'recipes.ingredient.split_offer', 6, 'Podziel na 6 składników'],
        ['en', 'recipes.ingredient.split_offer', 2, 'Split into 2 ingredients'],
    ])('%s %s with %i', (locale, key, n, expected) => {
        expect(t(locale, key, { n }, n)).toBe(expected);
    });

    it.each([
        ['sk', 1, 'Môžete pridať najviac 1 kategóriu.'],
        ['sk', 3, 'Môžete pridať najviac 3 kategórie.'],
        ['sk', 5, 'Môžete pridať najviac 5 kategórií.'],
        ['pl', 1, 'Możesz dodać najwyżej 1 kategorię.'],
        ['pl', 3, 'Możesz dodać najwyżej 3 kategorie.'],
        ['pl', 5, 'Możesz dodać najwyżej 5 kategorii.'],
        ['en', 1, 'You can add up to 1 category.'],
    ])('categories_max %s with %i', (locale, n, expected) => {
        expect(t(locale, 'recipes.form.categories_max', { n }, n)).toBe(expected);
    });

    it('photos hint and cap decline too', () => {
        expect(t('sk', 'recipes.photos.cap', { n: 3 }, 3)).toBe('Môžete pridať najviac 3 fotky.');
        expect(t('sk', 'recipes.photos.cap', { n: 10 }, 10)).toBe('Môžete pridať najviac 10 fotiek.');
        expect(t('pl', 'recipes.photos.hint', { n: 3, mb: 5 }, 3)).toBe('Najwyżej 3 zdjęcia, JPG, PNG lub WebP, każde do 5 MB.');
        expect(t('pl', 'recipes.photos.hint', { n: 10, mb: 5 }, 10)).toBe('Najwyżej 10 zdjęć, JPG, PNG lub WebP, każde do 5 MB.');
    });

    it('servings announcement declines in Slovak', () => {
        expect([1, 2, 5].map((n) => t('sk', 'recipes.servings.updated', { n }, n))).toEqual([
            'Množstvá upravené pre 1 porciu.', 'Množstvá upravené pre 2 porcie.', 'Množstvá upravené pre 5 porcií.',
        ]);
    });
});
