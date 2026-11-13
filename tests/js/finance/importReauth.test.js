import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { importPage, PREVIEW, csvFile } from './importFixtures.js';
import { preview } from '../../../resources/js/Finance/import/importApi.js';

let mockProps = {};
const visit = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps, get url() { return '/finance/import'; } }),
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: (...a) => visit(...a), on: () => () => {} },
    Link: { props: ['href'], render() { return null; } },
}));

import Import from '../../../resources/js/Pages/Finance/Import.vue';

const respond = (status, body) => vi.fn(async () => ({ ok: status < 400, status, json: async () => body }));

describe('importApi.preview idle refusals', () => {
    beforeEach(() => { document.cookie = 'XSRF-TOKEN=tok%3D1'; });

    it('maps 423 reauth_required', async () => {
        globalThis.fetch = respond(423, { error: 'reauth_required' });
        expect(await preview(csvFile())).toEqual({ ok: false, error: 'reauth_required' });
    });

    it('maps 423 second_factor_required', async () => {
        globalThis.fetch = respond(423, { error: 'second_factor_required' });
        expect(await preview(csvFile())).toEqual({ ok: false, error: 'second_factor_required' });
    });

    it('leaves other errors unchanged', async () => {
        globalThis.fetch = respond(422, { error: 'too_large' });
        expect(await preview(csvFile())).toEqual({ ok: false, error: 'too_large' });
        globalThis.fetch = respond(423, {});
        expect(await preview(csvFile())).toEqual({ ok: false, error: 'decode_failed' });
    });
});

describe('Import wizard idle refusals', () => {
    let wrapper;
    function mountPage() {
        window.innerWidth = 1280;
        window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
        mockProps = reactive({
            locale: 'en', flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
            auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: 'edit', health: 'none' } },
            app: { env: 'local', styleGuide: false },
        });
        wrapper = mount(Import, { props: importPage(), attachTo: document.body, global: { plugins: [createAppI18n('en', loadMessages('en'))] } });
    }
    async function readWith(status, body) {
        globalThis.fetch = respond(status, body);
        mountPage();
        const pick = async (label, value) => {
            const sel = [...document.querySelectorAll('label')].find((l) => l.textContent.replace(/\s*\*$/, '').trim() === label).control;
            sel.value = value;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
            await flushPromises();
        };
        await pick('Import into account', 'acc-main');
        await pick('Bank profile', '');
        const input = document.querySelector('input[type="file"]');
        Object.defineProperty(input, 'files', { value: [csvFile()], configurable: true });
        input.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();
        [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Read file').click();
        await flushPromises();
    }

    beforeEach(() => { visit.mockReset(); document.cookie = 'XSRF-TOKEN=tok%3D1'; });
    afterEach(() => { wrapper?.unmount(); document.body.innerHTML = ''; });

    it('visits the confirm page on reauth_required and shows no file error', async () => {
        await readWith(423, { error: 'reauth_required' });
        expect(visit).toHaveBeenCalledWith('/confirm-access');
        expect(document.body.textContent).not.toContain('Could not read');
        expect(document.querySelector('[role="alert"]')).toBeNull();
    });

    it('visits security settings on second_factor_required', async () => {
        await readWith(423, { error: 'second_factor_required' });
        expect(visit).toHaveBeenCalledWith('/settings/security');
    });

    it('keeps the existing message for other errors', async () => {
        await readWith(422, { error: 'decode_failed' });
        expect(visit).not.toHaveBeenCalled();
    });
});
