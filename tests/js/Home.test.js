import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { createAppI18n } from '../../resources/js/i18n.js';

let mockProps = {};
const post = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps }),
    router: { post: (...args) => post(...args) },
}));

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
const mountHome = (locale = 'en') => mount(Home, { global: { plugins: [createAppI18n(locale, load(locale))] } });

import Home from '../../resources/js/Pages/Home.vue';

describe('Home page', () => {
    it('renders the home page with the app heading and staging banner on staging', () => {
        mockProps = { app: { name: 'Family Hub', env: 'staging' } };
        const wrapper = mountHome();
        expect(wrapper.find('[data-testid=home]').exists()).toBe(true);
        expect(wrapper.find('h1').text()).toBe('Family Hub');
        expect(wrapper.find('[data-testid=staging-banner]').exists()).toBe(true);
        expect(wrapper.find('[data-testid=staging-banner]').text()).toBe('Staging - synthetic data only');
    });

    it('hides the staging banner in production', () => {
        mockProps = { app: { name: 'Family Hub', env: 'production' } };
        const wrapper = mountHome();
        expect(wrapper.find('[data-testid=home]').exists()).toBe(true);
        expect(wrapper.find('[data-testid=staging-banner]').exists()).toBe(false);
    });

    it('shows heartbeat status and runs a check on click', async () => {
        mockProps = {
            app: { name: 'Family Hub', env: 'production' },
            heartbeat: { last_recorded_at: '2026-11-13T10:00:00+00:00', last_source: 'schedule', count_24h: 3, expected_24h: 1440 },
        };
        const wrapper = mountHome();
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
        const wrapper = mountHome();
        expect(wrapper.find('[data-testid=heartbeat-none]').exists()).toBe(true);
        expect(wrapper.find('[data-testid=heartbeat-last]').exists()).toBe(false);
    });

    it('localizes plural count and button text from messages (pl)', () => {
        mockProps = {
            locale: 'pl',
            app: { name: 'Family Hub', env: 'production' },
            heartbeat: { last_recorded_at: null, last_source: null, count_24h: 22, expected_24h: 1440 },
        };
        const wrapper = mountHome('pl');
        expect(wrapper.find('[data-testid=heartbeat-count]').text()).toContain('22 sprawdzenia');
        expect(wrapper.find('[data-testid=heartbeat-run]').text()).toBe(load('pl').home.run_now);
        expect(wrapper.find('[data-testid=heartbeat-run]').text()).not.toBe('Run check now');
    });
});
