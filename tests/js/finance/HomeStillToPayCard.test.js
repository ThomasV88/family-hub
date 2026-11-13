import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';

let mockProps = {};
const visit = vi.fn();
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: mockProps }),
        router: { post: vi.fn(), visit: (...a) => visit(...a), on: vi.fn() },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import HomeStillToPayCard from '../../../resources/js/Dashboard/HomeStillToPayCard.vue';
import Home from '../../../resources/js/Pages/Home.vue';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
let current;
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; visit.mockClear(); document.documentElement.classList.remove('dark'); });
const text = () => document.body.textContent.replace(/\s+/g, ' ');
const mountCard = (card, locale = 'en') => {
    current = mount(HomeStillToPayCard, { props: { card }, attachTo: document.body, global: { plugins: [createAppI18n(locale, load(locale))] } });
    return current;
};
const mountHome = (props, locale = 'en') => {
    mockProps = { app: { name: 'Family Hub', env: 'production' }, auth: { access: { todo: 'edit', wishlist: 'edit', finance: 'edit', health: 'edit' } }, ...props };
    current = mount(Home, { attachTo: document.body, global: { plugins: [createAppI18n(locale, load(locale))] } });
    return current;
};

describe('HomeStillToPayCard', () => {
    it('shows the payment count with the overdue tag and the link, and no amount', () => {
        mountCard({ paymentsCount: 4, overdueCount: 1 });
        const card = document.querySelector('[data-testid="home-still-to-pay"]');
        expect(card.querySelector('h2').textContent).toBe('Still to pay');
        expect(card.textContent).toContain('4 payments');
        expect(card.textContent).toContain('1 overdue');
        expect(card.textContent).not.toMatch(/[€zł]|EUR|PLN/);
        const link = card.querySelector('a');
        expect(link.textContent.trim()).toBe('Open recurring');
        expect(link.getAttribute('href')).toBe('/finance/recurring');
    });

    it('uses the singular and hides the overdue tag at zero', () => {
        mountCard({ paymentsCount: 1, overdueCount: 0 });
        expect(text()).toContain('1 payment');
        expect(text()).not.toContain('1 payments');
        expect(text()).not.toContain('overdue');
    });

    it('navigates through Inertia on a plain click', () => {
        mountCard({ paymentsCount: 2, overdueCount: 0 });
        document.querySelector('a').click();
        expect(visit).toHaveBeenCalledWith('/finance/recurring');
    });

    it('is not a priced card: the component source never formats money', () => {
        const src = readFileSync(`${process.cwd()}/resources/js/Dashboard/HomeStillToPayCard.vue`, 'utf8');
        expect(src).not.toMatch(/MoneyAmount|formatMinor/);
    });

    it.each(['en', 'sk', 'pl'])('passes axe (%s, light and dark)', async (locale) => {
        for (const dark of [false, true]) {
            document.documentElement.classList.toggle('dark', dark);
            mountCard({ paymentsCount: 5, overdueCount: 2 }, locale);
            await expectNoAxeViolations(document.body);
            current.unmount();
            document.body.innerHTML = '';
        }
    });
});

describe('Home finance card wiring', () => {
    it('renders the card when financeCard exists', () => {
        mountHome({ financeCard: { paymentsCount: 3, overdueCount: 1 } });
        expect(document.querySelector('[data-testid="home-still-to-pay"]')).not.toBeNull();
        expect(text()).toContain('3 payments');
    });

    it('keeps the Finance module card for finance users without the prop', () => {
        mountHome({});
        expect(document.querySelector('[data-testid="home-still-to-pay"]')).toBeNull();
        expect(text()).toContain(load('en').module.finances);
    });

    it('renders no finance card for members without finance access even if the prop leaked', () => {
        mountHome({ auth: { access: { todo: 'edit', wishlist: 'none', finance: 'none', health: 'none' } }, financeCard: { paymentsCount: 3, overdueCount: 0 } });
        expect(document.querySelector('[data-testid="home-still-to-pay"]')).toBeNull();
    });
});
