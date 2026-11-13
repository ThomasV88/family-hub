import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { loadMessages } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';

let mockProps = {};
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: mockProps }),
        router: { post: vi.fn(), visit: vi.fn(), on: vi.fn() },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import Forbidden from '../../../resources/js/Pages/Errors/Forbidden.vue';

let wrapper;
const mountPage = (locale) => {
    mockProps = {
        locale,
        app: { name: 'Family Hub', env: 'production' },
        auth: { user: { name: 'Eva', email: 'e@example.test', isAdmin: false }, access: { todo: 'view', wishlist: 'none', finance: 'none', health: 'none' } },
    };
    wrapper = mount(Forbidden, { attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return wrapper;
};
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; document.documentElement.removeAttribute('data-theme'); });

describe('Forbidden page', () => {
    it.each(['en', 'sk', 'pl'])('renders the denied copy, one h1 and a link home in %s', (locale) => {
        mountPage(locale);
        const m = loadMessages(locale);
        expect(wrapper.findAll('h1')).toHaveLength(1);
        expect(wrapper.find('h1').text()).toBe(m.access.denied_title);
        expect(wrapper.text()).toContain(m.access.denied_body);
        const action = wrapper.find('[data-testid=forbidden] a');
        expect(action.attributes('href')).toBe('/');
        expect(action.text()).toBe(m.access.denied_action);
    });

    it('renders inside the app shell without data props', () => {
        mountPage('en');
        expect(wrapper.find('main').exists()).toBe(true);
        expect(wrapper.find('nav').exists()).toBe(true);
    });

    it.each([['en', 'light'], ['en', 'dark'], ['sk', 'light'], ['sk', 'dark'], ['pl', 'light'], ['pl', 'dark']])('has no axe violations in %s %s', async (locale, theme) => {
        document.documentElement.setAttribute('data-theme', theme);
        mountPage(locale);
        await expectNoAxeViolations(wrapper.element);
    });
});
