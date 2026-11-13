import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { finance } from './fixtures.js';
import { CANDIDATES, DIFFERENT, OVERDUE, PAID, UPCOMING } from './recurringFixtures.js';

const calls = { post: vi.fn(), delete: vi.fn() };
vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn(), post: (...a) => calls.post(...a), patch: vi.fn(), delete: (...a) => calls.delete(...a), visit: vi.fn(), on: vi.fn() },
}));

import BillPanel from '../../../resources/js/Finance/BillPanel.vue';

let current;
function mountPanel(props = {}, { locale = 'en' } = {}) {
    current = mount(BillPanel, {
        props: { open: true, finance: finance(), bill: OVERDUE, candidates: null, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, loadMessages(locale))] },
    });
    return current;
}
beforeEach(() => Object.values(calls).forEach((f) => f.mockReset()));
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); });

const btn = (text) => [...document.body.querySelectorAll('button, a')].find((b) => b.textContent.trim() === text);
const heading = () => document.body.querySelector('h2.ui-panel__title').textContent;
const field = (label) => [...document.body.querySelectorAll('label')].find((l) => l.textContent.replace('*', '').trim() === label);
const input = (label) => document.getElementById(field(label).getAttribute('for'));

describe('BillPanel details', () => {
    it('shows name, status tag, expected amount, due date, account and category for an overdue bill', () => {
        mountPanel();
        expect(heading()).toBe('Payment details');
        const text = document.body.textContent;
        for (const s of ['Rent', 'Overdue', 'Expected', '€650.00', 'Household', 'Housing', '1 March 2026']) expect(text).toContain(s);
        expect(btn('Record payment')).toBeDefined();
        expect(btn('Match a transaction')).toBeDefined();
        expect(btn('Unmatch payment')).toBeUndefined();
        expect(btn('Edit recurring expense')).toBeDefined();
        expect(btn('Close')).toBeDefined();
    });

    it('shows the matched transaction card with an Open transaction link for a paid bill and offers Unmatch only', () => {
        mountPanel({ bill: PAID });
        const card = document.querySelector('[data-testid="bill-match"]');
        expect(card.textContent).toContain('Matched transaction');
        expect(card.textContent).toContain('Telekom');
        expect(card.textContent).toContain('−€25.00');
        expect(card.querySelector('a').getAttribute('href')).toBe('/finance/transactions?tx=t2');
        expect(card.textContent).not.toContain('Paid €');
        expect(btn('Unmatch payment')).toBeDefined();
        expect(btn('Record payment')).toBeUndefined();
        expect(btn('Match a transaction')).toBeUndefined();
    });

    it('shows the expected-versus-actual line for a paid-differently bill using server values', () => {
        mountPanel({ bill: DIFFERENT });
        expect(document.querySelector('[data-testid="bill-match"]').textContent).toContain('Paid €87.50 on 9 March 2026, expected €80.00.');
        expect(document.body.textContent).toContain('Paid differently');
    });

    it('hides every action but Close for view-only members', () => {
        mountPanel({ finance: finance({ canEdit: false }) });
        expect(btn('Record payment')).toBeUndefined();
        expect(btn('Match a transaction')).toBeUndefined();
        expect(btn('Edit recurring expense')).toBeUndefined();
        expect(btn('Close')).toBeDefined();
        current.unmount();
        document.body.innerHTML = '';
        mountPanel({ bill: PAID, finance: finance({ canEdit: false }) });
        expect(btn('Unmatch payment')).toBeUndefined();
    });

    it('renders names and descriptions as text only', () => {
        mountPanel({ bill: { ...PAID, name: '<b>x</b>', match: { ...PAID.match, description: '<img src=x onerror=alert(1)>' } } });
        expect(document.querySelector('img')).toBeNull();
        expect(document.body.querySelector('.bill__name').innerHTML).toBe('&lt;b&gt;x&lt;/b&gt;');
    });
});

describe('BillPanel actions', () => {
    it('emits edit with the recurring id and close', () => {
        const wrapper = mountPanel();
        btn('Edit recurring expense').click();
        btn('Close').click();
        expect(wrapper.emitted('edit')).toEqual([['r1']]);
        expect(wrapper.emitted('close')).toBeDefined();
    });

    it('Record payment shows the prefilled form with the account locked and posts to the bill record URL', async () => {
        mountPanel();
        btn('Record payment').click();
        await flushPromises();
        expect(document.querySelector('[data-testid="transaction-form"]')).not.toBeNull();
        expect(input('Amount').value).toBe('650.00');
        expect(input('Date').value).toBe('2026-03-01');
        expect(input('Description').value).toBe('Rent');
        expect(input('Category').value).toBe('cat-rent');
        expect(input('Account').disabled).toBe(true);
        expect(document.querySelector('[role="radiogroup"]')).toBeNull();
        btn('Record payment').click();
        await flushPromises();
        const [url, payload] = calls.post.mock.calls[0];
        expect(url).toBe('/finance/recurring/r1/bills/2026-03-01/record');
        expect(payload).toMatchObject({ kind: 'expense', amount: '650.00', booked_on: '2026-03-01', description: 'Rent', category_id: 'cat-rent', account_id: 'acc-main' });
    });

    it('Back returns from the inline form to the details', async () => {
        mountPanel();
        btn('Record payment').click();
        await flushPromises();
        btn('Back').click();
        await flushPromises();
        expect(document.querySelector('[data-testid="transaction-form"]')).toBeNull();
        expect(btn('Match a transaction')).toBeDefined();
    });

    it('Match a transaction asks the page to open the candidate list', () => {
        const wrapper = mountPanel();
        btn('Match a transaction').click();
        expect(wrapper.emitted('match')).toHaveLength(1);
    });

    it('lists candidates with exact signed amounts and matches the chosen one', async () => {
        const wrapper = mountPanel({ candidates: CANDIDATES });
        const list = document.querySelector('[data-testid="bill-candidates"]');
        expect(list.textContent).toContain('Choose the transaction that paid this');
        expect(list.textContent).toContain('Landlord Mar');
        expect(list.textContent).toContain('−€650.00');
        expect([...list.querySelectorAll('button')].map((b) => b.textContent.trim())).toEqual(['Use this transaction', 'Use this transaction']);
        list.querySelectorAll('button')[1].click();
        expect(calls.post.mock.calls[0][0]).toBe('/finance/recurring/r1/bills/2026-03-01/match');
        expect(calls.post.mock.calls[0][1]).toEqual({ transaction_id: 'c2' });
        btn('Back').click();
        expect(wrapper.emitted('match-back')).toHaveLength(1);
    });

    it('shows the empty candidates text with a Record payment action', async () => {
        const wrapper = mountPanel({ candidates: [] });
        expect(document.body.textContent).toContain('No unmatched transactions near this date.');
        btn('Record payment').click();
        await flushPromises();
        expect(wrapper.emitted('match-back')).toHaveLength(1);
    });

    it('Unmatch sends DELETE to the match URL without a confirmation', () => {
        mountPanel({ bill: PAID });
        btn('Unmatch payment').click();
        expect(document.querySelector('[role="alertdialog"], [role="dialog"][aria-modal="true"] .ui-dialog')).toBeNull();
        expect(calls.delete.mock.calls[0][0]).toBe('/finance/recurring/r2/bills/2026-03-05/match');
    });
});

describe('BillPanel accessibility', () => {
    const cases = {
        upcoming: { bill: UPCOMING },
        overdue: { bill: OVERDUE },
        paid: { bill: PAID },
        'paid differently': { bill: DIFFERENT },
        candidates: { bill: OVERDUE, candidates: CANDIDATES },
        'no candidates': { bill: OVERDUE, candidates: [] },
    };
    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            for (const [name, props] of Object.entries(cases)) {
                it(`passes axe: ${name} in ${locale}${dark ? ' dark' : ''}`, async () => {
                    if (dark) document.documentElement.classList.add('dark');
                    mountPanel(props, { locale });
                    await flushPromises();
                    await expectNoAxeViolations(document.body);
                });
            }
        }
    }
    it('passes axe with the inline record form', async () => {
        mountPanel();
        btn('Record payment').click();
        await flushPromises();
        await expectNoAxeViolations(document.body);
    });
});
