import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';

let mockProps = {};
const visit = vi.fn();
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: mockProps }),
        router: { post: vi.fn(), visit: (...a) => visit(...a), on: vi.fn() },
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import Index from '../../../resources/js/Pages/Settings/Index.vue';
import Appearance from '../../../resources/js/Pages/Settings/Appearance.vue';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
const APPEARANCE = { key: 'appearance', href: '/settings/appearance', labelKey: 'settings.section.appearance', icon: 'palette' };
const MEMBERS = { key: 'members', href: '/settings/members', labelKey: 'settings.section.members', icon: 'users' };
let current;
function mountPage(component, { locale = 'en', sections = [APPEARANCE] } = {}) {
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 1, name: 'Anna', email: 'a@example.test', isAdmin: true }, access: {} },
        app: { env: 'local', styleGuide: false },
    };
    current = mount(component, { props: { sections }, attachTo: document.body, global: { plugins: [createAppI18n(locale, load(locale))] } });
    return current;
}
const setWide = (wide) => {
    window.matchMedia = (query) => ({ matches: wide && query.includes('640'), media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
};
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; visit.mockClear(); });

describe('Settings index', () => {
    it('shows the section list on a narrow container', async () => {
        setWide(false);
        mountPage(Index, { sections: [MEMBERS, APPEARANCE] });
        await flushPromises();
        expect(visit).not.toHaveBeenCalled();
        expect(current.find('h1').text()).toBe('Settings');
        const links = current.findAll('nav[aria-label="Settings"] a');
        expect(links.map((a) => a.attributes('href'))).toEqual(['/settings/members', '/settings/appearance']);
        expect(current.text()).toContain('Appearance and language');
    });

    it('replaces the visit with the first section on a wide container', async () => {
        setWide(true);
        mountPage(Index, { sections: [MEMBERS, APPEARANCE] });
        await flushPromises();
        expect(visit).toHaveBeenCalledWith('/settings/members', { replace: true });
    });
});

describe('Settings layout and appearance page', () => {
    it('marks the current section and offers Back to settings', () => {
        setWide(false);
        mountPage(Appearance, { sections: [MEMBERS, APPEARANCE] });
        const nav = current.find('nav[aria-label="Settings"]');
        const items = nav.findAll('a');
        expect(items).toHaveLength(2);
        expect(items[1].attributes('aria-current')).toBe('page');
        expect(items[0].attributes('aria-current')).toBeUndefined();
        expect(current.find('[data-testid=settings-back]').text()).toBe('Back to settings');
        expect(current.find('[data-testid=settings-back]').attributes('href')).toBe('/settings');
        expect(current.find('h1').text()).toBe('Appearance and language');
    });

    it('has Theme and Language cards with the existing switchers', () => {
        setWide(false);
        mountPage(Appearance);
        const headings = current.findAll('h2').map((h) => h.text());
        expect(headings).toEqual(['Theme', 'Language']);
        expect(current.find('[data-testid=theme-switch]').exists()).toBe(true);
        expect(current.find('[data-testid=language-switcher]').exists()).toBe(true);
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations in %s, light and dark', async (locale) => {
        setWide(false);
        for (const theme of ['light', 'dark']) {
            document.documentElement.dataset.theme = theme;
            for (const component of [Index, Appearance]) {
                mountPage(component, { locale, sections: [MEMBERS, APPEARANCE] });
                await expectNoAxeViolations(document.body);
                current.unmount();
                current = null;
            }
        }
        delete document.documentElement.dataset.theme;
    });
});
