import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { installHelp } from '../helpers/help.js';
import { resetHelp } from '../../../resources/js/Help/helpStore.js';

let mockProps = {};
const { routerMock, forms } = vi.hoisted(() => ({
    routerMock: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), visit: vi.fn(), replace: vi.fn(), reload: vi.fn(), on: vi.fn() },
    forms: [],
}));
vi.mock('@inertiajs/vue3', async () => {
    const { h, reactive } = await import('vue');
    return {
        usePage: () => ({ props: mockProps }),
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
                transform() { return form; },
                clearErrors() { form.errors = {}; },
            });
            forms.push(form);
            return form;
        },
    };
});

import Shopping from '../../../resources/js/Pages/Settings/Shopping.vue';

const SECTIONS = [
    { key: 'shopping', href: '/settings/shopping', labelKey: 'settings.section.shopping', icon: 'shopping-cart' },
    { key: 'appearance', href: '/settings/appearance', labelKey: 'settings.section.appearance', icon: 'palette' },
];
const UNITS = [
    { id: 1, key: 'pcs', names: null, builtIn: true, usedOn: 0 },
    { id: 3, key: 'kg', names: null, builtIn: true, usedOn: 0 },
    { id: 6, key: 'pack', names: null, builtIn: true, usedOn: 0 },
    { id: 10, key: null, names: { en: 'tin', sk: 'plech', pl: 'puszka' }, builtIn: false, usedOn: 3 },
];
const CATALOG = [
    { id: 1, name: 'Mliéko', defaultUnitId: 3, imageVersion: 2, usedOn: 2 },
    { id: 2, name: 'Apple', defaultUnitId: null, imageVersion: null, usedOn: 0 },
];
const STORES = [{ id: 1, name: 'Tesco', logoVersion: 1, usedOn: 1 }, { id: 2, name: 'Lidl', logoVersion: null, usedOn: 0 }];

let current;
function mountPage({ locale = 'en', messages = null, ...props } = {}) {
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 1, name: 'Anna', email: 'a@example.test', isAdmin: false }, access: {} },
        app: { env: 'local', styleGuide: false },
    };
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(Shopping, {
        props: { sections: SECTIONS, catalog: CATALOG, stores: STORES, units: UNITS, canManageUnits: false, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] },
    });
    return current;
}
const button = (label) => current.findAll('button').find((b) => b.text().includes(label) || b.attributes('aria-label')?.includes(label));
const openTab = async (name) => { await current.findAll('[role="tab"]').find((b) => b.text() === name).trigger('click'); };
const dialogText = () => document.body.querySelector('[role="dialog"]')?.textContent ?? '';

beforeEach(() => { installHelp('en'); Object.values(routerMock).forEach((f) => f.mockClear()); forms.length = 0; });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; resetHelp(); delete document.documentElement.dataset.theme; });

describe('Settings > Shopping page', () => {
    it('shows the title, the three tabs and the catalog rows with unit and usage', () => {
        mountPage();
        expect(current.find('h1').text()).toBe('Shopping settings');
        expect(current.findAll('[role="tab"]').map((b) => b.text())).toEqual(['Item catalog', 'Stores', 'Units']);
        const rows = current.findAll('[data-testid="catalog-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[0].text()).toContain('Mliéko');
        expect(rows[0].text()).toContain('Default unit: kg');
        expect(rows[0].text()).toContain('Used on 2 lists');
        expect(rows[1].text()).toContain('Used on 0 lists');
        expect(rows[1].text()).not.toContain('Default unit');
        expect(rows[0].find('img').attributes('src')).toBe('/shopping/catalog/1/image/thumb?v=2');
        expect(rows[1].find('img').exists()).toBe(false);
    });

    it('filters the catalog without regard to case or accents', async () => {
        mountPage();
        await current.find('input[type="search"]').setValue('MLIEKO');
        expect(current.findAll('[data-testid="catalog-row"]')).toHaveLength(1);
        await current.find('input[type="search"]').setValue('zzz');
        expect(current.text()).toContain('No items match your search.');
    });

    it('shows the empty states', async () => {
        mountPage({ catalog: [], stores: [] });
        expect(current.text()).toContain('The catalog is empty');
        await openTab('Stores');
        expect(current.text()).toContain('No stores yet');
    });

    it('puts a green help mark on each tab', () => {
        mountPage();
        expect(current.findAll('.help-tip__button')).toHaveLength(3);
    });

    it('opens the add dialog with image, name and default unit, and shows a duplicate error from the server', async () => {
        mountPage();
        await button('Add item').trigger('click');
        await flushPromises();
        expect(dialogText()).toContain('Add catalog item');
        expect(dialogText()).toContain('JPG, PNG or WebP, up to 5 MB. A square picture works best.');
        expect(dialogText()).toContain('Default unit');
        forms[0].errors = { name: 'shopping.settings.err_duplicate' };
        await flushPromises();
        expect(dialogText()).toContain('An item with this name already exists.');
        expect(dialogText()).toContain('Your changes were not saved.');
    });

    it('saves an edited item and then uploads the chosen picture to the image endpoint', async () => {
        mountPage();
        await button('Edit Apple').trigger('click');
        await flushPromises();
        const file = new File(['x'], 'a.png', { type: 'image/png' });
        const input = document.body.querySelector('[data-testid="image-input"]');
        Object.defineProperty(input, 'files', { value: [file] });
        input.dispatchEvent(new Event('change'));
        await flushPromises();
        document.body.querySelector('form#catalog-form').dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(forms[0].put).toHaveBeenCalledWith('/settings/shopping/catalog/2', expect.any(Object));
        expect(routerMock.post).toHaveBeenCalledWith('/shopping/catalog/2/image', { image: file }, expect.objectContaining({ forceFormData: true }));
    });

    it('uploads the picture of a new item to the id the server returned, not to a row found by name', async () => {
        mountPage();
        await button('Add item').trigger('click');
        await flushPromises();
        const file = new File(['x'], 'a.png', { type: 'image/png' });
        const input = document.body.querySelector('[data-testid="image-input"]');
        Object.defineProperty(input, 'files', { value: [file] });
        input.dispatchEvent(new Event('change'));
        await flushPromises();
        // the name the server stores differs from the typed one (for example a different Unicode form); the id is what counts
        document.body.querySelector('[data-testid="catalog-name"], input[autocomplete="off"]').value = 'Nový  Čaj';
        forms[0].name = 'Nový  Čaj';
        mockProps.flash = { status: null, toast: null, data: { created_id: 77 } };
        document.body.querySelector('form#catalog-form').dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(routerMock.post).toHaveBeenCalledWith('/shopping/catalog/77/image', { image: file }, expect.objectContaining({ forceFormData: true }));
    });

    it('shows a failed picture upload (413 or 500) inside the dialog and keeps the dialog open', async () => {
        mountPage();
        await button('Edit Apple').trigger('click');
        await flushPromises();
        const file = new File(['x'], 'a.png', { type: 'image/png' });
        const input = document.body.querySelector('[data-testid="image-input"]');
        Object.defineProperty(input, 'files', { value: [file] });
        input.dispatchEvent(new Event('change'));
        await flushPromises();
        document.body.querySelector('form#catalog-form').dispatchEvent(new Event('submit'));
        await flushPromises();
        const opts = routerMock.post.mock.calls.at(-1)[2];
        expect(opts.onHttpException({ status: 413 })).toBe(false);
        opts.onFinish();
        await flushPromises();
        expect(document.body.querySelector('[data-testid="image-error"]').textContent).toContain('That picture is too large');
        expect(document.body.querySelector('[role="dialog"]')).not.toBeNull();
        // the retry only repeats the picture
        document.body.querySelector('form#catalog-form').dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(forms[0].put).toHaveBeenCalledTimes(1);
        expect(routerMock.post).toHaveBeenCalledTimes(2);
        routerMock.post.mock.calls.at(-1)[2].onHttpException({ status: 500 });
        routerMock.post.mock.calls.at(-1)[2].onFinish();
        await flushPromises();
        expect(document.body.querySelector('[data-testid="image-error"]').textContent).toContain('We could not save the picture');
    });

    it('shows a failed delete as the save-failed alert and names a refusal', async () => {
        mountPage();
        await button('Delete Mliéko').trigger('click');
        await flushPromises();
        [...document.body.querySelectorAll('button')].find((b) => b.textContent.includes('Delete item')).click();
        await flushPromises();
        const opts = routerMock.delete.mock.calls.at(-1)[1];
        expect(opts.onHttpException({ status: 404 })).toBe(false);
        await flushPromises();
        expect(current.text()).toContain('Your changes were not saved.');
        expect(routerMock.reload).toHaveBeenCalled();
    });

    it('rejects a wrong file type and an oversized file on the client', async () => {
        mountPage();
        await button('Add item').trigger('click');
        await flushPromises();
        const input = document.body.querySelector('[data-testid="image-input"]');
        Object.defineProperty(input, 'files', { value: [new File(['x'], 'a.gif', { type: 'image/gif' })], configurable: true });
        input.dispatchEvent(new Event('change'));
        await flushPromises();
        expect(document.body.querySelector('[data-testid="image-error"]').textContent).toContain('We could not use that picture');
    });

    it('asks before deleting and names the usage', async () => {
        mountPage();
        await button('Delete Mliéko').trigger('click');
        await flushPromises();
        expect(dialogText()).toContain('Delete “Mliéko” from the catalog?');
        expect(dialogText()).toContain('It is on 2 lists. Those lists keep the item as plain text without the image.');
        [...document.body.querySelectorAll('button')].find((b) => b.textContent.includes('Delete item')).click();
        await flushPromises();
        expect(routerMock.delete).toHaveBeenCalledWith('/settings/shopping/catalog/1', expect.any(Object));
    });

    it('says "This cannot be undone." for an unused item', async () => {
        mountPage();
        await button('Delete Apple').trigger('click');
        await flushPromises();
        expect(dialogText()).toContain('This cannot be undone.');
    });

    it('lists stores with the logo and manages them', async () => {
        mountPage();
        await openTab('Stores');
        const rows = current.findAll('[data-testid="store-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[0].find('img').attributes('src')).toBe('/shopping/stores/1/image/thumb?v=1');
        expect(rows[0].text()).toContain('Used on 1 list');
        await button('Delete Tesco').trigger('click');
        await flushPromises();
        expect(dialogText()).toContain('It is assigned to 1 list. That list will have no store.');
        [...document.body.querySelectorAll('button')].find((x) => x.textContent.includes('Keep store')).click();
        await flushPromises();
        await button('Add store').trigger('click');
        await flushPromises();
        expect(dialogText()).toContain('Store name');
        expect(dialogText()).toContain('PNG, JPG or WebP, up to 2 MB. Transparent PNG works best.');
    });

    it('shows built-in units translated and read-only, and no Add unit to a member', async () => {
        mountPage({ locale: 'sk' });
        await openTab('Jednotky');
        const text = current.findAll('[data-testid="unit-row"]').map((r) => r.text());
        expect(text[0]).toContain('ks');
        expect(text[0]).toContain('Vstavaná');
        expect(text.some((x) => x.includes('plech'))).toBe(true);
        expect(current.findAll('button').some((b) => b.text().includes('Pridať jednotku'))).toBe(false);
        expect(current.find('[data-testid="units-admin-note"]').exists()).toBe(true);
        expect(current.findAll('[data-testid="unit-row"] button')).toHaveLength(0);
    });

    it('lets an administrator add, edit and delete custom units only', async () => {
        mountPage({ canManageUnits: true });
        await openTab('Units');
        expect(current.findAll('[data-testid="unit-row"]').filter((r) => r.find('button').exists())).toHaveLength(1);
        await button('Add unit').trigger('click');
        await flushPromises();
        expect(dialogText()).toContain('Name (English)');
        expect(dialogText()).toContain('Name (Slovak)');
        expect(dialogText()).toContain('Name (Polish)');
        forms[forms.length - 1].errors = { name_en: 'shopping.settings.err_duplicate' };
        await flushPromises();
        expect(dialogText()).toContain('An item with this name already exists.');
        document.body.querySelector('[role="dialog"] button[aria-label], [role="dialog"] button').click();
        await flushPromises();
        await button('Delete tin').trigger('click');
        await flushPromises();
        expect(dialogText()).toContain('Delete unit “tin”?');
        expect(dialogText()).toContain('It is used by 3 items. Those items will show the quantity without a unit.');
    });

    it('wraps pseudo-long text without losing names', async () => {
        const long = 'Extraordinarily long catalog item name that must wrap and never be truncated';
        mountPage({ messages: pseudoLong(loadMessages('en')), catalog: [{ id: 5, name: long, defaultUnitId: 3, imageVersion: null, usedOn: 1 }] });
        expect(current.find('[data-testid="catalog-row"]').text()).toContain(long);
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`has no axe violations for the page and every dialog (${locale}, ${dark ? 'dark' : 'light'})`, async () => {
                installHelp(locale);
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                mountPage({ locale, canManageUnits: true });
                await expectNoAxeViolations(current.element);
                for (const [tabIndex, addIndex] of [[0, 0], [1, 0], [2, 0]]) {
                    await current.findAll('[role="tab"]')[tabIndex].trigger('click');
                    const panel = current.findAll('[role="tabpanel"]')[tabIndex];
                    await expectNoAxeViolations(current.element);
                    await panel.findAll('button').filter((b) => /^(\S+ ){0,2}\S+$/.test(b.text()) && b.classes().includes('ui-button--primary'))[addIndex].trigger('click');
                    await flushPromises();
                    await expectNoAxeViolations(document.body);
                    document.body.querySelector('[role="dialog"] .ui-dialog__footer button, [role="dialog"] button').click();
                    await flushPromises();
                }
                const del = current.findAll('button').find((b) => /^(Delete|Odstrániť|Usuń) /.test(b.attributes('aria-label') ?? ''));
                await current.findAll('[role="tab"]')[0].trigger('click');
                await current.findAll('button').find((b) => /^(Delete|Odstrániť|Usuń) /.test(b.attributes('aria-label') ?? '')).trigger('click');
                await flushPromises();
                await expectNoAxeViolations(document.body);
                expect(del).toBeTruthy();
            });
        }
    }
});
