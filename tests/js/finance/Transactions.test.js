import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { finance, row, ROWS, transactionsPage, panel as panelFixture } from './fixtures.js';

let mockProps = {};
const calls = { get: vi.fn() };
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps, get url() { return '/finance/transactions'; } }),
    router: { get: (...a) => calls.get(...a), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() },
    Link: { props: ['href'], render() { return null; } },
}));

import Transactions from '../../../resources/js/Pages/Finance/Transactions.vue';
import TransactionList from '../../../resources/js/Finance/TransactionList.vue';
import TransactionFilters from '../../../resources/js/Finance/TransactionFilters.vue';

let current;
const plugin = (locale, messages) => createAppI18n(locale, messages ?? loadMessages(locale));
const setWidth = (w) => { window.innerWidth = w; };
function mountList(props = {}, { locale = 'en', width = 1280, messages } = {}) {
    setWidth(width);
    current = mount(TransactionList, {
        props: { items: ROWS, transactions: { total: 4, page: 1, hasMore: false }, filters: transactionsPage().filters, finance: finance(), ...props },
        attachTo: document.body,
        global: { plugins: [plugin(locale, messages)] },
    });
    return current;
}
function mountFilters(props = {}, { locale = 'en', width = 1280 } = {}) {
    setWidth(width);
    current = mount(TransactionFilters, {
        props: { filters: transactionsPage().filters, categories: finance().categories, ...props },
        attachTo: document.body,
        global: { plugins: [plugin(locale)] },
    });
    return current;
}
function mountPage(props = {}, { locale = 'en', width = 1280 } = {}) {
    setWidth(width);
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    mockProps = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: 'edit', health: 'none' } },
        app: { env: 'local', styleGuide: false },
    });
    current = mount(Transactions, { props: { ...transactionsPage(), ...props }, attachTo: document.body, global: { plugins: [plugin(locale)] } });
    return current;
}
beforeEach(() => { calls.get.mockReset(); window.history.replaceState({}, '', '/finance/transactions?month=2026-03'); });
afterEach(() => { vi.useRealTimers(); current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); });

const btn = (text) => [...document.body.querySelectorAll('button, a')].find((b) => b.textContent.trim() === text);
const byLabel = (re) => [...document.body.querySelectorAll('button')].find((b) => re.test(b.getAttribute('aria-label') ?? ''));

describe('TransactionList markup', () => {
    it('wide: one table, day headings, no stacked list, columns incl. Account', () => {
        mountList({}, { width: 1280 });
        expect(document.querySelectorAll('table')).toHaveLength(1);
        expect(document.querySelectorAll('ul.tx-list__items')).toHaveLength(0);
        expect([...document.querySelectorAll('thead th')].map((th) => th.textContent.trim())).toEqual(['Description', 'Category', 'Account', 'Amount']);
        const days = [...document.querySelectorAll('h2.tx-list__day')].map((h) => h.textContent);
        expect(days).toEqual(['Thursday 12 March', 'Tuesday 10 March']);
    });

    it('narrow: a list of li>button rows and no table', () => {
        mountList({}, { width: 320 });
        expect(document.querySelectorAll('table')).toHaveLength(0);
        expect(document.querySelectorAll('li > button')).toHaveLength(4);
        expect(document.querySelectorAll('h2.tx-list__day')).toHaveLength(2);
    });

    it('hides the Account column when one account is selected', () => {
        mountList({ finance: finance({ selectedAccount: 'acc-main' }) }, { width: 1280 });
        expect([...document.querySelectorAll('thead th')].map((th) => th.textContent.trim())).toEqual(['Description', 'Category', 'Amount']);
    });

    it('shows kind icons, signed exact amounts with U+2212, unsigned transfer, Imported tag and aria labels', () => {
        mountList({}, { width: 320 });
        const text = document.body.textContent;
        expect(text).toContain('−€45.90');
        expect(text).toContain('+€2,500.00');
        expect(text).toContain('€50.00');
        expect(text).not.toContain('+€50.00');
        expect(text).not.toContain('-€');
        expect(text).toContain('Transfer from Household to Wallet');
        expect(text).toContain('Imported');
        expect(text).toContain('Uncategorized');
        expect(byLabel(/^Weekly shop, Expense, −€45\.90, 12 March 2026$/)).toBeDefined();
        expect(byLabel(/^March salary, Income, \+€2,500\.00/)).toBeDefined();
        expect(byLabel(/^To wallet, Transfer, €50\.00/)).toBeDefined();
        expect(document.querySelectorAll('svg.lucide-arrow-down-left, svg.lucide-arrow-up-right, svg.lucide-arrow-left-right').length).toBeGreaterThanOrEqual(4);
    });

    it('announces the count with a plural and renders descriptions as text only', () => {
        mountList({ items: [row('x1', { description: '<img src=x onerror=alert(1)>' })], transactions: { total: 1, page: 1, hasMore: false } }, { width: 320 });
        expect(document.querySelector('[data-testid="tx-count"]').textContent).toBe('1 transaction');
        expect(document.querySelector('[data-testid="tx-count"]').getAttribute('aria-live')).toBe('polite');
        expect(document.querySelector('img')).toBeNull();
        expect(document.body.textContent).toContain('<img src=x onerror=alert(1)>');
    });

    it('opens a row by id', async () => {
        const wrapper = mountList({}, { width: 320 });
        byLabel(/^Weekly shop/).click();
        expect(wrapper.emitted('open')[0]).toEqual(['t1']);
    });
});

describe('TransactionList paging', () => {
    it('has no Show more button unless hasMore', () => {
        mountList();
        expect(btn('Show more transactions')).toBeUndefined();
    });

    it('requests page+1 as a partial reload that keeps the URL and does not reset the list', () => {
        window.history.replaceState({}, '', '/finance/transactions?month=2026-03&q=shop');
        mountList({ transactions: { total: 120, page: 2, hasMore: true } });
        btn('Show more transactions').click();
        const [path, query, options] = calls.get.mock.calls[0];
        expect(path).toBe('/finance/transactions');
        expect(query).toEqual({ month: '2026-03', q: 'shop', page: 3 });
        expect(options.only).toEqual(['transactionItems', 'transactions']);
        expect(options.preserveUrl).toBe(true);
        expect(options.reset).toBeUndefined();
    });

    it('shows the load-more error above the retained button', async () => {
        mountList({ transactions: { total: 120, page: 1, hasMore: true } });
        btn('Show more transactions').click();
        calls.get.mock.calls[0][2].onNetworkError();
        await flushPromises();
        expect(document.querySelector('[role="alert"]').textContent).toContain('We could not load more transactions. Try again.');
        expect(btn('Show more transactions')).toBeDefined();
    });
});

describe('TransactionList empty states', () => {
    it('empty month: title, Add transaction and Import CSV for editors', async () => {
        const wrapper = mountList({ items: [], transactions: { total: 0, page: 1, hasMore: false } });
        expect(document.body.textContent).toContain('No transactions this month');
        expect(btn('Add transaction')).toBeDefined();
        expect(btn('Import CSV')).toBeDefined();
        btn('Add transaction').click();
        expect(wrapper.emitted('add')).toHaveLength(1);
    });

    it('empty month for a view-only user has no actions', () => {
        mountList({ items: [], transactions: { total: 0, page: 1, hasMore: false }, finance: finance({ canEdit: false }) });
        expect(btn('Add transaction')).toBeUndefined();
        expect(btn('Import CSV')).toBeUndefined();
    });

    it('filtered empty: copy and Clear filters', () => {
        const wrapper = mountList({ items: [], transactions: { total: 0, page: 1, hasMore: false }, filters: { q: 'zz', type: 'all', category: 'all', source: 'all' } });
        expect(document.body.textContent).toContain('No transactions match these filters');
        btn('Clear filters').click();
        expect(wrapper.emitted('clear')).toHaveLength(1);
    });
});

describe('TransactionFilters', () => {
    it('debounces the search by 300 ms and resets the list', async () => {
        vi.useFakeTimers();
        mountFilters();
        const input = document.querySelector('input[type="search"]');
        input.value = 'shop';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.value = 'shopp';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        vi.advanceTimersByTime(299);
        expect(calls.get).not.toHaveBeenCalled();
        vi.advanceTimersByTime(2);
        expect(calls.get).toHaveBeenCalledTimes(1);
        const [, query, options] = calls.get.mock.calls[0];
        expect(query).toEqual({ month: '2026-03', q: 'shopp' });
        expect(options.reset).toEqual(['transactionItems']);
        expect(options.only).toContain('transactionItems');
    });

    it('changes Type, Category and Source through selects', () => {
        mountFilters({}, { width: 1280 });
        const select = (label) => document.getElementById([...document.querySelectorAll('label')].find((l) => l.textContent.trim() === label).getAttribute('for'));
        const change = (el, value) => { el.value = value; el.dispatchEvent(new Event('change', { bubbles: true })); };
        change(select('Type'), 'income');
        change(select('Category'), 'none');
        change(select('Source'), 'import');
        expect(calls.get.mock.calls.map((c) => c[1])).toEqual([
            { month: '2026-03', type: 'income' },
            { month: '2026-03', category: 'none' },
            { month: '2026-03', source: 'import' },
        ]);
        expect([...select('Category').options].map((o) => o.textContent)).toEqual(['All categories', 'Groceries', 'Housing', 'Pets', 'Salary', 'Uncategorized']);
    });

    it('lists active filters as removable chips with a Clear filters action', () => {
        window.history.replaceState({}, '', '/finance/transactions?month=2026-03&type=expense&category=cat-custom');
        mountFilters({ filters: { q: '', type: 'expense', category: 'cat-custom', source: 'all' } });
        const chips = [...document.querySelectorAll('button[aria-label^="Remove filter"]')];
        expect(chips.map((c) => c.getAttribute('aria-label'))).toEqual(['Remove filter Expense', 'Remove filter Pets']);
        chips[0].click();
        expect(calls.get.mock.calls[0][1]).toEqual({ month: '2026-03', category: 'cat-custom' });
        calls.get.mockReset();
        btn('Clear filters').click();
        expect(calls.get.mock.calls[0][1]).toEqual({ month: '2026-03' });
    });

    it('collapses behind a Filters disclosure on narrow containers and counts active filters', async () => {
        mountFilters({ filters: { q: '', type: 'expense', category: 'all', source: 'import' } }, { width: 320 });
        const toggle = btn('Filters (2)');
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        expect(document.getElementById(toggle.getAttribute('aria-controls')).style.display).toBe('none');
        toggle.click();
        await flushPromises();
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        expect(document.getElementById(toggle.getAttribute('aria-controls')).style.display).not.toBe('none');
    });
});

describe('Transactions page', () => {
    it('renders shell, filters and list and opens a row as ?tx= via a partial visit', async () => {
        mountPage();
        expect(document.querySelector('[data-testid="finance-transactions-page"] h1').textContent).toBe('Finance');
        byLabel(/^Weekly shop/).click();
        await flushPromises();
        const [, query, options] = calls.get.mock.calls[0];
        expect(query).toEqual({ month: '2026-03', tx: 't1' });
        expect(options.only).toEqual(['panelTransaction']);
    });

    it('opens the new-transaction panel from the toolbar', async () => {
        mountPage({ panelTransaction: { mode: 'new' } });
        await flushPromises();
        expect(document.querySelector('.ui-panel__title').textContent).toBe('New transaction');
    });

    it('shows nothing but the h1 and the empty state with zero accounts', () => {
        mountPage({ finance: finance({ accounts: [] }), transactionItems: [], transactions: { total: 0, page: 1, hasMore: false } });
        expect(document.body.textContent).toContain('No accounts to show');
        expect(document.querySelector('[data-testid="transaction-list"]')).toBeNull();
        expect(document.querySelector('[data-testid="transaction-filters"]')).toBeNull();
        expect(btn('Add transaction')).toBeUndefined();
    });
});

describe('Transactions accessibility', () => {
    const empty = { items: [], transactions: { total: 0, page: 1, hasMore: false } };
    const cases = [
        ['wide', (locale) => mountList({}, { width: 1280, locale })],
        ['320px', (locale) => mountList({}, { width: 320, locale })],
        ['empty', (locale) => mountList(empty, { locale })],
        ['filtered empty', (locale) => mountList({ ...empty, filters: { q: 'zz', type: 'all', category: 'all', source: 'all' } }, { locale })],
        ['with show more', (locale) => mountList({ transactions: { total: 120, page: 1, hasMore: true } }, { width: 320, locale })],
    ];
    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            for (const [name, make] of cases) {
                it(`list ${name} passes axe (${locale}${dark ? ', dark' : ''})`, async () => {
                    document.documentElement.classList.toggle('dark', dark);
                    make(locale);
                    await expectNoAxeViolations(document.body);
                });
            }
            it(`filters (open on 320px) pass axe (${locale}${dark ? ', dark' : ''})`, async () => {
                document.documentElement.classList.toggle('dark', dark);
                mountFilters({ filters: { q: 'a', type: 'expense', category: 'cat-groc', source: 'manual' } }, { locale, width: 320 });
                document.querySelector('[aria-expanded]').click();
                await flushPromises();
                await expectNoAxeViolations(document.body);
            });
            it(`inline filters pass axe (${locale}${dark ? ', dark' : ''})`, async () => {
                document.documentElement.classList.toggle('dark', dark);
                mountFilters({}, { locale, width: 1280 });
                await expectNoAxeViolations(document.body);
            });
        }
    }
    it('pseudo-long SK strings still render the narrow list and pass axe', async () => {
        mountList({}, { locale: 'sk', width: 320, messages: pseudoLong(loadMessages('sk')) });
        await expectNoAxeViolations(document.body);
    });
});
