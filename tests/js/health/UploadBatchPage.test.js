import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { h, reactive } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

let mockProps = {};
const { fetchMock, refusal, router } = vi.hoisted(() => ({
    fetchMock: vi.fn(),
    refusal: vi.fn(),
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn(() => vi.fn()) },
}));
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: fetchMock }));
vi.mock('../../../resources/js/Security/securityTransfer.js', () => ({ securityUpload: vi.fn(), securityDownload: vi.fn() }));
vi.mock('../../../resources/js/Health/healthRefusal.js', () => ({ handleHealthRefusal: refusal }));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps, get url() { return '/health'; } }),
    router,
    Head: { props: ['title'], setup: () => () => null },
    Link: { props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) },
}));

import UploadBatch from '../../../resources/js/Pages/Health/UploadBatch.vue';

const PID = '11111111-1111-4111-8111-111111111111';
const BID = '22222222-2222-4222-8222-222222222222';
const it_ = (n, state, over = {}) => ({ id: `item-${n}`, position: n, documentId: `doc-${n}`, title: `Document ${n}`, kind: 'pdf', state, rescanCount: 0, duplicateOf: null, ...over });
const ITEMS = [
    it_(1, 'done'),
    it_(2, 'review', { pending: 3 }),
    it_(3, 'processing'),
    it_(4, 'waiting'),
    it_(5, 'paused', { availableAt: '2099-01-01T10:30:00+00:00' }),
    it_(6, 'failed', { failureCode: 'unknown' }),
    it_(7, 'stopped'),
    it_(8, 'consent_off'),
    it_(9, 'done', { duplicateOf: 'doc-1' }),
];
const batchOf = (items, over = {}) => ({
    id: BID, status: 'open', expiresAt: '2026-12-03T10:00:00+00:00', currentItemId: 'item-2',
    counts: { total: items.length, done: items.filter((i) => i.state === 'done').length, review: 0, processing: 0, failed: 0 }, ...over,
});
const baseProps = (over = {}) => ({
    batch: batchOf(ITEMS), items: ITEMS, current: { item: ITEMS[1], document: { id: 'doc-2', kind: 'pdf', title: 'Document 2', pageCount: 1 }, review: null, choices: {}, adjustments: {} },
    limits: { maxFiles: 50, maxMb: 10, maxRescans: 5 }, ai: { available: true, providers: ['codex'], noticeAcknowledged: true, textNotice: false },
    person: { id: PID, name: 'Ada Novak', aiConsent: true }, ...over,
});
const statusUrl = `/health/people/${PID}/upload-batches/${BID}/status`;
const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => [...document.querySelectorAll(sel)];

let wrapper;
function mountPage(over = {}, { locale = 'en', width = 1280 } = {}) {
    window.innerWidth = width;
    mockProps = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: 'none', health: 'edit' } },
        app: { env: 'local', styleGuide: false },
    });
    wrapper = mount(UploadBatch, { props: baseProps(over), attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return wrapper;
}
function setHidden(value) {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => value });
    document.dispatchEvent(new Event('visibilitychange'));
}
beforeEach(() => {
    fetchMock.mockReset();
    refusal.mockReset();
    router.reload.mockReset();
    router.visit.mockReset();
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
});
afterEach(() => { wrapper?.unmount(); wrapper = null; setHidden(false); vi.useRealTimers(); document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); vi.restoreAllMocks(); });

const idleItems = [it_(1, 'done'), it_(2, 'review', { pending: 1 })];
const idle = () => baseProps({ items: idleItems, batch: batchOf(idleItems) });

describe('upload batch page: header and list', () => {
    it('shows the person, the progress, the localised expiry date and every document', () => {
        mountPage();
        expect($('[data-testid="batch-progress"]').textContent).toContain('2 of 9 done');
        expect($('[role="progressbar"]').getAttribute('aria-valuenow')).toBe('22');
        expect($('[data-testid="batch-expires"]').textContent).toMatch(/Unfinished documents are deleted on 3 Dec 2026/);
        expect(document.body.textContent).toContain('Ada Novak');
        expect($$('[data-testid="batch-item"]')).toHaveLength(9);
    });

    it('labels each status: waiting, reading, paused with a time, findings count, failed, stopped, consent off, done, already stored', () => {
        mountPage();
        const states = Object.fromEntries($$('[data-testid="batch-item"]').map((b) => [b.dataset.state + (b.querySelector('[data-testid="batch-item-duplicate"]') ? '+dup' : ''), b.querySelector('[data-testid="batch-item-state"]').textContent.trim()]));
        expect(states.waiting).toBe('Waiting');
        expect(states.processing).toBe('AI is reading');
        expect(states.paused).toMatch(/^Paused until .+, continues by itself$/);
        expect(states.review).toBe('3 findings to review');
        expect(states.failed).toBe('Failed');
        expect(states.stopped).toBe('Stopped');
        expect(states.consent_off).toBe('AI is turned off for this person');
        expect(states.done).toBe('Done');
        expect($('[data-testid="batch-item-duplicate"]').textContent).toBe('You already have this document');
    });

    it('uses the singular for one finding and the Slovak and Polish plural forms', () => {
        mountPage(idle());
        expect($('[data-state="review"]').textContent).toContain('1 finding to review');
        wrapper.unmount();
        mountPage(idle(), { locale: 'sk' });
        expect($('[data-state="review"]').textContent).toContain('1 nález na kontrolu');
        wrapper.unmount();
        mountPage(idle(), { locale: 'pl' });
        expect($('[data-state="review"]').textContent).toContain('1 wynik do sprawdzenia');
    });

    it('marks the current document with aria-current and shows its title in the review region', () => {
        mountPage();
        const current = $$('[aria-current="true"]');
        expect(current).toHaveLength(1);
        expect(current[0].textContent).toContain('Document 2');
        expect($('[data-testid="batch-review"]').textContent).toContain('Document 2');
    });

    it('shows the uploader only while the batch has room', () => {
        mountPage();
        expect($('[data-testid="batch-uploader"]')).not.toBeNull();
        wrapper.unmount();
        const full = Array.from({ length: 3 }, (_, i) => it_(i + 1, 'review', { pending: 1 }));
        mountPage({ items: full, batch: batchOf(full), limits: { maxFiles: 3, maxMb: 10, maxRescans: 5 } });
        expect($('[data-testid="batch-uploader"]')).toBeNull();
    });

    it('shows the empty state without documents and the finished line when all are done', () => {
        mountPage({ items: [], batch: batchOf([], { currentItemId: null }), current: null });
        expect($('[data-testid="batch-empty"]').textContent).toContain('Add files to start');
        wrapper.unmount();
        const done = [it_(1, 'done'), it_(2, 'done')];
        mountPage({ items: done, batch: batchOf(done, { currentItemId: null }), current: null });
        expect($('[data-testid="batch-finished"]').textContent).toBe('All documents are done');
    });

    it('is the same after a remount with the same props (reload, other device)', () => {
        mountPage();
        const first = $('[data-testid="batch-side"]').innerHTML;
        wrapper.unmount();
        mountPage();
        expect($('[data-testid="batch-side"]').innerHTML).toBe(first);
        expect($('[aria-current="true"]').textContent).toContain('Document 2');
    });

    it('collapses the list into a disclosure on a narrow screen', () => {
        mountPage({}, { width: 360 });
        expect($('[data-testid="batch-disclosure"]')).not.toBeNull();
        expect($('[data-testid="batch-disclosure"] summary').textContent).toContain('Documents (9)');
        wrapper.unmount();
        mountPage({}, { width: 1280 });
        expect($('[data-testid="batch-disclosure"]')).toBeNull();
    });

    it('has no axe violations on a wide and on a narrow screen', async () => {
        mountPage();
        await expectNoAxeViolations(document.body);
        wrapper.unmount();
        mountPage({}, { width: 360 });
        await expectNoAxeViolations(document.body);
    });
});

describe('upload batch page: choosing, deleting', () => {
    it('saves the cursor on the server and then reloads only the page data', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 204, data: {} });
        mountPage();
        $$('[data-testid="batch-item"]')[5].click();
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledWith(`/health/people/${PID}/upload-batches/${BID}/current`, expect.objectContaining({ method: 'PUT', body: { itemId: 'item-6' } }));
        expect(router.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['batch', 'items', 'current'] }));
        expect($('[aria-current="true"]').textContent).toContain('Document 6');
    });

    it('asks before deleting the batch, sends DELETE and goes back to the person', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: { removed: 4 } });
        mountPage();
        $$('[aria-haspopup="menu"]').find((b) => b.closest('[data-testid="upload-batch-page"]')).click();
        await flushPromises();
        [...document.querySelectorAll('[role="menuitem"]')].find((e) => e.textContent.includes('Delete batch')).click();
        await flushPromises();
        expect(document.body.textContent).toContain('Documents you have not finished are deleted. Finished documents and saved records stay.');
        expect(fetchMock).not.toHaveBeenCalled();
        [...document.querySelectorAll('button')].filter((b) => b.textContent.trim() === 'Delete batch').pop().click();
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledWith(`/health/people/${PID}/upload-batches/${BID}`, expect.objectContaining({ method: 'DELETE' }));
        expect(router.visit).toHaveBeenCalledWith(`/health/people/${PID}`);
    });
});

describe('upload batch page: live status', () => {
    const answer = (items) => ({ ok: true, status: 200, data: { batch: batchOf(items, { currentItemId: 'item-1' }), items } });
    beforeEach(() => { vi.useFakeTimers(); });

    it('polls every 5 s while a document waits or is read, updates in place and stops when nothing is active', async () => {
        const busy = [it_(1, 'processing'), it_(2, 'waiting')];
        const next = [it_(1, 'review', { pending: 2 }), it_(2, 'processing')];
        const last = [it_(1, 'review', { pending: 2 }), it_(2, 'review', { pending: 1 })];
        fetchMock.mockResolvedValueOnce(answer(next)).mockResolvedValueOnce(answer(last));
        mountPage({ items: busy, batch: batchOf(busy, { currentItemId: 'item-1' }), current: null });
        await vi.advanceTimersByTimeAsync(4900);
        expect(fetchMock).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(200);
        expect(fetchMock).toHaveBeenCalledWith(statusUrl, expect.any(Object));
        expect($$('[data-testid="batch-item-state"]').map((e) => e.textContent.trim())).toEqual(['2 findings to review', 'AI is reading']);
        await vi.advanceTimersByTimeAsync(5000);
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect($$('[data-testid="batch-item-state"]').map((e) => e.textContent.trim())).toEqual(['2 findings to review', '1 finding to review']);
        await vi.advanceTimersByTimeAsync(120000);
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('polls every 60 s while the only active document is paused for quota', async () => {
        const paused = [it_(1, 'paused', { availableAt: '2099-01-01T10:30:00+00:00' })];
        fetchMock.mockResolvedValue(answer(paused));
        mountPage({ items: paused, batch: batchOf(paused, { currentItemId: 'item-1' }), current: null });
        await vi.advanceTimersByTimeAsync(59000);
        expect(fetchMock).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(2000);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(60000);
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('does not poll when nothing is active', async () => {
        mountPage(idle());
        await vi.advanceTimersByTimeAsync(300000);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('stops while the tab is hidden and checks at once when it is visible again', async () => {
        const busy = [it_(1, 'processing')];
        fetchMock.mockResolvedValue(answer(busy));
        mountPage({ items: busy, batch: batchOf(busy, { currentItemId: 'item-1' }), current: null });
        setHidden(true);
        await vi.advanceTimersByTimeAsync(30000);
        expect(fetchMock).not.toHaveBeenCalled();
        setHidden(false);
        await vi.advanceTimersByTimeAsync(0);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('hands a gate refusal to the shared handler and never touches browser storage', async () => {
        const stores = [vi.spyOn(Storage.prototype, 'setItem'), vi.spyOn(Storage.prototype, 'getItem')];
        refusal.mockReturnValue(true);
        fetchMock.mockResolvedValue({ ok: false, status: 423, error: 'reauth_required', data: {} });
        const busy = [it_(1, 'processing')];
        mountPage({ items: busy, batch: batchOf(busy, { currentItemId: 'item-1' }), current: null });
        await vi.advanceTimersByTimeAsync(5100);
        expect(refusal).toHaveBeenCalledWith('reauth_required');
        await vi.advanceTimersByTimeAsync(60000);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(stores[0]).not.toHaveBeenCalled();
        // The shell reads its own theme and sidebar keys; nothing of the batch is read from or written to storage.
        expect(stores[1].mock.calls.map((c) => c[0]).filter((k) => /batch|item|document/i.test(k))).toEqual([]);
    });

    it('asks the server for fresh page data when the polled state of the current document changes', async () => {
        const before = [it_(1, 'processing')];
        const after = [it_(1, 'review', { pending: 1 })];
        fetchMock.mockResolvedValue(answer(after));
        mountPage({ items: before, batch: batchOf(before, { currentItemId: 'item-1' }), current: { item: before[0], document: { id: 'doc-1', kind: 'pdf', title: 'Document 1', pageCount: null }, review: null, choices: {}, adjustments: {} } });
        await vi.advanceTimersByTimeAsync(5100);
        expect(router.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['batch', 'items', 'current'] }));
    });

    const docOf = (n) => ({ id: `doc-${n}`, kind: 'pdf', title: `Document ${n}`, pageCount: null });

    it('asks for fresh page data when the first document of an empty batch becomes current (no current props yet)', async () => {
        const before = [it_(1, 'processing')];
        const after = [it_(1, 'review', { pending: 2 })];
        fetchMock.mockResolvedValue(answer(after));
        mountPage({ items: before, batch: batchOf(before, { currentItemId: 'item-1' }), current: null });
        await vi.advanceTimersByTimeAsync(5100);
        expect(router.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['batch', 'items', 'current'] }));
    });

    it('asks for fresh page data when the current document is another one than the rendered props', async () => {
        const items = [it_(1, 'done'), it_(2, 'processing')];
        const after = [it_(1, 'done'), it_(2, 'review', { pending: 1 })];
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: { batch: batchOf(after, { currentItemId: 'item-2' }), items: after } });
        mountPage({ items, batch: batchOf(items, { currentItemId: 'item-2' }), current: { item: items[0], document: docOf(1), review: null, choices: {}, adjustments: {} } });
        await vi.advanceTimersByTimeAsync(5100);
        expect(router.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['batch', 'items', 'current'] }));
    });

    it('shows the review once the page props follow the poll', async () => {
        const before = [it_(1, 'processing')];
        const after = [it_(1, 'review', { pending: 2 })];
        fetchMock.mockResolvedValue(answer(after));
        mountPage({ items: before, batch: batchOf(before, { currentItemId: 'item-1' }), current: null });
        await vi.advanceTimersByTimeAsync(5100);
        expect(router.reload).toHaveBeenCalledTimes(1);
        await wrapper.setProps({ items: after, batch: batchOf(after, { currentItemId: 'item-1' }), current: { item: after[0], document: docOf(1), review: null, choices: {}, adjustments: {} } });
        expect($('[data-testid="batch-current"]')).toBeNull();
    });

    it('stops polling and ignores a late 403 once Delete batch was requested', async () => {
        const busy = [it_(1, 'processing')];
        let late;
        fetchMock.mockImplementation((url, opts) => {
            if (opts?.method === 'DELETE') return Promise.resolve({ ok: true, status: 200, data: {} });
            return new Promise((resolve) => { late = resolve; });
        });
        mountPage({ items: busy, batch: batchOf(busy, { currentItemId: 'item-1' }), current: { item: busy[0], document: docOf(1), review: null, choices: {}, adjustments: {} } });
        await vi.advanceTimersByTimeAsync(5100);
        $$('[aria-haspopup="menu"]').find((b) => b.closest('[data-testid="upload-batch-page"]')).click();
        await flushPromises();
        [...document.querySelectorAll('[role="menuitem"]')].find((e) => e.textContent.includes('Delete batch')).click();
        await flushPromises();
        [...document.querySelectorAll('button')].filter((b) => b.textContent.trim() === 'Delete batch').pop().click();
        await flushPromises();
        late({ ok: false, status: 403, error: 'forbidden', data: {} });
        await vi.advanceTimersByTimeAsync(70000);
        const polls = fetchMock.mock.calls.filter((c) => String(c[0]).endsWith('/status'));
        expect(polls).toHaveLength(1);
        expect($('[data-testid="batch-poll-failed"]')).toBeNull();
    });
});
