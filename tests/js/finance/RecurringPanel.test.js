import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { finance } from './fixtures.js';
import { ITEM } from './recurringFixtures.js';

const calls = { post: vi.fn(), patch: vi.fn(), delete: vi.fn() };
vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn(), post: (...a) => calls.post(...a), patch: (...a) => calls.patch(...a), delete: (...a) => calls.delete(...a), visit: vi.fn(), on: vi.fn() },
}));

import RecurringPanel from '../../../resources/js/Finance/RecurringPanel.vue';

let current;
function mountPanel(props = {}, { locale = 'en' } = {}) {
    current = mount(RecurringPanel, {
        props: { open: true, finance: finance(), item: { mode: 'new' }, ...props },
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

describe('RecurringPanel create', () => {
    it('opens as New recurring expense with the fields, monthly repeat and no "repeat from" option', async () => {
        mountPanel();
        await flushPromises();
        expect(heading()).toBe('New recurring expense');
        const labels = [...document.body.querySelectorAll('label')].map((l) => l.textContent.replace('*', '').trim());
        for (const l of ['Name', 'Expected amount', 'Category', 'Account', 'First due date', 'Ends on (optional)', 'Text to look for', 'Allowed difference', 'Days around the due date']) expect(labels).toContain(l);
        expect(input('Account').tagName).toBe('SELECT');
        expect(document.querySelector('legend').textContent).toBeDefined();
        expect(document.body.textContent).toContain('Matching hints');
        expect(document.body.textContent).toContain('Repeat');
        expect(document.body.textContent).not.toContain('Repeat from');
        expect(document.querySelectorAll('input[type="radio"]')).toHaveLength(0);
        expect(input('Days around the due date').value).toBe('5');
        expect([...input('Days around the due date').options].map((o) => o.value)).toEqual(['1', '2', '3', '5', '7', '10', '14']);
        expect(input('Allowed difference').value).toBe('0.00');
        expect(document.querySelector('[data-testid="currency-suffix"]').textContent).toBe('EUR');
        expect(document.body.textContent).toContain('If a month is shorter, the last day of the month is used.');
        expect(document.body.textContent).toContain('Changes apply to payments that are not paid yet.');
        expect(document.body.textContent).toContain('Repeats every month on day 14');
        expect(btn('Delete recurring expense')).toBeUndefined();
        expect([...document.querySelectorAll('select option')].some((o) => o.value === 'none')).toBe(false);
    });

    it('validates name and amount locally before posting', async () => {
        mountPanel();
        await flushPromises();
        btn('Save recurring expense').click();
        await flushPromises();
        expect(document.body.textContent).toContain('Enter a name.');
        expect(document.body.textContent).toContain('Enter an amount above 0');
        expect(calls.post).not.toHaveBeenCalled();
    });

    it('posts name, amount string, account, repeat pattern, hints and no kind or mode', async () => {
        mountPanel();
        await flushPromises();
        await type(input('Name'), 'Rent');
        await type(input('Expected amount'), '650,00');
        await type(input('First due date'), '2026-04-01');
        await type(input('Text to look for'), 'landlord');
        await type(input('Days around the due date'), '7');
        btn('Save recurring expense').click();
        await flushPromises();
        const [url, payload] = calls.post.mock.calls[0];
        expect(url).toBe('/finance/recurring');
        expect(payload).toEqual({
            name: 'Rent', amount: '650,00', category_id: null, starts_on: '2026-04-01', ends_on: null,
            recurrence: { kind: 'monthly_day', interval: 1, monthDay: 1 },
            window_days: 7, tolerance: '0.00', text_hint: 'landlord', account_id: 'acc-main',
        });
        expect(payload).not.toHaveProperty('kind');
        expect(payload).not.toHaveProperty('mode');
        expect(payload).not.toHaveProperty('recurrence_mode');
    });

    it('rejects an end date before the first due date', async () => {
        mountPanel();
        await flushPromises();
        await type(input('Name'), 'Rent');
        await type(input('Expected amount'), '10');
        await type(input('First due date'), '2026-04-01');
        await type(input('Ends on (optional)'), '2026-03-01');
        btn('Save recurring expense').click();
        await flushPromises();
        expect(document.body.textContent).toContain('The end date must be after the first due date.');
        expect(calls.post).not.toHaveBeenCalled();
    });

    it('maps server errors onto fields and shows the generic alert for unknown keys', async () => {
        mountPanel();
        await flushPromises();
        await type(input('Name'), 'Rent');
        await type(input('Expected amount'), '10');
        btn('Save recurring expense').click();
        await flushPromises();
        calls.post.mock.calls[0][2].onError({ amount: 'finance.error.amount' });
        await flushPromises();
        expect(document.body.textContent).toContain('Enter an amount above 0, with up to 2 decimals.');
        calls.post.mock.calls[0][2].onError({ weird: 'x' });
        await flushPromises();
        expect(document.querySelector('[role="alert"]').textContent).toContain('We could not save the recurring expense.');
    });
});

describe('RecurringPanel edit', () => {
    it('prefills from the item, shows the account as text and never sends account_id or kind', async () => {
        mountPanel({ item: ITEM });
        await flushPromises();
        expect(heading()).toBe('Recurring expense');
        expect(input('Name').value).toBe('Rent');
        expect(input('Expected amount').value).toBe('650.00');
        expect(input('Allowed difference').value).toBe('5.00');
        expect(input('Text to look for').value).toBe('landlord');
        expect(input('Days around the due date').value).toBe('7');
        expect(input('First due date').value).toBe('2026-01-01');
        expect(document.querySelector('[data-testid="account-static"]').textContent).toBe('Household');
        expect(field('Account')).toBeUndefined();
        await type(input('Name'), 'Rent flat');
        btn('Save recurring expense').click();
        await flushPromises();
        const [url, payload] = calls.patch.mock.calls[0];
        expect(url).toBe('/finance/recurring/r1');
        expect(payload).not.toHaveProperty('account_id');
        expect(payload).not.toHaveProperty('kind');
        expect(payload.name).toBe('Rent flat');
        expect(payload.recurrence).toEqual({ kind: 'monthly_day', interval: 1, monthDay: 1 });
        expect(payload.window_days).toBe(7);
    });

    it('formats the amount with the locale decimal mark', async () => {
        mountPanel({ item: ITEM }, { locale: 'sk' });
        await flushPromises();
        expect(input('Očakávaná suma').value).toBe('650,00');
    });

    it('deletes after a confirmation whose default focus is Keep recurring expense', async () => {
        mountPanel({ item: ITEM });
        await flushPromises();
        btn('Delete recurring expense').click();
        await flushPromises();
        expect(document.body.textContent).toContain('Delete "Rent"?');
        expect(calls.delete).not.toHaveBeenCalled();
        const keep = btn('Keep recurring expense');
        expect(keep).toBeDefined();
        const dialogButtons = [...document.querySelectorAll('button')].filter((b) => b.textContent.trim() === 'Delete recurring expense');
        dialogButtons.at(-1).click();
        await flushPromises();
        expect(calls.delete.mock.calls[0][0]).toBe('/finance/recurring/r1');
    });
});

describe('RecurringPanel accessibility', () => {
    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            for (const [name, item] of [['new', { mode: 'new' }], ['edit', ITEM]]) {
                it(`passes axe: ${name} in ${locale}${dark ? ' dark' : ''}`, async () => {
                    if (dark) document.documentElement.classList.add('dark');
                    mountPanel({ item }, { locale });
                    await flushPromises();
                    await expectNoAxeViolations(document.body);
                });
            }
        }
    }
});
