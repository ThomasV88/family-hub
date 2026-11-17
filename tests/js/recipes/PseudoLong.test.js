import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { readFileSync, readdirSync } from 'node:fs';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';

let mockProps = {};
const { routerMock, fetchMock } = vi.hoisted(() => ({
    routerMock: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), visit: vi.fn(), replace: vi.fn(), reload: vi.fn(), on: vi.fn(() => vi.fn()) },
    fetchMock: vi.fn(),
}));
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: mockProps, url: '/recipes' }),
        router: routerMock,
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: fetchMock }));

import Index from '../../../resources/js/Pages/Recipes/Index.vue';
import Show from '../../../resources/js/Pages/Recipes/Show.vue';
import Form from '../../../resources/js/Pages/Recipes/Form.vue';

const ME = 2;
const LONG_TITLE = 'Veľmi dlhý názov receptu na pomalú zemiakovú polievku';
const LONG_ING = 'Veľmi dlhý názov suroviny z receptu';
const LONG_STEP = 'Veľmi dlhý text kroku postupu, ktorý sa musí zobraziť celý bez orezania na konci riadku.';
const UNITS = [{ id: 1, key: 'pcs', names: null }, { id: 2, key: 'g', names: null }];
const RECIPE = {
    id: 12, title: LONG_TITLE, description: 'Popis', servings: 4, prepMinutes: 10, cookMinutes: 30, visibility: 'shared',
    sourceUrl: null, sourceHost: null, createdVia: 'manual', author: { id: ME, name: 'Mira', isMe: true }, categories: ['Soup'],
    ingredients: [{ id: 101, name: LONG_ING, quantity: '200', unitId: 2, note: null }, { id: 102, name: 'Eggs', quantity: '2', unitId: 1, note: null }],
    steps: [{ id: 201, body: LONG_STEP }, { id: 202, body: 'Serve' }], photos: [],
};
const STATE = { favourite: false, note: null, servings: null, tickedIngredientIds: [], doneStepIds: [] };
const ABILITIES = { edit: true, delete: true, changeVisibility: true, addToShopping: true };
const LIMITS = {
    titleMax: 120, descriptionMax: 1000, ingredientNameMax: 120, ingredientNoteMax: 80, stepMax: 1000, ingredientsMax: 80, stepsMax: 60,
    categoriesMax: 3, categoryMax: 30, servingsMin: 1, servingsMax: 99, minutesMax: 1440, quantityMax: 9999, sourceUrlMax: 2000, photosMax: 10, photoMaxBytes: 5242880,
};
const FORM_BASE = { recipe: null, defaults: { servings: 4, visibility: 'private' }, units: UNITS, categorySuggestions: [], starterCategories: ['soup'], limits: LIMITS, isShared: false, canAddToShopping: true };
const PREFILL = {
    title: LONG_TITLE, description: null, servings: null, prep_minutes: 10, cook_minutes: null, categories: ['Dessert'],
    ingredients: [{ name: LONG_ING, quantity: '250', unit_id: 2, note: null, original: '250 g flour' }],
    steps: [{ body: LONG_STEP }], aiFields: ['title', 'ingredients', 'steps'],
    sourceUrl: 'https://recepty.example/a', sourceHost: 'recepty.example', textCut: false, importId: '33333333-3333-4333-8333-333333333333',
};
const PREVIEW = {
    baseServings: 4, lists: [], stores: [], catalog: [], units: UNITS, canCreateList: true, limits: { nameMax: 80, itemsMax: 80 },
    rows: [{ ingredientId: 101, name: LONG_ING, quantity: '200', unitId: 2, note: null, match: { tier: 'none', catalogItemId: null, catalogName: null }, suggestedUnitId: 2 }],
};

let current;
function mountPage(component, props, locale = 'en', width = 390) {
    window.innerWidth = width;
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: ME, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { recipes: 'edit' } },
        app: { env: 'local', styleGuide: false },
    };
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(component, { props, attachTo: document.body, global: { plugins: [createAppI18n(locale, pseudoLong(loadMessages(locale)))] } });
    return current;
}
const clamped = (el) => [...el.querySelectorAll('[class*="truncate"], [class*="ellipsis"], [class*="line-clamp"]')].map((n) => n.textContent.trim()).filter(Boolean);
const noWrapButtons = (root) => [...root.querySelectorAll('button')].filter((b) => /whitespace-nowrap/.test(b.className)).length;

beforeEach(() => {
    Object.values(routerMock).forEach((f) => f.mockClear());
    routerMock.on.mockImplementation(() => vi.fn());
    fetchMock.mockReset();
    fetchMock.mockResolvedValue({ ok: true, status: 200, data: PREVIEW });
});
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; });

describe('Recipes pages with pseudo-long text (+40 percent)', () => {
    it('only the grid card title is clamped in Recipes code', () => {
        const dirs = ['resources/js/Pages/Recipes', 'resources/js/Components/Recipes'];
        const offenders = [];
        for (const dir of dirs) {
            for (const f of readdirSync(dir).filter((n) => n.endsWith('.vue'))) {
                const src = readFileSync(`${dir}/${f}`, 'utf8');
                if (/line-clamp|text-overflow:\s*ellipsis|\btruncate\b/.test(src)) offenders.push(f);
            }
        }
        expect(offenders).toEqual(['RecipeCard.vue']);
    });

    it('Index: long titles appear on the card and buttons wrap', () => {
        const recipes = [{ id: 1, title: LONG_TITLE, mainPhoto: null, prepMinutes: 10, cookMinutes: 20, servings: 4, visibility: 'shared', categories: ['Soup'], author: { id: ME, name: 'Mira', isMe: true }, favourite: false }];
        mountPage(Index, { recipes, hasMore: false, page: 1, filters: {}, filterOptions: { categories: [{ name: 'Soup', key: 'soup' }], authors: [{ id: ME, name: 'Mira' }] }, canCreate: true });
        expect(current.find('[data-testid="recipe-card"]').text()).toContain(LONG_TITLE);
        expect(noWrapButtons(current.element)).toBe(0);
    });

    it('Show (phone): title, ingredient names and step text stay whole', () => {
        mountPage(Show, { recipe: RECIPE, state: STATE, abilities: ABILITIES, units: UNITS });
        const text = current.text();
        for (const s of [LONG_TITLE, LONG_ING, LONG_STEP]) expect(text).toContain(s);
        expect(clamped(current.element).filter((t) => t.includes('Veľmi'))).toEqual([]);
        expect(noWrapButtons(current.element)).toBe(0);
    });

    it('Show in cooking mode keeps ingredient names and step text whole', async () => {
        mountPage(Show, { recipe: RECIPE, state: STATE, abilities: ABILITIES, units: UNITS }, 'en', 400);
        await flushPromises();
        await current.find('[data-testid="cooking-toggle-bar"]').trigger('click');
        await flushPromises();
        const text = current.text();
        for (const s of [LONG_ING, LONG_STEP]) expect(text).toContain(s);
        expect(clamped(current.element).filter((t) => t.includes('Veľmi'))).toEqual([]);
        expect(noWrapButtons(current.element)).toBe(0);
    });

    it('the shopping dialog keeps long ingredient names whole', async () => {
        mountPage(Show, { recipe: RECIPE, state: STATE, abilities: ABILITIES, units: UNITS }, 'en', 1024);
        await current.find('[data-testid="add-to-shopping"]').trigger('click');
        await flushPromises();
        const dialog = document.body.querySelector('[role="dialog"]');
        expect(dialog).not.toBeNull();
        expect(dialog.querySelector('[data-testid="row-name"]').value).toBe(LONG_ING);
        expect(clamped(dialog).filter((t) => t.includes('Veľmi'))).toEqual([]);
        expect(noWrapButtons(dialog)).toBe(0);
    });

    it('Form (create): labels and buttons are not clamped or nowrap', () => {
        mountPage(Form, FORM_BASE);
        expect(clamped(current.element)).toEqual([]);
        expect(noWrapButtons(current.element)).toBe(0);
        expect(current.findAll('label').length).toBeGreaterThan(3);
    });

    it('Form (review): the long title, ingredient and step values are kept whole', () => {
        mountPage(Form, { ...FORM_BASE, prefill: PREFILL });
        expect(current.find('[data-testid="title-input"]').element.value).toBe(LONG_TITLE);
        expect(current.find('[data-field="name"]').element.value).toBe(LONG_ING);
        expect(current.find('[data-field="step"]').element.value).toBe(LONG_STEP);
        expect(clamped(current.element)).toEqual([]);
        expect(noWrapButtons(current.element)).toBe(0);
    });

    it.each([[360, 'en'], [360, 'sk'], [800, 'sk'], [800, 'pl']])('Form (review, all number fields tagged) at %ipx in %s: every tag stays with its field, no clamping', (width, locale) => {
        const prefill = { ...PREFILL, servings: 4, cook_minutes: 30, aiFields: ['title', 'description', 'servings', 'prep_minutes', 'cook_minutes', 'categories', 'ingredients', 'steps'] };
        mountPage(Form, { ...FORM_BASE, prefill }, locale, width);
        for (const id of ['servings', 'prep', 'cook']) {
            const tag = current.find(`[data-testid="from-page-${id}"]`);
            expect(tag.exists()).toBe(true);
            expect(tag.element.closest('.ui-field__head')).not.toBeNull();
        }
        expect(current.findAll('.ui-field__head').length).toBeGreaterThanOrEqual(5);
        expect(clamped(current.element)).toEqual([]);
        expect(noWrapButtons(current.element)).toBe(0);
    });
});
