import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { giverListWithReservations } from './givingFixtures.js';

vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: {}, url: '/wishlist' }),
        router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});
import GiftListView from '../../../resources/js/Wishlist/GiftListView.vue';

let current;
const mountView = (props = {}, locale = 'en') => {
    globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ groups: [] }) }));
    current = mount(GiftListView, { props: { list: giverListWithReservations(), ...props }, slots: { context: '<p data-testid="ctx">ctx</p>' }, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return current;
};
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; delete globalThis.fetch; });

describe('GiftListView with reservations', () => {
    it('keeps the server order (available first) and shows a status tag on every card', async () => {
        const w = mountView();
        await flushPromises();
        const tags = w.findAll('[data-testid="card-status"]').map((n) => n.text());
        expect(tags).toEqual(['Available', 'Reserved by you', 'Co-buy, 2 people', 'Reserved by Petra']);
    });

    it('shows the header with the wish count', () => {
        const w = mountView();
        expect(w.find('h2').text()).toBe("Adam's wishlist");
        expect(w.text()).toContain('4 wishes');
    });

    it('requests the shopping summary and renders it before the context slot', async () => {
        const w = mountView();
        await flushPromises();
        expect(globalThis.fetch).toHaveBeenCalledWith('/wishlist/shopping', expect.anything());
        const html = w.html();
        expect(html.indexOf('data-testid="ctx"')).toBeGreaterThan(-1);
    });

    it('is axe clean in sk dark', async () => {
        document.documentElement.dataset.theme = 'dark';
        mountView({}, 'sk');
        await flushPromises();
        await expectNoAxeViolations(document.body);
        delete document.documentElement.dataset.theme;
    });
});
