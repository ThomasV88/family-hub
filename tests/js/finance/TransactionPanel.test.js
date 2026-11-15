import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { finance, panel } from './fixtures.js';

const calls = { post: vi.fn(), patch: vi.fn(), delete: vi.fn() };
vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn(), post: (...a) => calls.post(...a), patch: (...a) => calls.patch(...a), delete: (...a) => calls.delete(...a), visit: vi.fn(), on: vi.fn() },
}));

import TransactionPanel from '../../../resources/js/Finance/TransactionPanel.vue';
import TransactionForm from '../../../resources/js/Finance/TransactionForm.vue';

let current;
function mountPanel(props = {}, { locale = 'en' } = {}) {
    current = mount(TransactionPanel, {
        props: { open: true, finance: finance(), transaction: { mode: 'new' }, ...props },
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
const kinds = () => [...document.querySelectorAll('[role="radiogroup"] button')];
const pressed = () => kinds().map((b) => b.getAttribute('aria-pressed'));

describe('TransactionPanel create', () => {
    it('opens as New transaction with Expense selected, today as the date and a disabled Save', async () => {
        mountPanel();
        await flushPromises();
        expect(heading()).toBe('New transaction');
        expect(kinds().map((b) => b.textContent.trim())).toEqual(['Expense', 'Income', 'Transfer']);
        expect(pressed()).toEqual(['true', 'false', 'false']);
        expect(input('Date').value).toBe('2026-03-14');
        expect(document.querySelector('[data-testid="currency-suffix"]').textContent).toBe('EUR');
        expect(btn('Save transaction').disabled).toBe(true);
        expect(btn('Delete transaction')).toBeUndefined();
    });

    it('focuses the Amount field shortly after opening', async () => {
        vi.useFakeTimers();
        try {
            mountPanel();
            await flushPromises();
            vi.advanceTimersByTime(80);
            expect(document.activeElement).toBe(input('Amount'));
        } finally {
            vi.useRealTimers();
        }
    });

    it('takes its currency suffix from the chosen account', async () => {
        mountPanel();
        await type(input('Account'), 'acc-zl');
        expect(document.querySelector('[data-testid="currency-suffix"]').textContent).toBe('PLN');
    });

    it('defaults the account to the selected chip', async () => {
        mountPanel({ finance: finance({ selectedAccount: 'acc-cash' }) });
        await flushPromises();
        expect(input('Account').value).toBe('acc-cash');
    });

    it('posts an expense with the raw amount string, no id fields beyond account_id and null blanks', async () => {
        mountPanel();
        await type(input('Amount'), '12,34');
        expect(btn('Save transaction').disabled).toBe(true);
        await type(input('Description'), '  Milk  ');
        expect(btn('Save transaction').disabled).toBe(false);
        await type(input('Category'), 'cat-groc');
        btn('Save transaction').click();
        const [url, payload, options] = calls.post.mock.calls[0];
        expect(url).toBe('/finance/transactions');
        expect(payload).toEqual({ kind: 'expense', amount: '12,34', booked_on: '2026-03-14', description: 'Milk', note: null, category_id: 'cat-groc', account_id: 'acc-main' });
        expect(options.preserveScroll).toBe(true);
    });

    it('closes the panel after a successful create', async () => {
        const wrapper = mountPanel();
        await type(input('Amount'), '5');
        await type(input('Description'), 'Tea');
        btn('Save transaction').click();
        calls.post.mock.calls[0][2].onSuccess();
        await flushPromises();
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('rejects too many decimals inline and keeps Save disabled', async () => {
        mountPanel();
        await type(input('Amount'), '1,234');
        await type(input('Description'), 'x');
        expect(document.body.textContent).toContain('Enter an amount above 0, with up to 2 decimals.');
        expect(btn('Save transaction').disabled).toBe(true);
    });

    it('clears the category when switching kind and lists categories of that kind only', async () => {
        mountPanel();
        await type(input('Category'), 'cat-groc');
        kinds()[1].click();
        await flushPromises();
        expect(pressed()).toEqual(['false', 'true', 'false']);
        expect(input('Category').value).toBe('');
        expect([...input('Category').options].map((o) => o.textContent)).toEqual(['Uncategorized', 'Salary']);
    });

    it('moves between kinds with the arrow keys (roving radiogroup)', async () => {
        mountPanel();
        kinds()[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
        await flushPromises();
        expect(pressed()).toEqual(['false', 'true', 'false']);
        expect(kinds().map((b) => b.getAttribute('tabindex'))).toEqual(['-1', '0', '-1']);
    });

    it('transfer: hides Category, shows From and To, and posts the transfer payload', async () => {
        mountPanel();
        await type(input('Amount'), '20');
        kinds()[2].click();
        await flushPromises();
        expect(field('Category')).toBeUndefined();
        expect(field('Account')).toBeUndefined();
        expect(input('From account').value).toBe('acc-main');
        expect(input('To account').value).toBe('acc-cash');
        btn('Save transaction').click();
        expect(calls.post.mock.calls[0][1]).toEqual({ kind: 'transfer', amount: '20', booked_on: '2026-03-14', description: '', note: null, from_account_id: 'acc-main', to_account_id: 'acc-cash' });
    });

    it('transfer: same account and different currencies show inline errors and disable Save', async () => {
        mountPanel();
        await type(input('Amount'), '20');
        kinds()[2].click();
        await flushPromises();
        await type(input('To account'), 'acc-main');
        expect(document.body.textContent).toContain('Choose two different accounts.');
        expect(btn('Save transaction').disabled).toBe(true);
        await type(input('To account'), 'acc-zl');
        expect(document.body.textContent).toContain('Both accounts must use the same currency.');
        expect(btn('Save transaction').disabled).toBe(true);
        await type(input('To account'), 'acc-cash');
        expect(btn('Save transaction').disabled).toBe(false);
    });

    it('maps server errors to fields and focuses the first invalid one', async () => {
        mountPanel();
        await type(input('Amount'), '5');
        await type(input('Description'), 'Tea');
        btn('Save transaction').click();
        calls.post.mock.calls[0][2].onError({ booked_on: 'finance.error.date_before_open' });
        await flushPromises();
        expect(document.body.textContent).toContain('Choose a date on or after the balance date.');
        expect(input('Date').getAttribute('aria-invalid')).toBe('true');
        expect(document.activeElement).toBe(input('Date'));
    });

    it('shows the generic alert for an unknown server failure and keeps the selections', async () => {
        mountPanel();
        await type(input('Amount'), '5');
        await type(input('Description'), 'Tea');
        btn('Save transaction').click();
        calls.post.mock.calls[0][2].onNetworkError();
        await flushPromises();
        expect(document.querySelector('[role="alert"]').textContent).toContain('We could not save the transaction. Nothing was changed. Try again.');
        expect(input('Description').value).toBe('Tea');
    });

    it('asks before discarding a dirty form', async () => {
        const wrapper = mountPanel();
        await type(input('Description'), 'Tea');
        btn('Close').click();
        await flushPromises();
        expect(document.body.textContent).toContain('Discard changes?');
        expect(wrapper.emitted('close')).toBeUndefined();
        btn('Discard').click();
        await flushPromises();
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('closes a clean form without asking', async () => {
        const wrapper = mountPanel();
        btn('Close').click();
        await flushPromises();
        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});

describe('TransactionPanel edit', () => {
    it('shows details, read-only kind and account, prefilled values and a disabled Save until changed', async () => {
        mountPanel({ transaction: panel() });
        await flushPromises();
        expect(heading()).toBe('Transaction details');
        expect(document.querySelector('[role="radiogroup"]')).toBeNull();
        expect(document.querySelector('[data-testid="kind-static"]').textContent).toBe('Expense');
        expect(document.querySelector('[data-testid="account-static"]').textContent).toBe('Household');
        expect(field('Account')).toBeUndefined();
        expect(input('Amount').value).toBe('45.90');
        expect(input('Description').value).toBe('Weekly shop');
        expect(input('Note').value).toBe('Fruit');
        expect(btn('Save transaction').disabled).toBe(true);
        await type(input('Description'), 'Weekly shop 2');
        expect(btn('Save transaction').disabled).toBe(false);
    });

    it('prefills the amount with the locale decimal mark (Slovak)', async () => {
        mountPanel({ transaction: panel() }, { locale: 'sk' });
        await flushPromises();
        expect(document.querySelector('input[inputmode="decimal"]').value).toBe('45,90');
    });

    it('PATCHes without account_id, kind, from_account_id or to_account_id', async () => {
        mountPanel({ transaction: panel() });
        await type(input('Amount'), '50,00');
        await type(input('Category'), '');
        btn('Save transaction').click();
        const [url, payload] = calls.patch.mock.calls[0];
        expect(url).toBe('/finance/transactions/t1');
        expect(Object.keys(payload).sort()).toEqual(['amount', 'booked_on', 'category_id', 'description', 'note']);
        expect(payload).toMatchObject({ amount: '50,00', category_id: null, note: 'Fruit' });
        for (const key of ['account_id', 'kind', 'from_account_id', 'to_account_id']) expect(payload).not.toHaveProperty(key);
    });

    it('edits a transfer with read-only accounts, no category and no immutable keys', async () => {
        const transfer = panel({ id: 't3', kind: 'transfer', amountMinor: '5000', categoryId: null, transfer: { fromId: 'acc-main', fromName: 'Household', toId: 'acc-cash', toName: 'Wallet' } });
        mountPanel({ transaction: transfer });
        await flushPromises();
        expect(document.querySelector('[data-testid="account-static"]').textContent).toBe('Transfer from Household to Wallet');
        expect(field('Category')).toBeUndefined();
        await type(input('Amount'), '60');
        btn('Save transaction').click();
        const payload = calls.patch.mock.calls[0][1];
        expect(Object.keys(payload).sort()).toEqual(['amount', 'booked_on', 'description', 'note']);
    });

    it('keeps the panel open after saving an edit', async () => {
        const wrapper = mountPanel({ transaction: panel() });
        await type(input('Description'), 'Changed');
        btn('Save transaction').click();
        calls.patch.mock.calls[0][2].onSuccess();
        await flushPromises();
        expect(wrapper.emitted('close')).toBeUndefined();
    });

    it('deletes after confirmation with default focus on Keep transaction', async () => {
        const wrapper = mountPanel({ transaction: panel() });
        btn('Delete transaction').click();
        await flushPromises();
        const dialog = document.querySelector('[role="dialog"][aria-modal="true"]:not(.ui-panel)') ?? document.querySelectorAll('[role="dialog"]')[1];
        expect(dialog.textContent).toContain('Delete "Weekly shop"? This cannot be undone.');
        await new Promise((resolve) => setTimeout(resolve, 80));
        expect(document.activeElement.textContent.trim()).toBe('Keep transaction');
        [...dialog.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Delete transaction').click();
        expect(calls.delete.mock.calls[0][0]).toBe('/finance/transactions/t1');
        calls.delete.mock.calls[0][1].onSuccess();
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('shows the Imported tag for imported rows', async () => {
        mountPanel({ transaction: panel({ source: 'import' }) });
        await flushPromises();
        expect(document.body.textContent).toContain('Imported');
    });
});

describe('TransactionPanel read-only', () => {
    it('renders text only with a Close button when the user cannot edit', async () => {
        mountPanel({ finance: finance({ canEdit: false }), transaction: panel() });
        await flushPromises();
        expect(document.querySelectorAll('.ui-panel input, .ui-panel select, .ui-panel textarea')).toHaveLength(0);
        const text = document.body.textContent;
        expect(text).toContain('Weekly shop');
        expect(text).toContain('−€45.90');
        expect(text).toContain('Fruit');
        expect(btn('Close')).toBeDefined();
        expect(btn('Save transaction')).toBeUndefined();
        expect(btn('Delete transaction')).toBeUndefined();
    });

    it('is read-only when the server marks the row not editable', async () => {
        mountPanel({ transaction: panel({ editable: false }) });
        await flushPromises();
        expect(btn('Save transaction')).toBeUndefined();
        expect(document.querySelector('[data-testid="transaction-readonly"]')).not.toBeNull();
    });

    it('renders a note as text, never HTML', async () => {
        mountPanel({ finance: finance({ canEdit: false }), transaction: panel({ note: '<b>bold</b>' }) });
        await flushPromises();
        expect(document.querySelector('.ui-panel b')).toBeNull();
        expect(document.body.textContent).toContain('<b>bold</b>');
    });
});

describe('TransactionForm (reusable)', () => {
    it('record mode posts to its own URL, hides the kind chips and honours locked fields', async () => {
        current = mount(TransactionForm, {
            props: { finance: finance(), initial: { amount: '120,00', bookedOn: '2026-03-05', description: 'Rent', accountId: 'acc-main', categoryId: 'cat-rent' }, mode: 'record', submitUrl: '/finance/bills/x/record', method: 'post', lockedFields: ['account'] },
            attachTo: document.body,
            global: { plugins: [createAppI18n('en', loadMessages('en'))] },
        });
        await flushPromises();
        expect(document.querySelector('[role="radiogroup"]')).toBeNull();
        expect(input('Account').disabled).toBe(true);
        expect(input('Amount').value).toBe('120,00');
        current.vm.save();
        const [url, payload] = calls.post.mock.calls[0];
        expect(url).toBe('/finance/bills/x/record');
        expect(payload).toMatchObject({ kind: 'expense', amount: '120,00', description: 'Rent', category_id: 'cat-rent', account_id: 'acc-main' });
    });
});

describe('TransactionPanel accessibility', () => {
    const transferRow = () => panel({ id: 't3', kind: 'transfer', amountMinor: '5000', categoryId: null, transfer: { fromId: 'acc-main', fromName: 'Household', toId: 'acc-cash', toName: 'Wallet' } });
    const cases = [
        ['new expense', () => ({ transaction: { mode: 'new' } }), null],
        ['new income', () => ({ transaction: { mode: 'new' } }), 1],
        ['new transfer', () => ({ transaction: { mode: 'new' } }), 2],
        ['edit', () => ({ transaction: panel() }), null],
        ['edit transfer', () => ({ transaction: transferRow() }), null],
        ['read-only', () => ({ finance: finance({ canEdit: false }), transaction: panel({ source: 'import' }) }), null],
    ];
    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            for (const [name, props, kind] of cases) {
                it(`${name} passes axe (${locale}${dark ? ', dark' : ''})`, async () => {
                    document.documentElement.classList.toggle('dark', dark);
                    mountPanel(props(), { locale });
                    if (kind !== null) { kinds()[kind].click(); }
                    await flushPromises();
                    await expectNoAxeViolations(document.body);
                });
            }
        }
    }
});
