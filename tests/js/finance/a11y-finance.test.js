import { describe, it, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { finance, accountsPage, transactionsPage, panel as txPanel } from './fixtures.js';
import { block, budgetRow, overviewPage } from './overviewFixtures.js';
import { CANDIDATES, OVERDUE, recurringPage } from './recurringFixtures.js';
import { importPage, batchPage, doneBatch } from './importFixtures.js';

/*
 * Phase-wide finance axe sweep: every finance page and its panels, SK/PL/EN, light and dark.
 * Per-page suites (05-10 .. 05-19) keep their detailed variants; this file proves the whole set in one place.
 * The Import "columns" step needs a chosen file and is swept in ImportMapping.test.js.
 */
let mockProps = {};
vi.mock('@inertiajs/vue3', async () => {
    const { h, reactive: r } = await import('vue');
    return {
        usePage: () => ({ props: mockProps, url: '/finance' }),
        router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() },
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
        useForm: (data) => r({ ...data, processing: false, errors: {}, post: vi.fn(), patch: vi.fn(), delete: vi.fn(), reset: vi.fn(), clearErrors: vi.fn() }),
    };
});

import Overview from '../../../resources/js/Pages/Finance/Overview.vue';
import Transactions from '../../../resources/js/Pages/Finance/Transactions.vue';
import Recurring from '../../../resources/js/Pages/Finance/Recurring.vue';
import Accounts from '../../../resources/js/Pages/Finance/Accounts.vue';
import Categories from '../../../resources/js/Pages/Finance/Categories.vue';
import Import from '../../../resources/js/Pages/Finance/Import.vue';
import ImportBatch from '../../../resources/js/Pages/Finance/ImportBatch.vue';
import FinanceAccounts from '../../../resources/js/Pages/Settings/FinanceAccounts.vue';
import HomeStillToPayCard from '../../../resources/js/Dashboard/HomeStillToPayCard.vue';

const CATS = [
    { id: 'c-groc', kind: 'expense', defaultKey: 'groceries', name: null },
    { id: 'c-zoo', kind: 'expense', defaultKey: null, name: 'Zoo' },
    { id: 'c-sal', kind: 'income', defaultKey: 'salary', name: null },
];
const SECTIONS = [
    { key: 'finance_accounts', href: '/settings/finance-accounts', labelKey: 'settings.section.finance_accounts', icon: 'wallet' },
    { key: 'appearance', href: '/settings/appearance', labelKey: 'settings.section.appearance', icon: 'palette' },
];
const MEMBERS = [{ id: 1, name: 'Anna', financeAccess: true }, { id: 2, name: 'Bob', financeAccess: false }];
const SETTINGS_ACCOUNTS = [
    { id: 'a-1', name: 'Joint', type: 'bank', currency: 'EUR', memberIds: [1], memberCount: 1, sharedWithMe: true, opening: { minor: '-123450', asOf: '2026-01-01', currencyLocked: false } },
    { id: 'a-2', name: 'Cash box', type: 'cash', currency: 'EUR', memberIds: [], memberCount: 0, sharedWithMe: false, opening: null },
];

const variants = {
    'Overview': [Overview, () => ({ ...overviewPage(), finance: finance({ currencies: ['EUR'] }) })],
    'Transactions': [Transactions, () => transactionsPage()],
    'Transactions with panel': [Transactions, () => transactionsPage({ panelTransaction: txPanel() })],
    'Recurring calendar': [Recurring, () => recurringPage()],
    'Recurring day panel': [Recurring, () => recurringPage({ dayPanel: { date: '2026-03-20', occurrences: [OVERDUE] } })],
    'Recurring bill panel': [Recurring, () => recurringPage({ panelBill: OVERDUE, candidates: CANDIDATES })],
    'Accounts': [Accounts, () => accountsPage()],
    'Categories': [Categories, () => ({ finance: finance({ categories: CATS }), panelCategory: null })],
    'Categories panel': [Categories, () => ({ finance: finance({ categories: CATS }), panelCategory: { mode: 'new' } })],
    'Import file': [Import, () => importPage()],
    'Import review': [ImportBatch, () => batchPage()],
    'Import done': [ImportBatch, () => doneBatch()],
    'Settings finance accounts': [FinanceAccounts, () => ({ sections: SECTIONS, accounts: SETTINGS_ACCOUNTS, members: MEMBERS, panel: null })],
    'Settings finance accounts panel': [FinanceAccounts, () => ({ sections: SECTIONS, accounts: SETTINGS_ACCOUNTS, members: MEMBERS, panel: 'a-1' })],
    'Home card': [HomeStillToPayCard, () => ({ card: { paymentsCount: 4, overdueCount: 1 } })],
};

let current;
afterEach(() => {
    current?.unmount();
    current = null;
    document.body.innerHTML = '';
    document.documentElement.classList.remove('dark');
});

describe('finance accessibility sweep', () => {
    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            for (const [name, [component, props]] of Object.entries(variants)) {
                it(`${name} passes axe (${locale}${dark ? ', dark' : ''})`, async () => {
                    document.documentElement.classList.toggle('dark', dark);
                    window.innerWidth = 1280;
                    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
                    mockProps = reactive({
                        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
                        auth: { user: { id: 1, name: 'Anna', email: 'a@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: 'edit', health: 'none' } },
                        app: { env: 'local', styleGuide: false },
                    });
                    window.history.replaceState({}, '', '/finance?month=2026-03');
                    window.localStorage.clear();
                    current = mount(component, { props: props(), attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
                    await expectNoAxeViolations(document.body);
                });
            }
        }
    }
});

// Keep the imported fixture helpers referenced so a rename in a fixture file fails this sweep loudly.
void block;
void budgetRow;
