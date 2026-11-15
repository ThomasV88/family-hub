import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { finance } from './fixtures.js';
import { block, budgetRow, overviewPage } from './overviewFixtures.js';

let mockProps = {};
const calls = { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() };
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps, get url() { return '/finance'; } }),
    router: { get: (...a) => calls.get(...a), post: (...a) => calls.post(...a), patch: (...a) => calls.patch(...a), delete: (...a) => calls.delete(...a), visit: vi.fn(), on: vi.fn() },
    Link: { props: ['href'], render() { return null; } },
}));

import Overview from '../../../resources/js/Pages/Finance/Overview.vue';

let current;
function mountPage(props = {}, { locale = 'en', canEdit = true } = {}) {
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    mockProps = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: canEdit ? 'edit' : 'view', health: 'none' } },
        app: { env: 'local', styleGuide: false },
    });
    const base = overviewPage();
    current = mount(Overview, { props: { ...base, finance: finance({ currencies: ['EUR'], canEdit }), ...props }, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return current;
}
beforeEach(() => { Object.values(calls).forEach((f) => f.mockReset()); window.history.replaceState({}, '', '/finance?month=2026-03'); });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); });
const text = () => document.body.textContent.replace(/\s+/g, ' ');
const btn = (label) => [...document.body.querySelectorAll('button, a')].find((b) => b.textContent.trim() === label);

describe('Overview page', () => {
    it('shows summary cards, still to pay, budgets and both charts in order', () => {
        mountPage();
        const page = document.querySelector('[data-testid="finance-overview-page"]');
        const order = ['summary-cards', 'still-to-pay', 'budget-list', 'category-chart', 'balance-chart'].map((id) => page.querySelector(`[data-testid="${id}"]`));
        order.forEach((el) => expect(el).not.toBeNull());
        for (let i = 1; i < order.length; i++) expect(order[i - 1].compareDocumentPosition(order[i]) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(document.querySelector('[data-testid="summary-income"]').textContent).toContain('€2,500.00');
        expect(document.querySelector('[data-testid="summary-spending"]').textContent).toContain('€1,234.56');
        expect(document.querySelector('[data-testid="summary-balance"]').textContent).toContain('€10,500.50');
        expect(text()).not.toContain('Below zero');
        expect(page.querySelector('h2.ov__currency')).toBeNull();
        expect(text()).toContain('€756.00');
    });

    it('marks a negative month-end balance with the Below zero tag', () => {
        mountPage({ overview: { hasRecurring: true, blocks: [block('EUR', { summary: { incomeMinor: '0', spendingMinor: '1000', balanceMinor: '-1000' } })] } });
        expect(text()).toContain('−€10.00');
        expect(text()).toContain('Below zero');
    });

    it('omits the still-to-pay card without visible recurring items', () => {
        mountPage({ overview: { hasRecurring: false, blocks: [block('EUR', { stillToPay: null })] } });
        expect(document.querySelector('[data-testid="still-to-pay"]')).toBeNull();
    });

    it('renders one block per currency with the ISO heading only when there are several', () => {
        mountPage({ overview: { hasRecurring: true, blocks: [block('EUR'), block('PLN', { summary: { incomeMinor: '1000', spendingMinor: '500', balanceMinor: '9000' } })] }, finance: finance({ currencies: ['EUR', 'PLN'] }) });
        const headings = [...document.querySelectorAll('h2.ov__currency')].map((h) => h.textContent);
        expect(headings).toEqual(['EUR', 'PLN']);
        expect(document.querySelectorAll('[data-testid="summary-cards"]')).toHaveLength(2);
        expect(document.body.textContent).toContain('90.00');
    });

    it('shows exact zero amounts and the chart empty lines for an empty month', () => {
        mountPage({ overview: { hasRecurring: false, blocks: [block('EUR', {
            summary: { incomeMinor: '0', spendingMinor: '0', balanceMinor: '0' }, stillToPay: null, budgets: [],
            categorySpending: { totalMinor: '0', top: [], other: null, all: [] },
            balanceSeries: [{ month: '2026-03', balanceMinor: '0' }],
        })] } });
        expect(document.querySelector('[data-testid="summary-income"]').textContent).toContain('€0.00');
        expect(text()).toContain('No spending recorded this month.');
        expect(text()).toContain('Not enough data yet.');
    });

    it('lists budgets with server percent, aria values, near and over tags and the carry-over hint', () => {
        mountPage();
        const rows = document.querySelectorAll('[data-testid="budget-row"]');
        expect(rows).toHaveLength(3);
        const near = rows[0];
        expect(near.textContent).toContain('€280.00 of €300.00');
        expect(near.textContent).toContain('93% used');
        expect(near.textContent).toContain('Nearly used up');
        const bar = near.querySelector('[role="progressbar"]');
        expect(bar.getAttribute('aria-valuenow')).toBe('93');
        expect(bar.getAttribute('aria-valuetext')).toBe('€280.00 of €300.00');
        expect(rows[1].textContent).toContain('Over budget by €50.00');
        expect(rows[1].querySelector('[role="progressbar"]').getAttribute('aria-valuenow')).toBe('107');
        expect(rows[1].querySelector('.ui-progress__fill').style.width).toBe('100%');
        expect(text()).toContain('Budgets carry over to next month until you change them.');
    });

    it('opens the budget panel through a partial visit from a row and from Add budget', async () => {
        mountPage();
        document.querySelectorAll('[data-testid="budget-row"]')[0].click();
        expect(calls.get).toHaveBeenCalledTimes(1);
        const [path, query, options] = calls.get.mock.calls[0];
        expect(path).toBe('/finance');
        expect(query).toMatchObject({ budget: 'b1', month: '2026-03' });
        expect(options.only).toEqual(['panelBudget']);
        btn('Add budget').click();
        expect(calls.get.mock.calls[1][1]).toMatchObject({ budget: 'new' });
    });

    it('view-only members get non-interactive rows and no Add budget', () => {
        mountPage({}, { canEdit: false });
        expect(document.querySelector('[data-testid="budget-row"]').tagName).toBe('DIV');
        expect(btn('Add budget')).toBeUndefined();
        expect(document.querySelector('[data-testid="budget-form"]')).toBeNull();
    });

    it('shows the empty budget state with Add budget for editors only', () => {
        mountPage({ overview: { hasRecurring: true, blocks: [block('EUR', { budgets: [] })] } });
        expect(text()).toContain('No budgets yet');
        expect(btn('Add budget')).toBeDefined();
        current.unmount();
        document.body.innerHTML = '';
        mountPage({ overview: { hasRecurring: true, blocks: [block('EUR', { budgets: [] })] } }, { canEdit: false });
        expect(text()).toContain('No budgets yet');
        expect(btn('Add budget')).toBeUndefined();
    });

    it('opens the panel when the server sends panelBudget', () => {
        mountPage({ panelBudget: { mode: 'new', currency: 'EUR' } });
        expect(document.querySelector('[data-testid="budget-form"]')).not.toBeNull();
        expect(document.body.querySelector('h2.ui-panel__title').textContent).toBe('New budget');
    });

    it('renders no v-html and keeps user content as text', () => {
        mountPage({ finance: finance({ currencies: ['EUR'], categories: [{ id: 'cat-groc', kind: 'expense', defaultKey: null, name: '<img src=x onerror=alert(1)>' }] }) });
        expect(document.querySelector('img')).toBeNull();
        expect(text()).toContain('<img src=x onerror=alert(1)>');
    });

    it.each(['en', 'sk', 'pl'])('passes axe for single, multi, empty and over-budget states (%s, light and dark)', async (locale) => {
        const variants = [
            {},
            { overview: { hasRecurring: true, blocks: [block('EUR'), block('PLN')] }, finance: finance({ currencies: ['EUR', 'PLN'] }) },
            { overview: { hasRecurring: false, blocks: [block('EUR', { stillToPay: null, budgets: [], categorySpending: { totalMinor: '0', top: [], other: null, all: [] }, balanceSeries: [] })] } },
            { overview: { hasRecurring: true, blocks: [block('EUR', { budgets: [budgetRow('bx', 'cat-rent', { percent: 150, state: 'over', overMinor: '9900' })] })] } },
        ];
        for (const dark of [false, true]) {
            document.documentElement.classList.toggle('dark', dark);
            for (const extra of variants) {
                mountPage(extra, { locale });
                await expectNoAxeViolations(document.body);
                current.unmount();
                document.body.innerHTML = '';
            }
        }
    });
});
