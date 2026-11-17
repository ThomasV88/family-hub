import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

const { routerMock, fetchMock } = vi.hoisted(() => ({
    routerMock: { get: vi.fn(), post: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn(() => vi.fn()) },
    fetchMock: vi.fn(),
}));
vi.mock('@inertiajs/vue3', () => ({ router: routerMock }));
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: fetchMock }));

import ImportFromUrlDialog from '../../../resources/js/Components/Recipes/ImportFromUrlDialog.vue';

const ID = '22222222-2222-4222-8222-222222222222';
const ok = (data = {}, status = 200) => ({ ok: true, status, data });
const bad = (status, data = {}, extra = {}) => ({ ok: false, status, error: 'failed', data, ...extra });
const STATUS = (status, reason = null) => ok({ id: ID, status, reason, host: 'recepty.example', textCut: false });
const INFO = { available: true, acknowledged: true, providers: ['codex'] };

let current;
function mountDialog({ info = INFO, locale = 'en' } = {}) {
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(ImportFromUrlDialog, { props: { open: false, info }, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });

    return current;
}
async function open() {
    await current.setProps({ open: true });
    await flushPromises();
}
const $ = (sel) => document.body.querySelector(sel);
const text = () => document.body.textContent;
const type = async (value) => {
    const input = $('[data-testid="import-url"]');
    input.value = value;
    input.dispatchEvent(new Event('input'));
    await flushPromises();
};
const read = async () => { $('[data-testid="import-read"]').click(); await flushPromises(); };
const advance = async (ms) => { await vi.advanceTimersByTimeAsync(ms); await flushPromises(); };

beforeEach(() => { vi.useFakeTimers(); fetchMock.mockReset(); Object.values(routerMock).forEach((f) => f.mockClear()); routerMock.on.mockImplementation(() => vi.fn()); });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; vi.useRealTimers(); });

describe('ImportFromUrlDialog: the address', () => {
    it('keeps Read recipe disabled for an empty or invalid address and enables it for a valid one', async () => {
        mountDialog();
        await open();
        const button = () => $('[data-testid="import-read"]');
        expect(button().disabled).toBe(true);
        await type('recepty.sk');
        expect(button().disabled).toBe(true);
        await type('https://recepty.example/a');
        expect(button().disabled).toBe(false);
        expect($('[data-testid="import-url"]').getAttribute('type')).toBe('url');
        expect($('[data-testid="import-url"]').getAttribute('inputmode')).toBe('url');
    });

    it('shows the address problem after the field is left, and clears it when the address is fixed', async () => {
        mountDialog();
        await open();
        await type('recepty.sk');
        $('[data-testid="import-url"]').dispatchEvent(new Event('blur'));
        await flushPromises();
        expect(text()).toContain('Enter a web address that starts with http:// or https://.');
        await type('https://recepty.example/a');
        expect(text()).not.toContain('Enter a web address that starts with');
    });

    it('shows a server 422 as the address problem and keeps the field editable', async () => {
        fetchMock.mockResolvedValueOnce(bad(422, { errors: { url: ['recipes.import.err_url'] } }, { error: 'invalid' }));
        mountDialog();
        await open();
        await type('https://recepty.example/a');
        await read();
        expect(text()).toContain('Enter a web address that starts with http:// or https://.');
        expect($('[data-testid="import-url"]').readOnly).toBe(false);
        expect($('[data-testid="import-status"]')).toBeNull();
    });
});

describe('ImportFromUrlDialog: the one-time notice', () => {
    const NOTICE = { available: true, acknowledged: false, providers: ['codex', 'copilot'] };

    it('shows the notice first, without the field; Got it posts the providers and reveals the field with the permanent hint', async () => {
        fetchMock.mockResolvedValueOnce({ ok: true, status: 204, data: {} });
        mountDialog({ info: NOTICE });
        await open();
        expect($('[data-testid="import-notice"]').textContent).toContain('The page text is sent to the AI provider');
        expect($('[data-testid="import-notice"]').textContent).toContain('the text of the page is sent to the AI provider');
        expect($('[data-testid="import-url"]')).toBeNull();
        expect($('[data-testid="import-read"]').disabled).toBe(true);
        $('[data-testid="import-notice-confirm"]').click();
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledWith('/recipes/imports/notice', { method: 'POST', body: { providers: ['codex', 'copilot'] } });
        expect($('[data-testid="import-notice"]')).toBeNull();
        expect($('[data-testid="import-url"]')).not.toBeNull();
        expect($('[data-testid="import-hint"]').textContent).toContain('The text of the page is sent to the AI provider.');
    });

    it('does not send the confirmation twice on a double click', async () => {
        let release;
        fetchMock.mockImplementationOnce(() => new Promise((resolve) => { release = () => resolve({ ok: true, status: 204, data: {} }); }));
        mountDialog({ info: NOTICE });
        await open();
        $('[data-testid="import-notice-confirm"]').click();
        $('[data-testid="import-notice-confirm"]').click();
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        release();
        await flushPromises();
    });

    it('a 409 updates the providers and shows the notice again with a message', async () => {
        fetchMock.mockResolvedValueOnce(bad(409, { outcome: 'notice_changed', providers: ['codex'] })).mockResolvedValueOnce({ ok: true, status: 204, data: {} });
        mountDialog({ info: NOTICE });
        await open();
        $('[data-testid="import-notice-confirm"]').click();
        await flushPromises();
        expect($('[data-testid="import-notice"]')).not.toBeNull();
        expect($('[data-testid="import-notice-problem"]').textContent).toContain('AI settings changed');
        $('[data-testid="import-notice-confirm"]').click();
        await flushPromises();
        expect(fetchMock).toHaveBeenLastCalledWith('/recipes/imports/notice', { method: 'POST', body: { providers: ['codex'] } });
        expect($('[data-testid="import-url"]')).not.toBeNull();
    });

    it.each([[403, 'You are not allowed'], [419, 'Your session expired'], [500, 'could not save your confirmation']])('HTTP %s on the notice has its own text', async (status, expected) => {
        fetchMock.mockResolvedValueOnce(bad(status));
        mountDialog({ info: NOTICE });
        await open();
        $('[data-testid="import-notice-confirm"]').click();
        await flushPromises();
        expect($('[data-testid="import-notice-problem"]').textContent).toContain(expected);
        expect($('[data-testid="import-notice"]')).not.toBeNull();
    });

    it('a start answered with notice_required shows the notice again with the new providers', async () => {
        fetchMock.mockResolvedValueOnce(bad(409, { outcome: 'notice_required', providers: ['codex', 'copilot'] })).mockResolvedValueOnce({ ok: true, status: 204, data: {} });
        mountDialog();
        await open();
        await type('https://recepty.example/a');
        await read();
        expect($('[data-testid="import-notice"]')).not.toBeNull();
        $('[data-testid="import-notice-confirm"]').click();
        await flushPromises();
        expect(fetchMock).toHaveBeenLastCalledWith('/recipes/imports/notice', { method: 'POST', body: { providers: ['codex', 'copilot'] } });
        expect($('[data-testid="import-url"]').value).toBe('https://recepty.example/a');
    });
});

describe('ImportFromUrlDialog: reading', () => {
    it('starts with the trimmed address, shows progress with the field read-only and a live announcement', async () => {
        fetchMock.mockResolvedValueOnce(ok({ outcome: 'started', id: ID }, 202));
        mountDialog();
        await open();
        await type('  https://recepty.example/a  ');
        await read();
        expect(fetchMock).toHaveBeenCalledWith('/recipes/imports', { method: 'POST', body: { url: 'https://recepty.example/a' } });
        expect($('[data-testid="import-status"]').textContent).toContain('Reading the page. This can take a minute.');
        expect($('[role="progressbar"]')).not.toBeNull();
        expect($('[data-testid="import-url"]').readOnly).toBe(true);
        expect($('[data-testid="import-announce"]').textContent).toContain('Reading the page.');
    });

    it('does not start twice on a double submit', async () => {
        let release;
        fetchMock.mockImplementationOnce(() => new Promise((resolve) => { release = () => resolve(ok({ outcome: 'started', id: ID }, 202)); }));
        mountDialog();
        await open();
        await type('https://recepty.example/a');
        $('[data-testid="import-read"]').click();
        $('[data-testid="import-read"]').click();
        $('form').dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        release();
        await flushPromises();
    });

    it('Escape and the close button do nothing while the start request runs', async () => {
        let release;
        fetchMock.mockImplementationOnce(() => new Promise((resolve) => { release = () => resolve(ok({ outcome: 'started', id: ID }, 202)); }));
        mountDialog();
        await open();
        await type('https://recepty.example/a');
        $('[data-testid="import-read"]').click();
        await flushPromises();
        $('.ui-dialog__scrim').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flushPromises();
        expect(current.emitted('update:open')).toBeUndefined();
        expect(document.body.querySelector('button[aria-label]')).toBeNull();
        release();
        await flushPromises();
    });

    it('ready opens the form for review', async () => {
        fetchMock.mockResolvedValueOnce(ok({ outcome: 'started', id: ID }, 202)).mockResolvedValueOnce(STATUS('running')).mockResolvedValueOnce(STATUS('ready'));
        mountDialog();
        await open();
        await type('https://recepty.example/a');
        await read();
        await advance(2000);
        expect(routerMock.visit).not.toHaveBeenCalled();
        await advance(2000);
        expect(routerMock.visit).toHaveBeenCalledWith(`/recipes/create?import=${ID}`);
        const calls = fetchMock.mock.calls.length;
        await advance(10000);
        expect(fetchMock.mock.calls.length).toBe(calls);
    });

    it.each([
        ['fetch', 'We could not open that page. Check the address, or type the recipe in yourself.', 'Change address'],
        ['no_recipe', 'We could not find a recipe on that page. Try the page with the full recipe, or type it in yourself.', 'Change address'],
        ['unavailable', 'Reading pages is not available right now. You can type the recipe in yourself.', 'Try again'],
    ])('a failed import (%s) says what happened with Type it in keeping the address', async (reason, message, again) => {
        fetchMock.mockResolvedValueOnce(ok({ outcome: 'started', id: ID }, 202)).mockResolvedValueOnce(STATUS('failed', reason));
        mountDialog();
        await open();
        await type('https://recepty.example/a?x=1&y=2');
        await read();
        await advance(2000);
        expect($('[data-testid="import-failure"]').textContent).toContain(message);
        expect($('[data-testid="import-again"]').textContent.trim()).toBe(again);
        const typeIt = $('[data-testid="import-type-it-in"]');
        expect(typeIt.getAttribute('href')).toBe(`/recipes/create?source=${encodeURIComponent('https://recepty.example/a?x=1&y=2')}`);
        typeIt.click();
        expect(routerMock.visit).toHaveBeenCalledWith(`/recipes/create?source=${encodeURIComponent('https://recepty.example/a?x=1&y=2')}`);
        expect(current.emitted('update:open').at(-1)).toEqual([false]);
    });

    it('Try again returns to the field with the pasted address kept', async () => {
        fetchMock.mockResolvedValueOnce(ok({ outcome: 'started', id: ID }, 202)).mockResolvedValueOnce(STATUS('failed', 'fetch'));
        mountDialog();
        await open();
        await type('https://recepty.example/a');
        await read();
        await advance(2000);
        $('[data-testid="import-again"]').click();
        await flushPromises();
        expect($('[data-testid="import-failure"]')).toBeNull();
        expect($('[data-testid="import-url"]').value).toBe('https://recepty.example/a');
        expect($('[data-testid="import-url"]').readOnly).toBe(false);
        expect($('[data-testid="import-read"]').disabled).toBe(false);
    });

    it('gives up after three minutes with a generic failure', async () => {
        fetchMock.mockResolvedValueOnce(ok({ outcome: 'started', id: ID }, 202)).mockResolvedValue(STATUS('running'));
        mountDialog();
        await open();
        await type('https://recepty.example/a');
        await read();
        await advance(3 * 60 * 1000 + 5000);
        expect($('[data-testid="import-failure"]').textContent).toContain('took too long');
    });

    it.each([
        [bad(429, {}, { error: 'throttle' }), 'You have read too many pages today. Try again later.'],
        [bad(409, { outcome: 'unavailable' }), 'Reading pages is not available right now.'],
        [bad(403), 'You are not allowed to do that'],
        [bad(419), 'Your session expired'],
        [bad(500), 'could not start reading the page'],
        [{ ok: false, status: 0, error: 'network', data: {} }, 'could not start reading the page'],
    ])('a start that fails (%#) shows its own text and Type it in', async (result, expected) => {
        fetchMock.mockResolvedValueOnce(result);
        mountDialog();
        await open();
        await type('https://recepty.example/a');
        await read();
        expect($('[data-testid="import-failure"]').textContent).toContain(expected);
        expect($('[data-testid="import-type-it-in"]')).not.toBeNull();
    });

    it('the limit offers no Try again', async () => {
        fetchMock.mockResolvedValueOnce(bad(429, { outcome: 'limit' }, { error: 'throttle' }));
        mountDialog();
        await open();
        await type('https://recepty.example/a');
        await read();
        expect($('[data-testid="import-again"]')).toBeNull();
    });

    it('Cancel while reading posts the cancel and closes', async () => {
        fetchMock.mockResolvedValueOnce(ok({ outcome: 'started', id: ID }, 202)).mockResolvedValueOnce({ ok: true, status: 204, data: {} });
        mountDialog();
        await open();
        await type('https://recepty.example/a');
        await read();
        $('[data-testid="import-cancel"]').click();
        await flushPromises();
        expect(fetchMock).toHaveBeenLastCalledWith(`/recipes/imports/${ID}/cancel`, { method: 'POST' });
        expect(current.emitted('update:open').at(-1)).toEqual([false]);
        expect(current.emitted('background')).toBeUndefined();
    });

    it('a failed Cancel stays open with a message and does not post twice at once', async () => {
        let release;
        fetchMock.mockResolvedValueOnce(ok({ outcome: 'started', id: ID }, 202)).mockImplementationOnce(() => new Promise((resolve) => { release = () => resolve(bad(500)); }));
        mountDialog();
        await open();
        await type('https://recepty.example/a');
        await read();
        $('[data-testid="import-cancel"]').click();
        $('[data-testid="import-cancel"]').click();
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(2);
        release();
        await flushPromises();
        expect($('[data-testid="import-cancel-problem"]').textContent).toContain('could not stop the reading');
        expect($('[data-testid="import-status"]')).not.toBeNull();
    });

    it('closing while reading keeps the import running and tells the page', async () => {
        fetchMock.mockResolvedValueOnce(ok({ outcome: 'started', id: ID }, 202));
        mountDialog();
        await open();
        await type('https://recepty.example/a');
        await read();
        $('.ui-dialog__header button').click();
        await flushPromises();
        expect(current.emitted('background')).toHaveLength(1);
        expect(current.emitted('update:open').at(-1)).toEqual([false]);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('stops polling when the dialog is closed and when it unmounts', async () => {
        fetchMock.mockResolvedValueOnce(ok({ outcome: 'started', id: ID }, 202)).mockResolvedValue(STATUS('running'));
        mountDialog();
        await open();
        await type('https://recepty.example/a');
        await read();
        await advance(2000);
        const calls = fetchMock.mock.calls.length;
        await current.setProps({ open: false });
        await advance(10000);
        expect(fetchMock.mock.calls.length).toBe(calls);
    });

    it('renders markup in the address as text only', async () => {
        fetchMock.mockResolvedValueOnce(ok({ outcome: 'started', id: ID }, 202)).mockResolvedValueOnce(STATUS('failed', 'fetch'));
        mountDialog();
        await open();
        await type('https://recepty.example/<img src=x onerror=alert(1)>');
        $('form').dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(document.body.querySelector('.ui-dialog img')).toBeNull();
    });
});

describe('ImportFromUrlDialog: accessibility', () => {
    it('has no axe violations on the notice, the field, the progress and a failure', async () => {
        vi.useRealTimers(); // axe needs real timers
        fetchMock.mockResolvedValueOnce({ ok: true, status: 204, data: {} }).mockResolvedValueOnce(ok({ outcome: 'started', id: ID }, 202)).mockResolvedValueOnce(STATUS('failed', 'fetch'));
        mountDialog({ info: { available: true, acknowledged: false, providers: ['codex'] } });
        await open();
        await expectNoAxeViolations(document.body);
        $('[data-testid="import-notice-confirm"]').click();
        await flushPromises();
        await expectNoAxeViolations(document.body);
        await type('https://recepty.example/a');
        await read();
        await expectNoAxeViolations(document.body);
        await new Promise((resolve) => setTimeout(resolve, 2100));
        await flushPromises();
        expect($('[data-testid="import-failure"]')).not.toBeNull();
        await expectNoAxeViolations(document.body);
    });
});
