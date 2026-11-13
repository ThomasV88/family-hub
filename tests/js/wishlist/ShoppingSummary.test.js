import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { MEMBERS, ownList, managedList } from './fixtures.js';

let mockProps = {};
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: mockProps, get url() { return '/wishlist'; } }),
        router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import ShoppingSummary from '../../../resources/js/Wishlist/ShoppingSummary.vue';
import Index from '../../../resources/js/Pages/Wishlist/Index.vue';

const DATA = {
    groups: [
        { owner: { id: 1, name: 'Adam' }, items: [
            { wish_id: 11, title: 'Blue backpack <b>x</b>', price: '49.90', currency: 'EUR', cobuy_state: 'open', is_organizer: false, my_amount: '20.00' },
            { wish_id: 12, title: 'Book', price: null, currency: 'EUR', cobuy_state: 'none', is_organizer: true, my_amount: null },
        ] },
        { owner: { id: 3, name: 'Zora' }, items: [{ wish_id: 21, title: 'Scarf', price: '15.00', currency: 'EUR', cobuy_state: 'none', is_organizer: true, my_amount: null }] },
    ],
};

let current;
const plugin = (locale, messages) => createAppI18n(locale, messages ?? loadMessages(locale));
function respond(body, ok = true) {
    globalThis.fetch = vi.fn(async () => ({ ok, status: ok ? 200 : 500, json: async () => body }));
}
async function mountSummary({ locale = 'en', messages } = {}) {
    current = mount(ShoppingSummary, { attachTo: document.body, global: { plugins: [plugin(locale, messages)] } });
    await flushPromises();
    return current;
}
beforeEach(() => { mockProps = reactive({ flash: { data: null } }); });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; delete globalThis.fetch; });

describe('ShoppingSummary', () => {
    it('fetches the JSON endpoint once, same-origin, with JSON headers', async () => {
        respond(DATA);
        await mountSummary();
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        const [url, init] = globalThis.fetch.mock.calls[0];
        expect(url).toBe('/wishlist/shopping');
        expect(init.credentials).toBe('same-origin');
        expect(init.headers.Accept).toBe('application/json');
        expect(init.headers['X-Requested-With']).toBe('XMLHttpRequest');
    });

    it('renders nothing when groups are empty or the request fails', async () => {
        respond({ groups: [] });
        const empty = await mountSummary();
        expect(empty.html()).toBe('<!--v-if-->');
        empty.unmount();
        respond({}, false);
        const failed = await mountSummary();
        expect(failed.html()).toBe('<!--v-if-->');
        failed.unmount();
        globalThis.fetch = vi.fn(async () => { throw new Error('offline'); });
        const offline = await mountSummary();
        expect(offline.text()).toBe('');
    });

    it('is collapsed by default and toggles with aria-expanded', async () => {
        respond(DATA);
        const w = await mountSummary();
        const toggle = w.find('button');
        expect(w.text()).toContain('Your shopping');
        expect(toggle.text()).toBe('Show');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(w.find('.shopping__body').isVisible()).toBe(false);
        await toggle.trigger('click');
        expect(toggle.text()).toBe('Hide');
        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(w.find('.shopping__body').isVisible()).toBe(true);
    });

    it('groups by person, links to the wish and shows only the own share on co-buy rows', async () => {
        respond(DATA);
        const w = await mountSummary();
        expect(w.findAll('.shopping__owner').map((h) => h.text())).toEqual(['Adam', 'Zora']);
        const links = w.findAll('a');
        expect(links.map((a) => a.attributes('href'))).toEqual(['/wishlist?member=1&wish=11', '/wishlist?member=1&wish=12', '/wishlist?member=3&wish=21']);
        expect(links[0].text()).toBe('Blue backpack <b>x</b>');
        expect(w.find('b').exists()).toBe(false);
        expect(w.text()).toContain('Your share: ' + new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'EUR' }).format(20));
        expect(w.text().match(/Your share/g)).toHaveLength(1);
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const theme of ['light', 'dark']) {
            it(`is axe clean expanded in ${locale} ${theme}`, async () => {
                document.documentElement.dataset.theme = theme;
                respond(DATA);
                const w = await mountSummary({ locale });
                await w.find('button').trigger('click');
                await expectNoAxeViolations(document.body);
                delete document.documentElement.dataset.theme;
            });
        }
    }

    it('survives pseudo-long strings', async () => {
        respond(DATA);
        const w = await mountSummary({ messages: pseudoLong(loadMessages('en')) });
        await w.find('button').trigger('click');
        await expectNoAxeViolations(document.body);
    });
});

describe('recipient views never request the shopping summary', () => {
    for (const [name, list] of [['own', ownList()], ['managed', managedList()]]) {
        it(`${name} list makes no /wishlist/shopping request`, async () => {
            respond(DATA);
            window.innerWidth = 1280;
            window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
            mockProps = reactive({
                locale: 'en', flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
                auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'edit', finance: 'none', health: 'none' } },
                app: { env: 'local', styleGuide: false },
            });
            current = mount(Index, { props: { members: MEMBERS, selected: null, list }, attachTo: document.body, global: { plugins: [plugin('en')] } });
            await flushPromises();
            expect(globalThis.fetch).not.toHaveBeenCalled();
            expect(current.find('[data-testid="shopping-summary"]').exists()).toBe(false);
        });
    }
});
