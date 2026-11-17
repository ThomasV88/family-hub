import { describe, it, expect, vi, afterEach } from 'vitest';
import { nextTick, h } from 'vue';
import { flushPromises } from '@vue/test-utils';
import { loadMessages } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { mount } from '@vue/test-utils';
import { useShell } from '../../../resources/js/Shell/shellState.js';

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
        // Five modules, then the manual (always available, not a module) in its own group.
        expect(items).toHaveLength(6);
        items.forEach((li, i) => expect(li.text()).toContain([...navLabels(en), en.nav.manual][i]));
        expect(items[5].find('a').attributes('href')).toBe('/manual');
        expect(navs[0].find('[aria-current="page"]').text()).toContain(en.nav.overview);
        expect(wrapper.find('main#main-content').exists()).toBe(true);
        const toggle = wrapper.find('button[aria-controls="fh-sidebar"]');
        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(wrapper.find('[aria-haspopup="menu"]').text()).toContain(en.nav.account);
    });

    it('shows the signed-in member avatar (photo or initial) in the account menu trigger', () => {
        setWidth(1280);
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' }, avatars: { 7: 2 }, auth: { user: { id: 7, name: 'Zuzana', email: 'z@example.test' } } };
        mountShell();
        expect(wrapper.find('[aria-haspopup="menu"] img').attributes('src')).toBe('/people/7/photo/thumb?v=2');
        wrapper.unmount();
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' }, avatars: {}, auth: { user: { id: 7, name: 'Zuzana', email: 'z@example.test' } } };
        mountShell();
        expect(wrapper.find('[aria-haspopup="menu"] img').exists()).toBe(false);
        expect(wrapper.find('[aria-haspopup="menu"]').text()).toContain('Z');
    });

    it('toggles and remembers the sidebar', async () => {
        setWidth(1280);
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' } };
        mountShell();
        await wrapper.find('button[aria-controls="fh-sidebar"]').trigger('click');
        expect(localStorage.getItem('fh-sidebar')).toBe('closed');
        expect(wrapper.find('button[aria-controls="fh-sidebar"]').attributes('aria-expanded')).toBe('false');
    });

    it('keeps the manual in the sidebar without any module grant and marks it current on its page', async () => {
        setWidth(1280);
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' }, auth: { access: { todo: 'none', wishlist: 'none', finance: 'none', health: 'none' } } };
        wrapper = mount(AppShell, { props: { current: 'manual' }, slots: { default: () => h('p', 'x') }, attachTo: document.body, global: { plugins: [createAppI18n('en', en)] } });
        await nextTick();
        const nav = wrapper.find(`nav[aria-label="${en.nav.main}"]`);
        const links = nav.findAll('li a').map((a) => a.attributes('href'));
        expect(links).toEqual(['/', '/manual']);
        expect(nav.find('[aria-current="page"]').text()).toContain(en.nav.manual);
    });

    it('does not add the manual to the phone bottom bar (five modules already fill it)', async () => {
        setWidth(390);
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' }, auth: { access: FULL_ACCESS } };
        mountShell();
        await nextTick();
        const nav = wrapper.find(`nav[aria-label="${en.nav.main}"]`);
        expect(nav.findAll('li')).toHaveLength(5);
        expect(nav.find('a[href="/manual"]').exists()).toBe(false);
    });

    it.each(['en', 'sk', 'pl'])('shows Shopping in the sidebar and the phone bottom bar for a member with the grant (%s)', async (locale) => {
        const messages = loadMessages(locale);
        mockProps = { locale, app: { name: 'Family Hub', env: 'production' }, auth: { access: { ...FULL_ACCESS, shopping: 'view' } } };
        setWidth(1280);
        mountShell({ locale, messages });
        await nextTick();
        const side = wrapper.find(`nav[aria-label="${messages.nav.main}"]`);
        const item = side.findAll('li').at(5);
        expect(item.find('a').attributes('href')).toBe('/shopping');
        expect(item.text()).toBe(messages.nav.shopping);
        expect(item.find('svg').exists()).toBe(true);
        wrapper.unmount();
        setWidth(390);
        mountShell({ locale, messages });
        await nextTick();
        const bar = wrapper.find(`nav[aria-label="${messages.nav.main}"]`);
        expect(bar.findAll('li')).toHaveLength(6);
        expect(bar.find('a[href="/shopping"]').text()).toContain(messages.nav.shopping);
    });

    it.each(['en', 'sk', 'pl'])('shows Recipes with a chef-hat icon in the sidebar and as the seventh phone bar item (%s)', async (locale) => {
        const messages = loadMessages(locale);
        mockProps = { locale, app: { name: 'Family Hub', env: 'production' }, auth: { access: { ...FULL_ACCESS, shopping: 'view', recipes: 'view' } } };
        setWidth(1280);
        mountShell({ locale, messages });
        await nextTick();
        const side = wrapper.find(`nav[aria-label="${messages.nav.main}"]`);
        const item = side.findAll('li').at(6);
        expect(item.find('a').attributes('href')).toBe('/recipes');
        expect(item.text()).toBe(messages.nav.recipes);
        expect(item.find('svg').exists()).toBe(true);
        wrapper.unmount();
        setWidth(390);
        mountShell({ locale, messages });
        await nextTick();
        const bar = wrapper.find(`nav[aria-label="${messages.nav.main}"]`);
        expect(bar.findAll('li')).toHaveLength(7);
        expect(bar.find('a[href="/recipes"]').text()).toContain(messages.nav.recipes);
    });

    it('hides Recipes without a grant', async () => {
        setWidth(1280);
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' }, auth: { access: { ...FULL_ACCESS, shopping: 'view', recipes: 'none' } } };
        mountShell();
        await nextTick();
        expect(wrapper.find('a[href="/recipes"]').exists()).toBe(false);
    });

    it('hides Shopping without a grant', async () => {
        setWidth(1280);
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' }, auth: { access: { ...FULL_ACCESS, shopping: 'none' } } };
        mountShell();
        await nextTick();
        expect(wrapper.find('a[href="/shopping"]').exists()).toBe(false);
    });

    it('links Health in the nav to /health with no badge or count', () => {
        setWidth(1280);
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' } };
        mountShell();
        const item = wrapper.findAll('nav li').at(4);
        expect(item.find('a').attributes('href')).toBe('/health');
        expect(item.find('button').exists()).toBe(false);
        expect(item.text()).toBe(en.nav.health);
    });

    it('opens and closes the coming-soon panel, returning focus', async () => {
        setWidth(1280);
        mockProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' } };
        const Opener = { setup() { const shell = useShell(); return () => h('button', { type: 'button', 'data-testid': 'opener', onClick: (e) => shell.openComingSoon('health', e.currentTarget) }, 'Soon'); } };
        wrapper = mount(AppShell, { slots: { default: () => h(Opener) }, attachTo: document.body, global: { plugins: [createAppI18n('en', en)] } });
        const soon = wrapper.find('[data-testid=opener]');
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
        for (const width of [360, 390, 1280]) {
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

describe('AppShell Add task per page', () => {
    const pageProps = { locale: 'en', app: { name: 'Family Hub', env: 'production' } };
    const topbarHas = () => wrapper.findAll('header button').some((b) => b.text() === en.nav.add_task);
    const fabHas = () => wrapper.find(`button[aria-label="${en.nav.add_task}"]`).exists();

    it.each([['overview', true], ['tasks', true], ['wishlist', false], ['finances', false], ['health', false], ['', false]])(
        'default Add task on page "%s" shown=%s (desktop and phone)',
        async (current, shown) => {
            mockProps = pageProps;
            setWidth(1280);
            mountShell({ props: { current } });
            await nextTick();
            expect(topbarHas()).toBe(shown);
            wrapper.unmount();
            setWidth(390);
            mountShell({ props: { current } });
            await nextTick();
            expect(fabHas()).toBe(shown);
        },
    );

    it('a page that registers its own labelled handler shows it even outside Overview and Tasks', async () => {
        mockProps = pageProps;
        setWidth(1280);
        const Child = { setup() { useShell().registerAddHandler(() => {}, 'Add visit'); return () => h('span'); } };
        wrapper = mount(AppShell, {
            props: { current: 'health' },
            slots: { default: () => h(Child) },
            attachTo: document.body,
            global: { plugins: [createAppI18n('en', loadMessages('en'))] },
        });
        await nextTick();
        expect(wrapper.findAll('header button').some((b) => b.text() === 'Add visit')).toBe(true);
        expect(topbarHas()).toBe(false);
    });

    it('Wishlist without its own handler (cannot add) shows nothing, with the labelled handler shows its label', async () => {
        mockProps = pageProps;
        setWidth(1280);
        const Child = { setup() { useShell().registerAddHandler(() => {}, 'Add wish'); return () => h('span'); } };
        wrapper = mount(AppShell, {
            props: { current: 'wishlist' },
            slots: { default: () => h(Child) },
            attachTo: document.body,
            global: { plugins: [createAppI18n('en', loadMessages('en'))] },
        });
        await nextTick();
        expect(wrapper.findAll('header button').some((b) => b.text() === 'Add wish')).toBe(true);
    });
});

describe('AppShell flash toast count', () => {
    it('uses the plural form for a toast with a count (sk, n=2 selects the second segment)', async () => {
        setWidth(1280);
        const messages = loadMessages('sk');
        mockProps = {
            locale: 'sk',
            app: { name: 'Family Hub', env: 'production' },
            flash: { status: null, toast: { key: 'twofactor.recovery.used', params: { n: 2 }, count: 2 }, data: null },
        };
        mountShell({ locale: 'sk', messages });
        await nextTick();
        await flushPromises();
        expect(document.body.textContent).toContain('Použili ste kód na obnovenie. Zostávajú 2 kódy.');
    });
});
