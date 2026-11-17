import { describe, it, expect, vi, afterEach } from 'vitest';
import { nextTick } from 'vue';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { createAppI18n } from '../../../resources/js/i18n.js';

let mockProps = {};
const flags = { extra: false };
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: mockProps }),
        router: { post: vi.fn(), visit: vi.fn(), on: vi.fn() },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});
vi.mock('../../../resources/js/Shell/modules.js', async (importOriginal) => {
    const real = await importOriginal();
    const { House } = await import('@lucide/vue');
    return {
        ...real,
        visibleModules: (access) => {
            const list = real.visibleModules(access);
            return flags.extra ? [...list, { key: 'recipes', labelKey: 'nav.overview', icon: House, href: '/recipes' }] : list;
        },
    };
});

import BottomNav from '../../../resources/js/Shell/BottomNav.vue';
import { bottomNavDensity } from '../../../resources/js/Shell/modules.js';

const FULL = { todo: 'edit', wishlist: 'edit', finance: 'edit', health: 'edit', shopping: 'edit' };
let wrapper;
const mountNav = (locale = 'en', messages = loadMessages(locale), props = {}) => {
    wrapper = mount(BottomNav, { props, attachTo: document.body, global: { plugins: [createAppI18n(locale, messages)] } });
    return wrapper;
};

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    mockProps = {};
    flags.extra = false;
});

describe('bottomNavDensity', () => {
    it.each([[1, 'normal'], [5, 'normal'], [6, 'dense'], [7, 'tight'], [8, 'tight']])('%i items is %s', (n, d) => {
        expect(bottomNavDensity(n)).toBe(d);
    });
});

describe('BottomNav density', () => {
    it('uses the dense class with six items', () => {
        mockProps = { auth: { access: FULL } };
        mountNav();
        const nav = wrapper.find('nav.bottom-nav');
        expect(nav.classes()).toContain('bottom-nav--dense');
        expect(nav.findAll('li')).toHaveLength(6);
    });

    it('has no dense styling with few items', () => {
        window.innerWidth = 360;
        mockProps = { auth: { access: { todo: 'edit', wishlist: 'none', finance: 'none', health: 'none' } } };
        mountNav();
        const nav = wrapper.find('nav.bottom-nav');
        expect(nav.findAll('li')).toHaveLength(2);
        expect(nav.classes()).not.toContain('bottom-nav--dense');
        expect(nav.classes()).not.toContain('bottom-nav--tight');
        expect(nav.find('.is-dense').exists()).toBe(false);
    });

    it('uses the tight class with seven items', () => {
        flags.extra = true;
        mockProps = { auth: { access: FULL } };
        mountNav();
        const nav = wrapper.find('nav.bottom-nav');
        expect(nav.classes()).toContain('bottom-nav--tight');
        expect(nav.findAll('li')).toHaveLength(7);
        expect(nav.find('.is-tight').exists()).toBe(true);
    });

    it.each(['dense', 'tight'])('keeps the current page marked in %s mode', async (mode) => {
        flags.extra = mode === 'tight';
        mockProps = { auth: { access: FULL } };
        mountNav('en', loadMessages('en'), { current: 'tasks' });
        await nextTick();
        const cur = wrapper.find('[aria-current="page"]');
        expect(cur.exists()).toBe(true);
        expect(cur.classes()).toContain('is-current');
    });
});

describe('BottomNav labels', () => {
    it.each(['en', 'sk', 'pl'])('renders the six labels whole (%s)', (locale) => {
        mockProps = { auth: { access: FULL } };
        const m = loadMessages(locale);
        mountNav(locale, m);
        const texts = wrapper.findAll('.nav-item__label').map((l) => l.text());
        expect(texts).toEqual(['overview', 'tasks', 'wishlist', 'finances', 'health', 'shopping'].map((k) => m.nav[k]));
    });

    it('keeps pseudo-long labels whole in the DOM with a title', () => {
        window.innerWidth = 360;
        mockProps = { auth: { access: FULL } };
        const m = pseudoLong(loadMessages('en'));
        mountNav('en', m);
        const labels = wrapper.findAll('.nav-item__label');
        expect(labels).toHaveLength(6);
        labels.forEach((l) => {
            expect(l.text().length).toBeGreaterThan(0);
            expect(l.attributes('title')).toBe(l.text());
        });
        expect(labels.map((l) => l.text())).toEqual(['overview', 'tasks', 'wishlist', 'finances', 'health', 'shopping'].map((k) => m.nav[k]));
    });
});

describe('NavItem CSS source', () => {
    const css = readFileSync('resources/js/Shell/NavItem.vue', 'utf8');
    const block = css.match(/\.nav-item__link--bottom \.nav-item__label \{([^}]*)\}/)?.[1] ?? '';

    it('never breaks bottom labels mid-word', () => {
        for (const rule of ['overflow-wrap: normal', 'word-break: normal', 'hyphens: manual', 'text-overflow: ellipsis', 'overflow: hidden']) {
            expect(block).toContain(rule);
        }
        expect(block).not.toContain('anywhere');
    });

    it('scopes overflow-wrap anywhere to the sidebar and keeps the 44 px target', () => {
        expect(css).toContain('.nav-item__link--side .nav-item__label { overflow-wrap: anywhere');
        expect(css).toMatch(/\.nav-item__link \{[^}]*min-height: 44px/);
    });
});
