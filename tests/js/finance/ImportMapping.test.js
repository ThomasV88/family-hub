import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { importPage, PREVIEW, csvFile } from './importFixtures.js';

let mockProps = {};
const post = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps, get url() { return '/finance/import'; } }),
    router: { get: vi.fn(), post: (...a) => post(...a), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: () => () => {} },
    Link: { props: ['href'], render() { return null; } },
}));

import Import from '../../../resources/js/Pages/Finance/Import.vue';

let current;
let previewResponse;
async function openColumns({ locale = 'en', width = 1280 } = {}) {
    window.innerWidth = width;
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    mockProps = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: 'edit', health: 'none' } },
        app: { env: 'local', styleGuide: false },
    });
    current = mount(Import, { props: importPage(), attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    const accountLabel = [...document.querySelectorAll('label')].find((l) => l.textContent.startsWith(current.vm.$t('finance.imp.account')));
    await choose(accountLabel.control, 'acc-main');
    const profileLabel = [...document.querySelectorAll('label')].find((l) => l.textContent.trim() === current.vm.$t('finance.imp.profile'));
    await choose(profileLabel.control, '');
    const input = document.querySelector('input[type="file"]');
    Object.defineProperty(input, 'files', { value: [csvFile()], configurable: true });
    input.dispatchEvent(new Event('change', { bubbles: true }));
    await flushPromises();
    document.querySelector('[data-testid="import-step-file"]').dispatchEvent(new Event('submit', { cancelable: true }));
    await flushPromises();
}
const field = (label) => [...document.querySelectorAll('label')].find((l) => l.textContent.replace(/\s*\*$/, '').trim() === label)?.control;
async function choose(el, value) {
    el.value = value;
    el.dispatchEvent(new Event('change', { bubbles: true }));
    await flushPromises();
}
const button = (text) => [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === text);
async function submit() {
    document.querySelector('[data-testid="import-step-columns"]').dispatchEvent(new Event('submit', { cancelable: true }));
    await flushPromises();
}
async function mapBasics() {
    await choose(field('Date'), '0');
    await choose(field('Amount'), '1');
    await choose(field('Description'), '2');
}

beforeEach(() => {
    post.mockReset();
    previewResponse = { ok: true, json: async () => PREVIEW };
    globalThis.fetch = vi.fn(async () => previewResponse);
});
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); });

describe('Import wizard, Columns step', () => {
    it('shows the decoded sample as plain text, never as markup or formulas', async () => {
        await openColumns();
        const cells = [...document.querySelectorAll('.imp-table td')].map((td) => td.textContent);
        expect(cells).toContain('Potraviny =SUM(A1)');
        expect(cells).toContain('<img src=x onerror=alert(1)>');
        expect(document.querySelector('.imp-table img')).toBeNull();
        expect(document.querySelectorAll('.imp-table tbody tr')).toHaveLength(3);
        expect([...document.querySelectorAll('.imp-table th')].map((th) => th.textContent.trim().split(/\s+/)[0])).toEqual(['Datum', 'Suma', 'Popis', 'Protistrana']);
    });

    it('offers the allowlisted settings from page props', async () => {
        await openColumns();
        expect([...field('Separator').options].map((o) => o.textContent)).toEqual(['Semicolon (;)', 'Comma (,)', 'Tab', 'Vertical bar']);
        expect([...field('Encoding').options].map((o) => o.textContent)).toContain('Windows-1250 (Central European)');
        expect(field('Encoding').value).toBe('WINDOWS-1250');
        expect([...field('Date format').options].map((o) => o.textContent)).toEqual(['DD.MM.YYYY', 'D.M.YYYY', 'YYYY-MM-DD', 'DD/MM/YYYY', 'DD-MM-YYYY', 'DD.MM.YY', 'MM/DD/YYYY']);
        expect([...field('Thousands separator').options].map((o) => o.textContent)).toEqual(['None', 'Space', 'Dot']);
        expect(field('Date').options[0].textContent).toBe('Not used');
        expect([...field('Date').options].map((o) => o.textContent)).toContain('Suma');
        expect(document.querySelector('[role="radiogroup"]').querySelectorAll('input[type="radio"]')).toHaveLength(3);
        expect(document.querySelector('input[role="switch"]').checked).toBe(true);
    });

    it('re-sends the file with the new encoding and marks the sample busy meanwhile', async () => {
        await openColumns();
        let release;
        globalThis.fetch = vi.fn(() => new Promise((resolve) => { release = () => resolve({ ok: true, json: async () => ({ ...PREVIEW, encoding: 'UTF-8', sample: [['1.1.2026', '1', 'x', 'y']] }) }); }));
        await choose(field('Encoding'), 'UTF-8');
        expect(fetch.mock.calls[0][1].body.get('encoding')).toBe('UTF-8');
        expect(fetch.mock.calls[0][1].body.get('file')).toBeInstanceOf(File);
        expect(document.querySelector('[aria-busy="true"]')).not.toBeNull();
        release();
        await flushPromises();
        expect(document.querySelector('.imp-map__sample').getAttribute('aria-busy')).toBeNull();
        expect(document.querySelectorAll('.imp-table tbody tr')).toHaveLength(1);
    });

    it('re-calls preview for separator and header row but not for decimal mark or date format', async () => {
        await openColumns();
        fetch.mockClear();
        await choose(field('Decimal mark'), '.');
        await choose(field('Date format'), 'Y-m-d');
        expect(fetch).not.toHaveBeenCalled();
        await choose(field('Separator'), ',');
        expect(fetch.mock.calls[0][1].body.get('delimiter')).toBe(',');
        await choose(field('Separator'), '\t');
        expect(fetch.mock.calls[1][1].body.get('delimiter')).toBe('tab');
        const row = field('Column names are in row');
        row.value = '2';
        row.dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();
        expect(fetch.mock.calls[2][1].body.get('header_row')).toBe('2');
    });

    it('shows the decode error next to Encoding', async () => {
        await openColumns();
        previewResponse = { ok: false, json: async () => ({ error: 'decode_failed' }) };
        await choose(field('Encoding'), 'UTF-8');
        const error = document.querySelector(`#${field('Encoding').id}-error`);
        expect(error.textContent).toContain('The file cannot be read with this encoding. Choose another one.');
    });

    it('requires date, amount and description with inline errors and does not post', async () => {
        await openColumns();
        await submit();
        expect(post).not.toHaveBeenCalled();
        const text = document.body.textContent;
        expect(text).toContain('Choose the date column.');
        expect(text).toContain('Choose the amount column.');
        expect(text).toContain('Choose the description column.');
    });

    it('switches fields with the amount mode', async () => {
        await openColumns();
        expect(field('Amount')).toBeTruthy();
        document.querySelector('input[value="split"]').click();
        await flushPromises();
        expect(field('Amount')).toBeUndefined();
        expect(field('Income column')).toBeTruthy();
        expect(field('Expense column')).toBeTruthy();
        document.querySelector('input[value="indicator"]').click();
        await flushPromises();
        expect(field('Amount')).toBeTruthy();
        expect(field('Income or expense column')).toBeTruthy();
        await mapBasics();
        await submit();
        expect(document.body.textContent).toContain('Choose the column and the values.');
        expect(post).not.toHaveBeenCalled();
    });

    it('reveals a required profile name when saving as a profile', async () => {
        await openColumns();
        expect(field('Profile name')).toBeUndefined();
        document.querySelector('input[role="switch"][type="checkbox"]:not(:checked)').click();
        await flushPromises();
        expect(field('Profile name')).toBeTruthy();
        await mapBasics();
        await submit();
        expect(document.body.textContent).toContain('Enter a profile name to save it.');
        expect(post).not.toHaveBeenCalled();
    });

    it('posts multipart file, account and a JSON settings string in ProfileSpec shape', async () => {
        await openColumns();
        await mapBasics();
        await choose(field('Payee (optional)'), '3');
        await choose(field('Separator'), '\t');
        await choose(field('Thousands separator'), ' ');
        document.querySelector('input[role="switch"][type="checkbox"]:not(:checked)').click();
        await flushPromises();
        const name = field('Profile name');
        name.value = 'Our bank 2';
        name.dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();
        await submit();
        const [url, data, options] = post.mock.calls[0];
        expect(url).toBe('/finance/import');
        expect(options.forceFormData).toBe(true);
        expect(data.file).toBeInstanceOf(File);
        expect(data.account_id).toBe('acc-main');
        expect(data.save_profile).toBe('1');
        expect(data.profile_name).toBe('Our bank 2');
        expect(JSON.parse(data.settings)).toEqual({
            delimiter: '\t', encoding: 'WINDOWS-1250', decimalSeparator: ',', groupingSeparator: ' ', dateFormat: 'd.m.Y', headerRow: 1, amountMode: 'single',
            columns: { date: { name: 'Datum', index: 0 }, amount: { name: 'Suma', index: 1 }, description: { name: 'Popis', index: 2 }, counterparty: { name: 'Protistrana', index: 3 } },
            indicatorExpense: [], indicatorIncome: [],
        });
    });

    it('sends header row 0 and no names when the first row has no column names', async () => {
        await openColumns();
        document.querySelector('input[role="switch"]').click();
        await flushPromises();
        await mapBasics();
        await submit();
        const settings = JSON.parse(post.mock.calls[0][1].settings);
        expect(settings.headerRow).toBe(0);
        expect(settings.columns.date).toEqual({ name: null, index: 0 });
    });

    it('shows a server error from staging without losing the mapping', async () => {
        await openColumns();
        await mapBasics();
        await submit();
        post.mock.calls[0][2].onError({ file: 'finance.imp.err_rows' });
        await flushPromises();
        expect(document.body.textContent).toContain('more than 5000 rows');
        expect(field('Date').value).toBe('0');
    });

    it('stacks the sample as cards at 320px without a table', async () => {
        await openColumns({ width: 320 });
        expect(document.querySelector('.imp-table')).toBeNull();
        expect(document.querySelectorAll('.imp-cards__item')).toHaveLength(3);
    });

    it('goes back to the File step', async () => {
        await openColumns();
        button('Back').click();
        await flushPromises();
        expect(document.querySelector('[data-testid="import-step-file"]')).not.toBeNull();
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`passes axe on Columns (${locale}${dark ? ', dark' : ''})`, async () => {
                document.documentElement.classList.toggle('dark', dark);
                await openColumns({ locale });
                await expectNoAxeViolations(document.body);
            });
        }
        it(`passes axe on Columns with errors, indicator mode and profile name (${locale}, 320px)`, async () => {
            await openColumns({ locale, width: 320 });
            document.querySelector('input[value="indicator"]').click();
            document.querySelector('input[role="switch"][type="checkbox"]:not(:checked)').click();
            await flushPromises();
            await submit();
            await expectNoAxeViolations(document.body);
        });
    }
});
