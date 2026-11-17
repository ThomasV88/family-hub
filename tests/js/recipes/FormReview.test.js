import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

let mockProps = {};
const { routerMock, fetchMock } = vi.hoisted(() => ({
    routerMock: { get: vi.fn(), post: vi.fn(), put: vi.fn(), visit: vi.fn(), replace: vi.fn(), reload: vi.fn(), on: vi.fn(() => vi.fn()) },
    fetchMock: vi.fn(),
}));
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: mockProps, url: '/recipes/create' }),
        router: routerMock,
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: fetchMock }));

import Form from '../../../resources/js/Pages/Recipes/Form.vue';

const UNITS = [{ id: 1, key: 'pcs', names: null }, { id: 2, key: 'g', names: null }];
const LIMITS = {
    titleMax: 120, descriptionMax: 1000, ingredientNameMax: 120, ingredientNoteMax: 80, stepMax: 1000, ingredientsMax: 80, stepsMax: 60,
    categoriesMax: 3, categoryMax: 30, servingsMin: 1, servingsMax: 99, minutesMax: 1440, quantityMax: 9999, sourceUrlMax: 2000, photosMax: 10, photoMaxBytes: 5242880,
};
const BASE = { recipe: null, defaults: { servings: 4, visibility: 'private' }, units: UNITS, categorySuggestions: [], starterCategories: ['soup'], limits: LIMITS, isShared: false, canAddToShopping: true };
const IMPORT_ID = '33333333-3333-4333-8333-333333333333';
const PREFILL = {
    title: 'Palacinky', description: null, servings: null, prep_minutes: 10, cook_minutes: null, categories: ['Dessert'],
    ingredients: [
        { name: 'flour', quantity: '250', unit_id: 2, note: null, original: '250 g flour' },
        { name: '<b>eggs</b>', quantity: '2', unit_id: 1, note: 'large', original: '2 large eggs <img src=x onerror=alert(1)>' },
    ],
    steps: [{ body: 'Mix everything.' }, { body: 'Fry.' }],
    aiFields: ['title', 'prep_minutes', 'categories', 'ingredients', 'steps'],
    sourceUrl: 'https://recepty.example/palacinky?a=1', sourceHost: 'recepty.example', textCut: false, importId: IMPORT_ID,
};

let current;
function mountForm({ prefill = PREFILL, locale = 'en', ...props } = {}) {
    window.innerWidth = 1024;
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { recipes: 'edit' } },
        app: { env: 'local', styleGuide: false },
    };
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(Form, { props: { ...BASE, prefill, ...props }, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });

    return current;
}
const $ = (sel) => current.find(sel);
const rows = () => current.findAll('[data-row]').filter((r) => r.find('[data-field="name"]').exists());
const save = async () => { await $('form').trigger('submit'); await flushPromises(); };

beforeEach(() => {
    vi.useRealTimers();
    Object.values(routerMock).forEach((f) => f.mockClear());
    routerMock.on.mockImplementation(() => vi.fn());
    fetchMock.mockReset();
});
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; });

describe('Recipe form: review mode', () => {
    it('is titled Check the recipe with the review banner', () => {
        mountForm();
        expect($('h1').text()).toBe('Check the recipe');
        expect($('[data-testid="review-banner"]').text()).toContain('Check everything the app filled in from the page. Nothing is saved until you press Save recipe.');
    });

    it('fills the form from the prefill, with servings left empty when the page had none', () => {
        mountForm();
        expect($('[data-testid="title-input"]').element.value).toBe('Palacinky');
        expect($('input[inputmode="numeric"]').element.value).toBe('');
        expect(rows()).toHaveLength(2);
        expect(rows()[0].find('[data-field="name"]').element.value).toBe('flour');
        expect(rows()[0].find('[data-field="quantity"]').element.value).toBe('250');
        expect(rows()[0].find('select').element.value).toBe('2');
        expect(current.findAll('textarea').map((t) => t.element.value).filter((v) => v.endsWith('.'))).toEqual(['Mix everything.', 'Fry.']);
    });

    it('tags AI-filled fields From the page until they are edited, and marks the missing ones Not found on the page', async () => {
        mountForm();
        expect($('[data-testid="from-page-title"]').text()).toContain('From the page');
        expect($('[data-testid="from-page-prep"]').exists()).toBe(true);
        expect($('[data-testid="from-page-cook"]').exists()).toBe(false);
        expect($('[data-testid="from-page-steps"]').exists()).toBe(true);
        expect($('[data-testid="from-page-categories"]').exists()).toBe(true);
        expect($('[data-testid="from-page-ingredients"]').exists()).toBe(true);
        expect($('[data-testid="from-page-description"]').exists()).toBe(false);
        expect($('[data-testid="from-page-servings"]').exists()).toBe(false);
        const notFound = current.findAll('.ui-field__hint').filter((h) => h.text() === 'Not found on the page.');
        expect(notFound).toHaveLength(3); // description, servings, cooking time
        await $('[data-testid="title-input"]').setValue('Pancakes');
        expect($('[data-testid="from-page-title"]').exists()).toBe(false);
        await rows()[0].find('[data-field="name"]').setValue('plain flour');
        expect($('[data-testid="from-page-ingredients"]').exists()).toBe(false);
        // Typing into an empty field removes its "not found" hint only once it has content.
        await $('input[inputmode="numeric"]').setValue('4');
        expect(current.findAll('.ui-field__hint').filter((h) => h.text() === 'Not found on the page.')).toHaveLength(2);
    });

    it('shows the original line under each ingredient as plain text, and never sends it', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 201, data: { id: 5 } });
        mountForm();
        const originals = current.findAll('[data-testid="ingredient-original"]');
        expect(originals.map((o) => o.text())).toEqual(['On the page: 250 g flour', 'On the page: 2 large eggs <img src=x onerror=alert(1)>']);
        expect(current.find('[data-testid="ingredient-original"] img').exists()).toBe(false);
        expect(current.html()).not.toContain('<img src=x');
        expect(rows()[1].find('[data-field="name"]').element.value).toBe('<b>eggs</b>');
        expect(current.find('[data-testid="recipe-form-page"] b').exists()).toBe(false);
        await $('input[inputmode="numeric"]').setValue('4');
        await save();
        const body = fetchMock.mock.calls[0][1].body;
        expect(JSON.stringify(body)).not.toContain('original');
        expect(JSON.stringify(body)).not.toContain('On the page');
    });

    it('sends import_id with the save and lands on the new recipe', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 201, data: { id: 5 } });
        mountForm();
        await save();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toBe('/recipes');
        expect(options.body).toMatchObject({ import_id: IMPORT_ID, title: 'Palacinky', servings: null, prep_minutes: 10, source_url: 'https://recepty.example/palacinky?a=1', categories: ['Dessert'] });
        expect(options.body.ingredients[0]).toMatchObject({ name: 'flour', quantity: '250', unit_id: 2 });
        expect(routerMock.visit).toHaveBeenCalledWith('/recipes/5', expect.any(Object));
    });

    it('shows the source as a link with its host and Edit makes it an editable field', async () => {
        mountForm();
        const link = $('[data-testid="source-link"]');
        expect(link.attributes('href')).toBe('https://recepty.example/palacinky?a=1');
        expect(link.attributes('rel')).toContain('noopener');
        expect(link.text()).toBe('recepty.example');
        expect(current.find('input[type="url"]').exists()).toBe(false);
        await $('[data-testid="source-edit"]').trigger('click');
        expect(current.find('input[type="url"]').element.value).toBe('https://recepty.example/palacinky?a=1');
        expect($('[data-testid="source-link"]').exists()).toBe(false);
    });

    it('never makes a link out of a source that is not http(s)', () => {
        mountForm({ prefill: { ...PREFILL, sourceUrl: 'javascript:alert(1)', sourceHost: '' } });
        expect($('[data-testid="source-link"]').exists()).toBe(false);
    });

    it('shows the long-page hint only when the text was cut', () => {
        mountForm();
        expect($('[data-testid="text-cut"]').exists()).toBe(false);
        current.unmount();
        mountForm({ prefill: { ...PREFILL, textCut: true } });
        expect($('[data-testid="text-cut"]').text()).toBe('The page was long, only the beginning was read.');
    });

    it('asks before leaving, since the draft is not saved yet', () => {
        mountForm();
        const guard = routerMock.on.mock.calls.find((c) => c[0] === 'before');
        expect(guard).toBeTruthy();
        const result = guard[1]({ detail: { visit: { url: new URL('http://localhost/recipes'), method: 'get', data: {} } } });
        expect(result).toBe(false);
    });

    it('a server error maps onto the fields and keeps the review', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 422, error: 'invalid', errors: { title: ['recipes.error.title_invalid'] }, data: {} });
        mountForm();
        await save();
        expect(current.text()).toContain('Enter a title of up to 120 characters.');
        expect($('[data-testid="review-banner"]').exists()).toBe(true);
        expect(routerMock.visit).not.toHaveBeenCalled();
    });

    it('has no axe violations in review mode', async () => {
        mountForm();
        await flushPromises();
        await expectNoAxeViolations(current.element);
    });
});

describe('Recipe form: prefill with only a source', () => {
    const SOURCE_ONLY = { sourceUrl: 'https://recepty.example/a', sourceHost: 'recepty.example', aiFields: [], textCut: false, importId: null };

    it('fills only the Source field in the normal form: no review banner, tags or import_id', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 201, data: { id: 6 } });
        mountForm({ prefill: SOURCE_ONLY });
        expect($('h1').text()).toBe('New recipe');
        expect($('[data-testid="review-banner"]').exists()).toBe(false);
        expect(current.find('input[type="url"]').element.value).toBe('https://recepty.example/a');
        expect($('[data-testid="title-input"]').element.value).toBe('');
        expect($('input[inputmode="numeric"]').element.value).toBe('4');
        expect(current.findAll('.ui-tag').length).toBe(0);
        await $('[data-testid="title-input"]').setValue('Soup');
        await rows()[0].find('[data-field="name"]').setValue('Water');
        await current.findAll('[data-row] textarea')[0].setValue('Boil');
        await save();
        expect(fetchMock.mock.calls[0][1].body).not.toHaveProperty('import_id');
        expect(fetchMock.mock.calls[0][1].body.source_url).toBe('https://recepty.example/a');
    });
});
