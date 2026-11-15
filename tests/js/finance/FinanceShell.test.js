import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { finance, ACCOUNTS } from './fixtures.js';

const calls = { get: vi.fn() };
vi.mock('@inertiajs/vue3', () => ({
    router: { get: (...a) => calls.get(...a), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() },
}));

import FinanceShell from '../../../resources/js/Finance/FinanceShell.vue';
import AccountChips from '../../../resources/js/Finance/AccountChips.vue';
import MonthSwitcher from '../../../resources/js/Finance/MonthSwitcher.vue';
import { financeUrl, visitFinance } from '../../../resources/js/Finance/financeQuery.js';
import { categoryLabel, accountTypeIcon } from '../../../resources/js/Finance/labels.js';

let current;
const plugin = (locale) => createAppI18n(locale, loadMessages(locale));
function mountShell(props = {}, { locale = 'en' } = {}) {
    current = mount(FinanceShell, {
        props: { finance: finance(), current: 'transactions', showAddFab: true, ...props },
        slots: { default: '<p data-testid="content">Content</p>' },
        attachTo: document.body,
        global: { plugins: [plugin(locale)] },
    });
    return current;
}
beforeEach(() => { calls.get.mockReset(); window.history.replaceState({}, '', '/finance/transactions?account=acc-main&month=2026-03&q=shop&page=3&tx=abc'); });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); });

const btn = (text) => [...document.body.querySelectorAll('button, a')].find((b) => b.textContent.trim() === text);

describe('financeQuery and labels', () => {
    it('financeUrl keeps account and month and drops defaults', () => {
        expect(financeUrl('/finance/recurring', { account: 'acc-main', month: '2026-03' })).toBe('/finance/recurring?account=acc-main&month=2026-03');
        expect(financeUrl('/finance', { account: 'all', month: undefined })).toBe('/finance');
        expect(financeUrl('/finance/transactions', { account: 'x', q: 'a b' })).toBe('/finance/transactions?account=x&q=a+b');
    });

    it('visitFinance merges over the URL query, drops page and tx, and reuses state', () => {
        visitFinance({ month: '2026-04' }, { only: ['transactionItems'], reset: ['transactionItems'] });
        const [path, query, options] = calls.get.mock.calls[0];
        expect(path).toBe('/finance/transactions');
        expect(query).toEqual({ account: 'acc-main', month: '2026-04', q: 'shop' });
        expect(options).toMatchObject({ preserveState: true, preserveScroll: true, replace: true, only: ['transactionItems'], reset: ['transactionItems'] });
    });

    it('categoryLabel prefers a custom name, then the translated default, then Uncategorized', () => {
        const t = (key) => `T(${key})`;
        expect(categoryLabel({ name: 'Pets', defaultKey: null }, t)).toBe('Pets');
        expect(categoryLabel({ name: null, defaultKey: 'groceries' }, t)).toBe('T(finance.category.default.groceries)');
        expect(categoryLabel(null, t)).toBe('T(finance.tx.uncategorized)');
        expect(accountTypeIcon('cash')).toBeTruthy();
    });
});

describe('FinanceShell', () => {
    it('renders the h1, links, toolbar, three route-backed tabs and a tabpanel for the current one', () => {
        mountShell();
        expect(document.querySelector('h1').textContent).toBe('Finance');
        expect(btn('Accounts').getAttribute('href')).toBe('/finance/accounts');
        expect(btn('Categories').getAttribute('href')).toBe('/finance/categories');
        expect(document.querySelector('[data-testid="finance-toolbar"]')).not.toBeNull();
        expect([...document.querySelectorAll('[role="tab"]')].map((t) => t.textContent.trim())).toEqual(['Overview', 'Transactions', 'Recurring']);
        const selected = document.querySelector('[role="tab"][aria-selected="true"]');
        expect(selected.textContent.trim()).toBe('Transactions');
        const panel = document.getElementById(selected.getAttribute('aria-controls'));
        expect(panel.getAttribute('role')).toBe('tabpanel');
        expect(panel.querySelector('[data-testid="content"]')).not.toBeNull();
    });

    it('navigates tabs keeping the selected account and month', () => {
        mountShell({ finance: finance({ selectedAccount: 'acc-main', month: '2026-02' }) });
        btn('Recurring').click();
        expect(calls.get).toHaveBeenCalledWith('/finance/recurring?account=acc-main&month=2026-02', {}, expect.any(Object));
    });

    it('omits the month in tab links when it is the current month', () => {
        mountShell({ finance: finance({ selectedAccount: 'all', month: '2026-03' }) });
        btn('Overview').click();
        expect(calls.get).toHaveBeenCalledWith('/finance', {}, expect.any(Object));
    });

    it('shows only the h1 and the non-admin empty state for a viewer with zero accounts', () => {
        mountShell({ finance: finance({ accounts: [] }) });
        const text = document.body.textContent;
        expect(document.querySelector('h1').textContent).toBe('Finance');
        expect(text).toContain('No accounts to show');
        expect(text).toContain('Ask an admin to share an account with you.');
        expect(document.querySelector('[data-testid="finance-toolbar"]')).toBeNull();
        expect(document.querySelector('[role="tablist"]')).toBeNull();
        expect(btn('Import CSV')).toBeUndefined();
        expect(btn('Add transaction')).toBeUndefined();
        expect(btn('Add account')).toBeUndefined();
        expect(btn('Accounts')).toBeUndefined();
    });

    it('offers admins with zero accounts an Add account link to settings', () => {
        mountShell({ finance: finance({ accounts: [], isAdmin: true }) });
        expect(document.body.textContent).toContain('Add your first account');
        expect(btn('Add account').getAttribute('href')).toBe('/settings/finance-accounts?acct=new');
        expect(document.querySelector('[role="tablist"]')).toBeNull();
    });

    it('hides Import and Add for view-only users and states why', () => {
        mountShell({ finance: finance({ canEdit: false }) });
        expect(btn('Import CSV')).toBeUndefined();
        expect(btn('Add transaction')).toBeUndefined();
        expect(document.body.textContent).toContain('You can view finance but not change it.');
    });

    it('emits add from the toolbar button', async () => {
        const wrapper = mountShell();
        btn('Add transaction').click();
        expect(wrapper.emitted('add')).toHaveLength(1);
    });

    it('contains no hidden-account trace: only the given accounts are chips', () => {
        mountShell({ finance: finance({ accounts: [ACCOUNTS[0]] }) });
        const chips = [...document.querySelectorAll('[role="group"] button')].map((b) => b.textContent.trim());
        expect(chips).toEqual(['All accounts', 'Household']);
        expect(document.body.innerHTML).not.toMatch(/Wallet|Polish card|acc-cash|acc-zl|lock/i);
    });
});

describe('AccountChips', () => {
    it('renders All accounts plus one chip per account in a labelled group', () => {
        current = mount(AccountChips, { props: { accounts: ACCOUNTS, selected: 'acc-cash' }, attachTo: document.body, global: { plugins: [plugin('en')] } });
        const group = document.querySelector('[role="group"]');
        expect(group.getAttribute('aria-label')).toBe('Choose an account');
        const chips = [...group.querySelectorAll('button')];
        expect(chips.map((b) => b.textContent.trim())).toEqual(['All accounts', 'Household', 'Wallet', 'Polish card']);
        expect(chips.map((b) => b.getAttribute('aria-pressed'))).toEqual(['false', 'false', 'true', 'false']);
        expect(group.textContent).not.toMatch(/\d/);
    });

    it('changes only the account param of the current URL', () => {
        current = mount(AccountChips, { props: { accounts: ACCOUNTS, selected: 'acc-main' }, attachTo: document.body, global: { plugins: [plugin('en')] } });
        [...document.querySelectorAll('button')].find((b) => b.textContent.includes('Wallet')).click();
        expect(calls.get.mock.calls[0][1]).toEqual({ account: 'acc-cash', month: '2026-03', q: 'shop' });
        calls.get.mockReset();
        [...document.querySelectorAll('button')].find((b) => b.textContent.includes('All accounts')).click();
        expect(calls.get.mock.calls[0][1]).toEqual({ month: '2026-03', q: 'shop' });
    });
});

describe('MonthSwitcher', () => {
    const mountMonth = (month, locale = 'en') => {
        current = mount(MonthSwitcher, { props: { month, today: '2026-03-14' }, attachTo: document.body, global: { plugins: [plugin(locale)] } });
    };

    it('shows the localized month, labelled arrows and This month only for another month', () => {
        mountMonth('2026-02');
        expect(document.querySelector('nav').getAttribute('aria-label')).toBe('Month');
        expect(document.querySelector('[data-testid="month-label"]').textContent).toBe('February 2026');
        expect(document.querySelector('[data-testid="month-label"]').getAttribute('aria-live')).toBe('polite');
        expect(document.querySelector('button[aria-label="Previous month"]')).not.toBeNull();
        expect(document.querySelector('button[aria-label="Next month"]')).not.toBeNull();
        expect(btn('This month')).toBeDefined();
        current.unmount();
        mountMonth('2026-03');
        expect(btn('This month')).toBeUndefined();
    });

    it('steps across the year boundary and returns to the current month by dropping the param', () => {
        mountMonth('2026-01');
        document.querySelector('button[aria-label="Previous month"]').click();
        expect(calls.get.mock.calls[0][1].month).toBe('2025-12');
        calls.get.mockReset();
        btn('This month').click();
        expect(calls.get.mock.calls[0][1].month).toBeUndefined();
    });

    it('formats the month in Slovak', () => {
        mountMonth('2026-03', 'sk');
        expect(document.querySelector('[data-testid="month-label"]').textContent.toLowerCase()).toContain('marec');
    });
});

describe('FinanceShell accessibility', () => {
    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`passes axe with accounts (${locale}${dark ? ', dark' : ''})`, async () => {
                document.documentElement.classList.toggle('dark', dark);
                mountShell({}, { locale });
                await expectNoAxeViolations(document.body);
            });
            it(`passes axe with zero accounts (${locale}${dark ? ', dark' : ''})`, async () => {
                document.documentElement.classList.toggle('dark', dark);
                mountShell({ finance: finance({ accounts: [], isAdmin: dark }) }, { locale });
                await expectNoAxeViolations(document.body);
            });
        }
    }
});
