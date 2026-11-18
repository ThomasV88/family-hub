import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';

const { router, upload, refusal } = vi.hoisted(() => ({
    router: { reload: vi.fn(), visit: vi.fn(), on: vi.fn(() => vi.fn()) },
    upload: vi.fn(),
    refusal: vi.fn(),
}));
vi.mock('@inertiajs/vue3', () => ({ router }));
vi.mock('../../../resources/js/Security/securityTransfer.js', () => ({ securityUpload: upload, securityDownload: vi.fn() }));
vi.mock('../../../resources/js/Health/healthRefusal.js', () => ({ handleHealthRefusal: refusal }));

import BatchUploader from '../../../resources/js/Health/Batch/BatchUploader.vue';

let wrapper;
const mountIt = (props = {}, locale = 'en') => {
    wrapper = mount(BatchUploader, {
        props: { personId: 'p1', batchId: 'b1', existing: 0, limits: { maxMb: 10, batchMaxFiles: 3 }, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, loadMessages(locale))] },
    });
    return wrapper;
};
beforeEach(() => { upload.mockReset(); refusal.mockReset(); refusal.mockReturnValue(false); });
afterEach(() => { vi.useRealTimers(); wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; vi.restoreAllMocks(); });

const file = (name, size = 100) => new File([new Uint8Array(size)], name, { type: 'application/octet-stream' });
async function pick(files) {
    const input = wrapper.find('[data-testid="batch-input"]');
    Object.defineProperty(input.element, 'files', { value: files, configurable: true });
    await input.trigger('change');
    await flushPromises();
}
const rows = () => wrapper.findAll('[data-testid="batch-queue"] li');

describe('batch uploader', () => {
    it('shows the limits in the hint', () => {
        mountIt();
        expect(wrapper.text()).toContain('Up to 3 files, 10 MB each');
    });

    it('uploads one file at a time with the batch id and tells the page after each success', async () => {
        const order = [];
        let release;
        upload.mockImplementationOnce(async (url, body) => { order.push(['start', body.get('file').name]); await new Promise((r) => { release = r; }); order.push(['end']); return { ok: true, status: 201, data: {} }; });
        upload.mockImplementationOnce(async (url, body) => { order.push(['start', body.get('file').name]); return { ok: true, status: 201, data: {} }; });
        mountIt();
        await pick([file('a.pdf'), file('b.pdf')]);
        expect(upload).toHaveBeenCalledTimes(1);
        const [url, body] = upload.mock.calls[0];
        expect(url).toBe('/health/people/p1/documents');
        expect(body.get('upload_batch_id')).toBe('b1');
        expect(body.get('file').name).toBe('a.pdf');
        release();
        await flushPromises();
        expect(order).toEqual([['start', 'a.pdf'], ['end'], ['start', 'b.pdf']]);
        expect(wrapper.emitted('uploaded')).toHaveLength(2);
        expect(rows().every((r) => r.attributes('data-status') === 'done')).toBe(true);
    });

    it('shows per-file progress', async () => {
        let report;
        let release;
        upload.mockImplementation(async (u, b, opts) => { report = opts.onProgress; await new Promise((r) => { release = r; }); return { ok: true, status: 201, data: {} }; });
        mountIt();
        await pick([file('a.pdf')]);
        report(0.5);
        await flushPromises();
        expect(wrapper.text()).toContain('Uploading 50%');
        release();
        await flushPromises();
    });

    it('accepts only the room that is left in the batch and says so', async () => {
        upload.mockResolvedValue({ ok: true, status: 201, data: {} });
        mountIt({ existing: 2 });
        await pick([file('a.pdf'), file('b.pdf')]);
        expect(upload).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).toContain('This batch already has 3 documents');
    });

    it('refuses a wrong extension and a too large file before sending', async () => {
        mountIt();
        await pick([file('a.exe'), file('big.pdf', 10 * 1048576 + 1)]);
        expect(upload).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('This file type is not supported');
        expect(wrapper.text()).toContain('larger than 10 MB');
    });

    it('maps the duplicate, full and closed refusals to their own text without a retry button', async () => {
        for (const [key, text] of [['health.batch.err_duplicate', 'This file is already in the batch'], ['health.batch.err_full', 'This batch already has 3 documents'], ['health.batch.err_closed', 'finished or no longer available']]) {
            upload.mockResolvedValueOnce({ ok: false, status: 422, error: 'validation', data: { errors: { file: [key] } } });
            mountIt();
            await pick([file('a.pdf')]);
            expect(wrapper.find('[role="alert"]').text()).toContain(text);
            expect(wrapper.find('[data-testid="batch-retry"]').exists()).toBe(false);
            wrapper.unmount();
        }
    });

    it('offers retry for a transfer failure and uploads again', async () => {
        upload.mockResolvedValueOnce({ ok: false, status: 500, error: 'failed', data: {} });
        upload.mockResolvedValueOnce({ ok: true, status: 201, data: {} });
        mountIt();
        await pick([file('a.pdf')]);
        await wrapper.find('[data-testid="batch-retry"]').trigger('click');
        await flushPromises();
        expect(upload).toHaveBeenCalledTimes(2);
        expect(rows()[0].attributes('data-status')).toBe('done');
    });

    it('waits 30 s after a 429 and retries the same file, at most 3 times', async () => {
        vi.useFakeTimers();
        upload.mockResolvedValue({ ok: false, status: 429, error: 'throttled', data: {} });
        mountIt();
        await pick([file('a.pdf')]);
        expect(upload).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).toContain('Waiting a moment before the next file');
        for (let i = 2; i <= 4; i += 1) {
            await vi.advanceTimersByTimeAsync(29000);
            expect(upload).toHaveBeenCalledTimes(i - 1);
            await vi.advanceTimersByTimeAsync(1000);
            await flushPromises();
            expect(upload).toHaveBeenCalledTimes(i);
            expect(new Set(upload.mock.calls.map((c) => c[1].get('file').name))).toEqual(new Set(['a.pdf']));
        }
        await vi.advanceTimersByTimeAsync(60000);
        expect(upload).toHaveBeenCalledTimes(4);
        expect(rows()[0].attributes('data-status')).toBe('failed');
    });

    it('leaves the page for the confirm screen on a gate refusal', async () => {
        refusal.mockReturnValue(true);
        upload.mockResolvedValue({ ok: false, status: 423, error: 'reauth_required', data: {} });
        mountIt();
        await pick([file('a.pdf')]);
        expect(refusal).toHaveBeenCalledWith('reauth_required', expect.any(Object));
        expect(upload).toHaveBeenCalledTimes(1);
    });

    it('never touches a browser storage API and drops the files on unmount', async () => {
        const spies = [
            vi.spyOn(Storage.prototype, 'setItem'),
            vi.spyOn(Storage.prototype, 'getItem'),
        ];
        const idb = globalThis.indexedDB ? vi.spyOn(globalThis.indexedDB, 'open') : null;
        upload.mockResolvedValue({ ok: true, status: 201, data: {} });
        mountIt();
        await pick([file('a.pdf')]);
        wrapper.unmount();
        for (const s of spies) expect(s).not.toHaveBeenCalled();
        if (idb) expect(idb).not.toHaveBeenCalled();
    });

    it('is Slovak and Polish and has no axe violations', async () => {
        for (const [locale, text] of [['sk', 'Najviac 3 súborov'], ['pl', 'Do 3 plików']]) {
            mountIt({}, locale);
            expect(wrapper.text()).toContain(text);
            wrapper.unmount();
        }
        mountIt();
        await pick([file('a.exe')]);
        await expectNoAxeViolations(document.body);
    });
});
