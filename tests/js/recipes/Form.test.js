import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
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
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const UNITS = [{ id: 1, key: 'pcs', names: null }, { id: 2, key: 'g', names: null }, { id: 8, key: 'tbsp', names: null }, { id: 9, key: 'tsp', names: null }];
const LIMITS = {
    titleMax: 120, descriptionMax: 1000, ingredientNameMax: 120, ingredientNoteMax: 80, stepMax: 1000, ingredientsMax: 80, stepsMax: 60,
    categoriesMax: 3, categoryMax: 30, servingsMin: 1, servingsMax: 99, minutesMax: 1440, quantityMax: 9999, sourceUrlMax: 2000, photosMax: 10, photoMaxBytes: 5242880,
};
const STARTER = ['breakfast', 'lunch', 'dinner', 'soup', 'salad', 'dessert', 'baking', 'snack', 'drink', 'vegetarian'];
const BASE = { recipe: null, defaults: { servings: 4, visibility: 'private' }, units: UNITS, categorySuggestions: ['Polievka', 'Dezert'], starterCategories: STARTER, limits: LIMITS, isShared: false, canAddToShopping: true };
const EXISTING = {
    id: 12, title: 'Soup', description: 'Warm', servings: 6, prepMinutes: 10, cookMinutes: 30, visibility: 'shared', sourceUrl: 'https://example.test/soup', sourceHost: 'example.test',
    categories: ['Soup'], author: { id: 2, name: 'Mira', isMe: true }, photos: [],
    ingredients: [{ id: 101, name: 'Water', quantity: '1.5', unitId: null, note: null }, { id: 102, name: 'Carrot', quantity: '2', unitId: 1, note: 'sliced' }],
    steps: [{ id: 201, body: 'Boil' }, { id: 202, body: 'Serve' }],
};

let current;
function mountForm({ locale = 'en', ...props } = {}) {
    window.innerWidth = 1024;
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { recipes: 'edit' } },
        app: { env: 'local', styleGuide: false },
    };
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(Form, { props: { ...BASE, ...props }, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });

    return current;
}
const $ = (sel) => current.find(sel);
const rows = () => current.findAll('[data-row]').filter((r) => r.find('[data-field="name"]').exists());
const stepRows = () => current.findAll('[data-row]').filter((r) => r.find('[data-field="step"]').exists());
const nameInput = (i) => rows()[i].find('[data-field="name"]');
const body = (text) => [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === text);
const click = async (el) => { el.click(); await flushPromises(); };
async function fillValid() {
    await $('[data-testid="title-input"]').setValue('Pancakes');
    await nameInput(0).setValue('Flour');
    await stepRows()[0].find('textarea').setValue('Mix it');
}
const save = async () => { await $('[data-testid="save"]').trigger('click'); await $('form').trigger('submit'); await flushPromises(); };

beforeEach(() => {
    vi.useRealTimers();
    Object.values(routerMock).forEach((f) => f.mockClear());
    routerMock.on.mockImplementation(() => vi.fn());
    fetchMock.mockReset();
    useToast().toasts.splice(0);
});
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; });

describe('Recipe form: create', () => {
    it('shows the title, one empty ingredient and one empty step, default servings and Only me', () => {
        mountForm();
        expect($('h1').text()).toBe('New recipe');
        expect(rows()).toHaveLength(1);
        expect(stepRows()).toHaveLength(1);
        expect($('input[inputmode="numeric"]').element.value).toBe('4');
        expect($('[data-testid="visibility-private"]').element.checked).toBe(true);
        expect($('[data-testid="shared-hint"]').exists()).toBe(false);
    });

    it('refuses an empty form, shows each error inline and focuses the first invalid field', async () => {
        mountForm();
        await save();
        expect(fetchMock).not.toHaveBeenCalled();
        expect($('[data-testid="form-alert"]').text()).toContain('Your changes were not saved');
        expect(current.text()).toContain('Enter a title of up to 120 characters.');
        expect($('[data-testid="ingredients-error"]').text()).toBe('Add at least one ingredient.');
        expect($('[data-testid="steps-error"]').text()).toBe('Add at least one step.');
        expect(document.activeElement).toBe($('[data-testid="title-input"]').element);
    });

    it('sends the ordered lines with unit ids, parsed quantities, categories and visibility, then opens the detail', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 201, data: { id: 9 } });
        mountForm();
        await fillValid();
        await rows()[0].find('[data-field="quantity"]').setValue('1 1/2');
        await rows()[0].find('select').setValue('2');
        await rows()[0].find('[data-field="note"]').setValue('sifted');
        await click($('[data-testid="add-ingredient"]').element);
        await nameInput(1).setValue('Milk');
        await rows()[1].find('[data-field="quantity"]').setValue('0,5');
        await $('[data-testid="visibility-shared"]').setValue(true);
        await $('input[inputmode="numeric"]').setValue('6');
        await save();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toBe('/recipes');
        expect(options.method).toBe('POST');
        expect(options.body).toMatchObject({
            title: 'Pancakes', description: null, servings: 6, prep_minutes: null, cook_minutes: null, source_url: null, visibility: 'shared', categories: [],
            ingredients: [{ name: 'Flour', quantity: '1.5', unit_id: 2, note: 'sifted' }, { name: 'Milk', quantity: '0.5', unit_id: null, note: null }],
            steps: [{ body: 'Mix it' }],
        });
        expect(options.body.ingredients[0]).not.toHaveProperty('id');
        expect(routerMock.visit).toHaveBeenCalledWith('/recipes/9', expect.any(Object));
        expect(useToast().toasts.map((t) => t.message)).toContain('Recipe saved');
    });

    it('sends one client token per form session, the same on a retry after a failure', async () => {
        fetchMock.mockResolvedValueOnce({ ok: false, status: 0, error: 'failed', data: {} });
        fetchMock.mockResolvedValueOnce({ ok: true, status: 201, data: { id: 9 } });
        mountForm();
        await fillValid();
        await save();
        await save();
        const tokens = fetchMock.mock.calls.map(([, o]) => o.body.client_token);
        expect(tokens[0]).toMatch(/^[A-Za-z0-9_-]{16,64}$/);
        expect(tokens[1]).toBe(tokens[0]);
        current.unmount();
        fetchMock.mockResolvedValueOnce({ ok: true, status: 201, data: { id: 10 } });
        mountForm();
        await fillValid();
        await save();
        expect(fetchMock.mock.calls[2][1].body.client_token).not.toBe(tokens[0]);
    });

    it('never visits /recipes/undefined when the answer has no id', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 201, data: {} });
        mountForm();
        await fillValid();
        await save();
        expect(routerMock.visit).not.toHaveBeenCalled();
        expect($('[data-testid="form-alert"]').exists()).toBe(true);
        expect($('[data-testid="save"]').attributes('disabled')).toBeUndefined();
    });

    it('tells the member when the chosen main photo could not be set after the recipe was saved', async () => {
        const photoOk = (id) => ({ ok: true, status: 201, json: async () => ({ photo: { id }, photos: [] }) });
        const realFetch = globalThis.fetch;
        globalThis.fetch = vi.fn().mockResolvedValueOnce(photoOk(31)).mockResolvedValueOnce(photoOk(32));
        fetchMock.mockImplementation(async (url) => (url === '/recipes'
            ? { ok: true, status: 201, data: { id: 9 } }
            : { ok: false, status: 500, data: {} }));
        try {
            mountForm();
            await fillValid();
            const file = (n) => new File(['x'], n, { type: 'image/png' });
            current.findComponent({ name: 'RecipePhotosField' }).vm.$emit('update:queue', [{ key: 'a', file: file('a.png'), isMain: false }, { key: 'b', file: file('b.png'), isMain: true }]);
            await flushPromises();
            await save();
            expect(useToast().toasts.map((x) => x.message)).toEqual(['The recipe was saved, but your chosen main photo could not be set. You can choose it again in Edit.']);
        } finally {
            globalThis.fetch = realFetch;
        }
    });

    it('drops blank rows from the request and ignores a second click while saving', async () => {
        let resolve;
        fetchMock.mockReturnValue(new Promise((r) => { resolve = r; }));
        mountForm();
        await fillValid();
        await click($('[data-testid="add-ingredient"]').element);
        await $('form').trigger('submit');
        await $('form').trigger('submit');
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][1].body.ingredients).toHaveLength(1);
        expect($('[data-testid="save"]').attributes('disabled')).toBeDefined();
        resolve({ ok: true, status: 201, data: { id: 3 } });
        await flushPromises();
        expect(routerMock.visit).toHaveBeenCalledTimes(1);
    });

    it('maps a 422 onto the right row and focuses it', async () => {
        fetchMock.mockResolvedValue({
            ok: false, status: 422, error: 'invalid',
            errors: { 'ingredients.1.name': ['recipes.error.ingredient_invalid'], 'ingredients.0.quantity': ['recipes.error.quantity'], title: ['recipes.error.title_invalid'] },
        });
        mountForm();
        await fillValid();
        await click($('[data-testid="add-ingredient"]').element);
        await nameInput(1).setValue('Milk');
        await save();
        expect(rows()[0].text()).toContain('Enter a quantity greater than 0');
        expect(rows()[1].text()).toContain('Enter the name of the ingredient');
        expect($('[data-testid="form-alert"]').exists()).toBe(true);
        expect(document.activeElement).toBe($('[data-testid="title-input"]').element);
        expect(routerMock.visit).not.toHaveBeenCalled();
        await $('[data-testid="title-input"]').setValue('Other');
        expect(current.text()).not.toContain('Enter a title of up to 120 characters.');
    });

    it.each([[403, 'You are not allowed to do that'], [429, 'Too many attempts'], [419, 'session expired'], [500, 'Your changes were not saved'], [0, 'Your changes were not saved']])(
        'answers a failed request (%s) with a message and keeps the form',
        async (status, text) => {
            fetchMock.mockResolvedValue({ ok: false, status, error: 'failed', data: {} });
            mountForm();
            await fillValid();
            await save();
            expect($('[data-testid="form-alert"]').text()).toContain(text);
            expect($('[data-testid="title-input"]').element.value).toBe('Pancakes');
            expect($('[data-testid="save"]').attributes('disabled')).toBeUndefined();
        },
    );

    it('leaves for the overview with a message when the recipe is gone (404)', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 404, error: 'failed', data: {} });
        mountForm();
        await fillValid();
        await save();
        expect(routerMock.visit).toHaveBeenCalledWith('/recipes', expect.any(Object));
        expect(useToast().toasts.map((t) => t.message).join(' ')).toContain('not available');
    });

    it('renders typed markup as text', async () => {
        mountForm({ recipe: { ...EXISTING, title: '<img src=x onerror=alert(1)>', ingredients: [{ id: 1, name: '<b>bold</b>', quantity: null, unitId: null, note: null }] } });
        expect(current.find('img[src="x"]').exists()).toBe(false);
        expect($('[data-testid="title-input"]').element.value).toBe('<img src=x onerror=alert(1)>');
        expect(nameInput(0).element.value).toBe('<b>bold</b>');
        expect(current.find('b').exists()).toBe(false);
    });
});

describe('Recipe form: edit', () => {
    it('fills the fields, sends line ids and shapes added and removed lines', async () => {
        mountForm({ recipe: EXISTING, isShared: true });
        expect($('h1').text()).toBe('Edit recipe');
        expect($('[data-testid="shared-hint"]').text()).toContain('Other members may be cooking');
        expect(nameInput(0).element.value).toBe('Water');
        expect(rows()[0].find('[data-field="quantity"]').element.value).toBe('1.5');
        expect(rows()[1].find('select').element.value).toBe('1');
        // Remove Water, add Salt.
        await click(rows()[0].find('[data-testid="remove-ingredient"]').element);
        await click($('[data-testid="add-ingredient"]').element);
        await nameInput(1).setValue('Salt');
        await save();
        expect(routerMock.put).toHaveBeenCalledTimes(1);
        const [url, data] = routerMock.put.mock.calls[0];
        expect(url).toBe('/recipes/12');
        expect(data.ingredients).toEqual([{ id: 102, name: 'Carrot', quantity: '2', unit_id: 1, note: 'sliced' }, { name: 'Salt', quantity: null, unit_id: null, note: null }]);
        expect(data.steps).toEqual([{ id: 201, body: 'Boil' }, { id: 202, body: 'Serve' }]);
        expect(data.visibility).toBe('shared');
    });

    it('asks before making a shared recipe private and sends nothing on Keep shared', async () => {
        mountForm({ recipe: EXISTING, isShared: true });
        await $('[data-testid="visibility-private"]').setValue(true);
        await save();
        expect(routerMock.put).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('Make this recipe private?');
        await click(body('Keep shared'));
        expect(routerMock.put).not.toHaveBeenCalled();
        await save();
        await click(body('Make private'));
        expect(routerMock.put).toHaveBeenCalledTimes(1);
        expect(routerMock.put.mock.calls[0][1].visibility).toBe('private');
    });

    it('does not ask when a private recipe stays private', async () => {
        mountForm({ recipe: { ...EXISTING, visibility: 'private' }, isShared: false });
        await save();
        expect(routerMock.put).toHaveBeenCalledTimes(1);
    });

    it('maps a server error bag onto fields and re-enables the button', async () => {
        mountForm({ recipe: EXISTING });
        await save();
        const options = routerMock.put.mock.calls[0][2];
        options.onError({ 'steps.1.body': 'recipes.error.step_invalid', 'ingredients.0.name': 'recipes.error.ingredient_invalid', visibility: 'recipes.error.visibility_invalid' });
        options.onFinish();
        await flushPromises();
        expect(stepRows()[1].text()).toContain('Enter the text of the step');
        expect(rows()[0].text()).toContain('Enter the name of the ingredient');
        expect($('[data-testid="save"]').attributes('disabled')).toBeUndefined();
        expect(document.activeElement).toBe(nameInput(0).element);
    });

    it.each([[403, 'You are not allowed'], [409, 'changed in the meantime'], [429, 'Too many attempts']])('shows a message for HTTP %s on update', async (status, text) => {
        mountForm({ recipe: EXISTING });
        await save();
        const options = routerMock.put.mock.calls[0][2];
        expect(options.onHttpException({ status })).toBe(false);
        options.onFinish();
        await flushPromises();
        expect($('[data-testid="form-alert"]').text()).toContain(text);
    });
});

describe('Recipe form: categories', () => {
    const input = () => $('[data-testid="category-input"]');
    const chips = () => current.findAll('[data-testid="category-chip"]').map((c) => c.find('.rec-cat__name').text());

    it('commits on Enter and comma, refuses a duplicate regardless of case and accents and a fourth category', async () => {
        mountForm();
        await input().setValue('Polievka');
        await input().trigger('keydown', { key: 'Enter' });
        expect(chips()).toEqual(['Polievka']);
        await input().setValue('polievka');
        await input().trigger('keydown', { key: 'Enter' });
        expect(chips()).toEqual(['Polievka']);
        expect($('[data-testid="category-message"]').text()).toContain('already added');
        await input().setValue('Šalát,');
        expect(chips()).toEqual(['Polievka', 'Šalát']);
        await input().setValue('salat');
        await input().trigger('keydown', { key: 'Enter' });
        expect(chips()).toEqual(['Polievka', 'Šalát']);
        await input().setValue('Desiata');
        await input().trigger('keydown', { key: 'Enter' });
        expect(chips()).toHaveLength(3);
        expect(input().element.disabled).toBe(true);
        expect(current.text()).toContain('You can add up to 3 categories.');
        // The chip's close button removes it and frees the box.
        await click(current.find('[data-testid="category-chip"] button').element);
        expect(chips()).toEqual(['Šalát', 'Desiata']);
        expect(input().element.disabled).toBe(false);
    });

    it('stores a starter suggestion as its label in the current language', async () => {
        mountForm({ locale: 'sk' });
        await input().trigger('focusin');
        const suggestion = current.findAll('[data-testid="category-suggestion"]').find((b) => b.text() === 'Raňajky');
        expect(suggestion).toBeTruthy();
        await suggestion.trigger('click');
        expect(chips()).toEqual(['Raňajky']);
        // The used-in-the-family list has Polievka and the starter has the same word: one suggestion only.
        await input().setValue('polie');
        expect(current.findAll('[data-testid="category-suggestion"]').map((b) => b.text())).toEqual(['Polievka']);
    });

    it('adds a typed category that was not committed when the form is saved', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 201, data: { id: 5 } });
        mountForm();
        await fillValid();
        await input().setValue('Brunch');
        await save();
        expect(fetchMock.mock.calls[0][1].body.categories).toEqual(['Brunch']);
    });
});

describe('Recipe form: ingredient and step rows', () => {
    it('Enter in the name adds a row after it and focuses it', async () => {
        mountForm({ recipe: EXISTING });
        await nameInput(0).trigger('keydown', { key: 'Enter' });
        await flushPromises();
        expect(rows()).toHaveLength(3);
        expect(rows()[1].find('[data-field="name"]').element.value).toBe('');
        expect(document.activeElement).toBe(rows()[1].find('[data-field="name"]').element);
        expect(nameInput(2).element.value).toBe('Carrot');
    });

    it('moves ingredients up and down through the row menu', async () => {
        mountForm({ recipe: EXISTING });
        await click(rows()[1].find('.ui-menu button').element);
        const item = [...document.body.querySelectorAll('[role="menuitem"]')].find((b) => b.textContent.trim() === 'Move up');
        await click(item);
        expect(rows().map((r) => r.find('[data-field="name"]').element.value)).toEqual(['Carrot', 'Water']);
        // The first row has no Move up.
        await click(rows()[0].find('.ui-menu button').element);
        expect([...document.body.querySelectorAll('[role="menuitem"]')].map((b) => b.textContent.trim())).toEqual(['Move down']);
    });

    it('removes a filled ingredient with an Undo toast that puts it back in place; an empty row goes without a toast', async () => {
        mountForm({ recipe: EXISTING });
        await click(rows()[0].find('[data-testid="remove-ingredient"]').element);
        expect(rows().map((r) => r.find('[data-field="name"]').element.value)).toEqual(['Carrot']);
        const toast = useToast().toasts.find((t) => t.message === 'Ingredient removed');
        expect(toast.action.label).toBe('Undo');
        toast.action.onSelect();
        await flushPromises();
        expect(rows().map((r) => r.find('[data-field="name"]').element.value)).toEqual(['Water', 'Carrot']);
        // An empty row goes without a toast.
        useToast().toasts.splice(0);
        await click($('[data-testid="add-ingredient"]').element);
        expect(rows()).toHaveLength(3);
        await click(rows()[2].find('[data-testid="remove-ingredient"]').element);
        expect(rows()).toHaveLength(2);
        expect(useToast().toasts).toHaveLength(0);
    });

    it('sends the id of an undone ingredient again, so its ticks survive', async () => {
        mountForm({ recipe: EXISTING });
        await click(rows()[0].find('[data-testid="remove-ingredient"]').element);
        useToast().toasts.find((t) => t.message === 'Ingredient removed').action.onSelect();
        await flushPromises();
        await save();
        expect(routerMock.put.mock.calls[0][1].ingredients.map((i) => i.id)).toEqual([101, 102]);
    });

    it('removes and moves steps, with an Undo for a filled one', async () => {
        mountForm({ recipe: EXISTING });
        await click(stepRows()[0].find('[data-move="down"]').element);
        expect(stepRows().map((r) => r.find('textarea').element.value)).toEqual(['Serve', 'Boil']);
        expect(stepRows()[0].find('[data-move="up"]').attributes('disabled')).toBeDefined();
        await click(stepRows()[0].find('[data-testid="remove-step"]').element);
        expect(stepRows()).toHaveLength(1);
        useToast().toasts.find((t) => t.message === 'Step removed').action.onSelect();
        await flushPromises();
        expect(stepRows().map((r) => r.find('textarea').element.value)).toEqual(['Serve', 'Boil']);
    });

    it('offers to split pasted lines and creates parsed rows', async () => {
        mountForm();
        const paste = '200 g flour\n2 ks eggs\n1/2 tsp salt';
        await nameInput(0).trigger('paste', { clipboardData: { getData: () => paste } });
        expect($('[data-testid="split-offer"]').text()).toContain('Split into 3 ingredients');
        await click($('[data-testid="split-button"]').element);
        expect(rows()).toHaveLength(3);
        const read = (r) => ({ q: r.find('[data-field="quantity"]').element.value, u: r.find('select').element.value, n: r.find('[data-field="name"]').element.value });
        expect(rows().map(read)).toEqual([{ q: '200', u: '2', n: 'flour' }, { q: '2', u: '1', n: 'eggs' }, { q: '0.5', u: '9', n: 'salt' }]);
        expect($('[data-testid="split-offer"]').exists()).toBe(false);
    });

    it('keeps the pasted text as one line when the split is not taken, and does not offer for one line', async () => {
        mountForm();
        await nameInput(0).trigger('paste', { clipboardData: { getData: () => '2 g salt' } });
        expect($('[data-testid="split-offer"]').exists()).toBe(false);
        await nameInput(0).trigger('paste', { clipboardData: { getData: () => 'flour\nsugar' } });
        expect(nameInput(0).element.value).toBe('flour, sugar');
        expect($('[data-testid="split-offer"]').exists()).toBe(true);
        await nameInput(0).setValue('flour, sugar, more');
        expect($('[data-testid="split-offer"]').exists()).toBe(false);
    });
});

describe('Recipe form: leaving', () => {
    const guard = () => routerMock.on.mock.calls.find(([name]) => name === 'before')[1];

    it('lets an untouched form go', () => {
        mountForm();
        expect(guard()({ detail: { visit: { url: new URL('http://x.test/recipes'), method: 'get' } } })).toBeUndefined();
    });

    it('asks first when there are unsaved changes, and visits on Leave', async () => {
        mountForm();
        await $('[data-testid="title-input"]').setValue('Draft');
        const result = guard()({ detail: { visit: { url: new URL('http://x.test/recipes'), method: 'get' } } });
        expect(result).toBe(false);
        await flushPromises();
        expect(document.body.textContent).toContain('Leave without saving?');
        expect(document.body.textContent).toContain('The recipe has not been saved yet.');
        await click(body('Leave'));
        expect(routerMock.visit).toHaveBeenCalledWith('http://x.test/recipes', expect.objectContaining({ method: 'get' }));
        // The visit that Leave starts passes the guard once.
        expect(guard()({ detail: { visit: { url: new URL('http://x.test/recipes'), method: 'get' } } })).toBeUndefined();
    });

    it('stays on Keep editing', async () => {
        mountForm();
        await $('[data-testid="title-input"]').setValue('Draft');
        guard()({ detail: { visit: { url: new URL('http://x.test/recipes'), method: 'get' } } });
        await flushPromises();
        await click(body('Keep editing'));
        expect(routerMock.visit).not.toHaveBeenCalled();
        expect($('[data-testid="title-input"]').element.value).toBe('Draft');
    });

    it('Cancel goes back to the overview or the detail', async () => {
        mountForm();
        await click($('[data-testid="cancel"]').element);
        expect(routerMock.visit).toHaveBeenCalledWith('/recipes');
        current.unmount();
        routerMock.visit.mockClear();
        mountForm({ recipe: EXISTING });
        await click($('[data-testid="cancel"]').element);
        expect(routerMock.visit).toHaveBeenCalledWith('/recipes/12');
    });

    describe('browser Back', () => {
        let pushState;
        let go;
        beforeEach(() => {
            pushState = vi.spyOn(window.history, 'pushState');
            go = vi.spyOn(window.history, 'go').mockImplementation(() => {});
        });
        afterEach(() => { pushState.mockRestore(); go.mockRestore(); });
        const back = () => window.dispatchEvent(new PopStateEvent('popstate'));

        it('puts no sentinel on a clean form and lets Back through', async () => {
            mountForm();
            expect(pushState).not.toHaveBeenCalled();
            back();
            expect(document.body.textContent).not.toContain('Leave without saving?');
        });

        it('asks on Back once the form is dirty, and Keep editing re-arms the guard', async () => {
            mountForm();
            await $('[data-testid="title-input"]').setValue('Draft');
            expect(pushState).toHaveBeenCalledTimes(1);
            back();
            await flushPromises();
            expect(document.body.textContent).toContain('Leave without saving?');
            expect(go).not.toHaveBeenCalled();
            await click(body('Keep editing'));
            expect(pushState).toHaveBeenCalledTimes(2);
            back();
            await flushPromises();
            expect(document.body.textContent).toContain('Leave without saving?');
        });

        it('goes one more step back on Leave and lets that step through', async () => {
            mountForm();
            await $('[data-testid="title-input"]').setValue('Draft');
            back();
            await flushPromises();
            await click(body('Leave'));
            expect(go).toHaveBeenCalledWith(-1);
            back();
            await flushPromises();
            expect(go).toHaveBeenCalledTimes(1);
        });

        it('guards an unsaved import draft from the start', async () => {
            mountForm({ prefill: { importId: 'abc', title: 'Soup', ingredients: [{ name: 'x' }], steps: [{ body: 'y' }], aiFields: ['title'], sourceUrl: 'https://a.example/b', sourceHost: 'a.example' } });
            expect(pushState).toHaveBeenCalledTimes(1);
            back();
            await flushPromises();
            expect(document.body.textContent).toContain('Leave without saving?');
        });

        it('replaces the sentinel entry when saving so Back does not land on the form twice', async () => {
            fetchMock.mockResolvedValue({ ok: true, status: 201, data: { id: 9 } });
            mountForm();
            await fillValid();
            await save();
            expect(routerMock.visit).toHaveBeenCalledWith('/recipes/9', expect.objectContaining({ replace: true }));
        });
    });

    it('sets beforeunload only while dirty', async () => {
        mountForm();
        const clean = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(clean);
        expect(clean.defaultPrevented).toBe(false);
        await $('[data-testid="title-input"]').setValue('Draft');
        const dirty = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(dirty);
        expect(dirty.defaultPrevented).toBe(true);
    });
});

describe('Recipe form: accessibility', () => {
    it.each(['en', 'sk', 'pl'])('has no axe violations (%s), empty and with errors', async (locale) => {
        mountForm({ locale, recipe: EXISTING, isShared: true });
        await expectNoAxeViolations(document.body);
        await nameInput(0).setValue('');
        await save();
        await nextTick();
        await expectNoAxeViolations(document.body);
    });
});
