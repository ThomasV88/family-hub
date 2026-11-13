import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { importPage, PREVIEW, csvFile } from './importFixtures.js';

let mockProps = {};
const handlers = {};
const post = vi.fn();
const visit = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps, get url() { return '/finance/import'; } }),
    router: { get: vi.fn(), post: (...a) => post(...a), patch: vi.fn(), delete: vi.fn(), visit: (...a) => visit(...a), on: (name, fn) => { handlers[name] = fn; return () => {}; } },
    Link: { props: ['href'], render() { return null; } },
}));

import Import from '../../../resources/js/Pages/Finance/Import.vue';

let current;
function mountPage(props = {}, { locale = 'en', width = 1280 } = {}) {
    window.innerWidth = width;
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    mockProps = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: 'edit', health: 'none' } },
        app: { env: 'local', styleGuide: false },
    });
    current = mount(Import, { props: { ...importPage(), ...props }, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return current;
}

async function pickFile(file) {
    const input = document.querySelector('input[type="file"]');
    Object.defineProperty(input, 'files', { value: [file], configurable: true });
    input.dispatchEvent(new Event('change', { bubbles: true }));
    await flushPromises();
}
const select = (label) => [...document.querySelectorAll('label')].find((l) => l.textContent.replace(/\s*\*$/, '').trim() === label).control;
async function choose(sel, value) {
    sel.value = value;
    sel.dispatchEvent(new Event('change', { bubbles: true }));
    await flushPromises();
}
const readButton = () => [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Read file');

beforeEach(() => {
    post.mockReset();
    visit.mockReset();
    for (const k of Object.keys(handlers)) delete handlers[k];
    document.cookie = 'XSRF-TOKEN=tok%3D1';
    globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => PREVIEW }));
});
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); });

describe('Import wizard, File step', () => {
    it('shows the stepper at File, the privacy note and a disabled Read file button', () => {
        mountPage();
        expect(document.querySelector('[aria-current="step"]').textContent).toContain('File');
        expect(document.body.textContent).toContain('The file is read once and not kept. Only the rows you import are saved.');
        expect(readButton().disabled).toBe(true);
        const profile = select('Bank profile');
        expect([...profile.options].map((o) => o.textContent.trim())).toEqual(['Our bank', 'New profile (set up columns)']);
    });

    it('preselects the account from the chip', () => {
        mountPage({ finance: { ...importPage().finance, selectedAccount: 'acc-cash' } });
        expect(select('Import into account').value).toBe('acc-cash');
    });

    it('refuses a non-CSV file without any request', async () => {
        mountPage();
        await choose(select('Import into account'), 'acc-main');
        await pickFile(csvFile('statement.xlsx'));
        expect(document.body.textContent).toContain('Choose a .csv or .txt file.');
        expect(readButton().disabled).toBe(true);
        expect(fetch).not.toHaveBeenCalled();
        expect(post).not.toHaveBeenCalled();
    });

    it('refuses a file of 2 MiB plus one byte', async () => {
        mountPage();
        await choose(select('Import into account'), 'acc-main');
        await pickFile(csvFile('big.csv', 2 * 1024 * 1024 + 1));
        expect(document.body.textContent).toContain('The file is larger than 2 MB.');
        expect(fetch).not.toHaveBeenCalled();
    });

    it('accepts a file of exactly 2 MiB', async () => {
        mountPage();
        await choose(select('Import into account'), 'acc-main');
        await pickFile(csvFile('ok.csv', 2 * 1024 * 1024));
        expect(readButton().disabled).toBe(false);
    });

    it('uses the preview API with the CSRF header for a new profile and moves to Columns', async () => {
        mountPage();
        await choose(select('Import into account'), 'acc-main');
        await choose(select('Bank profile'), '');
        await pickFile(csvFile());
        readButton().click();
        await flushPromises();
        expect(fetch).toHaveBeenCalledTimes(1);
        const [url, init] = fetch.mock.calls[0];
        expect(url).toBe('/finance/import/preview');
        expect(init.method).toBe('POST');
        expect(init.headers['X-XSRF-TOKEN']).toBe('tok=1');
        expect(init.body.get('file')).toBeInstanceOf(File);
        expect(post).not.toHaveBeenCalled();
        expect(document.querySelector('[aria-current="step"]').textContent).toContain('Columns');
        expect(document.querySelector('[data-testid="import-suggested"]').textContent).toContain('Suggested: Windows-1250 (Central European)');
    });

    it('posts a saved profile to /finance/import and shows an inline server error', async () => {
        mountPage();
        await choose(select('Import into account'), 'acc-main');
        await pickFile(csvFile());
        readButton().click();
        await flushPromises();
        expect(fetch).not.toHaveBeenCalled();
        const [url, data, options] = post.mock.calls[0];
        expect(url).toBe('/finance/import');
        expect(data).toMatchObject({ account_id: 'acc-main', profile_id: 'prof-1' });
        expect(data.file).toBeInstanceOf(File);
        expect(options.forceFormData).toBe(true);
        options.onError({ file: 'finance.imp.err_size' });
        await flushPromises();
        expect(document.body.textContent).toContain('The file is larger than 2 MB.');
    });

    it('stops at Columns with an alert when the saved profile does not match', async () => {
        mountPage();
        await choose(select('Import into account'), 'acc-main');
        await pickFile(csvFile());
        readButton().click();
        await flushPromises();
        post.mock.calls[0][2].onError({ profile_id: 'finance.imp.err_profile' });
        await flushPromises();
        expect(document.querySelector('[aria-current="step"]').textContent).toContain('Columns');
        expect(document.querySelector('[role="alert"], [role="status"]').textContent).toContain('This file does not match the profile. Check the columns.');
        // saved mapping resolved by header name
        expect(select('Date').value).toBe('0');
        expect(select('Description').value).toBe('2');
        expect(fetch.mock.calls[0][1].body.get('encoding')).toBe('WINDOWS-1250');
    });

    it('shows a decode error from the preview next to the page without moving on', async () => {
        globalThis.fetch = vi.fn(async () => ({ ok: false, json: async () => ({ error: 'decode_failed' }) }));
        mountPage();
        await choose(select('Import into account'), 'acc-main');
        await choose(select('Bank profile'), '');
        await pickFile(csvFile());
        readButton().click();
        await flushPromises();
        expect(document.body.textContent).toContain('The file cannot be read with this encoding. Choose another one.');
        expect(document.querySelector('[aria-current="step"]').textContent).toContain('File');
    });

    it('asks before leaving with a selected file and lets the member stay or leave', async () => {
        mountPage();
        await pickFile(csvFile());
        const event = { detail: { visit: { method: 'get', url: new URL('http://localhost/finance') } } };
        expect(handlers.before(event)).toBe(false);
        await flushPromises();
        expect(document.body.textContent).toContain('Leave the import?');
        const leave = [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Leave import');
        leave.click();
        await flushPromises();
        expect(visit).toHaveBeenCalledWith('http://localhost/finance');
        expect(handlers.before(event)).toBeUndefined();
    });

    it('does not guard navigation while no file is selected', () => {
        mountPage();
        expect(handlers.before({ detail: { visit: { method: 'get', url: new URL('http://localhost/finance') } } })).toBeUndefined();
    });

    it('lists earlier imports with Undo only where allowed and confirms before posting', async () => {
        mountPage();
        const history = document.querySelector('[data-testid="import-history"]');
        expect(history.textContent).toContain('12 transactions');
        expect(history.textContent).toContain('1 transaction');
        const undoButtons = [...history.querySelectorAll('button')].filter((b) => b.textContent.trim() === 'Undo import');
        expect(undoButtons).toHaveLength(1);
        undoButtons[0].click();
        await flushPromises();
        expect(document.body.textContent).toContain('Undo this import?');
        expect(document.activeElement.textContent.trim()).toBe('Keep import');
        [...document.querySelectorAll('[role="dialog"] button')].find((b) => b.textContent.trim() === 'Undo import').click();
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/finance/import/b-1/undo', {}, expect.anything());
    });

    it('never touches browser storage', async () => {
        const set = vi.spyOn(Storage.prototype, 'setItem');
        mountPage();
        await choose(select('Import into account'), 'acc-main');
        await pickFile(csvFile());
        expect(set).not.toHaveBeenCalled();
        set.mockRestore();
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`passes axe on File and history (${locale}${dark ? ', dark' : ''})`, async () => {
                document.documentElement.classList.toggle('dark', dark);
                mountPage({}, { locale });
                await expectNoAxeViolations(document.body);
            });
        }
        it(`passes axe on File with an error (${locale})`, async () => {
            mountPage({}, { locale });
            await pickFile(csvFile('x.xlsx'));
            await expectNoAxeViolations(document.body);
        });
    }
});
