import { describe, it, expect, vi, afterEach } from 'vitest';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { mountUi } from '../helpers/mount.js';

vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: {} }),
        router: { visit: vi.fn(), get: vi.fn(), on: vi.fn() },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import OccasionStrip from '../../../resources/js/Wishlist/OccasionStrip.vue';

const OCC = [
    { key: 'a', type: 'birthday', owner: { id: 1, name: 'Adam' }, title: null, date: '2027-03-02', days_away: 1 },
    { key: 'b', type: 'custom', owner: null, title: 'Wedding <b>day</b>', date: '2027-03-20', days_away: 19 },
    { key: 'c', type: 'christmas', owner: null, title: null, date: '2027-03-30', days_away: 29 },
];

let wrapper;
afterEach(() => { wrapper?.unmount(); wrapper = null; });

describe('OccasionStrip', () => {
    it('renders pills with text, date, days-away tag and the all-occasions link', () => {
        wrapper = mountUi(OccasionStrip, { props: { occasions: OCC } });
        const pills = wrapper.findAll('[data-testid=occasion-pill]');
        expect(pills).toHaveLength(3);
        expect(pills[0].text()).toContain("Adam's birthday");
        expect(pills[0].text()).toContain('Tomorrow');
        expect(pills[0].find('time').attributes('datetime')).toBe('2027-03-02');
        expect(pills[0].find('[role=img]').attributes('aria-label')).toBe('Birthday');
        expect(pills[1].text()).toContain('in 19 days');
        const link = wrapper.find('a');
        expect(link.attributes('href')).toBe('/wishlist/occasions');
        expect(link.text()).toBe('All occasions');
    });

    it('renders user titles as text, never as markup', () => {
        wrapper = mountUi(OccasionStrip, { props: { occasions: OCC } });
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.text()).toContain('Wedding <b>day</b>');
    });

    it('caps at 3 pills and renders only the link when empty', () => {
        wrapper = mountUi(OccasionStrip, { props: { occasions: [...OCC, { ...OCC[0], key: 'd' }] } });
        expect(wrapper.findAll('[data-testid=occasion-pill]')).toHaveLength(3);
        wrapper.unmount();
        wrapper = mountUi(OccasionStrip, { props: { occasions: [] } });
        expect(wrapper.findAll('[data-testid=occasion-pill]')).toHaveLength(0);
        expect(wrapper.find('a').attributes('href')).toBe('/wishlist/occasions');
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations (%s)', async (locale) => {
        wrapper = mountUi(OccasionStrip, { props: { occasions: OCC }, locale });
        await expectNoAxeViolations(wrapper.element);
    });

    it('has no axe violations in dark mode', async () => {
        document.documentElement.dataset.theme = 'dark';
        wrapper = mountUi(OccasionStrip, { props: { occasions: OCC } });
        await expectNoAxeViolations(wrapper.element);
        delete document.documentElement.dataset.theme;
    });
});
