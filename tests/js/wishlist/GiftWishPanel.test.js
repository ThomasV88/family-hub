import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { wish, link, image } from './fixtures.js';

vi.mock('@inertiajs/vue3', () => ({ router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() }, usePage: () => ({ props: {} }) }));

import GiftWishPanel from '../../../resources/js/Wishlist/GiftWishPanel.vue';

const FULL = wish(11, {
    title: 'Blue backpack',
    price: '49.90',
    size_notes: 'M',
    priority: 'top',
    notes: 'Line one\nLine two <b>bold</b>',
    urls: [link(1), link(2, { label: 'Outlet' })],
    image: image({ status: 'ready', thumb_url: '/wishlist/images/11/thumb', full_url: '/wishlist/images/11/full' }),
});

let current;
function mountPanel(props = {}, { locale = 'en', messages } = {}) {
    current = mount(GiftWishPanel, { props: { open: true, wish: FULL, ...props }, attachTo: document.body, global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] } });
    return current;
}
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; });

describe('GiftWishPanel', () => {
    it('shows the read-only wish details in order, with no form controls', async () => {
        mountPanel();
        await flushPromises();
        const root = document.body;
        expect(root.querySelector('h2.ui-panel__title').textContent).toBe('Wish details');
        expect(root.querySelector('[data-testid="gift-wish-panel"] img').getAttribute('src')).toBe('/wishlist/images/11/full');
        expect(root.querySelector('h3').textContent).toBe('Blue backpack');
        expect(root.textContent).toContain(new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'EUR' }).format(49.9));
        expect(root.textContent).toContain('Size or variant: M');
        expect(root.textContent).toContain('Top wish');
        expect(root.querySelectorAll('input, textarea, select')).toHaveLength(0);
        const buttons = [...root.querySelectorAll('.ui-panel__footer button')].map((b) => b.textContent.trim());
        expect(buttons).toEqual(['Close']);
    });

    it('renders links as safe new-tab anchors with label or host, never the full URL', async () => {
        mountPanel();
        const anchors = [...document.body.querySelectorAll('a')];
        expect(anchors).toHaveLength(2);
        for (const a of anchors) {
            expect(a.getAttribute('target')).toBe('_blank');
            expect(a.getAttribute('rel')).toBe('noopener noreferrer');
        }
        expect(anchors[0].getAttribute('aria-label')).toBe('shop1.example, opens in a new tab');
        expect(anchors[1].getAttribute('aria-label')).toBe('Outlet, opens in a new tab');
        expect(anchors[1].textContent).toContain('Outlet');
        expect(anchors[1].textContent).toContain('shop2.example');
        expect(document.body.textContent).not.toContain('https://shop1.example/item');
    });

    it('renders notes as text with pre-wrap, never as HTML', async () => {
        mountPanel();
        const notes = document.body.querySelector('.gift-panel__notes');
        expect(notes.textContent).toBe('Line one\nLine two <b>bold</b>');
        expect(notes.querySelector('b')).toBeNull();
    });

    it('omits empty sections', () => {
        mountPanel({ wish: wish(5, { title: 'Bare' }) });
        expect(document.body.querySelectorAll('a')).toHaveLength(0);
        expect(document.body.textContent).not.toContain('Notes');
        expect(document.body.textContent).not.toContain('Links');
    });

    it('emits close from the footer button', async () => {
        const w = mountPanel();
        [...document.body.querySelectorAll('.ui-panel__footer button')][0].click();
        expect(w.emitted('close')).toHaveLength(1);
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`has no axe violations (${locale}, ${dark ? 'dark' : 'light'})`, async () => {
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                mountPanel({}, { locale });
                await expectNoAxeViolations(document.body);
                delete document.documentElement.dataset.theme;
            });
        }
    }

    it('has no axe violations with pseudo-long copy', async () => {
        mountPanel({}, { locale: 'pl', messages: pseudoLong(loadMessages('pl')) });
        await expectNoAxeViolations(document.body);
    });
});
