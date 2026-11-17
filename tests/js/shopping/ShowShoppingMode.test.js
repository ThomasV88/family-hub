import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';

let mockProps = {};
const { routerMock, forms } = vi.hoisted(() => ({
    routerMock: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), visit: vi.fn(), replace: vi.fn(), reload: vi.fn(), on: vi.fn() },
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
                post: vi.fn(),
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

import Show from '../../../resources/js/Pages/Shopping/Show.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const ME = 2;
const UNITS = [{ id: 1, key: 'pcs', names: null }];
const item = (id, extra = {}) => ({ id, name: `Item ${id}`, quantity: '1.00', unitId: 1, note: null, state: 'pending', catalogItemId: null, imageVersion: null, ...extra });
const ITEMS = [item(1, { name: 'Mlieko' }), item(2, { name: 'Bread' }), item(3, { name: 'Apples' })];
const LIST = {
    id: 5, name: 'Týždenný nákup', status: 'locked', store: null, storeName: '',
    creator: { id: ME, name: 'Mira Nováková' }, responsible: { id: ME, name: 'Mira Nováková' }, responsibleFallback: null,
    remindAt: null, reminderPassed: false, reminderSent: false, lockedAt: '2026-11-17T10:00:00+01:00', doneAt: null, source: null,
};
const SHOP = { editItems: false, lock: false, unlock: true, mark: true, finish: true, copy: true, mail: true, delete: false };
const PLAN = { editItems: true, lock: true, unlock: false, mark: false, finish: false, copy: true, mail: true, delete: true, manageCatalog: false };
const READ = { editItems: false, lock: false, unlock: false, mark: false, finish: false, copy: true, mail: true, delete: false };

let current;
function mountPage({ locale = 'en', messages = null, width = 1024, ...props } = {}) {
    window.innerWidth = width;
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: ME, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { shopping: 'edit' } },
        app: { env: 'local', styleGuide: false },
    };
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(Show, {
        props: { list: LIST, items: ITEMS, abilities: SHOP, members: { contributors: [], viewers: [] }, units: UNITS, stores: [], catalog: [], ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] },
    });
    return current;
}
const rows = () => current.findAll('[data-testid="item-row"]');
const button = (label) => current.findAll('button').find((b) => b.text() === label);
const dialog = () => document.body.querySelector('[role="dialog"]');
const dialogButton = (label) => [...dialog().querySelectorAll('button')].find((b) => b.textContent.trim() === label);
const pressed = (row, which) => row.find(`[data-testid="mark-${which}"]`).attributes('aria-pressed');
const lastPut = () => routerMock.put.mock.calls.at(-1);

beforeEach(() => { Object.values(routerMock).forEach((f) => f.mockClear()); forms.length = 0; useToast().toasts.splice(0); });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; delete document.documentElement.dataset.theme; });

describe('lock and unlock', () => {
    it('shows Lock to the responsible person and locks after a confirmation', async () => {
        mountPage({ list: { ...LIST, status: 'open' }, abilities: PLAN });
        const lock = button('Lock list');
        expect(lock.attributes('disabled')).toBeUndefined();
        await lock.trigger('click');
        await flushPromises();
        expect(dialog().textContent).toContain('Lock this list?');
        expect(dialog().textContent).toContain('Nobody can add, remove or edit items while it is locked. You can unlock it again.');
        expect(routerMock.put).not.toHaveBeenCalled();
        dialogButton('Lock list').click();
        expect(lastPut()[0]).toBe('/shopping/lists/5/lock');
        expect(lastPut()[1]).toEqual({ locked: true });
    });

    it('disables Lock on an empty list and says why', () => {
        mountPage({ list: { ...LIST, status: 'open' }, abilities: PLAN, items: [] });
        expect(button('Lock list').attributes('disabled')).toBeDefined();
        expect(current.find('[data-testid="lock-empty-hint"]').text()).toContain('Add at least one item before you lock the list.');
    });

    it('shows the lock error from the server inside the dialog', async () => {
        mountPage({ list: { ...LIST, status: 'open' }, abilities: PLAN });
        await button('Lock list').trigger('click');
        await flushPromises();
        dialogButton('Lock list').click();
        lastPut()[2].onError({ list: 'shopping.error.lock_empty' });
        lastPut()[2].onFinish();
        await flushPromises();
        expect(dialog().textContent).toContain('Add at least one item before you lock the list.');
    });

    it('tells a contributor who can lock instead of showing the button', () => {
        mountPage({ list: { ...LIST, status: 'open', responsible: { id: 9, name: 'Peter' } }, abilities: { ...PLAN, lock: false, delete: false } });
        expect(button('Lock list')).toBeUndefined();
        expect(current.find('[data-testid="lock-only-hint"]').text()).toContain('Only Peter can lock this list.');
    });

    it('unlocks the list after a confirmation that says the marks are kept', async () => {
        mountPage();
        await button('Unlock list').trigger('click');
        await flushPromises();
        expect(dialog().textContent).toContain('Unlock this list?');
        expect(dialog().textContent).toContain('Marks Bought and Unavailable are kept.');
        dialogButton('Unlock list').click();
        expect(lastPut()[1]).toEqual({ locked: false });
    });

    it('offers no Lock or Unlock to people who may not', () => {
        mountPage({ abilities: READ });
        expect(button('Unlock list')).toBeUndefined();
        expect(button('Lock list')).toBeUndefined();
    });
});

describe('marking while shopping', () => {
    it('shows two pressed-state buttons per row in a labelled group for the responsible person', () => {
        mountPage();
        expect(rows()).toHaveLength(3);
        const group = rows()[0].find('[role="group"]');
        expect(group.attributes('aria-label')).toBe('Mark Mlieko');
        expect(pressed(rows()[0], 'bought')).toBe('false');
        expect(pressed(rows()[0], 'unavailable')).toBe('false');
    });

    it('marks Bought at once and sends the absolute state', async () => {
        mountPage();
        await rows()[0].find('[data-testid="mark-bought"]').trigger('click');
        expect(pressed(rows()[0], 'bought')).toBe('true');
        expect(lastPut()[0]).toBe('/shopping/lists/5/items/1/state');
        expect(lastPut()[1]).toEqual({ state: 'bought' });
        expect(current.find('[data-testid="shopping-progress"]').text()).toContain('Bought 1 · Unavailable 0 · Left 2');
    });

    it('clears the mark when the active button is tapped again', async () => {
        mountPage({ items: [item(1, { name: 'Mlieko', state: 'bought' }), ITEMS[1], ITEMS[2]] });
        expect(pressed(rows()[0], 'bought')).toBe('true');
        await rows()[0].find('[data-testid="mark-bought"]').trigger('click');
        expect(lastPut()[1]).toEqual({ state: 'pending' });
        expect(pressed(rows()[0], 'bought')).toBe('false');
    });

    it('switches from Bought to Unavailable in one request', async () => {
        mountPage({ items: [item(1, { name: 'Mlieko', state: 'bought' }), ITEMS[1], ITEMS[2]] });
        await rows()[0].find('[data-testid="mark-unavailable"]').trigger('click');
        expect(lastPut()[1]).toEqual({ state: 'unavailable' });
        expect(pressed(rows()[0], 'unavailable')).toBe('true');
        expect(pressed(rows()[0], 'bought')).toBe('false');
    });

    it('rolls back and shows the toast when the save fails', async () => {
        mountPage();
        await rows()[1].find('[data-testid="mark-bought"]').trigger('click');
        expect(pressed(rows()[1], 'bought')).toBe('true');
        const opts = lastPut()[2];
        expect(opts.onHttpException()).toBe(false);
        opts.onFinish();
        await flushPromises();
        expect(pressed(rows()[1], 'bought')).toBe('false');
        expect(useToast().toasts.map((x) => x.message)).toEqual(['Could not save. Try again.']);
    });

    it('rolls back on a network error and on a validation error', async () => {
        mountPage();
        await rows()[0].find('[data-testid="mark-unavailable"]').trigger('click');
        lastPut()[2].onNetworkError();
        lastPut()[2].onFinish();
        await flushPromises();
        expect(pressed(rows()[0], 'unavailable')).toBe('false');
        await rows()[0].find('[data-testid="mark-bought"]').trigger('click');
        lastPut()[2].onError({ state: 'shopping.error.state_invalid' });
        lastPut()[2].onFinish();
        await flushPromises();
        expect(pressed(rows()[0], 'bought')).toBe('false');
        expect(useToast().toasts).toHaveLength(2);
    });

    it('ignores a second tap on the same item while its request is running', async () => {
        mountPage();
        await rows()[0].find('[data-testid="mark-bought"]').trigger('click');
        await rows()[0].find('[data-testid="mark-unavailable"]').trigger('click');
        expect(routerMock.put).toHaveBeenCalledTimes(1);
    });

    it('keeps the rows in the same order after marking', async () => {
        mountPage();
        await rows()[0].find('[data-testid="mark-bought"]').trigger('click');
        expect(rows().map((r) => r.find('.shop-item__name').text())).toEqual(['Mlieko', 'Bread', 'Apples']);
    });

    it('shows contributors and viewers read-only state tags and no buttons', () => {
        mountPage({ abilities: READ, items: [item(1, { name: 'Mlieko', state: 'bought' }), item(2, { name: 'Bread', state: 'unavailable' }), ITEMS[2]] });
        expect(current.find('[data-testid="mark-buttons"]').exists()).toBe(false);
        expect(rows()[0].text()).toContain('Bought');
        expect(rows()[1].text()).toContain('Unavailable');
    });

    it('does not render mark buttons on an open list', () => {
        mountPage({ list: { ...LIST, status: 'open' }, abilities: PLAN });
        expect(current.find('[data-testid="mark-buttons"]').exists()).toBe(false);
    });
});

describe('the mark queue (several quick taps)', () => {
    const tick = () => new Promise((resolve) => { setTimeout(resolve, 5); });
    const tap = (index, which) => rows()[index].find(`[data-testid="mark-${which}"]`).trigger('click');
    const sent = () => routerMock.put.mock.calls.map((c) => [c[0], c[1].state]);
    // Settles the request that is in flight (what Inertia does when the response has arrived), then lets the queue move on.
    const settle = async (callIndex, { fail = false } = {}) => {
        const opts = routerMock.put.mock.calls[callIndex][2];
        if (fail) opts.onHttpException({ status: 500 });
        opts.onFinish();
        await tick();
        await flushPromises();
    };

    it('sends every tap, one request at a time and in tap order, and loses none', async () => {
        mountPage();
        await tap(0, 'bought');
        await tap(1, 'unavailable');
        await tap(2, 'bought');
        // only the first request is on the wire: a second visit would abort it
        expect(sent()).toEqual([['/shopping/lists/5/items/1/state', 'bought']]);
        expect([pressed(rows()[0], 'bought'), pressed(rows()[1], 'unavailable'), pressed(rows()[2], 'bought')]).toEqual(['true', 'true', 'true']);

        await settle(0);
        expect(sent()).toEqual([['/shopping/lists/5/items/1/state', 'bought'], ['/shopping/lists/5/items/2/state', 'unavailable']]);
        // the first request has settled, the other two taps still show their mark
        expect([pressed(rows()[1], 'unavailable'), pressed(rows()[2], 'bought')]).toEqual(['true', 'true']);

        await settle(1);
        expect(sent()).toEqual([
            ['/shopping/lists/5/items/1/state', 'bought'],
            ['/shopping/lists/5/items/2/state', 'unavailable'],
            ['/shopping/lists/5/items/3/state', 'bought'],
        ]);
        expect(pressed(rows()[2], 'bought')).toBe('true');
        await settle(2);
        expect(routerMock.put).toHaveBeenCalledTimes(3);
        expect(useToast().toasts).toHaveLength(0);
    });

    it('never aborts the first request: no second request starts before the first has finished', async () => {
        mountPage();
        await tap(0, 'bought');
        await tap(1, 'bought');
        await tick();
        expect(routerMock.put).toHaveBeenCalledTimes(1);
        await settle(0);
        expect(routerMock.put).toHaveBeenCalledTimes(2);
    });

    it('rolls back only the item whose request failed and shows one toast', async () => {
        mountPage();
        await tap(0, 'bought');
        await tap(1, 'bought');
        await tap(2, 'unavailable');
        await settle(0, { fail: true });
        // item 1 is rolled back at once, the other two taps are still pending and keep their mark
        expect(pressed(rows()[0], 'bought')).toBe('false');
        expect(pressed(rows()[1], 'bought')).toBe('true');
        expect(pressed(rows()[2], 'unavailable')).toBe('true');
        expect(useToast().toasts.map((x) => x.message)).toEqual(['Could not save. Try again.']);
        // the queue goes on after a failure: the later taps are still sent
        expect(sent().map((x) => x[0])).toEqual(['/shopping/lists/5/items/1/state', '/shopping/lists/5/items/2/state']);
        await settle(1);
        await tick();
        expect(sent().map((x) => x[0])).toContain('/shopping/lists/5/items/3/state');
        expect(useToast().toasts).toHaveLength(1);
    });

    it('rolls back the second item only when the second request fails', async () => {
        mountPage();
        await tap(0, 'bought');
        await tap(1, 'bought');
        await settle(0);
        await settle(1, { fail: true });
        expect(pressed(rows()[1], 'bought')).toBe('false');
        expect(useToast().toasts).toHaveLength(1);
    });

    it('says forbidden and reloads the page after a 403, and explains a changed list after a 409', async () => {
        mountPage();
        await tap(0, 'bought');
        lastPut()[2].onHttpException({ status: 403 });
        lastPut()[2].onFinish();
        await flushPromises();
        expect(useToast().toasts.map((x) => x.message)).toEqual(['You are not allowed to do that on this list.']);
        expect(routerMock.reload).toHaveBeenCalledTimes(1);
        await tick();
        await tap(1, 'bought');
        lastPut()[2].onHttpException({ status: 409 });
        lastPut()[2].onFinish();
        await flushPromises();
        expect(useToast().toasts.at(-1).message).toBe('The list was changed by someone else. It has been reloaded.');
        expect(routerMock.reload).toHaveBeenCalledTimes(2);
    });

    it('disables Unlock and Finish while a mark is in flight and enables them when it has settled', async () => {
        mountPage({ items: [item(1, { state: 'bought' }), item(2)] });
        expect(button('Unlock list').attributes('disabled')).toBeUndefined();
        await tap(1, 'bought');
        expect(button('Unlock list').attributes('disabled')).toBeDefined();
        expect(current.find('[data-testid="finish-shopping"]').attributes('disabled')).toBeDefined();
        // the response carries the new state of the list, then the request finishes
        await current.setProps({ items: [item(1, { state: 'bought' }), item(2, { state: 'bought' })] });
        await settle(0);
        expect(button('Unlock list').attributes('disabled')).toBeUndefined();
        expect(current.find('[data-testid="finish-shopping"]').attributes('disabled')).toBeUndefined();
    });

    it('announces politely that everything is marked', async () => {
        mountPage({ items: [item(1, { state: 'bought' }), item(2)] });
        const live = () => current.find('[data-testid="all-done-live"]');
        expect(live().attributes('aria-live')).toBe('polite');
        expect(live().text()).toBe('');
        await tap(1, 'unavailable');
        expect(live().text()).toBe('Everything is marked');
    });

    it('shows the pressed state with a different icon and not by colour alone', async () => {
        mountPage();
        const row = () => rows()[0];
        expect(row().find('[data-testid="mark-bought-on"]').exists()).toBe(false);
        await tap(0, 'bought');
        expect(row().find('[data-testid="mark-bought-on"]').exists()).toBe(true);
        expect(row().find('[data-testid="mark-unavailable-on"]').exists()).toBe(false);
        await tap(1, 'unavailable');
        expect(rows()[1].find('[data-testid="mark-unavailable-on"]').exists()).toBe(true);
    });
});

describe('progress, sticky bar and finishing', () => {
    it('counts bought, unavailable and left', () => {
        mountPage({ items: [item(1, { state: 'bought' }), item(2, { state: 'unavailable' }), item(3), item(4)] });
        expect(current.find('[data-testid="shopping-progress"]').text()).toContain('Bought 1 · Unavailable 1 · Left 2');
    });

    it('shows no Finish button while any item is pending', () => {
        mountPage({ items: [item(1, { state: 'bought' }), item(2)] });
        expect(current.find('[data-testid="finish-shopping"]').exists()).toBe(false);
    });

    it('shows Finish when every item is marked and it appears at once after the last tap', async () => {
        mountPage({ items: [item(1, { state: 'bought' }), item(2)] });
        await rows()[1].find('[data-testid="mark-unavailable"]').trigger('click');
        expect(current.find('[data-testid="finish-shopping"]').exists()).toBe(true);
    });

    it('does not show Finish to people without the finish ability', () => {
        mountPage({ abilities: READ, items: [item(1, { state: 'bought' })] });
        expect(current.find('[data-testid="finish-shopping"]').exists()).toBe(false);
    });

    it('opens the finish dialog', async () => {
        mountPage({ items: [item(1, { state: 'bought' })] });
        await current.find('[data-testid="finish-shopping"]').trigger('click');
        await flushPromises();
        expect(dialog().textContent).toContain('Finish shopping?');
    });

    it('on a phone shows a fixed bar with the items left, then the Finish button', async () => {
        mountPage({ width: 400, items: [item(1, { state: 'bought' }), item(2)] });
        expect(current.find('[data-testid="sticky-bar"]').text()).toBe('1 item left');
        await rows()[1].find('[data-testid="mark-bought"]').trigger('click');
        expect(current.find('[data-testid="sticky-bar"]').text()).toContain('Finish shopping');
    });

    it('uses the plural forms of the items-left text in Slovak', () => {
        mountPage({ width: 400, locale: 'sk', items: [item(1), item(2), item(3), item(4), item(5), item(6, { state: 'bought' })] });
        expect(current.find('[data-testid="sticky-bar"]').text()).toBe('Zostáva 5 položiek');
    });
});

describe('finished list', () => {
    const DONE = { ...LIST, status: 'done', doneAt: '2026-11-17T12:00:00+01:00' };
    const DONE_ITEMS = [item(1, { state: 'bought' }), item(2, { state: 'unavailable' })];

    it('shows the banner, no actions and only Copy and E-mail in the menu', async () => {
        mountPage({ list: DONE, items: DONE_ITEMS, abilities: { ...READ, unlock: false } });
        expect(current.find('[data-testid="archived-banner"]').text()).toBe('This shopping is finished. You can copy it into a new list.');
        expect(current.find('[data-testid="mark-buttons"]').exists()).toBe(false);
        expect(current.find('[data-testid="finish-shopping"]').exists()).toBe(false);
        expect(current.find('[data-testid="shopping-progress"]').exists()).toBe(false);
        const more = current.findAll('button[aria-haspopup="menu"]').find((b) => b.text() === 'More actions');
        await more.trigger('click');
        await flushPromises();
        const items = [...document.body.querySelectorAll('[role="menuitem"]')].map((e) => e.textContent.trim());
        expect(items).toEqual(['Send by e-mail', 'Copy list']);
    });
});

describe('accessibility', () => {
    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`has no axe violations in plan, shopping and finished mode (${locale}, ${dark ? 'dark' : 'light'})`, async () => {
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                const base = loadMessages(locale);
                const long = [item(1, { name: pseudoLong('Dlhý názov položky'), state: 'bought' }), item(2, { name: 'Bread' }), item(3, { name: 'Apples', state: 'unavailable' })];
                mountPage({ locale, messages: base, items: long, list: { ...LIST, name: pseudoLong('Zoznam') } });
                await expectNoAxeViolations(current.element);
                await button(base.shopping.lock.unlock_button).trigger('click');
                await flushPromises();
                await expectNoAxeViolations(document.body);
                dialogButton(base.shopping.lock.cancel).click();
                await flushPromises();
                current.unmount();
                mountPage({ locale, messages: base, width: 400, items: long.map((i) => ({ ...i, state: i.state === 'pending' ? 'bought' : i.state })) });
                await expectNoAxeViolations(current.element);
                current.unmount();
                mountPage({ locale, messages: base, list: { ...LIST, status: 'open' }, abilities: PLAN, items: long.map((i) => ({ ...i, state: 'pending' })) });
                await expectNoAxeViolations(current.element);
                current.unmount();
                mountPage({ locale, messages: base, list: { ...LIST, status: 'done' }, abilities: READ, items: long });
                await expectNoAxeViolations(current.element);
            });
        }
    }
});
