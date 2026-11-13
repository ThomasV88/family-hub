import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { finance } from './fixtures.js';
import { CANDIDATES, DIFFERENT, ITEM, OVERDUE, PAID, UPCOMING, occ, recurring, recurringPage } from './recurringFixtures.js';

let mockProps = {};
const calls = { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() };
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps, get url() { return '/finance/recurring'; } }),
    router: { get: (...a) => calls.get(...a), post: (...a) => calls.post(...a), patch: (...a) => calls.patch(...a), delete: (...a) => calls.delete(...a), visit: vi.fn(), on: vi.fn() },
    Link: { props: ['href'], render() { return null; } },
}));

import Recurring from '../../../resources/js/Pages/Finance/Recurring.vue';

let current;
function mountPage(props = {}, { locale = 'en', canEdit = true } = {}) {
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    mockProps = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: canEdit ? 'edit' : 'view', health: 'none' } },
        app: { env: 'local', styleGuide: false },
    });
    current = mount(Recurring, { props: { ...recurringPage({ finance: finance({ canEdit }) }), ...props }, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return current;
}
beforeEach(() => { calls.get.mockReset(); window.localStorage.clear(); window.history.replaceState({}, '', '/finance/recurring?month=2026-03'); });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); });
const btn = (text) => [...document.body.querySelectorAll('button, a')].find((b) => b.textContent.trim() === text);
const radio = (text) => [...document.querySelectorAll('[role="radiogroup"] button')].find((b) => b.textContent.trim() === text);

describe('Recurring page', () => {
    it('shows the still-to-pay card and the calendar by default, with the view switch and add button', () => {
        mountPage();
        expect(document.querySelector('[data-testid="still-to-pay"]').textContent).toContain('Still to pay');
        expect(document.querySelector('[data-testid="still-to-pay"]').textContent).toContain('€756.00');
        expect(document.querySelector('[data-testid="still-to-pay"]').textContent).toContain('4 payments');
        expect(document.querySelector('[data-testid="still-to-pay"]').textContent).toContain('1 overdue');
        expect(document.querySelector('[data-testid="payment-calendar"]')).not.toBeNull();
        expect(document.querySelector('[data-testid="recurring-list"]')).toBeNull();
        expect(document.querySelector('[role="radiogroup"]').getAttribute('aria-label')).toBe('View');
        expect(radio('Calendar').getAttribute('aria-pressed')).toBe('true');
        expect(btn('Add recurring expense')).toBeDefined();
    });

    it('omits the add button for view-only members', () => {
        mountPage({}, { canEdit: false });
        expect(btn('Add recurring expense')).toBeUndefined();
    });

    it('switches to the list, remembers it and disables the month switcher', async () => {
        mountPage();
        radio('List').click();
        await Promise.resolve();
        expect(window.localStorage.getItem('fh-finance-recurring-view')).toBe('list');
        expect(document.querySelector('[data-testid="recurring-list"]')).not.toBeNull();
        expect(document.querySelector('[data-testid="payment-calendar"]')).toBeNull();
        expect(document.querySelector('nav[aria-label="Month"]').getAttribute('aria-disabled')).toBe('true');
        const sections = [...document.querySelectorAll('[data-testid="recurring-list"] h2')].map((h) => h.textContent);
        expect(sections).toEqual(['Overdue', 'Next 30 days']);
    });

    it('restores a stored list view and ignores an invalid stored value', () => {
        window.localStorage.setItem('fh-finance-recurring-view', 'list');
        mountPage();
        expect(document.querySelector('[data-testid="recurring-list"]')).not.toBeNull();
        current.unmount();
        document.body.innerHTML = '';
        window.localStorage.setItem('fh-finance-recurring-view', '<x>');
        mountPage();
        expect(document.querySelector('[data-testid="payment-calendar"]')).not.toBeNull();
    });

    it('lists rows with name, due date, exact amount and a status tag with text; hides the Overdue section when empty', () => {
        window.localStorage.setItem('fh-finance-recurring-view', 'list');
        mountPage();
        const rows = [...document.querySelectorAll('[data-testid="recurring-list"] li')];
        expect(rows[0].textContent).toContain('Rent');
        expect(rows[0].textContent).toContain('€650.00');
        expect(rows[0].textContent).toContain('Overdue');
        expect(rows[0].querySelector('svg')).not.toBeNull();
        expect(document.querySelectorAll('[data-testid="recurring-list"] li')).toHaveLength(4);
        current.unmount();
        document.body.innerHTML = '';
        mountPage({ recurring: recurring({ overdue: [] }) });
        expect([...document.querySelectorAll('[data-testid="recurring-list"] h2')].map((h) => h.textContent)).toEqual(['Next 30 days']);
    });

    it('opens a bill from a list row and a day from the calendar with a partial visit that clears other panels', () => {
        window.localStorage.setItem('fh-finance-recurring-view', 'list');
        mountPage();
        document.querySelector('[data-testid="recurring-list"] li button').click();
        let [path, query, options] = calls.get.mock.calls.at(-1);
        expect(path).toBe('/finance/recurring');
        expect(query).toEqual({ month: '2026-03', bill: 'r1.2026-03-01' });
        expect(options.only).toEqual(['dayPanel', 'panelBill', 'panelRecurring', 'candidates']);
        current.unmount();
        document.body.innerHTML = '';
        window.localStorage.setItem('fh-finance-recurring-view', 'calendar');
        mountPage();
        document.querySelector('button[data-date="2026-03-20"]').click();
        [, query] = calls.get.mock.calls.at(-1);
        expect(query).toEqual({ month: '2026-03', day: '2026-03-20' });
    });

    it('opens the add panel through ?rec=new', () => {
        mountPage();
        btn('Add recurring expense').click();
        expect(calls.get.mock.calls.at(-1)[1]).toEqual({ month: '2026-03', rec: 'new' });
    });

    it('shows the day panel with its payments or the empty text', () => {
        mountPage({ dayPanel: { date: '2026-03-20', occurrences: [UPCOMING] } });
        expect(document.body.textContent).toContain('Insurance');
        expect(document.body.textContent).toContain('Friday, 20 March 2026');
        current.unmount();
        document.body.innerHTML = '';
        mountPage({ dayPanel: { date: '2026-03-03', occurrences: [] } });
        expect(document.body.textContent).toContain('No payments due on this day.');
    });

    it('shows the empty state, without still-to-pay card and calendar, when the viewer has no recurring items', () => {
        mountPage({ recurring: recurring({ items: [], calendar: [], overdue: [], next30: [], stillToPay: [{ currency: 'EUR', sumMinor: '0', count: 0, overdueCount: 0, next: [] }] }) });
        expect(document.body.textContent).toContain('No recurring expenses yet');
        expect(document.querySelector('[data-testid="still-to-pay"]')).toBeNull();
        expect(document.querySelector('[data-testid="payment-calendar"]')).toBeNull();
        expect(btn('Add recurring expense')).toBeDefined();
    });

    it('offers no add action in the empty state for view-only members', () => {
        mountPage({ recurring: recurring({ items: [], calendar: [], overdue: [], next30: [], stillToPay: [] }) }, { canEdit: false });
        expect(document.body.textContent).toContain('No recurring expenses yet');
        expect(btn('Add recurring expense')).toBeUndefined();
    });

    it('says "Nothing left to pay this month." when items exist but nothing is unpaid', () => {
        mountPage({ recurring: recurring({ stillToPay: [{ currency: 'EUR', sumMinor: '0', count: 0, overdueCount: 0, next: [] }] }) });
        expect(document.querySelector('[data-testid="still-to-pay"]').textContent).toContain('Nothing left to pay this month.');
    });

    it('renders recurring names as text only', () => {
        window.localStorage.setItem('fh-finance-recurring-view', 'list');
        mountPage({ recurring: recurring({ overdue: [occ('r1', '2026-03-01', { name: '<img src=x onerror=alert(1)>', status: 'overdue' })] }) });
        expect(document.querySelector('img')).toBeNull();
        expect(document.body.textContent).toContain('<img src=x onerror=alert(1)>');
    });
});

describe('Recurring page accessibility', () => {
    const variants = {
        calendar: () => mountPage(),
        list: () => { window.localStorage.setItem('fh-finance-recurring-view', 'list'); return mountPage(); },
        day: () => mountPage({ dayPanel: { date: '2026-03-20', occurrences: [UPCOMING, PAID, DIFFERENT, OVERDUE] } }),
        empty: () => mountPage({ recurring: recurring({ items: [], calendar: [], overdue: [], next30: [], stillToPay: [] }) }),
    };
    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            for (const [name, make] of Object.entries(variants)) {
                it(`passes axe: ${name} in ${locale}${dark ? ' dark' : ''}`, async () => {
                    if (dark) document.documentElement.classList.add('dark');
                    window.localStorage.clear();
                    make();
                    await expectNoAxeViolations(document.body);
                });
            }
        }
    }
});
