import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { mountUi } from '../helpers/mount.js';

const { routerMock, fetchMock } = vi.hoisted(() => ({
    routerMock: { visit: vi.fn(), reload: vi.fn() },
    fetchMock: vi.fn(),
}));
vi.mock('@inertiajs/vue3', () => ({ router: routerMock }));
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: fetchMock }));

import AddToShoppingDialog from '../../../resources/js/Components/Recipes/AddToShoppingDialog.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const UNITS = [
    { id: 1, key: 'pcs', names: null }, { id: 2, key: 'g', names: null }, { id: 3, key: 'kg', names: null },
    { id: 4, key: 'ml', names: null }, { id: 5, key: 'l', names: null }, { id: 8, key: 'tbsp', names: null }, { id: 9, key: 'tsp', names: null },
];
const NONE = { tier: 'none', catalogItemId: null, catalogName: null };
const RECIPE = {
    id: 12, title: 'Tomato soup', servings: 4,
    ingredients: [
        { id: 101, name: 'Flour', quantity: '200', unitId: 2, note: null },
        { id: 102, name: 'Eggs', quantity: '2', unitId: 1, note: null },
        { id: 103, name: 'Salt', quantity: null, unitId: null, note: 'to taste' },
        { id: 104, name: 'Pepper', quantity: '0.5', unitId: 9, note: null },
    ],
};
const ROWS = [
    { ingredientId: 101, name: 'Flour', quantity: '200', unitId: 2, note: null, match: { tier: 'exact', catalogItemId: 7, catalogName: 'Flour' }, suggestedUnitId: 2 },
    { ingredientId: 102, name: 'Eggs', quantity: '2', unitId: 1, note: null, match: NONE, suggestedUnitId: 1 },
    { ingredientId: 103, name: 'Salt', quantity: null, unitId: null, note: 'to taste', match: { tier: 'suggested', catalogItemId: 9, catalogName: 'Sea salt' }, suggestedUnitId: 1 },
    { ingredientId: 104, name: 'Pepper', quantity: '0.5', unitId: 9, note: null, match: NONE, suggestedUnitId: 9 },
];
const CATALOG = [
    { id: 7, name: 'Flour', defaultUnitId: 2, imageVersion: 1 },
    { id: 9, name: 'Sea salt', defaultUnitId: 1, imageVersion: null },
    { id: 11, name: 'Mléko', defaultUnitId: 5, imageVersion: null },
];
const LISTS = [
    { id: 31, name: 'Weekend', storeName: 'Tesco', updatedAt: '2026-11-17T10:00:00+00:00', pending: [{ catalogItemId: 11, unitId: 5, quantity: '1' }] },
    { id: 32, name: 'Party', storeName: null, updatedAt: '2026-11-16T10:00:00+00:00', pending: [{ catalogItemId: 7, unitId: 1, quantity: '2' }, { catalogItemId: 9, unitId: 1, quantity: '1' }] },
];
// The same lists, with flour in kilograms on the first one.
const KG_LISTS = [{ ...LISTS[0], pending: [{ catalogItemId: 7, unitId: 3, quantity: '1' }] }, LISTS[1]];
const preview = (over = {}) => ({
    baseServings: 4, lists: LISTS, stores: [{ id: 5, name: 'Tesco' }], catalog: CATALOG, units: UNITS, rows: ROWS, canCreateList: true,
    limits: { nameMax: 80, itemsMax: 80 }, ...over,
});
const ok = (data) => ({ ok: true, status: 200, data });

let wrapper;
const PROPS = { open: true, recipeId: 12, recipe: RECIPE, baseServings: 4, servings: 4, units: UNITS };
async function mountDialog({ props = {}, locale = 'en', get = ok(preview()) } = {}) {
    fetchMock.mockImplementation(async (url, options = {}) => (options.method === 'POST' ? ok({ listId: 31, listName: 'Weekend', count: 4 }) : get));
    wrapper = mountUi(AddToShoppingDialog, { props: { ...PROPS, ...props }, locale });
    await flushPromises();

    return wrapper;
}
const $ = (sel) => document.body.querySelector(sel);
const $$ = (sel) => [...document.body.querySelectorAll(sel)];
const row = (i) => $$('[data-testid="shopping-row"]')[i];
const inRow = (i, testid) => row(i).querySelector(`[data-testid="${testid}"]`);
async function setValue(el, value) {
    el.value = value;
    el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
    await flushPromises();
}
async function click(el) {
    el.click();
    await flushPromises();
}
const posts = () => fetchMock.mock.calls.filter(([, o]) => o?.method === 'POST');
const submitButton = () => $('[data-testid="shopping-submit"]');

beforeEach(() => {
    fetchMock.mockReset();
    Object.values(routerMock).forEach((f) => f.mockClear());
    useToast().toasts.splice(0);
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
});
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; });

describe('AddToShoppingDialog: loading and rows', () => {
    it('asks the server for the preview on open and shows skeletons meanwhile', async () => {
        let release;
        fetchMock.mockImplementation(() => new Promise((resolve) => { release = () => resolve(ok(preview())); }));
        wrapper = mountUi(AddToShoppingDialog, { props: PROPS });
        await flushPromises();
        expect($('[data-testid="shopping-loading"]')).not.toBeNull();
        expect(submitButton().disabled).toBe(true);
        expect(fetchMock).toHaveBeenCalledWith('/recipes/12/shopping');
        release();
        await flushPromises();
        expect($('[data-testid="shopping-loading"]')).toBeNull();
        expect($$('[data-testid="shopping-row"]')).toHaveLength(4);
    });

    it('does not fetch while closed', async () => {
        await mountDialog({ props: { open: false } });
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('tags matched, suggested and not-in-catalog rows and shows the catalog name', async () => {
        await mountDialog();
        expect(inRow(0, 'row-tag').textContent.trim()).toBe('Matched');
        expect(inRow(0, 'row-catalog-name').textContent).toBe('Flour');
        expect(inRow(1, 'row-tag').textContent.trim()).toBe('Not in catalog');
        expect(inRow(2, 'row-tag').textContent.trim()).toBe('Suggested: Sea salt');
        expect(inRow(2, 'row-catalog-name').textContent).toBe('Sea salt');
        expect(row(1).querySelector('[data-testid="row-catalog-name"]')).toBeNull();
    });

    it('brings a row without an amount in as 1 pcs with a hint, and scales the others', async () => {
        await mountDialog();
        expect(inRow(2, 'row-quantity').value).toBe('1');
        expect(inRow(2, 'row-unit').value).toBe('1');
        expect(inRow(2, 'row-no-amount').textContent).toBe('No amount in the recipe.');
        expect(inRow(0, 'row-no-amount')).toBeNull();
        expect(inRow(0, 'row-quantity').value).toBe('200');
        expect(inRow(0, 'row-unit').value).toBe('2');
        expect(inRow(3, 'row-quantity').value).toBe('0.5');
        expect(inRow(3, 'row-unit').value).toBe('9');
    });

    it('uses the decimal comma of the language', async () => {
        await mountDialog({ locale: 'pl' });
        expect(inRow(3, 'row-quantity').value).toBe('0,5');
    });

    it('rescales the quantities when the servings change in the dialog', async () => {
        await mountDialog({ props: { servings: 6 } });
        expect(inRow(0, 'row-quantity').value).toBe('300');
        expect($('[data-testid="shopping-servings"]').textContent).toContain('Quantities for 6 servings');
        await click($('[data-testid="servings-plus"]'));
        expect(inRow(0, 'row-quantity').value).toBe('350');
        await click($('[data-testid="servings-reset"]'));
        expect(inRow(0, 'row-quantity').value).toBe('200');
    });

    it('does not rescale a row whose quantity was typed', async () => {
        await mountDialog();
        await setValue(inRow(0, 'row-quantity'), '123');
        await click($('[data-testid="servings-plus"]'));
        expect(inRow(0, 'row-quantity').value).toBe('123');
        expect(inRow(3, 'row-quantity').value).toBe('0.63');
    });

    it('converts g to kg when the scaled quantity reaches 1000', async () => {
        await mountDialog({ props: { servings: 24 }, get: ok(preview({ rows: [{ ...ROWS[0], quantity: '200' }] })) });
        expect(inRow(0, 'row-quantity').value).toBe('1.2');
        expect(inRow(0, 'row-unit').value).toBe('3');
    });

    it('hides the servings control for a recipe without servings', async () => {
        await mountDialog({ props: { baseServings: null } });
        expect($('[data-testid="shopping-servings"]')).toBeNull();
    });
});

describe('AddToShoppingDialog: target', () => {
    it('defaults to the most recently changed open list and offers the stores for a new list', async () => {
        await mountDialog();
        expect($('[data-testid="target-existing"]').checked).toBe(true);
        expect($('[data-testid="list-select"]').value).toBe('31');
        expect($$('[data-testid="list-select"] option').map((o) => o.textContent)).toEqual(['Weekend (Tesco)', 'Party']);
        await click($('[data-testid="target-new"]'));
        expect($('[data-testid="list-name"]').value).toBe('Tomato soup');
        expect($$('[data-testid="list-store"] option').map((o) => o.textContent)).toEqual(['No store', 'Tesco']);
    });

    it('defaults to a new list and disables Open list when there is none', async () => {
        await mountDialog({ get: ok(preview({ lists: [] })) });
        expect($('[data-testid="target-new"]').checked).toBe(true);
        expect($('[data-testid="target-existing"]').disabled).toBe(true);
        expect($('[data-testid="no-open-list"]').textContent).toBe('You have no open list you can add to.');
        expect(submitButton().textContent.trim()).toBe('Create list and add 4 items');
    });

    it('needs a name for a new list', async () => {
        await mountDialog({ get: ok(preview({ lists: [] })) });
        await setValue($('[data-testid="list-name"]'), '   ');
        expect(submitButton().disabled).toBe(true);
    });
});

describe('AddToShoppingDialog: adding up with the list', () => {
    it('converts 300 g to 0.3 kg and says it will be added up when the list has the item in kg', async () => {
        await mountDialog({ props: { servings: 6 }, get: ok(preview({ lists: KG_LISTS })) });
        expect(inRow(0, 'row-quantity').value).toBe('0.3');
        expect(inRow(0, 'row-unit').value).toBe('3');
        expect(inRow(0, 'row-merge').textContent).toBe('Already on the list: 1 kg. It will be added up.');
        expect(inRow(1, 'row-merge')).toBeNull();
    });

    it('says a different unit is kept as a separate line (pieces on the list, grams in the recipe)', async () => {
        await mountDialog();
        await setValue($('[data-testid="list-select"]'), '32');
        expect(inRow(0, 'row-merge').textContent).toBe('Different unit: kept as a separate line.');
        expect(inRow(0, 'row-unit').value).toBe('2');
    });

    it('does not convert when that would lose precision (5 g stays 5 g next to a kg line)', async () => {
        await mountDialog({ get: ok(preview({ lists: KG_LISTS, rows: [{ ...ROWS[0], quantity: '5' }] })), props: { recipe: { ...RECIPE, ingredients: [{ ...RECIPE.ingredients[0], quantity: '5' }] } } });
        expect(inRow(0, 'row-quantity').value).toBe('5');
        expect(inRow(0, 'row-unit').value).toBe('2');
        expect(inRow(0, 'row-merge').textContent).toBe('Different unit: kept as a separate line.');
    });

    it('shows nothing for a new list', async () => {
        await mountDialog({ get: ok(preview({ lists: KG_LISTS })) });
        await click($('[data-testid="target-new"]'));
        expect(inRow(0, 'row-merge')).toBeNull();
    });
});

describe('AddToShoppingDialog: changing the match', () => {
    it('lets the member pick another catalog item, search for it, or add as a typed item', async () => {
        await mountDialog();
        await click(inRow(1, 'row-change'));
        const select = () => inRow(1, 'row-catalog-select');
        expect([...select().options].map((o) => o.textContent)).toEqual(['Add as typed item', 'Flour', 'Sea salt', 'Mléko']);
        await setValue(inRow(1, 'row-search'), 'mleko');
        expect([...select().options].map((o) => o.textContent)).toEqual(['Add as typed item', 'Mléko']);
        await setValue(select(), '11');
        expect(inRow(1, 'row-tag').textContent.trim()).toBe('Matched');
        expect(inRow(1, 'row-catalog-name').textContent).toBe('Mléko');
        await click(inRow(1, 'row-change'));
        await setValue(inRow(1, 'row-catalog-select'), '');
        expect(inRow(1, 'row-tag').textContent.trim()).toBe('Not in catalog');
    });
});

describe('AddToShoppingDialog: the name of a matched row', () => {
    it('is read-only with an explanation while matched, and editable again as a typed item', async () => {
        await mountDialog();
        const name = (i) => inRow(i, 'row-name');
        expect(name(0).readOnly).toBe(true);
        const note = inRow(0, 'row-name-locked');
        expect(note.textContent).toContain('The list gets the catalog name');
        expect(name(0).getAttribute('aria-describedby')).toBe(note.id);
        // An unmatched row has no lock and no note.
        expect(name(1).readOnly).toBe(false);
        expect(inRow(1, 'row-name-locked')).toBeNull();
        expect(name(1).getAttribute('aria-describedby')).toBeNull();
        // "Add as typed item" frees the name.
        await click(inRow(0, 'row-change'));
        await setValue(inRow(0, 'row-catalog-select'), '');
        expect(name(0).readOnly).toBe(false);
        expect(inRow(0, 'row-name-locked')).toBeNull();
    });
});

describe('AddToShoppingDialog: review and footer', () => {
    it('counts the included rows and disables the button at zero', async () => {
        await mountDialog();
        expect(submitButton().textContent.trim()).toBe('Add 4 items');
        await click(inRow(0, 'row-include'));
        expect(submitButton().textContent.trim()).toBe('Add 3 items');
        for (const i of [1, 2]) await click(inRow(i, 'row-include'));
        expect(submitButton().textContent.trim()).toBe('Add 1 item');
        await click(inRow(3, 'row-include'));
        expect(submitButton().disabled).toBe(true);
    });

    it('disables the button for an invalid included quantity and shows why, but not for an unticked row', async () => {
        await mountDialog();
        await setValue(inRow(0, 'row-quantity'), '0');
        expect(submitButton().disabled).toBe(true);
        expect(inRow(0, 'row-error').textContent).toBe('Enter a quantity greater than 0.');
        await setValue(inRow(0, 'row-quantity'), '1200');
        expect(inRow(0, 'row-error').textContent).toBe('Enter a quantity above 0 and below 1000.');
        await click(inRow(0, 'row-include'));
        expect(submitButton().disabled).toBe(false);
        expect(inRow(0, 'row-error')).toBeNull();
    });

    it('needs a name on a typed row but not on a matched row', async () => {
        await mountDialog();
        await setValue(inRow(1, 'row-name'), '');
        expect(submitButton().disabled).toBe(true);
        expect(inRow(1, 'row-error').textContent).toBe('Enter a name that is not empty and not too long.');
        await setValue(inRow(1, 'row-name'), 'Eggs');
        await setValue(inRow(0, 'row-name'), '');
        expect(submitButton().disabled).toBe(false);
    });

    it('marks a scaled quantity above 999.99 as invalid', async () => {
        await mountDialog({ props: { servings: 99 }, get: ok(preview({ rows: [{ ...ROWS[1], quantity: '600', unitId: 1 }] })) });
        expect(submitButton().disabled).toBe(true);
        expect(inRow(0, 'row-error').textContent).toBe('Enter a quantity above 0 and below 1000.');
    });
});

describe('AddToShoppingDialog: submitting', () => {
    it('sends the reviewed rows to an existing list, closes, and toasts with an Open list action', async () => {
        await mountDialog();
        await setValue(inRow(1, 'row-name'), 'Large eggs');
        await setValue(inRow(1, 'row-quantity'), '6');
        await click(inRow(3, 'row-include'));
        await click(submitButton());
        expect(posts()).toHaveLength(1);
        const [url, options] = posts()[0];
        expect(url).toBe('/recipes/12/shopping');
        expect(options.body).toEqual({
            target: 'existing', list_id: 31,
            items: [
                { catalog_item_id: 7, quantity: '200', unit_id: 2 },
                { name: 'Large eggs', quantity: '6', unit_id: 1 },
                { catalog_item_id: 9, quantity: '1', unit_id: 1 },
            ],
        });
        expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
        const toast = useToast().toasts[0];
        expect(toast.message).toBe('4 items added to Weekend');
        expect(toast.action.label).toBe('Open list');
        toast.action.onSelect();
        expect(routerMock.visit).toHaveBeenCalledWith('/shopping/lists/31');
    });

    it('sends the name and store of a new list', async () => {
        await mountDialog();
        await click($('[data-testid="target-new"]'));
        await setValue($('[data-testid="list-name"]'), 'Soup night');
        await setValue($('[data-testid="list-store"]'), '5');
        await click(submitButton());
        const body = posts()[0][1].body;
        expect(body).toMatchObject({ target: 'new', name: 'Soup night', store_id: 5 });
        expect(body.items).toHaveLength(4);
        expect(body).not.toHaveProperty('list_id');
    });

    it('ignores a second click while the request runs and keeps Escape from closing', async () => {
        let release;
        await mountDialog();
        fetchMock.mockImplementation((url, options = {}) => (options.method === 'POST' ? new Promise((resolve) => { release = () => resolve(ok({ listId: 31, listName: 'Weekend', count: 4 })); }) : ok(preview())));
        const button = submitButton();
        button.click();
        button.click();
        await flushPromises();
        expect(posts()).toHaveLength(1);
        $('.ui-dialog__scrim').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flushPromises();
        expect(wrapper.emitted('update:open')).toBeUndefined();
        expect($('[data-testid="shopping-cancel"]').disabled).toBe(true);
        release();
        await flushPromises();
        expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
    });

    it('shows the locked message on 409, selects the Open list radio and focuses the list select', async () => {
        await mountDialog();
        await click($('[data-testid="target-new"]'));
        fetchMock.mockImplementation(async (url, options = {}) => (options.method === 'POST' ? { ok: false, status: 409, error: 'failed', data: { message: 'shopping.error.list_changed' } } : ok(preview())));
        await click($('[data-testid="target-existing"]'));
        await click(submitButton());
        expect($('[data-testid="shopping-error"]').textContent).toContain('This list was locked in the meantime. Choose another list.');
        expect($('[data-testid="target-existing"]').checked).toBe(true);
        expect(document.activeElement).toBe($('[data-testid="list-select"]'));
        expect(wrapper.emitted('update:open')).toBeUndefined();
    });

    it('takes the locked list out of the choices on 409 and keeps a new-list attempt on "new list"', async () => {
        await mountDialog();
        fetchMock.mockImplementation(async (url, options = {}) => (options.method === 'POST' ? { ok: false, status: 409, error: 'failed', data: {} } : ok(preview())));
        const options = () => [...$('[data-testid="list-select"]').options].map((o) => o.value);
        const before = options();
        const chosen = $('[data-testid="list-select"]').value;
        await click($('[data-testid="target-existing"]'));
        await click(submitButton());
        expect(options()).not.toContain(chosen);
        expect(options()).toHaveLength(before.length - 1);
        expect($('[data-testid="list-select"]').value).not.toBe(chosen);

        // A conflict on the new-list path does not flip the radio.
        await click($('[data-testid="target-new"]'));
        await click(submitButton());
        expect($('[data-testid="target-new"]').checked).toBe(true);
    });

    it('keeps the rows and says nothing was added on a server error', async () => {
        await mountDialog();
        await setValue(inRow(1, 'row-name'), 'Large eggs');
        fetchMock.mockImplementation(async (url, options = {}) => (options.method === 'POST' ? { ok: false, status: 500, error: 'failed', data: {} } : ok(preview())));
        await click(submitButton());
        expect($('[data-testid="shopping-error"]').textContent).toContain('We could not add the items. Nothing was added. Try again.');
        expect(inRow(1, 'row-name').value).toBe('Large eggs');
        expect(submitButton().disabled).toBe(false);
        expect(useToast().toasts).toHaveLength(0);
    });

    it.each([
        [403, 'You are not allowed to do that with this recipe.'],
        [419, 'Your session expired. Reload the page and sign in again.'],
        [429, 'Too many attempts. Wait a moment and try again.'],
        [0, 'We could not add the items. Nothing was added. Try again.'],
    ])('maps status %s to a text, never to a raw key', async (status, text) => {
        await mountDialog();
        fetchMock.mockImplementation(async (url, options = {}) => (options.method === 'POST' ? { ok: false, status, error: 'failed', data: {} } : ok(preview())));
        await click(submitButton());
        expect($('[data-testid="shopping-error"]').textContent).toContain(text);
        expect($('[data-testid="shopping-error"]').textContent).not.toMatch(/recipes\.|shopping\./);
    });

    it('says the list is gone on 404 for an existing list and that the recipe is gone for a new one', async () => {
        await mountDialog();
        fetchMock.mockImplementation(async (url, options = {}) => (options.method === 'POST' ? { ok: false, status: 404, error: 'failed', data: {} } : ok(preview())));
        await click(submitButton());
        expect($('[data-testid="shopping-error"]').textContent).toContain('That list is no longer available.');
        await click($('[data-testid="target-new"]'));
        await click(submitButton());
        expect($('[data-testid="shopping-error"]').textContent).toContain('This recipe is not available.');
    });

    it('shows a known 422 message and falls back to the generic text for an unknown one', async () => {
        await mountDialog();
        fetchMock.mockImplementation(async (url, options = {}) => (options.method === 'POST' ? { ok: false, status: 422, error: 'invalid', errors: { 'items.0.name': ['shopping.error.items_limit'] }, data: {} } : ok(preview())));
        await click(submitButton());
        expect($('[data-testid="shopping-error"]').textContent).toContain('A list can hold at most 500 items.');
        fetchMock.mockImplementation(async (url, options = {}) => (options.method === 'POST' ? { ok: false, status: 422, error: 'invalid', errors: { x: ['The x field is invalid.'] }, data: {} } : ok(preview())));
        await click(submitButton());
        expect($('[data-testid="shopping-error"]').textContent).toContain('Nothing was added.');
    });
});

describe('AddToShoppingDialog: preview failures', () => {
    it('continues with typed rows and a warning when the preview fails', async () => {
        await mountDialog({ get: { ok: false, status: 500, error: 'failed', data: {} } });
        expect($('[data-testid="shopping-preview-failed"]').textContent).toBe('We could not check the catalog. The items will be added as typed items.');
        expect($$('[data-testid="shopping-row"]')).toHaveLength(4);
        expect(inRow(0, 'row-tag').textContent.trim()).toBe('Not in catalog');
        expect(row(0).querySelector('[data-testid="row-change"]')).toBeNull();
        expect($('[data-testid="target-new"]').checked).toBe(true);
        expect($('[data-testid="target-existing"]').disabled).toBe(true);
        await click(submitButton());
        expect(posts()[0][1].body.items[0]).toEqual({ name: 'Flour', quantity: '200', unit_id: 2 });
    });

    it.each([403, 404])('closes and reports status %s', async (status) => {
        await mountDialog({ get: { ok: false, status, error: 'failed', data: {} } });
        expect(wrapper.emitted('failed')?.[0]).toEqual([status]);
        expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
    });

    it('ignores a late answer after the dialog was closed and opened again', async () => {
        const answers = [];
        fetchMock.mockImplementation(() => new Promise((resolve) => answers.push(resolve)));
        wrapper = mountUi(AddToShoppingDialog, { props: PROPS });
        await flushPromises();
        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        answers[1](ok(preview({ rows: [ROWS[1]] })));
        await flushPromises();
        answers[0](ok(preview()));
        await flushPromises();
        expect($$('[data-testid="shopping-row"]')).toHaveLength(1);
    });
});

describe('AddToShoppingDialog: safety and accessibility', () => {
    it('renders names from the server and the recipe as text, never as markup', async () => {
        const evil = '<img src=x onerror=alert(1)>';
        await mountDialog({
            props: { recipe: { ...RECIPE, title: evil } },
            get: ok(preview({
                lists: [{ ...LISTS[0], name: evil, storeName: evil }],
                catalog: [{ id: 7, name: evil, defaultUnitId: 2, imageVersion: null }],
                rows: [{ ...ROWS[0], name: evil, match: { tier: 'exact', catalogItemId: 7, catalogName: evil } }],
            })),
        });
        expect($('[data-testid="shopping-dialog"] img[src="x"]')).toBeNull();
        expect($('[data-testid="row-catalog-name"]').textContent).toBe(evil);
        expect($('[data-testid="list-select"] option').textContent).toBe(`${evil} (${evil})`);
        await click($('[data-testid="target-new"]'));
        expect($('[data-testid="list-name"]').value).toBe(evil);
    });

    it('cuts long names by characters, not inside an emoji', async () => {
        const long = '🍅'.repeat(120);
        await mountDialog({ get: ok(preview({ rows: [{ ...ROWS[1], name: long }] })) });
        expect(Array.from(inRow(0, 'row-name').value)).toHaveLength(80);
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations (%s)', async (locale) => {
        await mountDialog({ locale });
        await click(inRow(1, 'row-change'));
        await expectNoAxeViolations(document.body);
    });

    it('has no axe violations for the new-list form and the loading state', async () => {
        await mountDialog({ get: ok(preview({ lists: [] })) });
        await expectNoAxeViolations(document.body);
    });

    it('shows no raw lang key in any language', async () => {
        for (const locale of ['en', 'sk', 'pl']) {
            await mountDialog({ locale });
            expect($('[data-testid="shopping-dialog"]').textContent).not.toMatch(/recipes\.shopping|shopping\.(error|create)/);
            expect(submitButton().textContent).not.toContain('recipes.');
            wrapper.unmount();
            document.body.innerHTML = '';
        }
    });
});
