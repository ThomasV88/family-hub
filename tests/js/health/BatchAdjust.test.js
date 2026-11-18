import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { h } from 'vue';
import { readFileSync } from 'node:fs';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';

const { fetchMock, refusal, router } = vi.hoisted(() => ({
    fetchMock: vi.fn(),
    refusal: vi.fn(),
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn() },
}));
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: fetchMock }));
vi.mock('../../../resources/js/Health/healthRefusal.js', () => ({ handleHealthRefusal: refusal }));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'en' }, get url() { return '/health'; } }),
    router,
    Head: { props: ['title'], setup: () => () => null },
    Link: { props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) },
}));

import BatchReview from '../../../resources/js/Health/Batch/BatchReview.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const PID = '11111111-1111-4111-8111-111111111111';
const BID = '22222222-2222-4222-8222-222222222222';
const base = `/health/people/${PID}/upload-batches/${BID}/items/item-1`;
const $ = (sel) => document.querySelector(sel);

const current = (state = 'review', over = {}, itemOver = {}) => ({
    item: { id: 'item-1', state, title: 'Letter', kind: 'image', documentId: 'doc-1', rescanCount: 0, ...itemOver },
    document: { id: 'doc-1', kind: 'image', title: 'Letter', pageCount: null },
    review: state === 'review' ? { extraction: { sections: { visits: [{ key: 'visit-0', fields: { doctor: 'Dr Kova', visited_on: '2025-05-02', specialty_key: 'cardiology' } }], medications: [], allergies: [], vaccinations: [] } }, provenance: null, specialties: ['cardiology'] } : null,
    choices: {}, adjustments: { brightness: 100, contrast: 100, bw: false }, ...over,
});
const AI = { available: true, providers: ['codex'], noticeAcknowledged: true, textNotice: false };

let wrapper;
function mountReview(cur = current(), { ai = AI, limits = { maxRescans: 5 } } = {}) {
    wrapper = mount(BatchReview, { props: { personId: PID, batchId: BID, current: cur, limits, ai }, attachTo: document.body, global: { plugins: [createAppI18n('en', loadMessages('en'))] } });
    return wrapper;
}
const setRange = async (sel, value) => {
    const el = $(sel);
    el.value = String(value);
    el.dispatchEvent(new Event('input'));
    await flushPromises();
};
beforeEach(() => {
    vi.useFakeTimers();
    fetchMock.mockReset();
    fetchMock.mockResolvedValue({ ok: true, status: 200, data: {} });
    refusal.mockReset();
    useToast().toasts.splice(0);
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
});
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; vi.useRealTimers(); vi.restoreAllMocks(); });

describe('adjustments', () => {
    it('shows labelled ranges with the saved values, the switch, Reset and the note', async () => {
        mountReview(current('review', { adjustments: { brightness: 120, contrast: 80, bw: true } }));
        await flushPromises();
        const b = $('[data-testid="adjust-brightness"]');
        const c = $('[data-testid="adjust-contrast"]');
        expect([b.min, b.max, b.step, b.value]).toEqual(['50', '150', '5', '120']);
        expect([c.min, c.max, c.step, c.value]).toEqual(['50', '200', '5', '80']);
        expect(document.querySelector(`label[for="${b.id}"]`).textContent).toBe('Brightness');
        expect(document.querySelector(`label[for="${c.id}"]`).textContent).toBe('Contrast');
        expect($('[data-testid="adjust-brightness-value"]').textContent).toBe('120%');
        expect($('[data-testid="adjust-bw"]').checked).toBe(true);
        expect($('[data-testid="adjust-note"]').textContent).toBe('Only the copy sent to AI changes. Your stored document stays as it is.');
        expect($('[data-testid="document-image"]').getAttribute('style')).toContain('brightness(120%) contrast(80%) grayscale(1)');
    });

    it('starts from the identity filter and updates the preview at once', async () => {
        mountReview();
        await flushPromises();
        expect($('[data-testid="document-image"]').getAttribute('style')).toContain('brightness(100%) contrast(100%) grayscale(0)');
        await setRange('[data-testid="adjust-brightness"]', 130);
        expect($('[data-testid="document-image"]').getAttribute('style')).toContain('brightness(130%) contrast(100%) grayscale(0)');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('saves 500 ms after the last change, once, with the values', async () => {
        mountReview();
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: {} });
        await setRange('[data-testid="adjust-brightness"]', 110);
        vi.advanceTimersByTime(300);
        await setRange('[data-testid="adjust-contrast"]', 150);
        vi.advanceTimersByTime(499);
        expect(fetchMock).not.toHaveBeenCalled();
        vi.advanceTimersByTime(2);
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledWith(`${base}/adjustments`, expect.objectContaining({ method: 'PUT', body: { brightness: 110, contrast: 150, bw: false } }));
    });

    it('black and white switches grayscale in the preview and Reset returns to 100/100/off', async () => {
        mountReview();
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: {} });
        const sw = $('[data-testid="adjust-bw"]');
        sw.checked = true;
        sw.dispatchEvent(new Event('change'));
        await flushPromises();
        expect($('[data-testid="document-image"]').getAttribute('style')).toContain('grayscale(1)');
        await $('[data-testid="adjust-reset"]').click();
        await flushPromises();
        expect($('[data-testid="document-image"]').getAttribute('style')).toContain('brightness(100%) contrast(100%) grayscale(0)');
        vi.advanceTimersByTime(600);
        await flushPromises();
        expect(fetchMock.mock.calls.at(-1)[1].body).toEqual({ brightness: 100, contrast: 100, bw: false });
    });

    it('sends a change made right before leaving', async () => {
        const w = mountReview();
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: {} });
        await setRange('[data-testid="adjust-contrast"]', 60);
        w.unmount();
        wrapper = null;
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});

describe('re-scan', () => {
    it('is offered for every unfinished document, with the hint and the remaining count', () => {
        for (const state of ['review', 'processing', 'waiting', 'paused', 'failed', 'stopped']) {
            mountReview(current(state, {}, { rescanCount: 2 }));
            expect($('[data-testid="item-rescan"]')).not.toBeNull();
            expect($('[data-testid="item-rescan-hint"]').textContent).toBe('Sends the document to AI again with the settings below');
            expect($('[data-testid="item-rescan-left"]').textContent).toBe('Re-scans left: 3');
            wrapper.unmount();
        }
        mountReview(current('done'));
        expect($('[data-testid="item-rescan"]')).toBeNull();
    });

    it('posts the re-scan, tells it was queued and asks the page to refresh', async () => {
        const w = mountReview();
        fetchMock.mockResolvedValue({ ok: true, status: 202, data: { status: 'queued' } });
        await $('[data-testid="item-rescan"]').click();
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledWith(`${base}/rescan`, expect.objectContaining({ method: 'POST', body: {} }));
        expect(useToast().toasts.map((x) => x.message)).toEqual(['The document was sent to AI again']);
        expect(w.emitted('changed')).toHaveLength(1);
        expect($('[data-testid="item-rescan-left"]').textContent).toBe('Re-scans left: 4');
    });

    it('opens the AI notice first when it was not acknowledged and resends with acknowledged', async () => {
        mountReview(current(), { ai: { ...AI, noticeAcknowledged: false } });
        await $('[data-testid="item-rescan"]').click();
        await flushPromises();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(document.querySelector('[data-testid="ai-notice-confirm"]')).not.toBeNull();
        fetchMock.mockResolvedValue({ ok: true, status: 202, data: {} });
        await document.querySelector('[data-testid="ai-notice-confirm"]').click();
        await flushPromises();
        expect(fetchMock.mock.calls[0][1].body).toEqual({ acknowledged: true, providers: ['codex'] });
    });

    it('opens the notice when the server answers notice_required', async () => {
        mountReview();
        fetchMock.mockResolvedValueOnce({ ok: false, status: 409, data: { reason: 'notice_required', providers: ['codex', 'copilot'] } });
        await $('[data-testid="item-rescan"]').click();
        await flushPromises();
        expect(document.querySelector('[data-testid="ai-notice-confirm"]')).not.toBeNull();
        fetchMock.mockResolvedValue({ ok: true, status: 202, data: {} });
        await document.querySelector('[data-testid="ai-notice-confirm"]').click();
        await flushPromises();
        expect(fetchMock.mock.calls[1][1].body).toEqual({ acknowledged: true, providers: ['codex', 'copilot'] });
    });

    it('shows the limit text when the limit is reached, disables the button and handles a server limit answer', async () => {
        mountReview(current('review', {}, { rescanCount: 5 }));
        expect($('[data-testid="item-rescan"]').disabled).toBe(true);
        expect($('[data-testid="item-rescan-limit"]').textContent).toBe('This document was re-scanned 5 times, the most allowed');
        wrapper.unmount();
        mountReview(current('review', {}, { rescanCount: 1 }));
        fetchMock.mockResolvedValue({ ok: false, status: 409, data: { reason: 'limit' } });
        await $('[data-testid="item-rescan"]').click();
        await flushPromises();
        expect($('[data-testid="item-alert"]').textContent).toBe('This document was re-scanned 5 times, the most allowed');
        expect($('[data-testid="item-rescan"]').disabled).toBe(true);
    });
});

describe('failed document', () => {
    it('offers Try again, Keep document without findings and Remove from batch', () => {
        mountReview(current('failed', {}, { failureCode: 'unknown' }));
        expect($('[data-testid="item-failed"]').textContent).toContain('AI could not read this document');
        expect($('[data-testid="item-rescan"]').textContent).toContain('Try again');
        expect($('[data-testid="item-keep"]').textContent).toBe('Keep document without findings');
        expect($('[data-testid="item-remove"]').textContent).toContain('Remove from batch');
        expect($('[data-testid="batch-continue"]')).toBeNull();
    });

    it('keeps the document without findings through continue', async () => {
        const w = mountReview(current('failed'));
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: { result: 'continued', nextItemId: null, approved: 0, rejected: 0 } });
        await $('[data-testid="item-keep"]').click();
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledWith(`${base}/continue`, expect.objectContaining({ method: 'POST' }));
        expect(w.emitted('changed')).toHaveLength(1);
    });

    it('removes the document only after a confirmation', async () => {
        const w = mountReview(current('failed'));
        await $('[data-testid="item-remove"]').click();
        await flushPromises();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('Remove this document?');
        fetchMock.mockResolvedValue({ ok: true, status: 204, data: null });
        const confirm = [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Remove from batch' && b.closest('[role="dialog"], [role="alertdialog"]'));
        await confirm.click();
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledWith(base, expect.objectContaining({ method: 'DELETE' }));
        expect(w.emitted('changed')).toHaveLength(1);
    });
});

describe('quality', () => {
    it('is axe clean for review and failed documents', async () => {
        vi.useRealTimers();
        mountReview();
        await expectNoAxeViolations(document.body);
        wrapper.unmount();
        mountReview(current('failed'));
        await expectNoAxeViolations(document.body);
    });

    it('labels every control, sizes the ranges at 40 px and keeps browser storage out', () => {
        const panel = readFileSync('resources/js/Health/Batch/AdjustmentPanel.vue', 'utf8');
        expect(panel).toMatch(/min-height: 40px/);
        expect(panel).toMatch(/grayscale/);
        for (const f of ['AdjustmentPanel', 'BatchItemActions', 'BatchReview', 'BatchEntry']) {
            expect(readFileSync(`resources/js/Health/Batch/${f}.vue`, 'utf8')).not.toMatch(/v-html|localStorage|sessionStorage|indexedDB/);
        }
        expect(readFileSync('resources/js/Health/Ai/DocumentPane.vue', 'utf8')).toMatch(/imageFilter/);
    });
});
