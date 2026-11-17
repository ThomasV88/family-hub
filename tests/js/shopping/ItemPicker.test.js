import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';

let mockProps = {};
const { forms } = vi.hoisted(() => ({ forms: [] }));
vi.mock('@inertiajs/vue3', async () => {
    const { h, reactive } = await import('vue');
    return {
        usePage: () => ({ props: mockProps, url: '/shopping/lists/5' }),
        router: { get: vi.fn(), post: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() },
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
        useForm: (data) => {
            const form = reactive({
                ...data,
                processing: false,
                errors: {},
                post: vi.fn((url, opts) => opts?.onSuccess?.()),
                put: vi.fn(),
                delete: vi.fn(),
                transform(cb) { form.transformer = cb; return form; },
                clearErrors() { form.errors = {}; },
            });
            forms.push(form);
            return form;
        },
    };
});

import ItemPickerDialog from '../../../resources/js/Shopping/ItemPickerDialog.vue';
import Show from '../../../resources/js/Pages/Shopping/Show.vue';

const UNITS = [{ id: 1, key: 'pcs', names: null }, { id: 2, key: 'kg', names: null }, { id: 3, key: 'l', names: null }];
const CATALOG = [
    { id: 11, name: 'Mliečko', defaultUnitId: 3, imageVersion: 2 },
    { id: 12, name: 'Jablká', defaultUnitId: 2, imageVersion: null },
    { id: 13, name: 'Chlieb', defaultUnitId: null, imageVersion: null },
];

let current;
function mountPicker({ locale = 'en', messages = null, ...props } = {}) {
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {} });
    current = mount(ItemPickerDialog, {
        props: { open: true, listId: 5, catalog: CATALOG, onListIds: [], units: UNITS, canManageCatalog: false, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] },
    });
    return current;
}
const dialog = () => document.body.querySelector('[role="dialog"]');
const tiles = () => [...dialog().querySelectorAll('[data-testid="tile"]')];
const tile = (name) => tiles().find((t) => t.textContent.includes(name));
const tileButton = (name) => tile(name).querySelector('button[aria-pressed]');
const button = (label) => [...dialog().querySelectorAll('button')].find((b) => b.textContent.trim() === label || b.getAttribute('aria-label') === label);
const typeSearch = async (value) => {
    const input = dialog().querySelector('input[type="search"]');
    input.value = value;
    input.dispatchEvent(new Event('input'));
    await flushPromises();
};
const settle = () => flushPromises();
const qtyInput = (name) => tile(name).querySelector('[data-testid="qty-input"]');
const qtyOf = (name) => qtyInput(name).value;
const typeQty = async (name, value) => {
    const input = qtyInput(name);
    input.value = value;
    input.dispatchEvent(new Event('input'));
    await flushPromises();
};
const setUnit = async (name, id) => {
    const select = tile(name).querySelector('select');
    select.value = String(id);
    select.dispatchEvent(new Event('change'));
    await flushPromises();
};
const GRAMS = [{ id: 1, key: 'pcs', names: null }, { id: 2, key: 'kg', names: null }, { id: 3, key: 'l', names: null }, { id: 4, key: 'g', names: null }, { id: 5, key: 'ml', names: null }];

beforeEach(() => { forms.length = 0; });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; delete document.documentElement.dataset.theme; });

describe('Item picker', () => {
    it('shows the catalog as picture tiles and filters ignoring accents and case', async () => {
        mountPicker();
        await settle();
        expect(tiles()).toHaveLength(3);
        expect(tile('Mliečko').querySelector('img').getAttribute('src')).toBe('/shopping/catalog/11/image/thumb?v=2');
        await typeSearch('MLIECKO');
        expect(tiles().map((t) => t.textContent)).toEqual([expect.stringContaining('Mliečko')]);
        await typeSearch('zzz');
        expect(dialog().querySelector('[data-testid="no-match"]').textContent).toBe('No catalog item matches “zzz”.');
    });

    it('selects several tiles, steps the quantity (kg by 0.1) and posts one request with every item', async () => {
        mountPicker();
        await settle();
        expect(button('Add items').disabled).toBe(true);
        tileButton('Mliečko').click();
        tileButton('Jablká').click();
        await settle();
        expect(tileButton('Mliečko').getAttribute('aria-pressed')).toBe('true');
        button('More Jablká').click();
        button('More Jablká').click();
        await settle();
        expect(qtyOf('Jablká')).toBe('1.2');
        button('Less Jablká').click();
        button('Less Jablká').click();
        button('Less Jablká').click();
        await settle();
        expect(qtyOf('Jablká')).toBe('0.9');
        button('More Jablká').click();
        await settle();
        expect(qtyOf('Jablká')).toBe('1');
        // the unit of the second tile changes to pieces: no fraction is left on a unit that has none
        const select = tile('Jablká').querySelector('select');
        select.value = '1';
        select.dispatchEvent(new Event('change'));
        await settle();
        expect(qtyOf('Jablká')).toBe('1');
        dialog().querySelector('[data-testid="type-own"]').click();
        await settle();
        const own = dialog().querySelector('[data-testid="own-form"]');
        const inputs = own.querySelectorAll('input[type="text"], input:not([type]), input[inputmode="decimal"]');
        inputs[0].value = 'Kiwi';
        inputs[0].dispatchEvent(new Event('input'));
        own.dispatchEvent(new Event('submit'));
        await settle();
        expect(dialog().querySelector('[data-testid="picker-live"]').textContent).toBe('3 selected');
        expect(button('Add 3 items')).toBeTruthy();
        button('Add 3 items').click();
        await settle();
        const form = forms[0];
        expect(form.post).toHaveBeenCalledWith('/shopping/lists/5/items', expect.any(Object));
        expect(form.items).toEqual([
            { catalog_item_id: 11, quantity: '1', unit_id: 3 },
            { catalog_item_id: 12, quantity: '1', unit_id: 1 },
            { name: 'Kiwi', quantity: '1', unit_id: 1 },
        ]);
        expect(current.emitted('update:open')?.at(-1)).toEqual([false]);
    });

    it('uses the singular footer label for one item', async () => {
        mountPicker();
        await settle();
        tileButton('Chlieb').click();
        await settle();
        expect(button('Add 1 item')).toBeTruthy();
        expect(forms[0].items).toEqual([]);
        button('Add 1 item').click();
        expect(forms[0].items).toEqual([{ catalog_item_id: 13, quantity: '1', unit_id: null }]);
    });

    it.each([['sk', ['Pridať 1 položku', 'Pridať 2 položky', 'Pridať 5 položiek']], ['pl', ['Dodaj 1 produkt', 'Dodaj 2 produkty', 'Dodaj 5 produktów']]])('uses the plural forms of %s', async (locale, labels) => {
        const many = Array.from({ length: 5 }, (_, i) => ({ id: 100 + i, name: `Item ${i}`, defaultUnitId: null, imageVersion: null }));
        mountPicker({ locale, catalog: many });
        await settle();
        const seen = [];
        for (let i = 0; i < 5; i++) {
            tileButton(`Item ${i}`).click();
            await settle();
            seen.push(dialog().querySelector('[data-testid="picker-add"]').textContent.trim());
        }
        expect([seen[0], seen[1], seen[4]]).toEqual(labels);
    });

    it('marks catalog items that are already on the list', async () => {
        mountPicker({ onListIds: [12] });
        await settle();
        expect(tile('Jablká').textContent).toContain('On list');
        expect(tile('Mliečko').textContent).not.toContain('On list');
    });

    it('offers a free-text row when typing and adds it to the selection without touching the catalog flag', async () => {
        mountPicker({ canManageCatalog: true });
        await settle();
        expect(dialog().querySelector('[data-testid="type-own"]')).not.toBeNull();
        await typeSearch('Kiwi');
        expect(dialog().querySelector('[data-testid="type-own"]')).toBeNull();
        const row = dialog().querySelector('[data-testid="free-row"]');
        expect(row.textContent).toContain('Add “Kiwi” as a new item');
        row.click();
        await settle();
        expect(dialog().querySelector('[data-testid="own-row"]').textContent).toContain('Kiwi');
        button('Add 1 item').click();
        expect(forms[0].items).toEqual([{ name: 'Kiwi', quantity: '1', unit_id: 1 }]);
    });

    it('shows Also save to catalog only to catalog managers, unchecked, and sends the flag when ticked', async () => {
        mountPicker();
        await settle();
        dialog().querySelector('[data-testid="type-own"]').click();
        await settle();
        expect(dialog().textContent).not.toContain('Also save to catalog');
        current.unmount();
        document.body.innerHTML = '';
        forms.length = 0;
        mountPicker({ canManageCatalog: true });
        await settle();
        dialog().querySelector('[data-testid="type-own"]').click();
        await settle();
        const box = dialog().querySelector('[data-testid="own-form"] input[type="checkbox"]');
        expect(box.checked).toBe(false);
        const own = dialog().querySelector('[data-testid="own-form"]');
        const [name, quantity] = own.querySelectorAll('input:not([type="checkbox"])');
        name.value = 'Soap';
        name.dispatchEvent(new Event('input'));
        quantity.value = '1,5';
        quantity.dispatchEvent(new Event('input'));
        box.click();
        await settle();
        own.dispatchEvent(new Event('submit'));
        await settle();
        button('Add 1 item').click();
        expect(forms[0].items).toEqual([{ name: 'Soap', quantity: '1.5', unit_id: 1, save_to_catalog: true }]);
    });

    it('validates the own item: a name and a quantity above zero', async () => {
        mountPicker();
        await settle();
        dialog().querySelector('[data-testid="type-own"]').click();
        await settle();
        const own = dialog().querySelector('[data-testid="own-form"]');
        const [name, quantity] = own.querySelectorAll('input');
        quantity.value = '0';
        quantity.dispatchEvent(new Event('input'));
        own.dispatchEvent(new Event('submit'));
        await settle();
        expect(dialog().textContent).toContain('Enter a name that is not empty and not too long.');
        expect(dialog().textContent).toContain('Enter a quantity greater than 0.');
        expect(dialog().querySelector('[data-testid="own-row"]')).toBeNull();
        expect(name).toBeTruthy();
    });

    it('shows the empty catalog state with the free-text form, and the settings link only for managers', async () => {
        mountPicker({ catalog: [] });
        await settle();
        expect(dialog().textContent).toContain('The catalog is empty');
        expect(dialog().textContent).toContain('Type your own item below.');
        expect(dialog().querySelector('[data-testid="type-own"]')).not.toBeNull();
        expect(dialog().querySelector('a[href="/settings/shopping"]')).toBeNull();
        current.unmount();
        document.body.innerHTML = '';
        mountPicker({ catalog: [], canManageCatalog: true });
        await settle();
        expect(dialog().querySelector('a[href="/settings/shopping"]').textContent).toBe('Add items in Shopping settings');
    });

    it('lets a quantity be typed, with a decimal comma, and sends it', async () => {
        mountPicker({ units: GRAMS });
        await settle();
        tileButton('Jablká').click();
        await settle();
        await typeQty('Jablká', '1,25');
        button('Add 1 item').click();
        await settle();
        expect(forms[0].items).toEqual([{ catalog_item_id: 12, quantity: '1.25', unit_id: 2 }]);
    });

    it('does not add while a typed quantity is invalid and says why', async () => {
        mountPicker({ units: GRAMS });
        await settle();
        tileButton('Jablká').click();
        await settle();
        for (const bad of ['', '0', 'abc', '1000', '1.234']) {
            await typeQty('Jablká', bad);
            expect(qtyInput('Jablká').getAttribute('aria-invalid')).toBe('true');
            expect(dialog().querySelector('[data-testid="picker-error"]').textContent).toContain('Enter a quantity greater than 0.');
            expect(dialog().querySelector('[data-testid="picker-add"]').disabled).toBe(true);
        }
        await typeQty('Jablká', '2');
        expect(dialog().querySelector('[data-testid="picker-add"]').disabled).toBe(false);
        expect(forms[0].post).not.toHaveBeenCalled();
    });

    it('steps grams and millilitres by 10 and pieces by 1', async () => {
        mountPicker({ units: GRAMS });
        await settle();
        tileButton('Jablká').click();
        await settle();
        await setUnit('Jablká', 4);
        await typeQty('Jablká', '250');
        button('More Jablká').click();
        await settle();
        expect(qtyOf('Jablká')).toBe('260');
        await setUnit('Jablká', 1);
        expect(qtyOf('Jablká')).toBe('260');
        button('Less Jablká').click();
        await settle();
        expect(qtyOf('Jablká')).toBe('259');
        await setUnit('Jablká', 5);
        button('More Jablká').click();
        await settle();
        expect(qtyOf('Jablká')).toBe('269');
    });

    it('converts g to kg and ml to l on a unit change and rounds a fraction away from pieces', async () => {
        mountPicker({ units: GRAMS });
        await settle();
        tileButton('Jablká').click();
        await settle();
        await setUnit('Jablká', 4);
        await typeQty('Jablká', '250');
        await setUnit('Jablká', 2);
        expect(qtyOf('Jablká')).toBe('0.25');
        await setUnit('Jablká', 4);
        expect(qtyOf('Jablká')).toBe('250');
        await setUnit('Jablká', 2);
        await typeQty('Jablká', '1.5');
        await setUnit('Jablká', 1);
        expect(qtyOf('Jablká')).toBe('2');
        expect(forms[0]).toBeTruthy();
    });

    it('keeps free text usable when the catalog could not be loaded', async () => {
        mountPicker({ catalog: null });
        await settle();
        expect(dialog().querySelector('[data-testid="catalog-error"]').textContent).toContain('We could not load the catalog. You can still type your own item.');
        expect(dialog().querySelector('[data-testid="type-own"]')).not.toBeNull();
    });

    it('shows a server error and stays open', async () => {
        mountPicker();
        await settle();
        forms[0].errors = { 'items.0.quantity': 'shopping.error.quantity' };
        await settle();
        expect(dialog().querySelector('[data-testid="picker-error"]').textContent).toContain('Enter a quantity greater than 0.');
    });

    it('renders catalog names as text, never as markup', async () => {
        mountPicker({ catalog: [{ id: 1, name: '<img src=x onerror=alert(1)>', defaultUnitId: null, imageVersion: null }] });
        await settle();
        expect(dialog().querySelector('img[src="x"]')).toBeNull();
        expect(tiles()[0].textContent).toContain('<img src=x onerror=alert(1)>');
    });

    it('opens once after creating a list: flash open_picker on an empty list, never on a list with items or without the right', async () => {
        const base = {
            locale: 'en', flash: { status: null, toast: null, data: { open_picker: true } }, availableLocales: ['sk', 'pl', 'en'],
            auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { shopping: 'edit' } }, app: { env: 'local', styleGuide: false },
        };
        const list = { id: 5, name: 'New', status: 'open', store: null, storeName: null, creator: { id: 2, name: 'Mira' }, responsible: { id: 2, name: 'Mira' }, remindAt: null, reminderPassed: false };
        const open = (props, flash = base.flash) => {
            mockProps = { ...base, flash };
            window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {} });
            current = mount(Show, { props: { list, items: [], abilities: { editItems: true }, units: UNITS, catalog: CATALOG, ...props }, attachTo: document.body, global: { plugins: [createAppI18n('en', loadMessages('en'))] } });
        };
        const picker = () => [...document.body.querySelectorAll('[role="dialog"]')].some((d) => d.textContent.includes('Search items') || d.textContent.includes('Type your own item'));
        open({});
        await settle();
        expect(picker()).toBe(true);
        current.unmount();
        document.body.innerHTML = '';
        open({}, { ...base.flash, data: null });
        await settle();
        expect(picker()).toBe(false);
        current.unmount();
        open({ items: [{ id: 1, name: 'Milk', quantity: '1.00', unitId: 1, note: null, state: 'pending', catalogItemId: null, imageVersion: null }] });
        await settle();
        expect(picker()).toBe(false);
        current.unmount();
        open({ abilities: { editItems: false }, catalog: null });
        await settle();
        expect(picker()).toBe(false);
    });

    it('opens from the Add items button', async () => {
        mockProps = { locale: 'en', flash: { status: null, toast: null, data: null }, availableLocales: ['en'], auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { shopping: 'edit' } }, app: { env: 'local', styleGuide: false } };
        window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {} });
        const list = { id: 5, name: 'L', status: 'open', store: null, storeName: null, creator: { id: 2, name: 'Mira' }, responsible: { id: 2, name: 'Mira' }, remindAt: null, reminderPassed: false };
        current = mount(Show, { props: { list, items: [], abilities: { editItems: true }, units: UNITS, catalog: CATALOG }, attachTo: document.body, global: { plugins: [createAppI18n('en', loadMessages('en'))] } });
        await current.find('[data-testid="add-items"]').trigger('click');
        await settle();
        expect(dialog().textContent).toContain('Add items');
        expect(tiles()).toHaveLength(3);
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`has no axe violations for the grid, selection, own items and empty states (${locale}, ${dark ? 'dark' : 'light'})`, async () => {
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                const base = loadMessages(locale);
                const catalog = [...CATALOG, { id: 14, name: pseudoLong('Dlhý názov'), defaultUnitId: 1, imageVersion: null }];
                mountPicker({ locale, messages: base, catalog, onListIds: [12], canManageCatalog: true });
                await settle();
                await expectNoAxeViolations(document.body);
                tileButton('Mliečko').click();
                dialog().querySelector('[data-testid="type-own"]').click();
                await settle();
                await expectNoAxeViolations(document.body);
                await typeSearch('Kiwi');
                dialog().querySelector('[data-testid="free-row"]').click();
                await settle();
                await expectNoAxeViolations(document.body);
                await typeSearch('zzz');
                await expectNoAxeViolations(document.body);
                current.unmount();
                document.body.innerHTML = '';
                mountPicker({ locale, messages: base, catalog: [], canManageCatalog: true });
                await settle();
                await expectNoAxeViolations(document.body);
                current.unmount();
                document.body.innerHTML = '';
                mountPicker({ locale, messages: base, catalog: null });
                await settle();
                await expectNoAxeViolations(document.body);
            });
        }
    }
});
