import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

let mockProps = {};
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps }),
}));

import Home from '../../resources/js/Pages/Home.vue';

describe('Home page', () => {
    it('renders the home page with the app heading and staging banner on staging', () => {
        mockProps = { app: { name: 'Family Hub', env: 'staging' } };
        const wrapper = mount(Home);
        expect(wrapper.find('[data-testid=home]').exists()).toBe(true);
        expect(wrapper.find('h1').text()).toBe('Family Hub');
        expect(wrapper.find('[data-testid=staging-banner]').exists()).toBe(true);
        expect(wrapper.find('[data-testid=staging-banner]').text()).toBe('Staging - synthetic data only');
    });

    it('hides the staging banner in production', () => {
        mockProps = { app: { name: 'Family Hub', env: 'production' } };
        const wrapper = mount(Home);
        expect(wrapper.find('[data-testid=home]').exists()).toBe(true);
        expect(wrapper.find('[data-testid=staging-banner]').exists()).toBe(false);
    });
});
