import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

// Failures that are not validation errors (403, 404, 409, 429, 5xx, no connection) in the Shopping dialogs and helpers.
let mockProps = {};
const { routerMock, forms } = vi.hoisted(() => ({
    routerMock: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn() },
    forms: [],
}));
vi.mock('@inertiajs/vue3', async () => {
    const { h, reactive } = await import('vue');
    return {
        usePage: () => ({ props: mockProps, url: '/shopping' }),
        router: routerMock,
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

import CopyListDialog from '../../../resources/js/Shopping/CopyListDialog.vue';
import CreateListDialog from '../../../resources/js/Shopping/CreateListDialog.vue';
import ItemEditDialog from '../../../resources/js/Shopping/ItemEditDialog.vue';
import ListSettingsPanel from '../../../resources/js/Shopping/ListSettingsPanel.vue';
import ItemPickerDialog from '../../../resources/js/Shopping/ItemPickerDialog.vue';
import { failureKey, httpHandlers, reconcile } from '../../../resources/js/Shopping/failure.js';
import { NAME_MAX, charLength, clip } from '../../../resources/js/Shopping/limits.js';
import { formatListDate } from '../../../resources/js/Shopping/listStatus.js';

let current;
function mountIt(component, props) {
    mockProps = { auth: { user: { id: 2 } }, flash: {} };
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {} });
    current = mount(component, { props, attachTo: document.body, global: { plugins: [createAppI18n('en', loadMessages('en'))] } });
    return current;
}
const dialog = () => document.body.querySelector('[role="dialog"]');
const clickButton = (label) => [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === label).click();
const form = () => forms.at(-1);

beforeEach(() => { forms.length = 0; Object.values(routerMock).forEach((f) => f.mockClear()); });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; });

describe('failure helpers', () => {
    it('maps the refusals to messages', () => {
        expect(failureKey({ status: 403 })).toBe('shopping.error.forbidden');
        expect(failureKey({ status: 404 })).toBe('shopping.error.list_changed');
        expect(failureKey({ status: 409 })).toBe('shopping.error.list_changed');
        expect(failureKey({ status: 429 })).toBe('shopping.error.too_many_requests');
        expect(failureKey({ status: 500 })).toBe('shopping.error.save_failed');
        expect(failureKey({ status: 500 }, 'x.y')).toBe('x.y');
        expect(failureKey(null)).toBe('shopping.error.save_failed');
    });

    it('reloads after 403, 404 and 409 only, and leaves for the overview when the list is gone', () => {
        reconcile({ status: 500 });
        reconcile({ status: 422 });
        expect(routerMock.reload).not.toHaveBeenCalled();
        for (const status of [403, 404, 409]) reconcile({ status });
        expect(routerMock.reload).toHaveBeenCalledTimes(3);
        const opts = routerMock.reload.mock.calls[0][0];
        expect(opts.onHttpException({ status: 404 })).toBe(false);
        expect(routerMock.visit).toHaveBeenCalledWith('/shopping', { replace: true });
    });

    it('httpHandlers reports and stops Inertia from opening its error window', () => {
        const report = vi.fn();
        const handlers = httpHandlers(report);
        expect(handlers.onHttpException({ status: 403 })).toBe(false);
        expect(report).toHaveBeenCalledWith('shopping.error.forbidden', { status: 403 });
        handlers.onNetworkError();
        expect(report).toHaveBeenLastCalledWith('shopping.error.save_failed', null);
    });

    it('counts and cuts text by characters, never inside a surrogate pair', () => {
        expect(NAME_MAX).toBe(80);
        expect(charLength('a😀b')).toBe(3);
        expect(clip('a😀😀b', 2)).toBe('a😀');
        expect(clip('😀😀', 1)).toBe('😀');
        expect(clip(null, 3)).toBe('');
    });
});

describe('the reminder time zone', () => {
    it('shows the family wall clock whatever the device zone is', () => {
        const iso = '2026-11-20T18:00:00+01:00';
        expect(formatListDate(iso, 'sk', { time: true })).toContain('18:00');
        // an instant that is 17:00 UTC is 18:00 in the family zone, whatever zone the test machine has
        expect(formatListDate('2026-11-20T17:00:00Z', 'sk', { time: true })).toContain('18:00');
        // 23:30 UTC is already the next day in the family zone
        expect(formatListDate('2026-11-20T23:30:00Z', 'en', { time: true })).toContain('Nov 21');
    });
});

describe('dialogs show every kind of failure', () => {
    it('copy dialog: forbidden after 403 and generic after 500', async () => {
        mountIt(CopyListDialog, { open: true, list: { id: 5, name: 'Weekly' } });
        await flushPromises();
        clickButton('Copy list');
        const opts = form().post.mock.calls[0][1];
        expect(opts.onHttpException({ status: 403 })).toBe(false);
        await flushPromises();
        expect(dialog().querySelector('[data-testid="copy-failed"]').textContent).toContain('You are not allowed to do that on this list.');
        clickButton('Copy list');
        form().post.mock.calls[1][1].onHttpException({ status: 500 });
        await flushPromises();
        expect(dialog().querySelector('[data-testid="copy-failed"]').textContent).toContain('Your changes were not saved.');
        form().post.mock.calls[1][1].onNetworkError();
        await flushPromises();
        expect(dialog().querySelector('[data-testid="copy-failed"]')).not.toBeNull();
    });

    it('copy dialog: the default name is cut by characters, not inside an emoji', async () => {
        mountIt(CopyListDialog, { open: true, list: { id: 5, name: `${'x'.repeat(60)}${'😀'.repeat(15)}` } });
        await flushPromises();
        expect(charLength(form().name)).toBeLessThanOrEqual(80);
        expect(form().name).not.toMatch(/[\ud800-\udbff](?![\udc00-\udfff])/);
        expect(JSON.parse(JSON.stringify(form().name))).toBe(form().name);
    });

    it('create dialog: 429 says to wait', async () => {
        mountIt(CreateListDialog, { open: true, stores: [], candidates: [{ id: 2, name: 'Me', isChild: false }] });
        await flushPromises();
        form().name = 'Party';
        await flushPromises();
        clickButton('Create list');
        form().post.mock.calls[0][1].onHttpException({ status: 429 });
        await flushPromises();
        expect(dialog().querySelector('[data-testid="create-failed"]').textContent).toContain('Too many requests.');
    });

    it('item edit: a double tap on Remove sends one DELETE and a 404 is explained', async () => {
        mountIt(ItemEditDialog, { open: true, listId: 5, item: { id: 1, name: 'Milk', quantity: '1.00', unitId: null, note: null, catalogItemId: null }, units: [] });
        await flushPromises();
        clickButton('Remove item');
        await flushPromises();
        clickButton('Remove item');
        clickButton('Remove item');
        await flushPromises();
        expect(routerMock.delete).toHaveBeenCalledTimes(1);
        expect(routerMock.delete.mock.calls[0][0]).toBe('/shopping/lists/5/items/1');
        const opts = routerMock.delete.mock.calls[0][1];
        expect(opts.onHttpException({ status: 404 })).toBe(false);
        opts.onFinish();
        await flushPromises();
        expect(routerMock.reload).toHaveBeenCalled();
        expect(dialog().querySelector('[data-testid="item-edit-failed"]').textContent).toContain('The list was changed by someone else');
    });

    it('item edit: the save shows a 409 instead of the raw error window', async () => {
        mountIt(ItemEditDialog, { open: true, listId: 5, item: { id: 1, name: 'Milk', quantity: '1.00', unitId: null, note: null, catalogItemId: null }, units: [] });
        await flushPromises();
        dialog().querySelector('form').dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(form().put.mock.calls[0][1].onHttpException({ status: 409 })).toBe(false);
        await flushPromises();
        expect(dialog().querySelector('[data-testid="item-edit-failed"]').textContent).toContain('The list was changed by someone else');
    });

    it('list settings: a refused save shows forbidden', async () => {
        mountIt(ListSettingsPanel, {
            open: true,
            list: { id: 5, name: 'Weekly', store: null, responsible: { id: 2, name: 'Me' }, creator: { id: 2, name: 'Me' }, remindAt: null, reminderSent: false },
            abilities: { editSettings: true }, members: { contributors: [], viewers: [] }, stores: [],
        });
        await flushPromises();
        form().name = 'Renamed';
        await flushPromises();
        document.body.querySelector('form#list-settings-form').dispatchEvent(new Event('submit'));
        await flushPromises();
        form().put.mock.calls[0][1].onHttpException({ status: 403 });
        await flushPromises();
        expect(document.body.querySelector('[data-testid="panel-failed"]').textContent).toContain('You are not allowed to do that on this list.');
    });

    it('picker: a refused add shows the message and an emoji is never split when typing a long name', async () => {
        mountIt(ItemPickerDialog, { open: true, listId: 5, catalog: [{ id: 1, name: 'Milk', defaultUnitId: null, imageVersion: null }], onListIds: [], units: [] });
        await flushPromises();
        const search = dialog().querySelector('input[type="search"]');
        search.value = `${'x'.repeat(79)}😀😀`;
        search.dispatchEvent(new Event('input'));
        await flushPromises();
        dialog().querySelector('[data-testid="free-row"]').click();
        await flushPromises();
        clickButton('Add 1 item');
        const sent = form().items[0].name;
        expect(Array.from(sent)).toHaveLength(80);
        expect(JSON.parse(JSON.stringify(sent))).toBe(sent);
        form().post.mock.calls[0][1].onHttpException({ status: 403 });
        await flushPromises();
        expect(dialog().querySelector('[data-testid="picker-error"]').textContent).toContain('You are not allowed to do that on this list.');
    });
});
