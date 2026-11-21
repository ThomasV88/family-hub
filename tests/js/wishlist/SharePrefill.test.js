import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

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

describe('share target prefill', () => {
    const prefill = { title: 'Shoes', url: 'https://shop.example/p' };

    it('fills title and first link and starts the preview once, without saving', async () => {
        mountPanel({ prefill });
        await flushPromises();
        await settle();
        expect(input('Title').value).toBe('Shoes');
        expect(document.body.querySelector('input[type="url"]').value).toBe('https://shop.example/p');
        expect(posts()).toHaveLength(1);
        expect(JSON.parse(posts()[0][1].body)).toEqual({ url: 'https://shop.example/p' });
        expect(calls.post).not.toHaveBeenCalled();
        expect(current.vm.dirty).toBe(true);
    });

    it('fills only the title when there is no link and does not preview', async () => {
        mountPanel({ prefill: { title: 'Just words', url: null } });
        await flushPromises();
        await settle();
        expect(input('Title').value).toBe('Just words');
        expect(document.body.querySelector('input[type="url"]')).toBeNull();
        expect(posts()).toHaveLength(0);
    });

    it('does not overwrite a typed title or an existing first link', async () => {
        mountPanel({ prefill: null });
        await flushPromises();
        await type(input('Title'), 'Mine');
        await addLink('https://mine.example/x');
        await settle();
        await current.setProps({ prefill });
        await flushPromises();
        expect(input('Title').value).toBe('Mine');
        expect(document.body.querySelector('input[type="url"]').value).toBe('https://mine.example/x');
    });

    it('renders hostile text as plain input value', async () => {
        mountPanel({ prefill: { title: '<img src=x onerror=alert(1)>', url: null } });
        await flushPromises();
        expect(input('Title').value).toBe('<img src=x onerror=alert(1)>');
        expect(document.body.querySelector('img[src="x"]')).toBeNull();
    });

    it('is ignored in edit mode and without a prefill', async () => {
        mountPanel({ prefill: null });
        await flushPromises();
        expect(input('Title').value).toBe('');
        expect(posts()).toHaveLength(0);
    });
});
