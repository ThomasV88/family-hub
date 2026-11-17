import { describe, it, expect, vi, afterEach } from 'vitest';
import { nextTick } from 'vue';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
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

import BottomNav from '../../../resources/js/Shell/BottomNav.vue';
import { bottomNavDensity } from '../../../resources/js/Shell/modules.js';

const FULL = { todo: 'edit', wishlist: 'edit', finance: 'edit', health: 'edit', shopping: 'edit' };
const FULL7 = { ...FULL, recipes: 'edit' };
const KEYS6 = ['overview', 'tasks', 'wishlist', 'finances', 'health', 'shopping'];
const KEYS7 = [...KEYS6, 'recipes'];
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
        mockProps = { auth: { access: FULL7 } };
        mountNav();
        const nav = wrapper.find('nav.bottom-nav');
        expect(nav.classes()).toContain('bottom-nav--tight');
        expect(nav.findAll('li')).toHaveLength(7);
        expect(nav.find('.is-tight').exists()).toBe(true);
    });

    it.each(['dense', 'tight'])('keeps the current page marked in %s mode', async (mode) => {
        mockProps = { auth: { access: mode === 'tight' ? FULL7 : FULL } };
        mountNav('en', loadMessages('en'), { current: 'tasks' });
        await nextTick();
        const cur = wrapper.find('[aria-current="page"]');
        expect(cur.exists()).toBe(true);
        expect(cur.classes()).toContain('is-current');
    });
});

describe('BottomNav with seven items', () => {
    it.each(['en', 'sk', 'pl'])('shows only the current label, the others are icon-only with a name (%s)', (locale) => {
        mockProps = { auth: { access: FULL7 } };
        const m = loadMessages(locale);
        mountNav(locale, m, { current: 'recipes' });
        const items = wrapper.findAll('li');
        expect(items).toHaveLength(7);
        items.forEach((li, i) => {
            const name = m.nav[KEYS7[i]];
            const link = li.find('a');
            const label = li.find('.nav-item__label');
            expect(label.text()).toBe(name);
            expect(label.attributes('title')).toBe(name);
            if (KEYS7[i] === 'recipes') {
                expect(label.classes()).not.toContain('visually-hidden');
                expect(link.attributes('aria-label')).toBeUndefined();
                expect(link.attributes('aria-current')).toBe('page');
            } else {
                expect(label.classes()).toContain('visually-hidden');
                expect(link.attributes('aria-label')).toBe(name);
            }
            expect(link.find('svg').exists()).toBe(true);
        });
    });

    it('keeps labels visible and unnamed-attribute free with six items', () => {
        mockProps = { auth: { access: FULL } };
        mountNav('en', loadMessages('en'), { current: 'tasks' });
        wrapper.findAll('.nav-item__label').forEach((l) => expect(l.classes()).not.toContain('visually-hidden'));
        wrapper.findAll('a').forEach((a) => expect(a.attributes('aria-label')).toBeUndefined());
    });

    it('marks the current item so the bar can give it room', () => {
        mockProps = { auth: { access: FULL7 } };
        mountNav('en', loadMessages('en'), { current: 'health' });
        expect(wrapper.findAll('li.nav-item--current')).toHaveLength(1);
        expect(wrapper.find('li.nav-item--current a').attributes('href')).toBe('/health');
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

describe('Bottom bar layout rules (CSS source; jsdom does no layout)', () => {
    const nav = readFileSync('resources/js/Shell/BottomNav.vue', 'utf8');
    const item = readFileSync('resources/js/Shell/NavItem.vue', 'utf8');

    it('uses equal-width items that may shrink below their content', () => {
        expect(nav).toContain('grid-auto-columns: minmax(0, 1fr)');
        expect(nav).toMatch(/\.bottom-nav__list > :deep\(li\) \{[^}]*min-width: 0/);
        expect(nav).toMatch(/\.bottom-nav__list > :deep\(li > \*\) \{[^}]*min-width: 0/);
        expect(item).toMatch(/\.nav-item__link--bottom \{[^}]*min-width: 0/);
    });

    it('sets the dense and tight labels at 11 px with tight spacing and no wasted padding', () => {
        expect(item).toMatch(/\.is-dense \.nav-item__label \{[^}]*font-size: 0\.6875rem[^}]*letter-spacing: -0\.02em/);
        expect(item).toMatch(/\.is-tight \.nav-item__label \{[^}]*font-size: 0\.6875rem[^}]*letter-spacing: -0\.02em/);
        expect(item).toMatch(/\.nav-item__link--bottom\.is-dense \{ padding-inline: 1px/);
        expect(item).toMatch(/\.nav-item__link--bottom\.is-tight \{ padding-inline: 2px/);
    });

    it('gives 7 items 44 px minimum each and lets only the current one grow to its whole label', () => {
        expect(nav).toMatch(/\.bottom-nav--tight \.bottom-nav__list > :deep\(li\) \{[^}]*flex: 1 1 0[^}]*min-width: 44px/);
        expect(nav).toMatch(/li\.nav-item--current\) \{[^}]*flex: 1 0 auto/);
        expect(item).toMatch(/\.nav-item--current > \.nav-item__link--bottom\.is-tight \.nav-item__label \{[^}]*overflow: visible[^}]*text-overflow: clip/);
        expect(item).toMatch(/\.nav-item__link \{[^}]*min-height: 44px/);
    });

    it('fits seven items in 360 px: six 44 px icon targets leave room for the current label', () => {
        const viewport = 360;
        const barPadding = 2 * 2;
        const room = viewport - barPadding - 6 * 44;
        // The longest label at 11 px bold is about 8 glyphs of at most 7 px, plus 2 x 2 px padding.
        expect(room).toBeGreaterThanOrEqual(8 * 7 + 4);
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
