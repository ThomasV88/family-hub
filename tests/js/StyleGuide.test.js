import { describe, it, expect, vi, afterEach } from 'vitest';
import { mountUi } from './helpers/mount.js';
import { expectNoAxeViolations } from './helpers/axe.js';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'en', app: { name: 'Family Hub', env: 'local' } } }),
    router: { post: vi.fn() },
    Link: { template: '<a><slot /></a>' },
}));

import StyleGuide from '../../resources/js/Pages/StyleGuide.vue';

let wrapper;
afterEach(() => wrapper?.unmount());

describe('StyleGuide page', () => {
    it('renders heading, theme switch and discovered sections', async () => {
        wrapper = mountUi(StyleGuide);
        expect(wrapper.findAll('h1')).toHaveLength(1);
        expect(wrapper.find('h1').text()).toBe('Style guide');
        expect(wrapper.find('[data-testid=theme-switch]').exists()).toBe(true);
        for (const id of ['tokens', 'buttons']) {
            const section = wrapper.find(`section#${id}`);
            expect(section.exists()).toBe(true);
            const label = document.getElementById(section.attributes('aria-labelledby'));
            expect(label.tagName).toBe('H2');
        }
        await expectNoAxeViolations(wrapper.element);
    });
});
