import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';

let mockProps = {};
const { routerMock, forms } = vi.hoisted(() => ({
    routerMock: { get: vi.fn(), post: vi.fn(), visit: vi.fn(), replace: vi.fn(), reload: vi.fn(), on: vi.fn() },
    forms: [],
}));
vi.mock('@inertiajs/vue3', async () => {
    const { h, reactive } = await import('vue');
    return {
        usePage: () => ({ props: mockProps, url: '/shopping' }),
        router: routerMock,
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
        useForm: (data) => {
            const form = reactive({
                ...data,
                processing: false,
                errors: {},
                post: vi.fn((url, opts) => opts?.onSuccess?.()),
                transform(cb) { form.transformer = cb; return form; },
                clearErrors() { form.errors = {}; },
            });
            forms.push(form);
            return form;
        },
    };
});

import Index from '../../../resources/js/Pages/Shopping/Index.vue';

const ME = 2;
const person = (id, name) => ({ id, name, isChild: false });
const open = (id, extra = {}) => ({
    id, name: `List ${id}`, status: 'open', store: null, responsible: person(ME, 'Mira Nováková'), remindAt: null, reminderPassed: false,
    itemsCount: 0, boughtCount: 0, unavailableCount: 0, abilities: { copy: true }, ...extra,
});
const OPEN = [
    open(1, { name: 'Týždenný nákup', store: { id: 4, name: 'Tesco', logoVersion: 2 }, remindAt: '2026-11-20T18:00:00+01:00' }),
    open(2, { name: 'Party', status: 'locked', itemsCount: 8, boughtCount: 3, store: { id: 5, name: 'Lidl', logoVersion: null } }),
    open(3, { name: 'Garden', remindAt: '2026-11-10T08:00:00+01:00', reminderPassed: true, abilities: { copy: false } }),
];
const ARCHIVE = { data: [{ id: 9, name: 'Old shop', doneAt: '2026-11-01T10:00:00+01:00', storeName: 'Tesco', abilities: { copy: true } }], currentPage: 1, lastPage: 1, perPage: 20, total: 1 };
const STORES = [{ id: 4, name: 'Tesco', logoVersion: 2 }, { id: 5, name: 'Lidl', logoVersion: null }];
const CANDIDATES = [person(1, 'Zora'), person(ME, 'Mira Nováková')];

let current;
function mountPage({ locale = 'en', messages = null, ...props } = {}) {
    window.innerWidth = 1024;
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: ME, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { shopping: 'edit' } },
        app: { env: 'local', styleGuide: false },
    };
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(Index, {
        props: { tab: 'open', lists: OPEN, archive: ARCHIVE, counts: { open: OPEN.length, archive: 1 }, stores: STORES, responsibleCandidates: CANDIDATES, canCreate: true, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] },
    });
    return current;
}
const rows = () => current.findAll('[role="tabpanel"]')[0].findAll('[data-testid="list-row"]');
const tab = (name) => current.findAll('[role="tab"]').find((b) => b.text().startsWith(name));
const dialog = () => document.body.querySelector('[role="dialog"]');

beforeEach(() => { Object.values(routerMock).forEach((f) => f.mockClear()); forms.length = 0; });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; delete document.documentElement.dataset.theme; });

describe('Shopping overview', () => {
    it('shows the title, both tabs with counts and the open rows as links', () => {
        mountPage();
        expect(current.find('h1').text()).toBe('Shopping');
        expect(current.findAll('[role="tab"]').map((b) => b.text())).toEqual(['Open (3)', 'Archive (1)']);
        expect(rows()).toHaveLength(3);
        expect(rows()[0].find('a').attributes('href')).toBe('/shopping/lists/1');
        expect(rows()[0].text()).toContain('Týždenný nákup');
        expect(rows()[0].text()).toContain('Tesco');
        expect(rows()[0].text()).toContain('Mira');
        expect(rows()[0].find('img').attributes('src')).toBe('/shopping/stores/4/image/thumb?v=2');
    });

    it('omits the count in a tab label when it is zero', () => {
        mountPage({ counts: { open: 0, archive: 0 }, lists: [], archive: { data: [], currentPage: 1, lastPage: 1, total: 0 } });
        expect(current.findAll('[role="tab"]').map((b) => b.text())).toEqual(['Open', 'Archive']);
    });

    it('shows the Locked tag, the progress bar with its label and the item counts for a locked list', () => {
        mountPage();
        const row = rows()[1];
        expect(row.text()).toContain('Locked');
        expect(row.text()).toContain('8 items · 3 bought');
        const bar = row.find('[role="progressbar"]');
        expect(bar.attributes('aria-label')).toBe('Bought 3 of 8');
        expect(bar.attributes('aria-valuenow')).toBe('3');
        expect(rows()[0].find('[role="progressbar"]').exists()).toBe(false);
    });

    it('shows a reminder with its date and the warn tag when it has passed', () => {
        mountPage();
        expect(rows()[0].find('.shop-list-row__reminder').exists()).toBe(true);
        expect(rows()[0].text()).not.toContain('Reminder passed');
        expect(rows()[2].text()).toContain('Reminder passed');
        expect(rows()[1].find('.shop-list-row__reminder').exists()).toBe(false);
    });

    it('lists the archive with the finish date and pages through the server', async () => {
        mountPage({ tab: 'archive', archive: { ...ARCHIVE, currentPage: 1, lastPage: 3, total: 41 } });
        await tab('Archive').trigger('click');
        const archiveRows = current.findAll('#' + tab('Archive').attributes('aria-controls') + ' [data-testid="list-row"]');
        expect(archiveRows).toHaveLength(1);
        expect(archiveRows[0].text()).toContain('Finished');
        expect(archiveRows[0].text()).toContain('Tesco');
        expect(current.text()).toContain('Page 1 of 3');
        const next = current.findAll('button').find((b) => b.text() === 'Next');
        await next.trigger('click');
        expect(routerMock.get).toHaveBeenCalledWith('/shopping', { tab: 'archive', page: 2 }, expect.objectContaining({ preserveState: true }));
    });

    it('shows an archive load error with Retry after a server error or a dropped connection', async () => {
        mountPage({ tab: 'archive', archive: { ...ARCHIVE, currentPage: 1, lastPage: 3, total: 41 } });
        const next = () => current.findAll('button').find((b) => b.text() === 'Next');
        await next().trigger('click');
        const opts = routerMock.get.mock.calls.at(-1)[2];
        expect(opts.onHttpException({ status: 500 })).toBe(false);
        opts.onFinish();
        await flushPromises();
        expect(current.text()).toContain('We could not load');
        const retry = current.findAll('button').find((b) => b.text() === 'Try again');
        await retry.trigger('click');
        expect(routerMock.get).toHaveBeenCalledTimes(2);
        routerMock.get.mock.calls.at(-1)[2].onNetworkError();
        routerMock.get.mock.calls.at(-1)[2].onFinish();
        await flushPromises();
        expect(current.text()).toContain('We could not load');
    });

    it('opens a list with an in-app visit on a plain click and leaves modified clicks to the browser', async () => {
        mountPage();
        const link = rows()[0].find('a');
        expect(link.attributes('href')).toBe('/shopping/lists/1');
        const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
        link.element.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(true);
        expect(routerMock.visit).toHaveBeenCalledWith('/shopping/lists/1');
        routerMock.visit.mockClear();
        const modified = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ctrlKey: true });
        link.element.dispatchEvent(modified);
        expect(modified.defaultPrevented).toBe(false);
        expect(routerMock.visit).not.toHaveBeenCalled();
    });

    it('puts the selected tab in the address so Back returns to it', async () => {
        mountPage();
        await tab('Archive').trigger('click');
        await flushPromises();
        expect(routerMock.replace).toHaveBeenCalledTimes(1);
        const call = routerMock.replace.mock.calls[0][0];
        expect(call.url).toBe('/shopping?tab=archive');
        expect(call.props({ tab: 'open', lists: [] })).toEqual({ tab: 'archive', lists: [] });
        // the replace has made the page's own tab 'archive'
        await current.setProps({ tab: 'archive' });
        await tab('Open').trigger('click');
        await flushPromises();
        expect(routerMock.replace.mock.calls[1][0].url).toBe('/shopping');
    });

    it('follows the tab of the page when Back restores another one', async () => {
        mountPage({ tab: 'archive' });
        expect(tab('Archive').attributes('aria-selected')).toBe('true');
        await current.setProps({ tab: 'open' });
        expect(tab('Open').attributes('aria-selected')).toBe('true');
        expect(routerMock.replace).not.toHaveBeenCalled();
    });

    it('shows the empty states with the New list action only when the member may create', async () => {
        mountPage({ lists: [], counts: { open: 0, archive: 0 }, archive: { data: [], currentPage: 1, lastPage: 1, total: 0 } });
        expect(current.text()).toContain('No shopping lists yet');
        expect(current.text()).toContain('Create a list, add items from the catalog and share it with the family.');
        expect(current.findAll('button').filter((b) => b.text() === 'New list')).toHaveLength(2);
        await tab('Archive').trigger('click');
        expect(current.text()).toContain('Nothing finished yet');
        current.unmount();
        mountPage({ lists: [], canCreate: false, counts: { open: 0, archive: 0 }, archive: { data: [], currentPage: 1, lastPage: 1, total: 0 } });
        expect(current.findAll('button').filter((b) => b.text() === 'New list')).toHaveLength(0);
    });

    it('hides New list without create permission', () => {
        mountPage({ canCreate: false });
        expect(current.findAll('button').some((b) => b.text() === 'New list')).toBe(false);
    });

    it('shows the search only above 10 open lists and ignores case and accents', async () => {
        mountPage();
        expect(current.find('input[type="search"]').exists()).toBe(false);
        current.unmount();
        const many = [...OPEN, ...Array.from({ length: 9 }, (_, i) => open(20 + i, { name: `Other ${i}` }))];
        mountPage({ lists: many, counts: { open: many.length, archive: 1 } });
        expect(current.find('input[type="search"]').exists()).toBe(true);
        expect(rows()).toHaveLength(12);
        await current.find('input[type="search"]').setValue('tyzdenny');
        expect(rows()).toHaveLength(1);
        expect(rows()[0].text()).toContain('Týždenný nákup');
        await current.find('input[type="search"]').setValue('zzz');
        expect(current.text()).toContain('No list matches your search.');
    });

    it('offers Copy only on rows where the server allows it', () => {
        mountPage();
        expect(rows()[0].find('button[aria-label="Copy list Týždenný nákup"]').exists()).toBe(true);
        expect(rows()[2].find('button').exists()).toBe(false);
    });

    it('opens the copy dialog from a row with the default name and keep checked', async () => {
        mountPage();
        await rows()[0].find('button[aria-label^="Copy list"]').trigger('click');
        await flushPromises();
        expect(dialog().textContent).toContain('Copy list');
        expect(dialog().querySelector('input[type="text"], input:not([type])').value).toBe('Týždenný nákup (copy)');
        expect(dialog().querySelector('input[type="checkbox"]').checked).toBe(true);
    });

    it('creates a list: Create stays disabled without a name, then posts name, store and responsible person', async () => {
        mountPage();
        await current.findAll('button').find((b) => b.text() === 'New list').trigger('click');
        await flushPromises();
        const submit = [...dialog().querySelectorAll('button')].find((b) => b.textContent.trim() === 'Create list');
        expect(submit.disabled).toBe(true);
        const form = forms[0];
        expect(form.responsible_id).toBe(String(ME));
        form.name = '  Weekend ';
        form.store_id = '5';
        await flushPromises();
        expect([...dialog().querySelectorAll('button')].find((b) => b.textContent.trim() === 'Create list').disabled).toBe(false);
        dialog().querySelector('form#create-list-form').dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(form.post).toHaveBeenCalledWith('/shopping/lists', expect.any(Object));
        expect(form.transformer(form)).toEqual({ name: 'Weekend', store_id: 5, responsible_id: ME });
    });

    it('shows server errors of the create dialog inline', async () => {
        mountPage();
        await current.findAll('button').find((b) => b.text() === 'New list').trigger('click');
        await flushPromises();
        forms[0].errors = { name: 'shopping.error.name_invalid' };
        await flushPromises();
        expect(dialog().textContent).toContain('Enter a name that is not empty and not too long.');
    });

    it('renders list names as text, never as markup', () => {
        mountPage({ lists: [open(1, { name: '<img src=x onerror=alert(1)>' })], counts: { open: 1, archive: 1 } });
        expect(current.find('img[src="x"]').exists()).toBe(false);
        expect(rows()[0].text()).toContain('<img src=x onerror=alert(1)>');
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`has no axe violations for the lists, the empty states and both dialogs (${locale}, ${dark ? 'dark' : 'light'})`, async () => {
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                const base = loadMessages(locale);
                mountPage({ locale, messages: base, lists: [...OPEN, open(7, { name: pseudoLong('Zoznam') })] });
                await expectNoAxeViolations(current.element);
                await tab(base.shopping.overview.tab_archive).trigger('click');
                await expectNoAxeViolations(current.element);
                await current.findAll('button').find((b) => b.text() === base.shopping.overview.new_list).trigger('click');
                await flushPromises();
                await expectNoAxeViolations(document.body);
                [...dialog().querySelectorAll('button')].find((b) => b.textContent.trim() === base.shopping.create.cancel).click();
                await flushPromises();
                await rows()[0].find('button[aria-label^="' + base.shopping.overview.copy + '"]').trigger('click');
                await flushPromises();
                await expectNoAxeViolations(document.body);
                [...dialog().querySelectorAll('button')].find((b) => b.textContent.trim() === base.shopping.copy.cancel).click();
                await flushPromises();
                current.unmount();
                mountPage({ locale, messages: base, lists: [], counts: { open: 0, archive: 0 }, archive: { data: [], currentPage: 1, lastPage: 1, total: 0 } });
                await expectNoAxeViolations(current.element);
            });
        }
    }
});
