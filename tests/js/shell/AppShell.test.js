import { describe, it, expect, vi, afterEach } from 'vitest';
import { nextTick, h } from 'vue';
import { flushPromises } from '@vue/test-utils';
import { loadMessages } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { mount } from '@vue/test-utils';

let mockProps = {};
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: mockProps }),
        router: { post: vi.fn(), visit: vi.fn(), on: vi.fn() },
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
        const tasks = wrapper.findAll('nav li').at(1).find('button');
        tasks.element.focus();
        await tasks.trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain(en.soon.title);
        const back = wrapper.findAll('button').find((b) => b.text() === en.soon.back);
        await back.trigger('click');
        await flushPromises();
        expect(wrapper.text()).not.toContain(en.soon.title);
        expect(document.activeElement).toBe(tasks.element);
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

    it('opens the coming-soon sheet from the FAB', async () => {
        setWidth(390);
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' } };
        mountShell();
        await wrapper.find(`button[aria-label="${en.nav.add_task}"]`).trigger('click');
        await flushPromises();
        expect(document.body.textContent).toContain(en.soon.title);
        expect(document.body.querySelector('[role=dialog]')).not.toBeNull();
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
