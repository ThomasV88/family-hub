import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

let mockProps = {};
vi.mock('@inertiajs/vue3', async () => {
    const { h, reactive } = await import('vue');
    return {
        usePage: () => ({ props: mockProps }),
        router: { get: vi.fn(), post: vi.fn(), visit: vi.fn(), on: vi.fn() },
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
        useForm: (data) => reactive({ ...data, processing: false, errors: {}, post: vi.fn(), patch: vi.fn() }),
    };
});

import FinanceAccounts from '../../../resources/js/Pages/Settings/FinanceAccounts.vue';

const SECTIONS = [
    { key: 'finance_accounts', href: '/settings/finance-accounts', labelKey: 'settings.section.finance_accounts', icon: 'wallet' },
    { key: 'appearance', href: '/settings/appearance', labelKey: 'settings.section.appearance', icon: 'palette' },
];
const MEMBERS = [{ id: 1, name: 'Anna', financeAccess: true }, { id: 2, name: 'Bob', financeAccess: false }];
const ACCOUNTS = [
    { id: 'a-1', name: 'Joint', type: 'bank', currency: 'EUR', memberIds: [1], memberCount: 1, sharedWithMe: true, opening: { minor: '-123450', asOf: '2026-01-01', currencyLocked: false } },
    { id: 'a-2', name: 'Cash box', type: 'cash', currency: 'EUR', memberIds: [], memberCount: 0, sharedWithMe: false, opening: null },
    { id: 'a-3', name: 'Kids', type: 'savings', currency: 'PLN', memberIds: [1, 2], memberCount: 2, sharedWithMe: false, opening: null },
];

let current;
function mountPage({ locale = 'en', ...props } = {}) {
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 1, name: 'Anna', email: 'a@example.test', isAdmin: true }, access: {} },
        app: { env: 'local', styleGuide: false },
    };
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(FinanceAccounts, {
        props: { sections: SECTIONS, accounts: ACCOUNTS, members: MEMBERS, panel: null, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, loadMessages(locale))] },
    });
    return current;
}
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; });

describe('Settings > Finance accounts page', () => {
    it('lists accounts with type, visibility lines and the not-shared-with-you marker, and shows no amount', () => {
        mountPage();
        expect(current.find('h1').text()).toBe('Finance accounts');
        const rows = current.findAll('li.ui-row');
        expect(rows).toHaveLength(3);
        expect(rows[0].text()).toContain('Joint');
        expect(rows[0].text()).toContain('Visible to 1 member');
        expect(rows[0].text()).not.toContain('Not shared with you');
        expect(rows[1].text()).toContain('Not shared with anyone');
        expect(rows[1].text()).toContain('Not shared with you');
        expect(rows[2].text()).toContain('Visible to 2 members');
        const text = current.text();
        expect(text).not.toMatch(/[€$£]|\bzł\b|\bKč\b|\d[  ]?\d*[.,]\d{2}\b/);
        expect(text).not.toContain('1234');
        expect(current.text()).toContain('Balances and transactions are not shown here.');
    });

    it('shows the empty state when there are no accounts', () => {
        mountPage({ accounts: [] });
        expect(current.text()).toContain('Add your first account');
    });

    it('opens the new-account panel from the primary button', async () => {
        mountPage();
        const add = current.findAll('button').find((b) => b.text().includes('Add account'));
        await add.trigger('click');
        await flushPromises();
        expect([...document.body.querySelectorAll('h2')].map((h) => h.textContent)).toContain('New account');
    });

    it('opens the edit panel for a row and from the initial panel prop', async () => {
        mountPage({ panel: 'a-2' });
        await flushPromises();
        expect(document.body.textContent).toContain('Account details');
        expect(document.body.textContent).not.toContain('Opening balance');
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`has no axe violations for the page and the open panel (${locale}, ${dark ? 'dark' : 'light'})`, async () => {
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                mountPage({ locale });
                await expectNoAxeViolations(current.element);
                current.unmount();
                document.body.innerHTML = '';
                mountPage({ locale, panel: 'a-1' });
                await flushPromises();
                await expectNoAxeViolations(document.body);
                delete document.documentElement.dataset.theme;
            });
        }
    }
});
