import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

let mockProps = {};
const { routerMock, secureMock } = vi.hoisted(() => ({
    routerMock: { get: vi.fn(), post: vi.fn(), put: vi.fn(), visit: vi.fn(), replace: vi.fn(), reload: vi.fn(), on: vi.fn(() => vi.fn()) },
    secureMock: vi.fn(),
}));
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: mockProps, url: '/recipes/create' }),
        router: routerMock,
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: secureMock }));

import RecipePhotosField from '../../../resources/js/Components/Recipes/RecipePhotosField.vue';
import Form from '../../../resources/js/Pages/Recipes/Form.vue';
import { uploadPhoto, uploadQueue } from '../../../resources/js/Components/Recipes/recipePhotosApi.js';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const file = (name, type = 'image/jpeg', size = 10) => new File([new Uint8Array(size)], name, { type });
const json = (status, data) => ({ ok: status >= 200 && status < 300, status, json: async () => data });
const stored = (id, isMain = false, position = id) => ({ id, position, isMain, version: 1 });

let current;
const fetchMock = vi.fn();
function install(locale = 'en') {
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    return { global: { plugins: [createAppI18n(locale, loadMessages(locale))] }, attachTo: document.body };
}
function mountField(props = {}, locale = 'en') {
    const holder = { queue: props.queue ?? [] };
    current = mount(RecipePhotosField, {
        props: { recipeId: null, photos: [], queue: [], max: 10, ...props, 'onUpdate:queue': (q) => { holder.queue = q; current.setProps({ queue: q }); } },
        ...install(locale),
    });
    current.holder = holder;
    return current;
}
async function pick(wrapper, files) {
    const input = wrapper.find('[data-testid="photo-input"]');
    Object.defineProperty(input.element, 'files', { value: files, configurable: true });
    await input.trigger('change');
    await flushPromises();
}
const tiles = () => current.findAll('[data-testid="photo-tile"]');
const body = (text) => [...document.body.querySelectorAll('button, [role="menuitem"]')].find((b) => b.textContent.trim() === text);
const click = async (el) => { el.click(); await flushPromises(); };
async function openMenu(index) { await click(tiles()[index].find('.ui-menu button').element); }

beforeEach(() => {
    vi.stubGlobal('fetch', fetchMock);
    fetchMock.mockReset();
    secureMock.mockReset();
    Object.values(routerMock).forEach((f) => f.mockClear());
    routerMock.on.mockImplementation(() => vi.fn());
    useToast().toasts.splice(0);
    let n = 0;
    URL.createObjectURL = vi.fn(() => `blob:local-${n++}`);
    URL.revokeObjectURL = vi.fn();
});
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; vi.unstubAllGlobals(); });

describe('RecipePhotosField create mode', () => {
    it('shows local thumbnails with the first marked Main, and Make main moves the marker', async () => {
        mountField();
        await pick(current, [file('a.jpg'), file('b.png', 'image/png'), file('c.webp', 'image/webp')]);
        expect(tiles()).toHaveLength(3);
        expect(tiles().map((t) => t.find('img').attributes('src'))).toEqual(['blob:local-0', 'blob:local-1', 'blob:local-2']);
        expect(tiles()[0].text()).toContain('Main');
        expect(tiles()[2].text()).not.toContain('Main');
        await openMenu(2);
        await click(body('Make main'));
        expect(current.holder.queue.map((q) => q.isMain)).toEqual([false, false, true]);
        expect(tiles()[2].text()).toContain('Main');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('moves a photo earlier or later, and removes after a confirmation (main passes to the first left)', async () => {
        mountField();
        await pick(current, [file('a.jpg'), file('b.jpg'), file('c.jpg')]);
        const keys = current.holder.queue.map((q) => q.key);
        await openMenu(1);
        await click(body('Move earlier'));
        expect(current.holder.queue.map((q) => q.key)).toEqual([keys[1], keys[0], keys[2]]);
        // Remove the first (main) one: ask first.
        await openMenu(0);
        await click(body('Remove'));
        expect(document.body.textContent).toContain('Remove this photo?');
        await click(body('Keep photo'));
        expect(tiles()).toHaveLength(3);
        await openMenu(0);
        await click(body('Remove'));
        await click(body('Remove photo'));
        expect(current.holder.queue.map((q) => q.key)).toEqual([keys[0], keys[2]]);
        expect(current.holder.queue.map((q) => q.isMain)).toEqual([true, false]);
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:local-1');
    });

    it('revokes every object URL on unmount', async () => {
        mountField();
        await pick(current, [file('a.jpg'), file('b.jpg')]);
        current.unmount();
        current = null;
        expect(URL.revokeObjectURL).toHaveBeenCalledTimes(2);
    });

    it('refuses a wrong type or a file over the size limit inline, without a request', async () => {
        mountField({ maxBytes: 100 });
        await pick(current, [file('a.gif', 'image/gif')]);
        expect(current.find('[data-testid="photo-error"]').text()).toContain('We could not use that picture');
        await pick(current, [file('big.jpg', 'image/jpeg', 500)]);
        expect(current.find('[data-testid="photo-error"]').text()).toContain('larger than');
        expect(tiles()).toHaveLength(0);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('stops at the cap with a message, takes only the files that fit, and hides the add tile', async () => {
        mountField({ max: 3 });
        await pick(current, [file('1.jpg'), file('2.jpg'), file('3.jpg'), file('4.jpg')]);
        expect(tiles()).toHaveLength(3);
        expect(current.find('[data-testid="photo-error"]').text()).toBe('You can add up to 3 photos.');
        expect(current.find('[data-testid="photo-add"]').exists()).toBe(false);
        expect(current.text()).toContain('You can add up to 3 photos.');
        expect(fetchMock).not.toHaveBeenCalled();
    });
});

describe('RecipePhotosField edit mode', () => {
    const P = [stored(1, true, 1), stored(2, false, 2), stored(3, false, 3)];

    it('shows the saved photos by their thumbnail address, with the main one marked', () => {
        mountField({ recipeId: 12, photos: P });
        expect(tiles().map((t) => t.find('img').attributes('src'))).toEqual(['/recipes/12/photos/1/thumb?v=1', '/recipes/12/photos/2/thumb?v=1', '/recipes/12/photos/3/thumb?v=1']);
        expect(tiles()[0].text()).toContain('Main');
    });

    it('uploads multipart image files one by one and replaces the list from each answer', async () => {
        fetchMock
            .mockResolvedValueOnce(json(201, { photo: stored(4), photos: [...P, stored(4)] }))
            .mockResolvedValueOnce(json(201, { photo: stored(5), photos: [...P, stored(4), stored(5)] }));
        mountField({ recipeId: 12, photos: P });
        await pick(current, [file('a.jpg'), file('b.jpg')]);
        expect(fetchMock).toHaveBeenCalledTimes(2);
        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toBe('/recipes/12/photos');
        expect(options.method).toBe('POST');
        expect(options.body).toBeInstanceOf(FormData);
        expect(options.body.get('image').name).toBe('a.jpg');
        expect(options.headers).not.toHaveProperty('Content-Type');
        expect(options.credentials).toBe('same-origin');
        expect(tiles()).toHaveLength(5);
    });

    it('shows the server message of a 422 and goes on with the next file', async () => {
        fetchMock
            .mockResolvedValueOnce(json(422, { errors: { image: ['recipes.image.err_type'] } }))
            .mockResolvedValueOnce(json(201, { photo: stored(4), photos: [...P, stored(4)] }));
        mountField({ recipeId: 12, photos: P });
        await pick(current, [file('a.jpg'), file('b.jpg')]);
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(current.find('[data-testid="photo-error"]').text()).toContain('We could not use that picture');
        expect(tiles()).toHaveLength(4);
    });

    it('stops after a 429 and tells the member to wait; a 404 tells the form the recipe is gone', async () => {
        fetchMock.mockResolvedValue(json(429, {}));
        mountField({ recipeId: 12, photos: P });
        await pick(current, [file('a.jpg'), file('b.jpg')]);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(current.find('[data-testid="photo-error"]').text()).toContain('Too many attempts');
        fetchMock.mockReset();
        fetchMock.mockResolvedValue(json(404, {}));
        await pick(current, [file('a.jpg')]);
        expect(current.emitted('gone')).toBeTruthy();
    });

    it('refuses an eleventh photo without a request', async () => {
        const ten = Array.from({ length: 10 }, (_, i) => stored(i + 1, i === 0));
        mountField({ recipeId: 12, photos: ten });
        expect(current.find('[data-testid="photo-add"]').exists()).toBe(false);
        await pick(current, [file('x.jpg')]);
        expect(fetchMock).not.toHaveBeenCalled();
        expect(current.find('[data-testid="photo-error"]').text()).toBe('You can add up to 10 photos.');
    });

    it('calls main, move and remove (after confirm) and replaces the list from the response', async () => {
        secureMock
            .mockResolvedValueOnce({ ok: true, status: 200, data: { photos: [stored(1, false), stored(2, true), stored(3)] } })
            .mockResolvedValueOnce({ ok: true, status: 200, data: { photos: [stored(2, true), stored(1), stored(3)] } })
            .mockResolvedValueOnce({ ok: true, status: 200, data: { photos: [stored(2, true), stored(1)] } });
        mountField({ recipeId: 12, photos: P });
        await openMenu(1);
        await click(body('Make main'));
        expect(secureMock).toHaveBeenLastCalledWith('/recipes/12/photos/2/main', { method: 'PUT', body: undefined });
        expect(tiles()[1].text()).toContain('Main');
        expect(tiles()[0].text()).not.toContain('Main');
        await openMenu(1);
        await click(body('Move earlier'));
        expect(secureMock).toHaveBeenLastCalledWith('/recipes/12/photos/2/position', { method: 'PUT', body: { direction: 'earlier' } });
        expect(tiles()[0].find('img').attributes('src')).toContain('/photos/2/');
        await openMenu(2);
        await click(body('Remove'));
        expect(secureMock).toHaveBeenCalledTimes(2);
        await click(body('Remove photo'));
        expect(secureMock).toHaveBeenLastCalledWith('/recipes/12/photos/3', { method: 'DELETE', body: undefined });
        expect(tiles()).toHaveLength(2);
    });

    it('shows a failure inline and keeps the list; actions are blocked while one runs', async () => {
        let resolve;
        secureMock.mockReturnValueOnce(new Promise((r) => { resolve = r; }));
        mountField({ recipeId: 12, photos: P });
        await openMenu(1);
        await click(body('Make main'));
        expect(current.find('[data-testid="photo-add"]').attributes('disabled')).toBeDefined();
        resolve({ ok: false, status: 403, error: 'failed', data: {} });
        await flushPromises();
        expect(current.find('[data-testid="photo-error"]').text()).toContain('not allowed');
        expect(tiles()[0].text()).toContain('Main');
        expect(current.find('[data-testid="photo-add"]').attributes('disabled')).toBeUndefined();
    });
});

describe('recipePhotosApi', () => {
    it('uploadQueue stores in order and makes the third main', async () => {
        fetchMock.mockImplementation(async () => json(201, { photo: stored(fetchMock.mock.calls.length + 10), photos: [] }));
        secureMock.mockResolvedValue({ ok: true, status: 200, data: { photos: [] } });
        const queue = [{ key: 'a', file: file('a.jpg') }, { key: 'b', file: file('b.jpg') }, { key: 'c', file: file('c.jpg') }];
        const result = await uploadQueue(9, queue, 'c');
        expect(result).toEqual({ failed: 0 });
        expect(fetchMock.mock.calls.map((c) => c[1].body.get('image').name)).toEqual(['a.jpg', 'b.jpg', 'c.jpg']);
        expect(secureMock).toHaveBeenCalledTimes(1);
        expect(secureMock.mock.calls[0][0]).toBe('/recipes/9/photos/13/main');
    });

    it('uploadQueue does not call main when the first is main, counts failures and stops on 429', async () => {
        fetchMock.mockResolvedValueOnce(json(201, { photo: stored(1), photos: [] })).mockResolvedValueOnce(json(500, {})).mockResolvedValueOnce(json(201, { photo: stored(3), photos: [] }));
        const queue = ['a', 'b', 'c'].map((key) => ({ key, file: file(`${key}.jpg`) }));
        expect(await uploadQueue(9, queue, 'a')).toEqual({ failed: 1 });
        expect(secureMock).not.toHaveBeenCalled();
        fetchMock.mockReset();
        fetchMock.mockResolvedValueOnce(json(201, { photo: stored(1), photos: [] })).mockResolvedValueOnce(json(429, {}));
        expect(await uploadQueue(9, queue, 'a')).toEqual({ failed: 2 });
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('uploadPhoto never throws and sends the XSRF header', async () => {
        document.cookie = 'XSRF-TOKEN=abc%20def';
        fetchMock.mockRejectedValue(new Error('offline'));
        expect(await uploadPhoto(9, file('a.jpg'))).toMatchObject({ ok: false, status: 0 });
        fetchMock.mockResolvedValue(json(201, { photo: stored(1), photos: [stored(1)] }));
        await uploadPhoto(9, file('a.jpg'));
        expect(fetchMock.mock.calls.at(-1)[1].headers['X-XSRF-TOKEN']).toBe('abc def');
    });
});

describe('Recipe form with photos', () => {
    const LIMITS = { photosMax: 10, photoMaxBytes: 5242880 };
    const BASE = { recipe: null, defaults: { servings: 4, visibility: 'private' }, units: [], categorySuggestions: [], starterCategories: [], limits: LIMITS, isShared: false, canAddToShopping: false };
    function mountForm(props = {}, locale = 'en') {
        window.innerWidth = 1024;
        mockProps = {
            locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
            auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { recipes: 'edit' } }, app: { env: 'local', styleGuide: false },
        };
        current = mount(Form, { props: { ...BASE, ...props }, ...install(locale) });
        return current;
    }
    const fill = async () => {
        await current.find('[data-testid="title-input"]').setValue('Pancakes');
        await current.find('[data-field="name"]').setValue('Flour');
        await current.find('[data-field="step"]').setValue('Mix');
    };

    it('uploads the queued photos after the recipe is saved, makes the chosen one main and opens the detail', async () => {
        secureMock.mockImplementation(async (url) => (url === '/recipes' ? { ok: true, status: 201, data: { id: 9 } } : { ok: true, status: 200, data: { photos: [] } }));
        let id = 100;
        fetchMock.mockImplementation(async () => json(201, { photo: stored(id++), photos: [] }));
        mountForm();
        await fill();
        await pick(current, [file('a.jpg'), file('b.jpg'), file('c.jpg')]);
        await openMenu(2);
        await click(body('Make main'));
        await current.find('form').trigger('submit');
        await flushPromises();
        expect(secureMock.mock.calls[0][0]).toBe('/recipes');
        expect(fetchMock.mock.calls.map((c) => [c[0], c[1].body.get('image').name])).toEqual([
            ['/recipes/9/photos', 'a.jpg'], ['/recipes/9/photos', 'b.jpg'], ['/recipes/9/photos', 'c.jpg'],
        ]);
        expect(secureMock.mock.calls[1][0]).toBe('/recipes/9/photos/102/main');
        expect(routerMock.visit).toHaveBeenCalledWith('/recipes/9', expect.any(Object));
        expect(useToast().toasts.map((t) => t.message)).toEqual(['Recipe saved']);
    });

    it('keeps the recipe when a photo fails and says so in the toast', async () => {
        secureMock.mockResolvedValue({ ok: true, status: 201, data: { id: 9 } });
        fetchMock.mockResolvedValueOnce(json(201, { photo: stored(1), photos: [] })).mockResolvedValueOnce(json(500, {}));
        mountForm();
        await fill();
        await pick(current, [file('a.jpg'), file('b.jpg')]);
        await current.find('form').trigger('submit');
        await flushPromises();
        expect(routerMock.visit).toHaveBeenCalledWith('/recipes/9', expect.any(Object));
        expect(useToast().toasts.map((t) => t.message)).toEqual(['The recipe was saved, but 1 photo could not be added. You can add it again in Edit.']);
    });

    it('treats queued photos as unsaved changes for the leave guard', async () => {
        mountForm();
        await pick(current, [file('a.jpg')]);
        const guard = routerMock.on.mock.calls.find(([name]) => name === 'before')[1];
        expect(guard({ detail: { visit: { url: new URL('http://x.test/recipes'), method: 'get' } } })).toBe(false);
    });
});

describe('RecipePhotosField accessibility', () => {
    it.each(['en', 'sk', 'pl'])('has no axe violations (%s)', async (locale) => {
        mountField({ recipeId: 12, photos: [stored(1, true), stored(2)] }, locale);
        await expectNoAxeViolations(document.body);
        await openMenu(1);
        await expectNoAxeViolations(document.body);
    });
});
