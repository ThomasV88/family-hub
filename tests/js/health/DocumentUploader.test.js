import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import { readFileSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

const { router, upload, refusal } = vi.hoisted(() => ({
    router: { reload: vi.fn(), visit: vi.fn(), on: vi.fn(() => vi.fn()) },
    upload: vi.fn(),
    refusal: vi.fn(),
}));
vi.mock('@inertiajs/vue3', () => ({ router }));
vi.mock('../../../resources/js/Security/securityTransfer.js', () => ({ securityUpload: upload, securityDownload: vi.fn() }));
vi.mock('../../../resources/js/Health/healthRefusal.js', () => ({ handleHealthRefusal: refusal }));

import DocumentUploader from '../../../resources/js/Health/DocumentUploader.vue';

const VISIT = { id: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeee1', visitedOn: '2025-05-01', doctor: 'Dr. Kova', specialtyKey: 'dentist', specialtyOther: null };
let wrapper;
function mountIt(props = {}, locale = 'en') {
    wrapper = mount(DocumentUploader, {
        props: { personId: 'p1', visits: [VISIT], limits: { maxMb: 10, filesAtOnce: 3 }, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, loadMessages(locale))] },
    });
    return wrapper;
}
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; vi.clearAllMocks(); });

const file = (name, size = 100) => new File([new Uint8Array(size)], name, { type: 'application/octet-stream' });
async function pick(files) {
    const input = wrapper.find('[data-testid="uploader-input"]');
    Object.defineProperty(input.element, 'files', { value: files, configurable: true });
    await input.trigger('change');
}
const start = () => wrapper.find('[data-testid="uploader-start"]');

describe('document uploader', () => {
    it('has a native multi-file input that accepts HEIC and shows the server limits in the hint', () => {
        mountIt();
        const input = wrapper.find('input[type="file"]');
        expect(input.attributes('multiple')).toBeDefined();
        expect(input.attributes('accept')).toContain('.heic');
        expect(wrapper.text()).toContain('PDF or photos, up to 10 MB each, 3 files at a time.');
    });

    it('labels the primary action with the plural count and offers the name field for one file only', async () => {
        mountIt();
        await pick([file('a.pdf')]);
        expect(start().text()).toBe('Add document');
        expect(wrapper.text()).toContain('Name');
        await pick([file('b.jpg')]);
        expect(start().text()).toBe('Add 2 documents');
        expect(wrapper.text()).not.toMatch(/\bName\b/);
    });

    it('pre-checks extension and size and caps the batch, without uploading those files', async () => {
        mountIt();
        await pick([file('evil.exe'), file('big.pdf', 11 * 1048576), file('a.pdf'), file('b.pdf'), file('c.pdf')]);
        const rows = wrapper.findAll('li');
        expect(rows).toHaveLength(3);
        expect(wrapper.text()).toContain('This file type is not supported');
        expect(wrapper.text()).toContain('larger than 10 MB');
        expect(wrapper.text()).toContain('You can add up to 3 files at a time');
        expect(upload).not.toHaveBeenCalled();
    });

    it('uploads one file at a time with progress, the optional name and visit, then refreshes the page props', async () => {
        const calls = [];
        upload.mockImplementation((url, body, opts) => {
            calls.push({ url, body });
            opts.onProgress(0.5);
            return Promise.resolve({ ok: true, status: 201, data: {} });
        });
        mountIt({ initialVisit: VISIT.id });
        await pick([file('scan.pdf')]);
        await wrapper.find('input.ui-input, input[type="text"]').setValue('My scan');
        await start().trigger('click');
        await flushPromises();
        expect(calls).toHaveLength(1);
        expect(calls[0].url).toBe('/health/people/p1/documents');
        expect(calls[0].body.get('title')).toBe('My scan');
        expect(calls[0].body.get('visit_id')).toBe(VISIT.id);
        expect(wrapper.text()).toContain('Added');
        expect(router.reload).toHaveBeenCalledWith({ only: expect.arrayContaining(['documents', 'visits', 'timeline']) });
    });

    it('marks a failed file with the translated server message and retries it', async () => {
        upload.mockResolvedValueOnce({ ok: false, status: 422, error: 'validation', data: { errors: { file: ['health.upload.err_size'] }, params: { max: 10, count: 200 } } });
        upload.mockResolvedValueOnce({ ok: true, status: 201, data: {} });
        mountIt();
        await pick([file('a.pdf')]);
        await start().trigger('click');
        await flushPromises();
        expect(wrapper.find('[role="alert"]').text()).toBe('This file is larger than 10 MB. Choose a smaller file or reduce its size.');
        await wrapper.find('[data-testid="uploader-retry"]').trigger('click');
        await flushPromises();
        expect(upload).toHaveBeenCalledTimes(2);
        expect(wrapper.text()).toContain('Added');
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    });

    it('uses the dedicated per-person cap text for the server limit_reached key', async () => {
        upload.mockResolvedValue({ ok: false, status: 422, error: 'validation', data: { errors: { file: ['health.upload.err_limit'] }, params: { max: 10, count: 200 } } });
        mountIt();
        await pick([file('a.pdf')]);
        await start().trigger('click');
        await flushPromises();
        const text = wrapper.find('[role="alert"]').text();
        expect(text).toContain('200 documents');
        expect(text).not.toContain('at a time');
    });

    it('keeps going after one failure so later files still upload', async () => {
        upload.mockResolvedValueOnce({ ok: false, status: 0, error: 'network' }).mockResolvedValueOnce({ ok: true, status: 201, data: {} });
        mountIt();
        await pick([file('a.pdf'), file('b.pdf')]);
        await start().trigger('click');
        await flushPromises();
        expect(upload).toHaveBeenCalledTimes(2);
        expect(wrapper.findAll('li')[0].text()).toContain('We could not upload this file');
        expect(wrapper.findAll('li')[1].text()).toContain('Added');
    });

    it('hands a 423 to the shared refusal handler, lifts the leave guard first and does not replay', async () => {
        upload.mockResolvedValue({ ok: false, status: 423, error: 'confirm_required' });
        refusal.mockImplementation((code, { onBeforeLeave }) => { onBeforeLeave(); return true; });
        mountIt();
        await pick([file('a.pdf'), file('b.pdf')]);
        await start().trigger('click');
        await flushPromises();
        expect(refusal).toHaveBeenCalledWith('confirm_required', expect.objectContaining({ onBeforeLeave: expect.any(Function) }));
        expect(upload).toHaveBeenCalledTimes(1);
    });

    it('aborts the running upload on Cancel and keeps files that were already added', async () => {
        let signal;
        upload.mockImplementationOnce(() => Promise.resolve({ ok: true, status: 201, data: {} }));
        upload.mockImplementationOnce((url, body, opts) => new Promise((resolve) => {
            signal = opts.signal;
            signal.addEventListener('abort', () => resolve({ ok: false, status: 0, error: 'aborted' }));
        }));
        mountIt();
        await pick([file('a.pdf'), file('b.pdf'), file('c.pdf')]);
        await start().trigger('click');
        await flushPromises();
        await wrapper.find('[data-testid="uploader-cancel"]').trigger('click');
        await flushPromises();
        expect(signal.aborted).toBe(true);
        expect(upload).toHaveBeenCalledTimes(2);
        const rows = wrapper.findAll('li');
        expect(rows).toHaveLength(1);
        expect(rows[0].text()).toContain('a.pdf');
        expect(rows[0].text()).toContain('Added');
        expect(router.reload).toHaveBeenCalled();
    });

    it('opens the leave guard on a get visit while uploading and stays quiet otherwise', async () => {
        let handler;
        router.on.mockImplementation((name, fn) => { handler = fn; return vi.fn(); });
        let release;
        upload.mockImplementation(() => new Promise((resolve) => { release = resolve; }));
        mountIt();
        const idle = { detail: { visit: { method: 'get', url: new URL('http://localhost/x') } } };
        expect(handler(idle)).toBeUndefined();
        await pick([file('a.pdf')]);
        await start().trigger('click');
        await nextTick();
        expect(handler(idle)).toBe(false);
        await nextTick();
        expect(document.body.textContent).toContain('Leave while uploading?');
        release({ ok: true, status: 201, data: {} });
        await flushPromises();
    });

    it('translates in sk and pl and writes nothing to browser storage', async () => {
        const spies = ['setItem'].flatMap(() => [vi.spyOn(Storage.prototype, 'setItem')]);
        upload.mockResolvedValue({ ok: true, status: 201, data: {} });
        for (const locale of ['sk', 'pl']) {
            mountIt({}, locale);
            await pick([file('a.pdf'), file('b.pdf')]);
            expect(start().text()).toMatch(/2/);
            await start().trigger('click');
            await flushPromises();
            wrapper.unmount();
        }
        wrapper = null;
        expect(spies[0]).not.toHaveBeenCalled();
        const src = readFileSync(`${process.cwd()}/resources/js/Health/DocumentUploader.vue`, 'utf8');
        expect(src).not.toMatch(/localStorage|sessionStorage|indexedDB|remember/);
    });

    it('has no axe violations while waiting, uploading and failed', async () => {
        upload.mockResolvedValueOnce({ ok: false, status: 0, error: 'network' });
        mountIt();
        await pick([file('a.pdf'), file('b.pdf')]);
        await expectNoAxeViolations(wrapper.element);
        upload.mockImplementationOnce(() => new Promise(() => {}));
        await start().trigger('click');
        await flushPromises();
        await expectNoAxeViolations(wrapper.element);
    });
});
