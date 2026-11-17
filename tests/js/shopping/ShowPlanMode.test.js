import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';

let mockProps = {};
const { routerMock, forms } = vi.hoisted(() => ({
    routerMock: { get: vi.fn(), post: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() },
    forms: [],
}));
vi.mock('@inertiajs/vue3', async () => {
    const { h, reactive } = await import('vue');
    return {
        usePage: () => ({ props: mockProps, url: '/shopping/lists/5' }),
        router: routerMock,
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
        useForm: (data) => {
            const form = reactive({
                ...data,
                processing: false,
                errors: {},
                post: vi.fn((url, opts) => opts?.onSuccess?.()),
                put: vi.fn((url, opts) => opts?.onSuccess?.()),
                delete: vi.fn((url, opts) => { opts?.onSuccess?.(); opts?.onFinish?.(); }),
                transform(cb) { form.transformer = cb; return form; },
                clearErrors() { form.errors = {}; },
            });
            forms.push(form);
            return form;
        },
    };
});

import Show from '../../../resources/js/Pages/Shopping/Show.vue';

const ME = 2;
const UNITS = [
    { id: 1, key: 'pcs', names: null },
    { id: 2, key: 'kg', names: null },
    { id: 3, key: 'l', names: null },
    { id: 4, key: 'pack', names: null },
];
const item = (id, extra = {}) => ({ id, name: `Item ${id}`, quantity: '1.00', unitId: 1, note: null, state: 'pending', catalogItemId: null, imageVersion: null, ...extra });
const ITEMS = [
    item(1, { name: 'Mlieko', quantity: '1.50', unitId: 3, catalogItemId: 11, imageVersion: 3, note: 'bez laktózy' }),
    item(2, { name: 'Bread', quantity: '1.00', unitId: 1 }),
    item(3, { name: 'Apples', quantity: '2.00', unitId: 2, catalogItemId: 12 }),
];
const LIST = {
    id: 5, name: 'Týždenný nákup', status: 'open', store: { id: 4, name: 'Tesco', logoVersion: 2 }, storeName: 'Tesco',
    creator: { id: ME, name: 'Mira Nováková' }, responsible: { id: ME, name: 'Mira Nováková' }, responsibleFallback: null,
    remindAt: '2026-11-20T18:00:00+01:00', reminderPassed: false, reminderSent: false, lockedAt: null, doneAt: null, source: null,
};
const ALL = { editItems: true, copy: true, delete: true, lock: true, manageCatalog: false };
const NONE = { editItems: false, copy: false, delete: false };

let current;
function mountPage({ locale = 'en', messages = null, ...props } = {}) {
    window.innerWidth = 1024;
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: ME, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { shopping: 'edit' } },
        app: { env: 'local', styleGuide: false },
    };
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(Show, {
        props: { list: LIST, items: ITEMS, abilities: ALL, members: { contributors: [], viewers: [] }, units: UNITS, stores: [], catalog: [], ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] },
    });
    return current;
}
const rows = () => current.findAll('[data-testid="item-row"]');
const moreButton = () => current.findAll('button[aria-haspopup="menu"]').find((b) => b.text() === 'More actions') ?? { exists: () => false, trigger: async () => {} };
const editForm = () => forms.find((f) => 'unit_id' in f);
const dialog = () => document.body.querySelector('[role="dialog"]');
const dialogButton = (label) => [...dialog().querySelectorAll('button')].find((b) => b.textContent.trim() === label);

beforeEach(() => { Object.values(routerMock).forEach((f) => f.mockClear()); forms.length = 0; });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; delete document.documentElement.dataset.theme; });

describe('Shopping list page in plan mode', () => {
    it('shows the header: back link, name, state tag, store, responsible person and reminder', () => {
        mountPage();
        expect(current.find('h1').text()).toBe('Týždenný nákup');
        expect(current.find('a[href="/shopping"]').text()).toContain('Shopping');
        expect(current.text()).toContain('Open');
        expect(current.find('[data-testid="header-store"]').text()).toBe('Tesco');
        expect(current.find('[data-testid="header-store"] img').attributes('src')).toBe('/shopping/stores/4/image/thumb?v=2');
        expect(current.find('[data-testid="header-responsible"]').text()).toContain('Responsible: Mira Nováková');
        expect(current.find('[data-testid="header-reminder"]').text()).toContain('Reminder:');
    });

    it('shows the Locked tag and the reminder-passed tag', () => {
        mountPage({ list: { ...LIST, status: 'locked', reminderPassed: true }, abilities: NONE });
        expect(current.text()).toContain('Locked');
        expect(current.text()).toContain('Reminder passed');
    });

    it('shows items with picture, name, formatted quantity and note, omitting 1 pcs', () => {
        mountPage();
        expect(rows()).toHaveLength(3);
        expect(rows()[0].find('img').attributes('src')).toBe('/shopping/catalog/11/image/thumb?v=3');
        expect(rows()[0].text()).toContain('Mlieko');
        expect(rows()[0].find('[data-testid="item-quantity"]').text()).toBe('1.5 l');
        expect(rows()[0].text()).toContain('bez laktózy');
        expect(rows()[1].find('[data-testid="item-quantity"]').exists()).toBe(false);
        expect(rows()[1].find('img').exists()).toBe(false);
        expect(rows()[2].find('[data-testid="item-quantity"]').text()).toBe('2 kg');
    });

    it('formats quantities with the decimal comma in Slovak', () => {
        mountPage({ locale: 'sk' });
        expect(rows()[0].find('[data-testid="item-quantity"]').text()).toBe('1,5 l');
    });

    it('lets an allowed person tap a row to edit; a viewer gets non-interactive rows and no Add controls', () => {
        mountPage();
        expect(rows()[0].find('button.ui-row__main--interactive').exists()).toBe(true);
        current.unmount();
        mountPage({ abilities: NONE, catalog: null });
        expect(current.find('button.ui-row__main--interactive').exists()).toBe(false);
        expect(rows()).toHaveLength(3);
        expect(current.findAll('button').some((b) => b.text() === 'Add items')).toBe(false);
    });

    it('shows the empty state with a hint only for people who may add items', () => {
        mountPage({ items: [] });
        expect(current.text()).toContain('This list is empty');
        expect(current.text()).toContain('Add items from the catalog or type your own.');
        current.unmount();
        mountPage({ items: [], abilities: NONE, catalog: null });
        expect(current.text()).toContain('This list is empty');
        expect(current.text()).not.toContain('Add items from the catalog');
    });

    it('saves an edit with the quantity as typed and the unit and note', async () => {
        mountPage();
        await rows()[0].find('button.ui-row__main--interactive').trigger('click');
        await flushPromises();
        expect(dialog().textContent).toContain('Edit item');
        const form = editForm();
        expect(form.quantity).toBe('1.5');
        expect(form.unit_id).toBe('3');
        form.quantity = '0,5';
        form.unit_id = '2';
        form.note = ' ';
        await flushPromises();
        dialog().querySelector('form#item-edit-form').dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(form.put).toHaveBeenCalledWith('/shopping/lists/5/items/1', expect.any(Object));
        expect(form.transformer(form)).toEqual({ quantity: '0,5', unit_id: 2, note: null });
    });

    it('does not offer the name of a catalog item for editing', async () => {
        mountPage();
        await rows()[0].find('button.ui-row__main--interactive').trigger('click');
        await flushPromises();
        expect(dialog().querySelector('input[maxlength="80"]')).toBeNull();
        expect(dialog().textContent).not.toContain('Not in catalog');
        dialog().querySelector('form#item-edit-form').dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(editForm().transformer(editForm())).not.toHaveProperty('name');
    });

    it('edits the name of a free-text item and tags it', async () => {
        mountPage();
        await rows()[1].find('button.ui-row__main--interactive').trigger('click');
        await flushPromises();
        expect(dialog().textContent).toContain('Not in catalog');
        const form = editForm();
        form.name = ' Rye bread ';
        dialog().querySelector('form#item-edit-form').dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(form.transformer(form)).toMatchObject({ name: 'Rye bread' });
    });

    it('shows a server error of the edit dialog', async () => {
        mountPage();
        await rows()[0].find('button.ui-row__main--interactive').trigger('click');
        await flushPromises();
        editForm().errors = { quantity: 'shopping.error.quantity' };
        await flushPromises();
        expect(dialog().textContent).toContain('Enter a quantity greater than 0.');
        expect(dialog().textContent).toContain('Your changes were not saved.');
    });

    it('removes an item only after the confirmation', async () => {
        mountPage();
        await rows()[0].find('button.ui-row__main--interactive').trigger('click');
        await flushPromises();
        const form = editForm();
        dialogButton('Remove item').click();
        await flushPromises();
        expect(dialog().textContent).toContain('Remove Mlieko from this list?');
        expect(form.delete).not.toHaveBeenCalled();
        dialogButton('Keep item').click();
        await flushPromises();
        expect(form.delete).not.toHaveBeenCalled();
        expect(dialog().textContent).toContain('Edit item');
        dialogButton('Remove item').click();
        await flushPromises();
        dialogButton('Remove item').click();
        await flushPromises();
        expect(form.delete).toHaveBeenCalledWith('/shopping/lists/5/items/1', expect.any(Object));
    });

    it('renders locked and finished lists read-only with Bought and Unavailable states', () => {
        const states = [item(1, { name: 'Milk', state: 'bought' }), item(2, { name: 'Salt', state: 'unavailable' }), item(3, { name: 'Tea' })];
        mountPage({ list: { ...LIST, status: 'locked' }, items: states, abilities: { ...NONE, copy: true } });
        expect(current.find('button.ui-row__main--interactive').exists()).toBe(false);
        expect(rows()[0].text()).toContain('Bought');
        expect(rows()[1].text()).toContain('Unavailable');
        expect(rows()[2].text()).not.toContain('Bought');
        expect(rows()[0].find('.shop-item__name').element.closest('s, del')).toBeNull();
        current.unmount();
        mountPage({ list: { ...LIST, status: 'done' }, items: states, abilities: { ...NONE, copy: true } });
        expect(current.find('[data-testid="archived-banner"]').text()).toContain('This shopping is finished.');
    });

    const menuLabels = () => [...document.body.querySelectorAll('[role="menu"][aria-label="More actions"] [role="menuitem"]')].map((b) => b.textContent.trim());

    it('offers List settings, Send by e-mail, Copy and Delete in this order according to the abilities', async () => {
        mountPage({ abilities: { ...ALL, mail: true } });
        await moreButton().trigger('click');
        await flushPromises();
        expect(menuLabels()).toEqual(['List settings', 'Send by e-mail', 'Copy list', 'Delete list']);
        current.unmount();
        mountPage();
        await moreButton().trigger('click');
        await flushPromises();
        expect(menuLabels()).toEqual(['List settings', 'Copy list', 'Delete list']);
        current.unmount();
        mountPage({ abilities: { ...ALL, delete: false } });
        await moreButton().trigger('click');
        await flushPromises();
        expect(menuLabels()).toEqual(['List settings', 'Copy list']);
        current.unmount();
        // Everyone with access can open the (read-only) settings, so the menu is never empty.
        mountPage({ abilities: NONE });
        await moreButton().trigger('click');
        await flushPromises();
        expect(menuLabels()).toEqual(['List settings']);
    });

    it('hides Send by e-mail from a viewer and shows it to a contributor on a finished list', async () => {
        mountPage({ list: { ...LIST, status: 'done' }, abilities: { ...NONE, mail: false } });
        await moreButton().trigger('click');
        await flushPromises();
        expect(menuLabels()).not.toContain('Send by e-mail');
        current.unmount();
        mountPage({ list: { ...LIST, status: 'done' }, abilities: { ...NONE, mail: true } });
        await moreButton().trigger('click');
        await flushPromises();
        expect(menuLabels()).toContain('Send by e-mail');
    });

    it('opens the settings panel and the e-mail dialog from the menu', async () => {
        mountPage({ abilities: { ...ALL, mail: true, editSettings: true, changeResponsible: true, setReminder: true, manageMembers: true } });
        await moreButton().trigger('click');
        await flushPromises();
        [...document.body.querySelectorAll('[role="menu"][aria-label="More actions"] [role="menuitem"]')].find((b) => b.textContent.includes('List settings')).click();
        await flushPromises();
        expect(dialog().querySelector('h2').textContent).toBe('List settings');
        dialog().querySelector('button[aria-label="Close"]').click();
        await flushPromises();
        await moreButton().trigger('click');
        await flushPromises();
        [...document.body.querySelectorAll('[role="menu"][aria-label="More actions"] [role="menuitem"]')].find((b) => b.textContent.includes('Send by e-mail')).click();
        await flushPromises();
        expect(dialog().querySelector('h2').textContent).toBe('Send by e-mail');
    });

    it('tells the creator why they became responsible, and nobody else', () => {
        mountPage({ list: { ...LIST, responsibleFallback: { previousName: 'Jozef' } } });
        expect(current.find('[data-testid="takeover-alert"]').text()).toBe('You are now responsible for this list because Jozef was removed.');
        current.unmount();
        mountPage({ list: { ...LIST, responsibleFallback: { previousName: null } } });
        expect(current.find('[data-testid="takeover-alert"]').text()).toContain('because the previous person was removed');
        current.unmount();
        mountPage();
        expect(current.find('[data-testid="takeover-alert"]').exists()).toBe(false);
    });

    it('marks a sent reminder in the header', () => {
        mountPage({ list: { ...LIST, reminderSent: true } });
        expect(current.find('[data-testid="header-reminder"]').text()).toContain('(sent)');
    });

    it('deletes the list after the danger confirmation', async () => {
        mountPage();
        await moreButton().trigger('click');
        await flushPromises();
        [...document.body.querySelectorAll('[role="menu"][aria-label="More actions"] [role="menuitem"]')].find((b) => b.textContent.includes('Delete list')).click();
        await flushPromises();
        expect(dialog().textContent).toContain('Delete “Týždenný nákup”?');
        expect(dialog().textContent).toContain('The list and its items will be deleted for everyone. This cannot be undone.');
        expect(routerMock.delete).not.toHaveBeenCalled();
        dialogButton('Delete list').click();
        await flushPromises();
        expect(routerMock.delete).toHaveBeenCalledWith('/shopping/lists/5');
    });

    it('opens the copy dialog from the menu', async () => {
        mountPage();
        await moreButton().trigger('click');
        await flushPromises();
        [...document.body.querySelectorAll('[role="menu"][aria-label="More actions"] [role="menuitem"]')].find((b) => b.textContent.includes('Copy list')).click();
        await flushPromises();
        expect(dialog().textContent).toContain('Name of the new list');
        expect(dialog().querySelector('input').value).toBe('Týždenný nákup (copy)');
    });

    it('renders names and notes as text, never as markup', () => {
        mountPage({ items: [item(1, { name: '<img src=x onerror=alert(1)>', note: '<b onmouseover=alert(2)>n</b>' })], list: { ...LIST, name: '<script>alert(3)</script>' } });
        expect(current.find('img[src="x"]').exists()).toBe(false);
        expect(current.find('b').exists()).toBe(false);
        expect(rows()[0].text()).toContain('<img src=x onerror=alert(1)>');
        expect(current.find('h1').text()).toBe('<script>alert(3)</script>');
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`has no axe violations for the page, the edit dialog and the confirmations (${locale}, ${dark ? 'dark' : 'light'})`, async () => {
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                const base = loadMessages(locale);
                const long = [...ITEMS, item(9, { name: pseudoLong('Dlhý názov položky'), note: pseudoLong('Poznámka') })];
                mountPage({ locale, messages: base, items: long, list: { ...LIST, name: pseudoLong('Zoznam') } });
                await expectNoAxeViolations(current.element);
                await rows()[1].find('button.ui-row__main--interactive').trigger('click');
                await flushPromises();
                await expectNoAxeViolations(document.body);
                dialogButton(base.shopping.item.remove).click();
                await flushPromises();
                await expectNoAxeViolations(document.body);
                dialogButton(base.shopping.item.remove_cancel).click();
                await flushPromises();
                dialogButton(base.shopping.item.cancel).click();
                await flushPromises();
                current.unmount();
                mountPage({ locale, messages: base, items: [item(1, { state: 'bought' }), item(2, { state: 'unavailable' })], list: { ...LIST, status: 'locked' }, abilities: NONE });
                await expectNoAxeViolations(current.element);
                current.unmount();
                mountPage({ locale, messages: base, items: [], abilities: NONE });
                await expectNoAxeViolations(current.element);
            });
        }
    }
});
