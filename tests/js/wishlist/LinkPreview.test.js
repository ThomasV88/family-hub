import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { wish, link } from './fixtures.js';

const calls = { post: vi.fn() };
vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn(), post: (...a) => calls.post(...a), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() },
}));

import OwnWishPanel from '../../../resources/js/Wishlist/OwnWishPanel.vue';

const TOKEN = 'a'.repeat(40);
let current;
let answer;
const fetchMock = vi.fn();

function mountPanel(props = {}) {
    current = mount(OwnWishPanel, {
        props: { open: true, mode: 'create', wish: null, ownerId: 2, ownerName: 'Mira', managing: false, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n('en', loadMessages('en'))] },
    });
    return current;
}
const field = (label) => [...document.body.querySelectorAll('label')].find((l) => l.textContent.replace('*', '').trim() === label);
const input = (label) => document.getElementById(field(label).getAttribute('for'));
const btn = (text) => [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === text);
async function type(el, value) {
    el.value = value;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}
async function addLink(url) {
    if (!document.body.querySelector('input[type="url"]')) {
        btn('Add link').click();
        await flushPromises();
    }
    const url0 = document.body.querySelector('input[type="url"]');
    await type(url0, url);
    url0.dispatchEvent(new Event('blur', { bubbles: true }));
    await flushPromises();
}
const posts = () => fetchMock.mock.calls.filter(([, init]) => init?.method === 'POST');
async function settle() {
    await vi.advanceTimersByTimeAsync(1600);
    await flushPromises();
}

beforeEach(() => {
    vi.useFakeTimers();
    calls.post.mockReset();
    fetchMock.mockReset();
    answer = { status: 'done', title: 'Lamp', description: 'A nice lamp', image: true, failures: {} };
    fetchMock.mockImplementation(async (url, init) => {
        const body = url === '/wishlist/link-preview' && init?.method === 'POST' ? { token: TOKEN } : answer;
        return { ok: true, status: 200, headers: { get: () => null }, json: async () => body };
    });
    vi.stubGlobal('fetch', fetchMock);
});
afterEach(() => {
    current?.unmount();
    current = null;
    document.body.innerHTML = '';
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('link preview autofill', () => {
    it('posts once for the first URL and fills blank title and notes', async () => {
        mountPanel();
        await flushPromises();
        await addLink('https://shop.example/lamp');
        await settle();
        expect(posts()).toHaveLength(1);
        expect(JSON.parse(posts()[0][1].body)).toEqual({ url: 'https://shop.example/lamp' });
        expect(input('Title').value).toBe('Lamp');
        expect(input('Notes').value).toBe('A nice lamp');
        expect(document.body.querySelector('[data-testid="preview-image"]').getAttribute('src')).toBe(`/wishlist/link-preview/${TOKEN}/image`);
        expect(document.body.querySelector('[data-testid="preview-image-note"]')).not.toBeNull();
        expect(document.body.querySelector('[data-testid="preview-missing"]')).toBeNull();
    });

    it('does not fetch again for a second link or for later edits of the first', async () => {
        mountPanel();
        await flushPromises();
        await addLink('https://shop.example/lamp');
        await settle();
        btn('Add link').click();
        await flushPromises();
        const rows = document.body.querySelectorAll('input[type="url"]');
        await type(rows[1], 'https://other.example/x');
        rows[1].dispatchEvent(new Event('blur', { bubbles: true }));
        await type(rows[0], 'https://shop.example/other');
        rows[0].dispatchEvent(new Event('blur', { bubbles: true }));
        await settle();
        expect(posts()).toHaveLength(1);
    });

    it('never overwrites typed text', async () => {
        mountPanel();
        await flushPromises();
        await type(input('Title'), 'My own title');
        await addLink('https://shop.example/lamp');
        await settle();
        expect(input('Title').value).toBe('My own title');
        expect(input('Notes').value).toBe('A nice lamp');
    });

    it('does not apply a result to a field typed into while the request was running', async () => {
        mountPanel();
        await flushPromises();
        await addLink('https://shop.example/lamp');
        await type(input('Notes'), 'typed meanwhile');
        await settle();
        expect(input('Notes').value).toBe('typed meanwhile');
        expect(input('Title').value).toBe('Lamp');
    });

    it('lists exactly the missing pieces with reasons', async () => {
        answer = { status: 'done', title: 'Lamp', description: null, image: false, failures: { description: 'no_tags', image: 'timeout' } };
        mountPanel();
        await flushPromises();
        await addLink('https://shop.example/lamp');
        await settle();
        const text = document.body.querySelector('[data-testid="preview-missing"]').textContent;
        expect(text).toContain('image (the page took too long)');
        expect(text).toContain('description (the page does not provide it)');
        expect(text).not.toContain('title');
        expect(document.body.querySelector('[data-testid="preview-image-note"]')).toBeNull();
    });

    it('does nothing automatically for a wish that already has links, and the Reload button fetches', async () => {
        mountPanel({ mode: 'edit', wish: wish(11, { title: 'Blue backpack', urls: [link(1)] }) });
        await flushPromises();
        const url0 = document.body.querySelector('input[type="url"]');
        url0.dispatchEvent(new Event('blur', { bubbles: true }));
        await settle();
        expect(posts()).toHaveLength(0);
        btn('Reload from first link').click();
        await settle();
        expect(posts()).toHaveLength(1);
        expect(input('Title').value).toBe('Blue backpack');
        expect(calls.post).toHaveBeenCalledWith('/wishlist/wishes/11/image/fetch', {}, expect.anything());
    });
});
