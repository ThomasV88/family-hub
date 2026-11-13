import { describe, it, expect, vi, afterEach } from 'vitest';
import { nextTick, h } from 'vue';
import { flushPromises } from '@vue/test-utils';
import { loadMessages } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { mount } from '@vue/test-utils';

let mockProps = {};
const visit = vi.fn();
// Tests that do not care about permissions get full module access (visibleModules hides everything without it).
const FULL_ACCESS = { todo: 'edit', wishlist: 'edit', finance: 'edit', health: 'edit' };
const withAccess = (p) => ({ ...p, auth: { ...p.auth, access: p.auth?.access ?? FULL_ACCESS } });
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: withAccess(mockProps) }),
        router: { post: vi.fn(), visit: (...a) => visit(...a), on: vi.fn() },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import AppShell from '../../../resources/js/Layouts/AppShell.vue';

const en = loadMessages('en');
let wrapper;
const setWidth = (w) => { window.innerWidth = w; };
const content = () => h('h1', 'Page title');
const mountShell = (opts = {}) => {
    const { locale = 'en', messages = loadMessages(locale), props = {} } = opts;
    wrapper = mount(AppShell, {
        props,
        slots: { default: content },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, messages)] },
    });
    return wrapper;
};
const navLabels = (key) => ['overview', 'tasks', 'wishlist', 'finances', 'health'].map((k) => key.nav[k]);

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    localStorage.clear();
    document.body.innerHTML = '';
    mockProps = {};
});

describe('AppShell desktop', () => {
    it('has skip link first, labelled nav, current page and main landmark', async () => {
        setWidth(1280);
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' } };
        mountShell();
        await nextTick();
        const first = wrapper.element.querySelector('a[href], button, input, select, textarea');
        expect(first.getAttribute('href')).toBe('#main-content');
        expect(first.textContent.trim()).toBe(en.nav.skip);
        const navs = wrapper.findAll(`nav[aria-label="${en.nav.main}"]`);
        expect(navs).toHaveLength(1);
        const items = navs[0].findAll('li');
        expect(items).toHaveLength(5);
        items.forEach((li, i) => expect(li.text()).toContain(navLabels(en)[i]));
        expect(navs[0].find('[aria-current="page"]').text()).toContain(en.nav.overview);
        expect(wrapper.find('main#main-content').exists()).toBe(true);
        const toggle = wrapper.find('button[aria-controls="fh-sidebar"]');
        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(wrapper.find('[aria-haspopup="menu"]').text()).toContain(en.nav.account);
    });

    it('toggles and remembers the sidebar', async () => {
        setWidth(1280);
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' } };
        mountShell();
        await wrapper.find('button[aria-controls="fh-sidebar"]').trigger('click');
        expect(localStorage.getItem('fh-sidebar')).toBe('closed');
        expect(wrapper.find('button[aria-controls="fh-sidebar"]').attributes('aria-expanded')).toBe('false');
    });

    it('opens and closes the coming-soon panel, returning focus', async () => {
        setWidth(1280);
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' } };
        mountShell();
        const soon = wrapper.findAll('nav li').at(3).find('button');
        soon.element.focus();
        await soon.trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain(en.soon.title);
        const back = wrapper.findAll('button').find((b) => b.text() === en.soon.back);
        await back.trigger('click');
        await flushPromises();
        expect(wrapper.text()).not.toContain(en.soon.title);
        expect(document.activeElement).toBe(soon.element);
    });

    it('shows the staging banner with exact text only on staging', () => {
        setWidth(1280);
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'staging' } };
        mountShell();
        expect(wrapper.find('[data-testid=staging-banner]').text()).toBe(en.banner.staging);
    });

    it('shows a calm offline banner while offline', async () => {
        setWidth(1280);
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' } };
        mountShell();
        expect(wrapper.text()).not.toContain(en.offline.banner);
        window.dispatchEvent(new Event('offline'));
        await nextTick();
        expect(wrapper.find('[role=status]').text()).toContain(en.offline.banner);
        window.dispatchEvent(new Event('online'));
        await nextTick();
        expect(wrapper.text()).not.toContain(en.offline.banner);
    });

    it('is axe clean', async () => {
        setWidth(1280);
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' } };
        mountShell();
        await expectNoAxeViolations(wrapper.element);
    });
});

describe('AppShell phone', () => {
    it('renders bottom nav and FAB, no sidebar', async () => {
        setWidth(390);
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' } };
        mountShell();
        await nextTick();
        expect(wrapper.find('#fh-sidebar').exists()).toBe(false);
        expect(wrapper.findAll(`nav[aria-label="${en.nav.main}"]`)).toHaveLength(1);
        expect(wrapper.findAll('nav li')).toHaveLength(5);
        expect(wrapper.find(`button[aria-label="${en.nav.add_task}"]`).exists()).toBe(true);
    });

    it('navigates to /todo?task=new from the FAB instead of a coming-soon sheet', async () => {
        setWidth(390);
        visit.mockClear();
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' } };
        mountShell();
        await wrapper.find(`button[aria-label="${en.nav.add_task}"]`).trigger('click');
        await flushPromises();
        expect(visit).toHaveBeenCalledWith('/todo?task=new');
        expect(document.body.querySelector('[role=dialog]')).toBeNull();
    });

    it('hides the FAB without To-do edit', async () => {
        setWidth(390);
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' }, auth: { access: { todo: 'view', wishlist: 'none', finance: 'none', health: 'none' } } };
        mountShell();
        await nextTick();
        expect(wrapper.find(`button[aria-label="${en.nav.add_task}"]`).exists()).toBe(false);
    });

    it('is axe clean', async () => {
        setWidth(390);
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' } };
        mountShell();
        await expectNoAxeViolations(wrapper.element);
    });
});

describe('AppShell text length', () => {
    const cases = [
        ['sk', () => loadMessages('sk')],
        ['pl', () => loadMessages('pl')],
        ['pseudo-long en', () => pseudoLong(loadMessages('en'))],
    ];
    for (const [name, get] of cases) {
        for (const width of [390, 1280]) {
            it(`shows full nav labels in ${name} at ${width}`, async () => {
                setWidth(width);
                mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' } };
                const messages = get();
                mountShell({ messages });
                await nextTick();
                const text = wrapper.find('nav').text();
                navLabels(messages).forEach((label) => expect(text).toContain(label));
            });
        }
    }
});

describe('AppShell access-aware navigation', () => {
    it.each([[1280], [390]])('renders only permitted modules in the nav at %s', async (width) => {
        setWidth(width);
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' }, auth: { access: { todo: 'view', wishlist: 'none', finance: 'none', health: 'none' } } };
        mountShell();
        await nextTick();
        const navs = wrapper.findAll('nav');
        expect(navs.length).toBeGreaterThan(0);
        for (const nav of navs) {
            const text = nav.text();
            expect(text).toContain(en.nav.overview);
            expect(text).toContain(en.nav.tasks);
            for (const hidden of ['wishlist', 'finances', 'health']) expect(text).not.toContain(en.nav[hidden]);
        }
    });
});

describe('AppShell topbar Add task', () => {
    it('shows Add task on desktop for editors and navigates to the create panel', async () => {
        setWidth(1280);
        visit.mockClear();
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' } };
        mountShell();
        await nextTick();
        const btn = wrapper.findAll('header button').find((b) => b.text() === en.nav.add_task);
        await btn.trigger('click');
        expect(visit).toHaveBeenCalledWith('/todo?task=new');
    });

    it('hides Add task on desktop without To-do edit', async () => {
        setWidth(1280);
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' }, auth: { access: { todo: 'view', wishlist: 'none', finance: 'none', health: 'none' } } };
        mountShell();
        await nextTick();
        expect(wrapper.findAll('header button').some((b) => b.text() === en.nav.add_task)).toBe(false);
    });
});
