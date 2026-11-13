import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { wish, link, image } from './fixtures.js';

const calls = { post: vi.fn(), delete: vi.fn(), reload: vi.fn() };
vi.mock('@inertiajs/vue3', () => ({
    router: {
        get: vi.fn(), patch: vi.fn(), visit: vi.fn(), on: vi.fn(),
        post: (...a) => calls.post(...a),
        delete: (...a) => calls.delete(...a),
        reload: (...a) => calls.reload(...a),
    },
}));

import OwnWishPanel from '../../../resources/js/Wishlist/OwnWishPanel.vue';
import GiftWishPanel from '../../../resources/js/Wishlist/GiftWishPanel.vue';

let current;
function mountPanel(props = {}, locale = 'en') {
    current = mount(OwnWishPanel, {
        props: { open: true, mode: 'edit', wish: wish(11, { urls: [link(1)] }), ownerId: 2, ownerName: 'Mira', managing: false, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, loadMessages(locale))] },
    });
    return current;
}
beforeEach(() => {
    Object.values(calls).forEach((f) => f.mockReset());
    URL.createObjectURL = vi.fn(() => 'blob:preview');
    URL.revokeObjectURL = vi.fn();
});
afterEach(() => {
    current?.unmount();
    current = null;
    document.body.innerHTML = '';
    vi.useRealTimers();
});

const btn = (text) => [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === text);
const status = () => document.body.querySelector('[data-testid="image-status"]').textContent.trim();
const error = () => document.body.querySelector('[data-testid="image-error"]');
async function pick(file) {
    const input = document.body.querySelector('[data-testid="image-input"]');
    Object.defineProperty(input, 'files', { value: [file], configurable: true });
    input.dispatchEvent(new Event('change', { bubbles: true }));
    await flushPromises();
}
const file = (type = 'image/jpeg', size = 1000) => {
    const f = new File(['x'], 'p.jpg', { type });
    Object.defineProperty(f, 'size', { value: size });
    return f;
};

describe('upload', () => {
    it('accepts only jpeg, png and webp in the picker', () => {
        mountPanel();
        expect(document.body.querySelector('[data-testid="image-input"]').getAttribute('accept')).toBe('image/jpeg,image/png,image/webp');
    });

    it('rejects an oversized file or another type inline without a request', async () => {
        mountPanel();
        await pick(file('image/jpeg', 5 * 1024 * 1024 + 1));
        expect(error().textContent).toContain('The image is larger than 5 MB.');
        await pick(file('image/svg+xml'));
        expect(error().textContent).toContain('Use a JPEG, PNG or WebP image.');
        expect(calls.post).not.toHaveBeenCalled();
    });

    it('shows a local preview with Uploading and posts multipart', async () => {
        mountPanel();
        await pick(file());
        expect(calls.post).toHaveBeenCalledTimes(1);
        const [url, data, options] = calls.post.mock.calls[0];
        expect(url).toBe('/wishlist/wishes/11/image');
        expect(data.image).toBeInstanceOf(File);
        expect(options.forceFormData).toBe(true);
        expect(status()).toBe('Uploading image...');
        expect(document.body.querySelector('.wish-image img').getAttribute('src')).toBe('blob:preview');
        expect(document.body.querySelector('[role="progressbar"]')).not.toBeNull();

        options.onFinish();
        await flushPromises();
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:preview');
        expect(status()).not.toBe('Uploading image...');
    });

    it('maps a server error key to its message', async () => {
        mountPanel();
        await pick(file());
        calls.post.mock.calls[0][2].onError({ image: 'wishlist.image.err_type' });
        await flushPromises();
        expect(error().textContent).toContain('Use a JPEG, PNG or WebP image.');
    });

    it('disables upload in create mode with no extra text', () => {
        mountPanel({ mode: 'create', wish: null });
        expect(btn('Upload image').disabled).toBe(true);
        expect(document.body.querySelector('[data-testid="image-status"]').textContent.trim()).toBe('');
        expect(btn('Load image from link')).toBeUndefined();
    });
});

describe('load from link and remove', () => {
    it('offers Load image from link only with a saved link and posts to /image/fetch', async () => {
        mountPanel({ wish: wish(11) });
        expect(btn('Load image from link')).toBeUndefined();
        current.unmount();
        mountPanel();
        btn('Load image from link').click();
        expect(calls.post.mock.calls[0][0]).toBe('/wishlist/wishes/11/image/fetch');
    });

    it('shows Loading while pending and hides the load button', () => {
        mountPanel({ wish: wish(11, { urls: [link(1)], image: image({ status: 'pending' }) }) });
        expect(status()).toBe('Looking for an image on the link...');
        expect(btn('Load image from link')).toBeUndefined();
    });

    it('shows Image from host for fetched images and offers Remove', () => {
        mountPanel({ wish: wish(11, { urls: [link(1)], image: image({ status: 'ready', thumb_url: '/t', full_url: '/f', source: 'fetched', host: 'shop1.example' }) }) });
        expect(status()).toBe('Image from shop1.example.');
        btn('Remove image').click();
        expect(calls.delete.mock.calls[0][0]).toBe('/wishlist/wishes/11/image');
    });

    it('has no Remove without an image and shows the hint for none', () => {
        mountPanel();
        expect(btn('Remove image')).toBeUndefined();
        expect(status()).toContain('No image found.');
    });
});

describe('pending polling', () => {
    const pending = () => wish(11, { urls: [link(1)], image: image({ status: 'pending' }) });

    it('reloads the list every 3 s, at most 10 times, then shows the hint', async () => {
        vi.useFakeTimers();
        mountPanel({ wish: pending() });
        vi.advanceTimersByTime(3000 * 3);
        expect(calls.reload).toHaveBeenCalledTimes(3);
        expect(calls.reload).toHaveBeenCalledWith({ only: ['list'] });
        vi.advanceTimersByTime(3000 * 12);
        await flushPromises();
        expect(calls.reload).toHaveBeenCalledTimes(10);
        expect(status()).toContain('No image found.');
    });

    it('pauses while the tab is hidden', () => {
        vi.useFakeTimers();
        Object.defineProperty(document, 'hidden', { value: true, configurable: true });
        mountPanel({ wish: pending() });
        vi.advanceTimersByTime(9000);
        expect(calls.reload).not.toHaveBeenCalled();
        Object.defineProperty(document, 'hidden', { value: false, configurable: true });
        vi.advanceTimersByTime(3000);
        expect(calls.reload).toHaveBeenCalledTimes(1);
    });

    it('stops on unmount and never polls when nothing is pending', () => {
        vi.useFakeTimers();
        mountPanel({ wish: pending() });
        current.unmount();
        current = null;
        vi.advanceTimersByTime(30000);
        expect(calls.reload).not.toHaveBeenCalled();

        mountPanel();
        vi.advanceTimersByTime(30000);
        expect(calls.reload).not.toHaveBeenCalled();
    });
});

describe('giver side', () => {
    it('has no image controls or polling in the giver panel', () => {
        const g = mount(GiftWishPanel, {
            props: { open: true, wish: wish(11, { image: image({ status: 'pending' }) }), reservation: null, ownerName: 'Adam' },
            attachTo: document.body,
            global: { plugins: [createAppI18n('en', loadMessages('en'))] },
        });
        expect(document.body.querySelector('input[type="file"]')).toBeNull();
        expect(btn('Upload image')).toBeUndefined();
        expect(btn('Remove image')).toBeUndefined();
        g.unmount();
    });
});

describe('a11y', () => {
    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`image block has no axe violations (${locale}, ${dark ? 'dark' : 'light'})`, async () => {
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                mountPanel({ wish: wish(11, { urls: [link(1)], image: image({ status: 'ready', thumb_url: '/t', full_url: '/f', source: 'fetched', host: 'shop1.example' }) }) }, locale);
                await expectNoAxeViolations(document.body);
                current.unmount();
                mountPanel({ wish: wish(11, { urls: [link(1)] }) }, locale);
                await pick(file());
                await expectNoAxeViolations(document.body);
                delete document.documentElement.dataset.theme;
            });
        }
    }
});
