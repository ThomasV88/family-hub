import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { expectNoAxeViolations } from './helpers/axe.js';
import { createAppI18n } from '../../resources/js/i18n.js';

let mockProps = {};
// Tests that do not care about permissions get full module access (visibleModules hides everything without it).
const FULL_ACCESS = { todo: 'edit', wishlist: 'edit', finance: 'edit', health: 'edit' };
const withAccess = (p) => ({ ...p, auth: { ...p.auth, access: p.auth?.access ?? FULL_ACCESS } });
const post = vi.fn();
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: withAccess(mockProps) }),
        router: { post: (...args) => post(...args), visit: vi.fn(), on: vi.fn() },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
let current;
const mountHome = (locale = 'en') => {
    current = mount(Home, { attachTo: document.body, global: { plugins: [createAppI18n(locale, load(locale))] } });
    return current;
};
afterEach(() => { current?.unmount(); current = null; post.mockClear(); });

import Home from '../../resources/js/Pages/Home.vue';
import { useToast } from '../../resources/js/Components/ui/useToast.js';

describe('Home page', () => {
    it('renders the home page with the app heading and staging banner on staging', () => {
        mockProps = { app: { name: 'Family Hub', env: 'staging' } };
        const wrapper = mountHome();
        expect(wrapper.find('[data-testid=home]').exists()).toBe(true);
        expect(wrapper.find('h1').text()).toBe('Overview');
        expect(wrapper.find('header').text()).toContain('Family Hub');
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

    describe('dashboard', () => {
        const base = { app: { name: 'Family Hub', env: 'production' } };
        const soonOpen = () => document.body.textContent.includes('This part is coming soon');

        it('puts quick add first in main and orders the cards', () => {
            mockProps = base;
            const w = mountHome();
            const first = w.find('main').find('input, button, a, select');
            expect(first.attributes('placeholder')).toBe('Add a task and press Enter');
            const h2 = w.findAll('main h2').map((h) => h.text());
            expect(h2).toEqual(['To-do', 'Wishlist', 'Finances', 'Health', 'System status']);
            const empties = w.findAll('main h3').map((h) => h.text());
            expect(empties.slice(0, 4)).toEqual(['No tasks yet', 'No wishes yet', 'No transactions yet', 'No health records yet']);
        });

        it('Enter with text posts the title to /todo/tasks and clears the input on success', async () => {
            mockProps = base;
            const w = mountHome();
            const input = w.find('input');
            await input.setValue('  Buy bread ');
            await input.trigger('keydown', { key: 'Enter' });
            expect(post).toHaveBeenCalledWith('/todo/tasks', { title: 'Buy bread' }, expect.objectContaining({ preserveScroll: true }));
            expect(soonOpen()).toBe(false);
            post.mock.calls[0][2].onSuccess();
            await w.vm.$nextTick();
            expect(input.element.value).toBe('');
        });

        it('Enter during IME composition sends nothing', async () => {
            mockProps = base;
            const w = mountHome();
            const input = w.find('input');
            await input.setValue('Hello');
            await input.trigger('keydown', { key: 'Enter', isComposing: true });
            expect(post).not.toHaveBeenCalled();
        });

        it('Enter on empty or whitespace input does nothing', async () => {
            mockProps = base;
            const w = mountHome();
            const input = w.find('input');
            await input.trigger('keydown', { key: 'Enter' });
            await input.setValue('   ');
            await input.trigger('keydown', { key: 'Enter' });
            expect(soonOpen()).toBe(false);
            expect(post).not.toHaveBeenCalled();
            expect(w.text()).toContain('Enter a task title.');
        });

        it('Open on a module card opens coming soon', async () => {
            mockProps = base;
            const w = mountHome();
            await w.findAll('main button').find((b) => b.text() === 'Open').trigger('click');
            expect(soonOpen()).toBe(true);
        });

        it('QuickAdd no longer registers a shell add handler', () => {
            mockProps = base;
            const source = readFileSync(`${process.cwd()}/resources/js/Dashboard/QuickAdd.vue`, 'utf8');
            expect(source).not.toMatch(/registerAddHandler|openComingSoon/);
        });

        it('has no axe violations', async () => {
            mockProps = base;
            const w = mountHome();
            await expectNoAxeViolations(w.element);
        });
    });
});

describe('flash toast', () => {
    it('shows a translated toast from the server flash', () => {
        const { toasts } = useToast();
        toasts.splice(0);
        mockProps = { app: { name: 'Family Hub', env: 'production' }, flash: { toast: { key: 'invite.accept.welcome', params: { name: 'Anna' } } } };
        mountHome();
        expect(toasts.map((x) => x.message)).toEqual(['Welcome to Family Hub, Anna.']);
        toasts.splice(0);
    });
});

describe('Home dashboard module cards', () => {
    it('renders only the module cards the member may use', () => {
        mockProps = { app: { name: 'Family Hub', env: 'production' }, auth: { access: { todo: 'view', wishlist: 'none', finance: 'none', health: 'none' } } };
        const text = mountHome().find('.home__grid').text();
        expect(text).toContain(load('en').module.tasks);
        for (const k of ['wishlist', 'finances', 'health']) expect(text).not.toContain(load('en').module[k]);
    });
});


describe('Home Due soon card', () => {
    const base = { app: { name: 'Family Hub', env: 'production' } };
    const dueSoon = (extra = {}) => ({
        items: [
            { id: 4, title: 'Pay rent', dueDate: '2000-01-01', dueTime: null, visibility: 'personal' },
            { id: 9, title: 'Call gran', dueDate: '2999-01-01', dueTime: '18:00', visibility: 'family' },
        ],
        more: 0, hasAnyTasks: true, today: '2026-11-13', timezone: 'Europe/Bratislava', ...extra,
    });

    it('replaces the tasks module card with Due soon rows linking to /todo?task={id}', () => {
        mockProps = { ...base, dueSoon: dueSoon({ more: 3 }) };
        const w = mountHome();
        const h2 = w.findAll('main h2').map((h) => h.text());
        expect(h2).toEqual(['Due soon', 'Wishlist', 'Finances', 'Health', 'System status']);
        const card = w.find('[data-testid=due-soon]');
        expect(card.findAll('a').map((a) => a.attributes('href'))).toEqual(['/todo', '/todo?task=4', '/todo?task=9']);
        expect(card.find('input[type=checkbox]').exists()).toBe(false);
        expect(card.text()).toContain('Overdue');
        expect(card.find('[aria-label="Personal task"]').exists()).toBe(true);
        expect(card.find('[data-testid=due-soon-more]').text()).toBe('and 3 more');
    });

    it('shows the empty state with Add task for editors, none without edit', () => {
        mockProps = { ...base, dueSoon: dueSoon({ items: [], hasAnyTasks: false }) };
        const w = mountHome();
        expect(w.find('[data-testid=due-soon]').text()).toContain('No tasks yet');
        expect(w.find('[data-testid=due-soon]').findAll('button').map((b) => b.text())).toEqual(['Add task']);
        current.unmount();
        mockProps = { ...base, auth: { access: { todo: 'view', wishlist: 'none', finance: 'none', health: 'none' } }, dueSoon: dueSoon({ items: [], hasAnyTasks: false }) };
        expect(mountHome().find('[data-testid=due-soon]').findAll('button')).toHaveLength(0);
    });

    it('shows the muted none-due text when tasks exist but none is due', () => {
        mockProps = { ...base, dueSoon: dueSoon({ items: [] }) };
        const card = mountHome().find('[data-testid=due-soon]');
        expect(card.find('[data-testid=due-soon-none]').text()).toContain('Nothing is due soon.');
    });

    it('renders quick add only for To-do editors and no card without To-do access', () => {
        mockProps = { ...base, auth: { access: { todo: 'view', wishlist: 'edit', finance: 'none', health: 'none' } }, dueSoon: dueSoon() };
        let w = mountHome();
        expect(w.find('[data-testid=quick-add]').exists()).toBe(false);
        current.unmount();
        mockProps = { ...base, auth: { access: { todo: 'none', wishlist: 'edit', finance: 'none', health: 'none' } }, dueSoon: null };
        w = mountHome();
        expect(w.find('[data-testid=due-soon]').exists()).toBe(false);
        expect(w.findAll('main h2').map((h) => h.text())).not.toContain('To-do');
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations (%s)', async (locale) => {
        mockProps = { ...base, locale, dueSoon: dueSoon({ more: 2 }) };
        await expectNoAxeViolations(mountHome(locale).element);
    });
});
