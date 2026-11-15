import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { accountsPage, finance, ACCOUNTS } from './fixtures.js';

let mockProps = {};
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps, get url() { return '/finance/accounts'; } }),
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() },
    Link: { props: ['href'], render() { return null; } },
}));

import Accounts from '../../../resources/js/Pages/Finance/Accounts.vue';

let current;
function mountPage(props = {}, { locale = 'en' } = {}) {
    window.innerWidth = 1280;
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    mockProps = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: 'edit', health: 'none' } },
        app: { env: 'local', styleGuide: false },
    });
    current = mount(Accounts, { props: { ...accountsPage(), ...props }, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return current;
}
beforeEach(() => window.history.replaceState({}, '', '/finance/accounts'));
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); });

describe('Finance Accounts page', () => {
    it('lists visible accounts with type label and balance, and no interactive rows', () => {
        mountPage();
        const rows = [...document.querySelectorAll('[data-testid="finance-accounts"] li')];
        expect(rows).toHaveLength(3);
        expect(rows[0].textContent).toContain('Household');
        expect(rows[0].textContent).toContain('Bank account');
        expect(rows[0].textContent).toMatch(/Balance\s*€1,523\.40/);
        expect(rows[1].textContent).toContain('Cash');
        expect(rows[1].textContent).toContain('Balance −€25.00');
        expect(rows[2].textContent).toMatch(/Balance\s*PLN\s*100\.00|Balance\s*100\.00\s*PLN/);
        expect(document.querySelectorAll('[data-testid="finance-accounts"] li button, [data-testid="finance-accounts"] li a')).toHaveLength(0);
    });

    it('shows no Manage accounts link and no sharing hints to members', () => {
        mountPage();
        expect(document.body.textContent).not.toContain('Manage accounts');
        expect(document.body.textContent).not.toMatch(/Visible to|Not shared|cannot be changed/);
    });

    it('gives admins a Manage accounts link to Settings', () => {
        mountPage({ finance: finance({ isAdmin: true }) });
        const link = [...document.querySelectorAll('a')].find((a) => a.textContent.trim() === 'Manage accounts');
        expect(link.getAttribute('href')).toBe('/settings/finance-accounts');
        expect(document.body.textContent).not.toMatch(/Visible to|Not shared|cannot be changed/);
    });

    it('renders only the heading and the empty state with zero accounts', () => {
        mountPage({ finance: finance({ accounts: [] }), accounts: [] });
        expect(document.body.textContent).toContain('No accounts to show');
        expect(document.querySelector('[data-testid="finance-toolbar"]')).toBeNull();
        expect(document.querySelector('[role="tablist"]')).toBeNull();
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`passes axe (${locale}${dark ? ', dark' : ''})`, async () => {
                document.documentElement.classList.toggle('dark', dark);
                mountPage({ finance: finance({ isAdmin: dark }) }, { locale });
                await expectNoAxeViolations(document.body);
            });
        }
        it(`passes axe with zero accounts (${locale})`, async () => {
            mountPage({ finance: finance({ accounts: [] }), accounts: [] }, { locale });
            await expectNoAxeViolations(document.body);
        });
    }
});
