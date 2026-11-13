import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { finance } from './fixtures.js';
import { block, budgetRow } from './overviewFixtures.js';

const calls = { post: vi.fn(), patch: vi.fn(), delete: vi.fn() };
vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn(), post: (...a) => calls.post(...a), patch: (...a) => calls.patch(...a), delete: (...a) => calls.delete(...a), visit: vi.fn(), on: vi.fn() },
}));

import BudgetPanel from '../../../resources/js/Finance/BudgetPanel.vue';

const BLOCKS = [block('EUR', { budgets: [budgetRow('b1', 'cat-groc')] })];
let current;
function mountPanel(props = {}, { locale = 'en' } = {}) {
    current = mount(BudgetPanel, {
        props: { open: true, finance: finance({ currencies: ['EUR'] }), budget: { mode: 'new', currency: 'EUR' }, blocks: BLOCKS, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, loadMessages(locale))] },
    });
    return current;
}
beforeEach(() => Object.values(calls).forEach((f) => f.mockReset()));
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); });

const btn = (text) => [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === text);
const field = (label) => [...document.body.querySelectorAll('label')].find((l) => l.textContent.replace('*', '').trim() === label);
const input = (label) => document.getElementById(field(label).getAttribute('for'));
async function type(el, value) {
    el.value = value;
    el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
    await flushPromises();
}
const heading = () => document.body.querySelector('h2.ui-panel__title').textContent;
const text = () => document.body.textContent.replace(/\s+/g, ' ');

describe('BudgetPanel create', () => {
    it('lists only expense categories without a budget, shows the currency suffix and the carry-over hint', () => {
        mountPanel();
        expect(heading()).toBe('New budget');
        const options = [...input('Category').options].map((o) => o.textContent);
        expect(options).toEqual(['Housing', 'Pets']);
        expect(document.querySelector('[data-testid="currency-suffix"]').textContent).toBe('EUR');
        expect(text()).toContain('Applies from March 2026 and carries over to later months.');
    });

    it('posts category, currency, amount and the selected month', async () => {
        mountPanel();
        await type(input('Category'), 'cat-custom');
        await type(input('Monthly limit'), '150,50');
        btn('Save budget').click();
        await flushPromises();
        expect(calls.post).toHaveBeenCalledTimes(1);
        expect(calls.post.mock.calls[0][0]).toBe('/finance/budgets');
        expect(calls.post.mock.calls[0][1]).toEqual({ category_id: 'cat-custom', currency: 'EUR', amount: '150,50', from_month: '2026-03' });
    });

    it('rejects an invalid amount locally and sends nothing', async () => {
        mountPanel();
        await type(input('Monthly limit'), '0');
        btn('Save budget').click();
        await flushPromises();
        expect(calls.post).not.toHaveBeenCalled();
        expect(text()).toContain('Enter an amount above 0, with up to 2 decimals.');
    });

    it('shows the taken-category error inline from the server', async () => {
        mountPanel();
        await type(input('Monthly limit'), '10');
        calls.post.mockImplementation((_u, _d, options) => options.onError({ category_id: 'finance.budget.err_taken' }));
        btn('Save budget').click();
        await flushPromises();
        expect(text()).toContain('This category already has a budget.');
    });

    it('offers a currency select when All accounts spans several currencies and refilters categories', async () => {
        mountPanel({ finance: finance({ currencies: ['EUR', 'PLN'] }), blocks: [...BLOCKS, block('PLN', { budgets: [] })] });
        const currency = input('Currency');
        expect([...currency.options].map((o) => o.value)).toEqual(['EUR', 'PLN']);
        await type(currency, 'PLN');
        expect(document.querySelector('[data-testid="currency-suffix"]').textContent).toBe('PLN');
        expect([...input('Category').options].map((o) => o.textContent)).toEqual(['Groceries', 'Housing', 'Pets']);
    });

    it('uses the selected account currency without a select', () => {
        mountPanel({ finance: finance({ selectedAccount: 'acc-zl', currencies: ['EUR', 'PLN'] }), budget: { mode: 'new', currency: 'PLN' } });
        expect(field('Currency')).toBeUndefined();
        expect(document.querySelector('[data-testid="currency-suffix"]').textContent).toBe('PLN');
    });
});

describe('BudgetPanel edit', () => {
    const edit = budgetRow('b1', 'cat-groc', { currency: 'EUR', limitMinor: '30050' });

    it('shows the category read-only and the current limit, and patches with the month', async () => {
        mountPanel({ budget: edit });
        expect(heading()).toBe('Edit budget');
        expect(document.querySelector('[data-testid="category-static"]').textContent).toBe('Groceries');
        expect(input('Monthly limit').value).toBe('300.50');
        await type(input('Monthly limit'), '400');
        btn('Save budget').click();
        await flushPromises();
        expect(calls.patch.mock.calls[0][0]).toBe('/finance/budgets/b1');
        expect(calls.patch.mock.calls[0][1]).toEqual({ amount: '400', from_month: '2026-03' });
    });

    it('asks before removing, focuses Keep budget and deletes with the month', async () => {
        mountPanel({ budget: edit });
        btn('Remove budget').click();
        await flushPromises();
        expect(text()).toContain('Remove budget?');
        expect(text()).toContain('Remove the budget for "Groceries" from March 2026 on? Earlier months keep it.');
        expect(document.activeElement.textContent.trim()).toBe('Keep budget');
        expect(calls.delete).not.toHaveBeenCalled();
        const remove = [...document.body.querySelectorAll('button')].filter((b) => b.textContent.trim() === 'Remove budget').pop();
        remove.click();
        await flushPromises();
        expect(calls.delete.mock.calls[0][0]).toBe('/finance/budgets/b1');
        expect(calls.delete.mock.calls[0][1].data).toEqual({ from_month: '2026-03' });
    });

    it('keeps the budget when the user chooses Keep budget', async () => {
        mountPanel({ budget: edit });
        btn('Remove budget').click();
        await flushPromises();
        btn('Keep budget').click();
        await flushPromises();
        expect(calls.delete).not.toHaveBeenCalled();
    });
});

describe('BudgetPanel accessibility', () => {
    it.each(['en', 'sk', 'pl'])('passes axe for new and edit (%s, light and dark)', async (locale) => {
        for (const dark of [false, true]) {
            document.documentElement.classList.toggle('dark', dark);
            mountPanel({}, { locale });
            await expectNoAxeViolations(document.body);
            current.unmount();
            document.body.innerHTML = '';
            mountPanel({ budget: budgetRow('b1', 'cat-groc', { currency: 'EUR' }) }, { locale });
            await expectNoAxeViolations(document.body);
            current.unmount();
            document.body.innerHTML = '';
        }
    });
});
