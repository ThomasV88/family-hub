import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { MEMBERS, WISHES, wish, image, ownList, managedList, giverList } from './fixtures.js';

let mockProps = {};
const calls = { get: vi.fn() };
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: mockProps, get url() { return '/wishlist'; } }),
        router: { get: (...a) => calls.get(...a), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import OwnListView from '../../../resources/js/Wishlist/OwnListView.vue';
import GiftListView from '../../../resources/js/Wishlist/GiftListView.vue';
import PersonChips from '../../../resources/js/Wishlist/PersonChips.vue';
import WishImage from '../../../resources/js/Wishlist/WishImage.vue';
import Index from '../../../resources/js/Pages/Wishlist/Index.vue';

let current;
const plugin = (locale) => createAppI18n(locale, loadMessages(locale));
function mountView(component, props, { locale = 'en', slots = {} } = {}) {
    current = mount(component, { props, slots, attachTo: document.body, global: { plugins: [plugin(locale)] } });
    return current;
}
function mountPage(props, { locale = 'en', access = 'edit' } = {}) {
    window.innerWidth = 1280;
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    mockProps = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: access, finance: 'none', health: 'none' } },
        app: { env: 'local', styleGuide: false },
    });
    current = mount(Index, { props: { members: MEMBERS, selected: null, ...props }, attachTo: document.body, global: { plugins: [plugin(locale)] } });
    return current;
}
beforeEach(() => { calls.get.mockReset(); sessionStorage.clear(); });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; });

const byText = (root, text) => [...root.querySelectorAll('button')].find((b) => b.textContent.trim() === text);

describe('Own list gallery', () => {
    it('renders the Add wish tile first, then the cards in the given order', () => {
        const w = mountView(OwnListView, { list: ownList() });
        const items = w.findAll('[data-testid="gallery"] > li');
        expect(items).toHaveLength(4);
        expect(items[0].text()).toBe('Add wish');
        expect(items.slice(1).map((li) => li.find('button').attributes('aria-label'))).toEqual(WISHES.map((x) => x.title));
    });

    it('has no tile and shows the view-only hint when the viewer cannot edit', () => {
        const w = mountView(OwnListView, { list: ownList({ can_edit: false }) });
        expect(w.find('[data-testid="add-tile"]').exists()).toBe(false);
        expect(w.text()).toContain('You can view this list but not change it.');
    });

    it('formats price per wish currency, shows link count and the Top wish tag only where they apply', () => {
        const w = mountView(OwnListView, { list: ownList({ wishes: [WISHES[0], WISHES[1], wish(14, { title: 'Bare' })] }) });
        const cards = w.findAll('.wish-card');
        expect(cards[0].text()).toContain(new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'EUR' }).format(49.9));
        expect(cards[0].text()).toContain('2 links');
        expect(cards[0].text()).toContain('Top wish');
        expect(cards[1].text()).not.toContain('link');
        expect(cards[1].text()).not.toContain('Top wish');
        expect(cards[2].text()).toBe('Bare');
        expect(w.find('.wish-card__title').exists()).toBe(true);
    });

    it('uses a single button per card and emits open with the wish id', async () => {
        const w = mountView(OwnListView, { list: ownList() });
        const card = w.findAll('.wish-card')[1];
        expect(card.element.tagName).toBe('BUTTON');
        expect(card.findAll('button, a, input')).toHaveLength(0);
        await card.trigger('click');
        expect(w.emitted('open')[0]).toEqual([12]);
        await w.find('[data-testid="add-tile"]').trigger('click');
        expect(w.emitted('add')).toHaveLength(1);
    });

    it('shows the dismissible own banner, and the managing banner with the add label for a manager', async () => {
        const own = mountView(OwnListView, { list: ownList() });
        expect(own.text()).toContain('This is your wishlist. Everyone with access can see it.');
        await own.find('.ui-alert button').trigger('click');
        expect(own.text()).not.toContain('This is your wishlist.');
        own.unmount();
        const managed = mountView(OwnListView, { list: managedList() });
        expect(managed.text()).toContain("You are managing Zora's list.");
        expect(managed.find('[data-testid="add-tile"]').text()).toBe('Add wish for Zora');
    });

    it('shows the empty states', () => {
        const own = mountView(OwnListView, { list: ownList({ wishes: [], received: [] }) });
        expect(own.text()).toContain('Your wishlist is empty');
        expect(byText(document.body, 'Add wish')).toBeTruthy();
        own.unmount();
        const other = mountView(GiftListView, { list: giverList({ wishes: [] }) });
        expect(other.text()).toContain('Adam has not added any wishes yet');
    });

    it('collapses the Received group with aria-expanded and neutral received tags', async () => {
        const w = mountView(OwnListView, { list: managedList() });
        const toggle = byText(w.element, 'Received (1)');
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        await toggle.click();
        await flushPromises();
        expect(byText(w.element, 'Hide received (1)').getAttribute('aria-expanded')).toBe('true');
        expect(w.find('#wishlist-received').text()).toContain('Received');
    });
});

describe('Giver list gallery', () => {
    it('renders the heading, the pluralised count and no add controls', () => {
        const w = mountView(GiftListView, { list: giverList() });
        expect(w.find('h2').text()).toBe("Adam's wishlist");
        expect(w.text()).toContain('3 wishes');
        expect(w.find('[data-testid="add-tile"]').exists()).toBe(false);
        expect(w.findAll('[data-testid="gallery"] > li')).toHaveLength(3);
        mountView(GiftListView, { list: giverList({ wishes: [WISHES[0]] }) });
        expect(current.text()).toContain('1 wish');
    });

    it('renders the context slot before the header', () => {
        const w = mountView(GiftListView, { list: giverList() }, { slots: { context: '<p data-testid="ctx">ctx</p>' } });
        const html = w.html();
        expect(html.indexOf('data-testid="ctx"')).toBeLessThan(html.indexOf('<header'));
    });
});

describe('WishImage', () => {
    it('renders the thumb for a ready image only', () => {
        const w = mountView(WishImage, { image: image({ status: 'ready', thumb_url: '/wishlist/images/1/thumb', full_url: '/wishlist/images/1/full' }) });
        const img = w.find('img');
        expect(img.attributes()).toMatchObject({ src: '/wishlist/images/1/thumb', alt: '', loading: 'lazy', decoding: 'async' });
    });
    it('uses the full image in the panel size', () => {
        const w = mountView(WishImage, { size: 'panel', image: image({ status: 'ready', thumb_url: '/t', full_url: '/f' }) });
        expect(w.find('img').attributes('src')).toBe('/f');
    });
    it('shows the placeholder for none, failed and ready-without-url, and never an img from a host', () => {
        for (const img of [image(), image({ status: 'failed', host: 'evil.example' }), image({ status: 'ready' })]) {
            const w = mountView(WishImage, { image: img });
            expect(w.find('img').exists()).toBe(false);
            expect(w.find('.wish-image__none').exists()).toBe(true);
            w.unmount();
        }
    });
    it('never turns a wish link into an image source', () => {
        const w = mountView(OwnListView, { list: ownList({ wishes: [wish(1, { urls: [{ id: 1, url: 'https://x.example/a.png', label: null, host: 'x.example' }] })] }) });
        expect(w.find('img').exists()).toBe(false);
        expect(w.html()).not.toContain('x.example');
    });
});

describe('PersonChips', () => {
    it('is a labelled group with the viewer first and aria-pressed on the selection', () => {
        const w = mountView(PersonChips, { members: MEMBERS, selectedId: 1 });
        expect(w.attributes('role')).toBe('group');
        expect(w.attributes('aria-label')).toBe('Choose a list');
        const chips = w.findAll('button');
        expect(chips[0].text()).toContain('Mira (You)');
        expect(chips.map((c) => c.attributes('aria-pressed'))).toEqual(['false', 'true', 'false']);
    });
    it('navigates to ?member= without preserving state', async () => {
        const w = mountView(PersonChips, { members: MEMBERS, selectedId: 2 });
        await w.findAll('button')[2].trigger('click');
        expect(calls.get).toHaveBeenCalledWith('/wishlist', { member: 3 }, expect.objectContaining({ preserveState: false, replace: true }));
        calls.get.mockReset();
        await w.findAll('button')[0].trigger('click');
        expect(calls.get).not.toHaveBeenCalled();
    });
});

describe('Wishlist page', () => {
    it('picks the own view from viewer_is_recipient and the giver view otherwise', () => {
        const own = mountPage({ list: ownList() });
        expect(own.find('[data-testid="own-list"]').exists()).toBe(true);
        expect(own.find('[data-testid="gift-list"]').exists()).toBe(false);
        own.unmount();
        const gift = mountPage({ list: giverList() });
        expect(gift.find('[data-testid="gift-list"]').exists()).toBe(true);
        expect(gift.find('[data-testid="own-list"]').exists()).toBe(false);
    });

    it('registers the shell add action only for a recipient who can edit', async () => {
        const own = mountPage({ list: ownList() });
        await flushPromises();
        expect(own.find('.topbar button.ui-button--primary').text()).toBe('Add wish');
        own.unmount();
        const managed = mountPage({ list: managedList(), });
        await flushPromises();
        expect(managed.find('.topbar button.ui-button--primary').text()).toBe('Add wish for Zora');
        managed.unmount();
        const gift = mountPage({ list: giverList() });
        await flushPromises();
        expect(gift.find('.topbar button.ui-button--primary').exists()).toBe(false);
    });

    it('opens a wish by setting ?wish= (and ?member= for another list)', async () => {
        const gift = mountPage({ list: giverList() });
        await gift.findAll('.wish-card')[0].trigger('click');
        expect(calls.get).toHaveBeenCalledWith('/wishlist', { member: 1, wish: 11 }, expect.objectContaining({ preserveState: true, preserveScroll: true, replace: true }));
    });
});

describe('a11y', () => {
    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`own, managed, giver and empty states have no axe violations (${locale}, ${dark ? 'dark' : 'light'})`, async () => {
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                for (const [component, list] of [[OwnListView, ownList()], [OwnListView, managedList()], [GiftListView, giverList()], [OwnListView, ownList({ wishes: [] })], [GiftListView, giverList({ wishes: [] })]]) {
                    const w = mountView(component, { list }, { locale, slots: { context: '<div></div>' } });
                    await expectNoAxeViolations(w.element);
                    w.unmount();
                }
                delete document.documentElement.dataset.theme;
            });
        }
    }
});
