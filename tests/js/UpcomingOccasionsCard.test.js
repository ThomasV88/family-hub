import { describe, it, expect, vi, afterEach } from 'vitest';
import { expectNoAxeViolations } from './helpers/axe.js';
import { mountUi } from './helpers/mount.js';

const visit = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: {} }),
    router: { visit: (...a) => visit(...a), get: vi.fn(), on: vi.fn() },
}));

import UpcomingOccasionsCard from '../../resources/js/Dashboard/UpcomingOccasionsCard.vue';

const OCC = [
    { key: 'a', type: 'birthday', owner: { id: 1, name: 'Adam' }, title: null, date: '2027-03-01', days_away: 0 },
    { key: 'b', type: 'nameday', owner: { id: 2, name: 'Zora' }, title: null, date: '2027-03-20', days_away: 19 },
];

let wrapper;
afterEach(() => { wrapper?.unmount(); wrapper = null; visit.mockClear(); });

describe('UpcomingOccasionsCard', () => {
    it('lists occasions with a link to the wishlist', async () => {
        wrapper = mountUi(UpcomingOccasionsCard, { props: { occasions: OCC } });
        expect(wrapper.find('h2').text()).toBe('Upcoming occasions');
        expect(wrapper.text()).toContain("Adam's birthday");
        expect(wrapper.text()).toContain('Today');
        expect(wrapper.text()).toContain('in 19 days');
        const open = wrapper.find('a.uo__open');
        expect(open.attributes('href')).toBe('/wishlist');
        expect(open.text()).toBe('Open wishlist');
        await open.trigger('click');
        expect(visit).toHaveBeenCalledWith('/wishlist');
    });

    it('shows the empty message with a link to occasions', () => {
        wrapper = mountUi(UpcomingOccasionsCard, { props: { occasions: [] } });
        const none = wrapper.find('[data-testid=upcoming-occasions-none]');
        expect(none.text()).toContain('No occasions in the next 60 days.');
        expect(none.find('a').attributes('href')).toBe('/wishlist/occasions');
        expect(none.find('a').text()).toBe('Open occasions');
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations (%s)', async (locale) => {
        wrapper = mountUi(UpcomingOccasionsCard, { props: { occasions: OCC }, locale });
        await expectNoAxeViolations(wrapper.element);
    });

    it('has no axe violations when empty, in dark mode', async () => {
        document.documentElement.dataset.theme = 'dark';
        wrapper = mountUi(UpcomingOccasionsCard, { props: { occasions: [] } });
        await expectNoAxeViolations(wrapper.element);
        delete document.documentElement.dataset.theme;
    });
});
