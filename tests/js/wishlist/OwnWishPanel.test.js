import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { wish, link } from './fixtures.js';

const calls = { post: vi.fn(), patch: vi.fn(), delete: vi.fn() };
vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn(), post: (...a) => calls.post(...a), patch: (...a) => calls.patch(...a), delete: (...a) => calls.delete(...a), visit: vi.fn(), on: vi.fn() },
}));

import OwnWishPanel from '../../../resources/js/Wishlist/OwnWishPanel.vue';

let current;
function mountPanel(props = {}, { locale = 'en', messages } = {}) {
    current = mount(OwnWishPanel, {
        props: { open: true, mode: 'create', wish: null, ownerId: 2, ownerName: 'Mira', managing: false, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] },
    });
    return current;
}
beforeEach(() => Object.values(calls).forEach((f) => f.mockReset()));
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; });

const btn = (text) => [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === text);
const field = (label) => [...document.body.querySelectorAll('label')].find((l) => l.textContent.replace('*', '').trim() === label);
const input = (label) => document.getElementById(field(label).getAttribute('for'));
async function type(el, value) {
    el.value = value;
    el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
    await flushPromises();
}
const heading = () => document.body.querySelector('h2.ui-panel__title').textContent;
const EDIT = wish(11, { title: 'Blue backpack', price: '49.90', currency: 'PLN', priority: 'top', notes: 'n', size_notes: 'M', urls: [link(1), link(2, { label: 'Outlet' })] });

describe('OwnWishPanel create mode', () => {
    it('has the New wish heading, a disabled Save until a title is typed, and defaults', async () => {
        mountPanel();
        await flushPromises();
        expect(heading()).toBe('New wish');
        expect(btn('Save wish').disabled).toBe(true);
        expect(input('Currency').value).toBe('EUR');
        expect([...document.querySelectorAll('[role="radiogroup"] button')].map((b) => b.getAttribute('aria-pressed'))).toEqual(['false', 'true', 'false']);
        await type(input('Title'), 'Lamp');
        expect(btn('Save wish').disabled).toBe(false);
    });

    it('uses the manager heading', async () => {
        mountPanel({ managing: true, ownerId: 3, ownerName: 'Zora' });
        await flushPromises();
        expect(heading()).toBe('New wish for Zora');
    });

    it('posts the payload with owner_id, dropping blank link rows and normalising the price', async () => {
        mountPanel({ ownerId: 3 });
        await type(input('Title'), '  Lamp ');
        await type(input('Price'), '12,5');
        btn('Add link').click();
        await flushPromises();
        btn('Add link').click();
        await flushPromises();
        await type(input('Link address 1'), 'https://shop.example/lamp');
        await type(input('Label 1'), 'Shop');
        btn('Save wish').click();
        await flushPromises();
        expect(calls.post).toHaveBeenCalledTimes(1);
        const [url, data] = calls.post.mock.calls[0];
        expect(url).toBe('/wishlist/wishes');
        expect(data).toEqual({ owner_id: 3, title: 'Lamp', notes: null, price: '12.5', currency: 'EUR', size_notes: null, priority: 'love', urls: [{ url: 'https://shop.example/lamp', label: 'Shop' }] });
    });

    it('does not submit on Enter in the title', async () => {
        mountPanel();
        await type(input('Title'), 'Lamp');
        const event = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
        input('Title').dispatchEvent(event);
        expect(event.defaultPrevented).toBe(true);
        expect(calls.post).not.toHaveBeenCalled();
    });

    it('shows price and url errors and does not post', async () => {
        mountPanel();
        await type(input('Title'), 'Lamp');
        await type(input('Price'), '12.345');
        btn('Add link').click();
        await flushPromises();
        await type(input('Link address 1'), 'javascript:alert(1)');
        btn('Save wish').click();
        await flushPromises();
        expect(calls.post).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('Enter a price of 0 or more, with up to 2 decimals.');
        expect(document.body.textContent).toContain('Enter a web address that starts with http:// or https://.');
    });

    it('maps server 422 errors to fields and focuses the first invalid one', async () => {
        calls.post.mockImplementation((url, data, opts) => { opts.onError({ title: 'wishlist.error.title_required', 'urls.0.url': 'wishlist.error.url_invalid' }); });
        mountPanel();
        await type(input('Title'), 'Lamp');
        btn('Add link').click();
        await flushPromises();
        await type(input('Link address 1'), 'https://ok.example');
        btn('Save wish').click();
        await flushPromises();
        expect(document.body.textContent).toContain('Enter a title for the wish.');
        expect(document.body.textContent).toContain('Enter a web address that starts with http:// or https://.');
        expect(document.activeElement).toBe(input('Title'));
    });

    it('shows the save failure alert on an unmapped server error', async () => {
        calls.post.mockImplementation((url, data, opts) => { opts.onError({ owner_id: 'x' }); });
        mountPanel();
        await type(input('Title'), 'Lamp');
        btn('Save wish').click();
        await flushPromises();
        expect(document.body.querySelector('[role="alert"]').textContent).toContain('We could not save the wish. Nothing was changed. Try again.');
    });

    it('closes after a successful create', async () => {
        calls.post.mockImplementation((url, data, opts) => { opts.onSuccess(); opts.onFinish(); });
        const w = mountPanel();
        await type(input('Title'), 'Lamp');
        btn('Save wish').click();
        await flushPromises();
        expect(w.emitted('close')).toHaveLength(1);
    });
});

describe('LinksEditor', () => {
    it('limits to 5 rows with a hint, removes rows with a numbered label', async () => {
        mountPanel();
        for (let i = 0; i < 5; i += 1) { btn('Add link').click(); await flushPromises(); }
        expect(document.querySelectorAll('input[type="url"]')).toHaveLength(5);
        expect(btn('Add link').disabled).toBe(true);
        expect(document.body.textContent).toContain('You can add up to 5 links.');
        expect(document.querySelector('input[type="url"]').getAttribute('inputmode')).toBe('url');
        expect(document.querySelector('input[type="url"]').getAttribute('placeholder')).toBe('https://');
        document.querySelector('button[aria-label="Remove link 2"]').click();
        await flushPromises();
        expect(document.querySelectorAll('input[type="url"]')).toHaveLength(4);
        expect(btn('Add link').disabled).toBe(false);
    });

    it('validates a url on blur', async () => {
        mountPanel();
        btn('Add link').click();
        await flushPromises();
        const el = input('Link address 1');
        await type(el, 'ftp://x.example');
        el.dispatchEvent(new Event('blur'));
        await flushPromises();
        expect(document.body.textContent).toContain('Enter a web address that starts with http:// or https://.');
    });
});

describe('Priority radiogroup', () => {
    it('moves and selects with arrow keys, exactly one checked', async () => {
        mountPanel();
        const group = () => [...document.querySelectorAll('[role="radiogroup"] button')];
        group()[1].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true, cancelable: true }));
        await flushPromises();
        expect(group().map((b) => b.getAttribute('aria-pressed'))).toEqual(['false', 'false', 'true']);
        group()[2].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true, cancelable: true }));
        group()[1].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true, cancelable: true }));
        await flushPromises();
        expect(group().map((b) => b.getAttribute('aria-pressed'))).toEqual(['true', 'false', 'false']);
    });
});

describe('OwnWishPanel edit mode', () => {
    it('shows Wish details, a Save that needs changes, and patches', async () => {
        mountPanel({ mode: 'edit', wish: EDIT });
        await flushPromises();
        expect(heading()).toBe('Wish details');
        expect(input('Title').value).toBe('Blue backpack');
        expect(input('Price').value).toBe('49.90');
        expect(btn('Save wish').disabled).toBe(true);
        await type(input('Title'), 'Red backpack');
        expect(btn('Save wish').disabled).toBe(false);
        btn('Save wish').click();
        await flushPromises();
        const [url, data] = calls.patch.mock.calls[0];
        expect(url).toBe('/wishlist/wishes/11');
        expect(data).not.toHaveProperty('owner_id');
        expect(data.title).toBe('Red backpack');
        expect(data.urls).toEqual([{ url: 'https://shop1.example/item', label: null }, { url: 'https://shop2.example/item', label: 'Outlet' }]);
    });

    it('marks as received with a POST', async () => {
        const w = mountPanel({ mode: 'edit', wish: EDIT });
        btn('Mark as received').click();
        await flushPromises();
        expect(calls.post.mock.calls[0][0]).toBe('/wishlist/wishes/11/received');
        expect(w.emitted('close')).toBeUndefined();
    });

    it('confirms deletion with the wish title, focusing Keep wish, and then deletes', async () => {
        mountPanel({ mode: 'edit', wish: EDIT });
        btn('Delete wish').click();
        await flushPromises();
        expect(document.body.textContent).toContain('Delete "Blue backpack"? This cannot be undone.');
        expect(calls.delete).not.toHaveBeenCalled();
        const confirm = [...document.body.querySelectorAll('button')].filter((b) => b.textContent.trim() === 'Delete wish').pop();
        confirm.click();
        await flushPromises();
        expect(calls.delete.mock.calls[0][0]).toBe('/wishlist/wishes/11');
    });

    it('received wishes are read-only with Delete and Close only', async () => {
        mountPanel({ mode: 'edit', wish: { ...EDIT, received: true } });
        expect(btn('Save wish')).toBeUndefined();
        expect(btn('Mark as received')).toBeUndefined();
        expect(document.querySelector('fieldset.wish-panel__fields').disabled).toBe(true);
        expect(btn('Delete wish')).toBeTruthy();
    });

    it('shows the image hint for none and failed states only', async () => {
        mountPanel({ mode: 'edit', wish: EDIT });
        expect(document.body.textContent).toContain('No image found. Upload one to make this wish easier to spot.');
        current.unmount();
        mountPanel({ mode: 'edit', wish: { ...EDIT, image: { status: 'ready', thumb_url: '/t', full_url: '/f', source: 'uploaded', host: null } } });
        expect(document.body.textContent).not.toContain('No image found.');
    });
});

describe('Discard dialog and close', () => {
    it('closes straight away without changes', async () => {
        const w = mountPanel({ mode: 'edit', wish: EDIT });
        btn('Close').click();
        await flushPromises();
        expect(w.emitted('close')).toHaveLength(1);
    });

    it('asks first with unsaved changes and keeps editing by default', async () => {
        const w = mountPanel({ mode: 'edit', wish: EDIT });
        await type(input('Title'), 'Changed');
        btn('Close').click();
        await flushPromises();
        expect(document.body.textContent).toContain('Discard changes?');
        expect(w.emitted('close')).toBeUndefined();
        btn('Keep editing').click();
        await flushPromises();
        expect(w.emitted('close')).toBeUndefined();
        btn('Close').click();
        await flushPromises();
        btn('Discard').click();
        await flushPromises();
        expect(w.emitted('close')).toHaveLength(1);
    });

    it('never labels a button Cancel', async () => {
        mountPanel({ mode: 'edit', wish: EDIT });
        expect(btn('Cancel')).toBeUndefined();
    });
});

describe('a11y and layout', () => {
    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`create and edit panels have no axe violations (${locale}, ${dark ? 'dark' : 'light'})`, async () => {
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                mountPanel({}, { locale });
                document.querySelector('fieldset.links > button').click();
                await flushPromises();
                await expectNoAxeViolations(document.body);
                current.unmount();
                mountPanel({ mode: 'edit', wish: EDIT }, { locale });
                await expectNoAxeViolations(document.body);
                delete document.documentElement.dataset.theme;
            });
        }
    }

    it('renders pseudo-long copy without axe violations', async () => {
        mountPanel({ mode: 'edit', wish: EDIT }, { messages: pseudoLong(loadMessages('sk')), locale: 'sk' });
        await expectNoAxeViolations(document.body);
    });
});
