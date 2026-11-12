import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

let mockProps = {};
const post = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps }),
    router: { post: (...args) => post(...args) },
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

    it('shows heartbeat status and runs a check on click', async () => {
        mockProps = {
            app: { name: 'Family Hub', env: 'production' },
            heartbeat: { last_recorded_at: '2026-11-13T10:00:00+00:00', last_source: 'schedule', count_24h: 3, expected_24h: 1440 },
        };
        const wrapper = mount(Home);
        expect(wrapper.find('[data-testid=heartbeat-last]').exists()).toBe(true);
        expect(wrapper.find('[data-testid=heartbeat-count]').text()).toContain('3');
        await wrapper.find('[data-testid=heartbeat-run]').trigger('click');
        expect(post).toHaveBeenCalledWith('/heartbeat', {}, expect.anything());
    });

    it('shows the empty state when no check has run', () => {
        mockProps = {
            app: { name: 'Family Hub', env: 'production' },
            heartbeat: { last_recorded_at: null, last_source: null, count_24h: 0, expected_24h: 1440 },
        };
        const wrapper = mount(Home);
        expect(wrapper.find('[data-testid=heartbeat-none]').exists()).toBe(true);
        expect(wrapper.find('[data-testid=heartbeat-last]').exists()).toBe(false);
    });
});
