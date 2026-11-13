import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { batchPage, doneBatch, rowOf } from './importFixtures.js';

let mockProps = {};
const post = vi.fn();
const del = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps, get url() { return '/finance/import/batch-1'; } }),
    router: { get: vi.fn(), post: (...a) => post(...a), patch: vi.fn(), delete: (...a) => del(...a), visit: vi.fn(), on: () => () => {} },
    Link: { props: ['href'], render() { return null; } },
}));

import ImportBatch from '../../../resources/js/Pages/Finance/ImportBatch.vue';

let current;
function mountPage(props = batchPage(), { locale = 'en', width = 1280 } = {}) {
    window.innerWidth = width;
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    mockProps = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: 'edit', health: 'none' } },
        app: { env: 'local', styleGuide: false },
    });
    current = mount(ImportBatch, { props, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return current;
}
const button = (text) => [...document.querySelectorAll('button, a')].find((b) => b.textContent.trim() === text);
const checkbox = (label) => document.querySelector(`input[type="checkbox"][aria-label="${label}"]`);
const commitButton = () => [...document.querySelectorAll('button')].find((b) => /^Import \d+ transactions?$/.test(b.textContent.trim()));

beforeEach(() => { post.mockReset(); del.mockReset(); });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); });

describe('Import wizard, Review step', () => {
    it('shows the server counts and the help line', () => {
        mountPage();
        const text = document.querySelector('[data-testid="import-step-review"]').textContent;
        expect(text).toContain('5 rows');
        expect(text).toContain('New 2');
        expect(text).toContain('Possible duplicate 2');
        expect(text).toContain('Cannot be read 1');
        expect(text).toContain('Rows that look like transactions you already have are struck through and left out. Tick one to import it anyway.');
        expect(document.querySelector('[aria-current="step"]').textContent).toContain('Review');
    });

    it('wide layout is a table; new rows are ticked, duplicates struck through and unticked, unreadable rows have no checkbox', () => {
        mountPage();
        expect(document.querySelector('table')).not.toBeNull();
        expect([...document.querySelectorAll('thead th')].map((th) => th.textContent.trim())).toEqual(['Import', 'Date', 'Description', 'Category', 'Amount', 'Status']);
        expect(checkbox('Import row 1').checked).toBe(true);
        expect(checkbox('Import row 2').checked).toBe(true);
        const dup = checkbox('Row 3, possible duplicate, left out');
        expect(dup.checked).toBe(false);
        expect(checkbox('Row 4, possible duplicate, left out').checked).toBe(false);
        const dupRow = dup.closest('tr');
        expect(dupRow.querySelectorAll('.imp-struck')).toHaveLength(2);
        expect(dupRow.textContent).toContain('Possible duplicate');
        const bad = [...document.querySelectorAll('tbody tr')][4];
        expect(bad.querySelector('input[type="checkbox"]')).toBeNull();
        expect(bad.textContent).toContain('Row 5: date not recognised');
        expect(bad.textContent).toContain('Cannot be read');
    });

    it('shows the recurring match of a row', () => {
        mountPage();
        expect(document.body.textContent).toContain('Matches Rent');
    });

    it('imports the ticked rows with their category overrides and lets a duplicate be ticked', async () => {
        mountPage();
        expect(commitButton().textContent.trim()).toBe('Import 2 transactions');
        const cat = document.querySelector('select[aria-label="Category: Weekly shop"]');
        const options = [...cat.options].map((o) => o.textContent);
        expect(options).toEqual(['Uncategorized', 'Groceries', 'Housing', 'Pets']);
        cat.value = 'cat-groc';
        cat.dispatchEvent(new Event('change', { bubbles: true }));
        const salary = document.querySelector('select[aria-label="Category: March salary"]');
        expect([...salary.options].map((o) => o.textContent)).toEqual(['Uncategorized', 'Salary']);

        const dup = checkbox('Row 4, possible duplicate, left out');
        dup.checked = true;
        dup.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();
        expect(commitButton().textContent.trim()).toBe('Import 3 transactions');
        expect(checkbox('Import row 4')).not.toBeNull();

        commitButton().click();
        await flushPromises();
        const [url, data] = post.mock.calls[0];
        expect(url).toBe('/finance/import/batch-1/commit');
        expect(data).toEqual({ include: ['r1', 'r2', 'r4'], categories: { r1: 'cat-groc' } });
        expect(commitButton().getAttribute('aria-busy')).toBe('true');
    });

    it('uses the singular for one ticked row and disables the button with a hint at zero', async () => {
        mountPage();
        button('Clear selection').click();
        await flushPromises();
        expect(commitButton().disabled).toBe(true);
        expect(document.body.textContent).toContain('Select at least one row to import.');
        const one = checkbox('Import row 1');
        one.checked = true;
        one.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();
        expect(commitButton().textContent.trim()).toBe('Import 1 transaction');
        button('Select all new rows').click();
        await flushPromises();
        expect(commitButton().textContent.trim()).toBe('Import 2 transactions');
    });

    it('keeps the selection and shows an alert when the import fails', async () => {
        mountPage();
        const dup = checkbox('Row 3, possible duplicate, left out');
        dup.checked = true;
        dup.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();
        commitButton().click();
        await flushPromises();
        post.mock.calls[0][2].onError({ commit: 'finance.imp.err_commit' });
        post.mock.calls[0][2].onFinish();
        await flushPromises();
        expect(document.querySelector('[role="alert"]').textContent).toContain('We could not import the rows. Nothing was saved. Try again.');
        expect(commitButton().textContent.trim()).toBe('Import 3 transactions');
        expect(checkbox('Import row 3').checked).toBe(true);
    });

    it('Back discards the draft', () => {
        mountPage();
        button('Back').click();
        expect(del).toHaveBeenCalledWith('/finance/import/batch-1');
    });

    it('narrow layout stacks rows as a list with a full-width category select and no table', () => {
        mountPage(batchPage(), { width: 320 });
        expect(document.querySelector('table')).toBeNull();
        const items = document.querySelectorAll('.imp-rlist > li');
        expect(items).toHaveLength(5);
        const first = items[0];
        expect(first.querySelector('.imp-rrow__line input[type="checkbox"]')).not.toBeNull();
        expect(first.querySelector('.imp-rrow__amount')).not.toBeNull();
        expect(first.querySelector('.imp-rrow__meta time')).not.toBeNull();
        expect(first.querySelector('select')).not.toBeNull();
        expect(items[2].querySelectorAll('.imp-struck')).toHaveLength(2);
        expect(items[4].textContent).toContain('Row 5: date not recognised');
        expect(items[4].querySelector('select')).toBeNull();
    });

    it('renders descriptions as text only', () => {
        const page = batchPage();
        page.batch.rows[0].description = '<img src=x onerror=alert(1)> =HYPERLINK("x")';
        mountPage(page);
        expect(document.querySelector('.imp-rtable img')).toBeNull();
        expect(document.body.textContent).toContain('<img src=x onerror=alert(1)> =HYPERLINK("x")');
    });

    it('shows 50 rows at a time for more than 200 rows while counts cover all rows', async () => {
        const rows = Array.from({ length: 260 }, (_, i) => rowOf(`x${i}`, i + 1));
        mountPage(batchPage({ batch: { rows, counts: { total: 260, new: 260, duplicate: 0, probable: 0, invalid: 0 } } }));
        expect(document.querySelectorAll('tbody tr')).toHaveLength(50);
        expect(document.body.textContent).toContain('260 rows');
        expect(commitButton().textContent.trim()).toBe('Import 260 transactions');
        button('Show more rows').click();
        await flushPromises();
        expect(document.querySelectorAll('tbody tr')).toHaveLength(100);
        commitButton().click();
        expect(post.mock.calls[0][1].include).toHaveLength(260);
    });

    it('does not page up to 200 rows', () => {
        const rows = Array.from({ length: 200 }, (_, i) => rowOf(`x${i}`, i + 1));
        mountPage(batchPage({ batch: { rows, counts: { total: 200, new: 200, duplicate: 0, probable: 0, invalid: 0 } } }));
        expect(document.querySelectorAll('tbody tr')).toHaveLength(200);
        expect(button('Show more rows')).toBeUndefined();
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            for (const width of [1280, 320]) {
                it(`passes axe on Review (${locale}, ${width}px${dark ? ', dark' : ''})`, async () => {
                    document.documentElement.classList.toggle('dark', dark);
                    mountPage(batchPage(), { locale, width });
                    await expectNoAxeViolations(document.body);
                });
            }
        }
    }
});

describe('Import wizard, Done step', () => {
    it('reports added and matched counts and offers the actions', () => {
        mountPage(doneBatch());
        const alert = document.querySelector('[role="status"]');
        expect(alert.textContent).toContain('4 transactions added to Household.');
        expect(alert.textContent).toContain('1 payment matched to recurring expenses.');
        expect(button('View transactions').getAttribute('href')).toBe('/finance/transactions?account=acc-main');
        expect(button('Import another file').getAttribute('href')).toBe('/finance/import?account=acc-main');
        expect(button('Undo import')).toBeDefined();
        expect(document.querySelector('[aria-current="step"]').textContent).toContain('Done');
    });

    it('omits the matched line when nothing matched and shows no file or row text', () => {
        mountPage(doneBatch({ added: 1, matched: 0, kept: 0 }));
        expect(document.body.textContent).toContain('1 transaction added to Household.');
        expect(document.body.textContent).not.toContain('matched');
        expect(document.body.textContent).not.toMatch(/\.csv|Weekly shop/);
    });

    it('confirms before undoing, with focus on Keep import', async () => {
        mountPage(doneBatch());
        button('Undo import').click();
        await flushPromises();
        expect(document.body.textContent).toContain('Undo this import?');
        expect(document.body.textContent).toContain('Remove the 4 transactions added by this import? Payments matched to them become unpaid again.');
        expect(document.activeElement.textContent.trim()).toBe('Keep import');
        [...document.querySelectorAll('[role="dialog"] button')].find((b) => b.textContent.trim() === 'Undo import').click();
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/finance/import/batch-1/undo', {}, expect.anything());
    });

    it('hides Undo for undone batches and for viewers without edit access', () => {
        mountPage(doneBatch({ added: 4, matched: 1, kept: 2 }, 'undone'));
        expect(button('Undo import')).toBeUndefined();
        expect(button('View transactions')).toBeUndefined();
        expect(document.body.textContent).toContain('Import undone');
        expect(document.body.textContent).toContain('2 transactions were kept because they were edited.');
        current.unmount();
        const view = doneBatch();
        view.finance = { ...view.finance, canEdit: false };
        mountPage(view);
        expect(button('Undo import')).toBeUndefined();
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`passes axe on Done (${locale}${dark ? ', dark' : ''})`, async () => {
                document.documentElement.classList.toggle('dark', dark);
                mountPage(doneBatch(), { locale });
                await expectNoAxeViolations(document.body);
            });
        }
    }
});
