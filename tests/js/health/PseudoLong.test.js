import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { h, nextTick, ref } from 'vue';
import { readFileSync, readdirSync } from 'node:fs';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';

/*
 * Phase 13 layout check (UI-SPEC "long strings"): every Health string of the recognised-text screens is at least 40 percent
 * longer (sk and pl, phone width) and must still be present whole in the page. jsdom has no layout, so "whole" is asserted as:
 * the padded string is in the DOM text, no element carries a truncate, ellipsis or line-clamp class, no button is
 * nowrap, and a source scan proves the only line clamp of these screens is the three-line snippet the UI-SPEC allows.
 */
let mockProps = {};
const { fetchMock } = vi.hoisted(() => ({ fetchMock: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps, get url() { return '/health'; } }),
    router: { get: vi.fn(), post: vi.fn(), reload: vi.fn(), visit: vi.fn(), on: vi.fn(() => vi.fn()) },
    Head: { props: ['title'], setup: () => () => null },
    Link: { props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) },
}));
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: fetchMock }));

import DocumentSearch from '../../../resources/js/Health/Search/DocumentSearch.vue';
import RecognisedText from '../../../resources/js/Health/Text/RecognisedText.vue';
import RunAiControl from '../../../resources/js/Health/Ai/RunAiControl.vue';
import AiNoticeDialog from '../../../resources/js/Health/Ai/AiNoticeDialog.vue';
import AiConsentControl from '../../../resources/js/Health/AiConsentControl.vue';
import BulkRegenPanel from '../../../resources/js/Settings/AiAgents/BulkRegenPanel.vue';
import UploadBatch from '../../../resources/js/Pages/Health/UploadBatch.vue';
import BatchUploader from '../../../resources/js/Health/Batch/BatchUploader.vue';
import BatchReview from '../../../resources/js/Health/Batch/BatchReview.vue';
import BatchItemActions from '../../../resources/js/Health/Batch/BatchItemActions.vue';
import AdjustmentPanel from '../../../resources/js/Health/Batch/AdjustmentPanel.vue';
import OpenBatchBanner from '../../../resources/js/Health/Batch/OpenBatchBanner.vue';

const ID = '11111111-1111-4111-8111-111111111111';
const PERSON = { id: ID, name: 'Ada Novak', canEdit: true };
const DOC = { id: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeee1', kind: 'pdf', title: 'Lab result', visitId: null, uploadedOn: '2025-05-02', sizeBytes: 2048, hasText: true };
const HIT = {
    documentId: 'bbbbbbbb-bbbb-4bbb-8bbb-000000000001', personId: ID, personName: 'Ada Novak', title: 'Blood test with a rather long document title', uploadedOn: '2026-03-04',
    visitId: 'v1', visitName: 'Dr Heart', visitOn: '2026-03-01', visitSpecialty: 'cardiology', page: 2,
    snippets: [[{ text: 'Hemoglobin ', hit: false }, { text: 'result', hit: true }, { text: ' within range', hit: false }]],
    textComplete: false, pagesRead: 3, pagesTotal: 8,
};

let current;
function mountLong(component, props, locale, width = 360) {
    window.innerWidth = width;
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: true }, access: { health: 'edit' } },
        app: { env: 'local', styleGuide: false },
    };
    const messages = pseudoLong(loadMessages(locale));
    current = mount(component, { props, attachTo: document.body, global: { plugins: [createAppI18n(locale, messages)] } });
    return { wrapper: current, messages };
}
const $ = (sel) => document.querySelector(sel);
const text = (sel) => $(sel)?.textContent.replace(/\s+/g, ' ').trim() ?? '';
const clamped = () => [...document.body.querySelectorAll('[class*="truncate"], [class*="ellipsis"], [class*="line-clamp"]')].map((n) => n.textContent.trim()).filter(Boolean);
const noWrapButtons = () => [...document.body.querySelectorAll('button')].filter((b) => /whitespace-nowrap/.test(b.className));
const settle = async () => { for (let i = 0; i < 8; i += 1) { await Promise.resolve(); await nextTick(); } };
const get = (obj, path) => path.split('.').reduce((o, k) => o?.[k], obj);

beforeEach(() => { fetchMock.mockReset(); });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; });

const makePoller = (statuses) => ({
    statuses: ref(statuses), available: ref(null), failedPolls: ref(0), stoppedLongWait: ref(false), refresh: vi.fn(async () => {}), markQueued: vi.fn(),
});
const aiStatus = (status, extra = {}) => ({ status, extractionId: 'x1', readyExtractionId: null, cancelReason: null, pagesRead: null, pagesTotal: null, entryCount: null, pending: 0, ...extra });

describe.each(['sk', 'pl'])('Phase 13 Health screens with pseudo-long text (%s)', (locale) => {
    it('keeps the search results whole: title, person and visit tags, snippet, partial note, uncertain alert and Load more', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: { items: [HIT, { ...HIT, documentId: 'bbbbbbbb-bbbb-4bbb-8bbb-000000000002', title: 'Second document', snippets: [], textComplete: true, visitId: null, page: null }], hasMore: true, page: 1 } });
        const { messages } = mountLong(DocumentSearch, { person: PERSON, peopleCount: 2, reliability: 'uncertain' }, locale);
        const input = $('[data-testid="search-input"]');
        input.value = 'result';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        await nextTick();
        $('[data-testid="doc-search-form"]').dispatchEvent(new Event('submit', { cancelable: true }));
        await settle();

        const rows = [...document.querySelectorAll('[data-testid="search-row"]')];
        expect(rows).toHaveLength(2);
        expect(rows[0].textContent).toContain(HIT.title);
        expect(rows[0].textContent).toContain('Ada Novak');
        expect(rows[0].textContent).toContain('Dr Heart');
        expect(rows[0].textContent).toContain('Hemoglobin result within range');
        expect(text('[data-testid="search-partial"]')).toContain('3');
        expect(text('[data-testid="search-uncertain"]')).toContain(get(messages, 'health.search.uncertain').replace(/\s+/g, ' ').trim());
        expect(text('[data-testid="search-more"]')).toBe(get(messages, 'health.search.load_more'));
        expect(noWrapButtons()).toHaveLength(0);
        expect(clamped()).toEqual([]);
    });

    it('keeps the recognised text header whole with both tags, the info line, the partial alert and the actions row', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: { pages: [{ page: 1, text: 'First page text' }, { page: 2, text: '' }], pagesRead: 4, pagesTotal: 9, complete: false, language: 'sk' } });
        const { messages } = mountLong(RecognisedText, { personId: ID, document: DOC, canEdit: true, reliability: 'uncertain', runActive: false }, locale);
        $('[data-testid="rtext-toggle"]').click();
        await settle();

        expect(text('[data-testid="rtext-tag-errors"]')).toBe(get(messages, 'health.document_text.tag_errors'));
        expect(text('[data-testid="rtext-tag-partial"]')).toContain('4');
        expect(text('[data-testid="rtext-tag-partial"]')).toContain('9');
        expect(text('[data-testid="rtext-info"]')).toBe(get(messages, 'health.document_text.info'));
        expect(text('[data-testid="rtext-partial-alert"]')).toContain('4');
        expect(text('[data-testid="rtext-copy"]')).toBe(get(messages, 'health.document_text.copy'));
        expect(text('[data-testid="rtext-remove"]')).toBe(get(messages, 'health.document_text.remove'));
        expect(noWrapButtons()).toHaveLength(0);
        expect(clamped()).toEqual([]);
    });

    const CASES = [
        ['text_saved', { status: 'text_saved', mode: 'text', textSaved: true, pagesRead: 4, pagesTotal: 9 }, 'health.ai.status.text_saved_detail', null],
        ['running with the text saved', { status: 'running', textSaved: true }, 'health.ai.status.text_saved_preparing', null],
        ['waiting with the text saved', { status: 'waiting', textSaved: true, availableAt: new Date(Date.now() + 3 * 3600 * 1000).toISOString() }, null, 'health.ai.status.waiting_text_saved'],
        ['failed with the text saved', { status: 'failed', textSaved: true }, 'health.ai.status.failed_suggestions_detail', null],
        ['cancelled after the text was saved', { status: 'cancelled', textSaved: true }, 'health.ai.status.cancelled_suggestions_detail', null],
    ];
    it.each(CASES)('keeps the run detail lines whole: %s', async (_name, entry, detailKey, extraKey) => {
        const { messages } = mountLong(RunAiControl, {
            personId: ID, document: DOC, consentOn: true,
            ai: { canRun: true, available: true, noticeAcknowledged: true, textNotice: true, providers: ['codex'] },
            poller: makePoller({ [DOC.id]: aiStatus(entry.status, entry) }),
        }, locale);
        await settle();
        if (detailKey) expect(text('[data-testid="ai-detail"]')).toContain(get(messages, detailKey));
        if (extraKey) expect(text('[data-testid="ai-detail-text"]')).toBe(get(messages, extraKey));
        // the provider line belongs to the idle controls (no run active)
        if (!['running', 'waiting'].includes(entry.status)) expect(text('[data-testid="ai-sends"]')).toBe(get(messages, 'health.ai.run.provider_text_codex'));
        expect(noWrapButtons()).toHaveLength(0);
        expect(clamped()).toEqual([]);
    });

    it.each([['codex', 'health.ai.run.notice_text_body'], ['multi', 'health.ai.notice.multi_text_body'], ['github', 'health.ai.notice.github_text_body']])('keeps the notice dialog text variant whole: %s', async (variant, bodyKey) => {
        const { messages } = mountLong(AiNoticeDialog, { open: true, variant, text: true }, locale);
        await settle();
        const dialog = $('[role="dialog"]');
        expect(dialog.textContent.replace(/\s+/g, ' ')).toContain(get(messages, bodyKey).replace(/\s+/g, ' ').trim());
        expect(noWrapButtons()).toHaveLength(0);
        expect(clamped()).toEqual([]);
    });

    it('keeps the consent confirmation with the purge sentence whole', async () => {
        const { messages } = mountLong(AiConsentControl, { personId: ID, value: true, canEdit: true, purgesText: true }, locale);
        $('[data-testid="ai-switch"]').click();
        await settle();
        expect(text('[data-testid="ai-off-purge"]')).toBe(get(messages, 'health.ai.off_text_purge'));
        expect(text('[data-testid="ai-keep-on"]')).toBe(get(messages, 'health.ai.off_keep'));
        expect(text('[data-testid="ai-turn-off"]')).toBe(get(messages, 'health.ai.off_confirm'));
        expect(noWrapButtons()).toHaveLength(0);
        expect(clamped()).toEqual([]);
    });

    it('keeps the bulk panel no_text scope, its hint and its note whole', async () => {
        const { messages } = mountLong(BulkRegenPanel, {
            bulk: { active: null, history: [], ocrEnabled: true, textNoticeAcknowledged: true },
            bulkConfig: { defaultCap: 20, maxCap: 30, startHour: 2, timezone: 'Europe/Bratislava', validMinutes: 15 },
            providers: ['codex'], agents: [{ key: 'codex', enabled: true, position: 1, health: { state: 'logged_in' } }],
        }, locale);
        await settle();
        const radio = [...document.querySelectorAll('[role="radio"]')].find((r) => r.getAttribute('data-scope') === 'no_text');
        expect(radio.textContent).toContain(get(messages, 'settings.ai_bulk.scope_no_text'));
        expect(radio.textContent).toContain(get(messages, 'settings.ai_bulk.scope_no_text_hint'));
        radio.click();
        await settle();
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: { token: '11111111-1111-4111-8111-111111111111', checkedAt: '2026-11-14T10:00:00+00:00', validMinutes: 15, counts: { eligible: 18, skipped_consent: 2, skipped_unsupported: 3, skipped_decided: 0, skipped_active: 1 } } });
        document.querySelector('[data-control="check"]').click();
        await flushPromises();
        await settle();
        expect(text('[data-testid="bulk-no-text-note"]')).toBe(get(messages, 'settings.ai_bulk.no_text_note'));
        expect(noWrapButtons()).toHaveLength(0);
        expect(clamped()).toEqual([]);
    });
});

describe('Phase 13 long-string sources', () => {
    const dirs = ['resources/js/Health/Search', 'resources/js/Health/Text', 'resources/js/Health/Ai'];
    const files = dirs.flatMap((d) => readdirSync(d).filter((f) => f.endsWith('.vue')).map((f) => `${d}/${f}`));

    it('has exactly one line clamp, the three-line snippet, and no ellipsis or nowrap anywhere on these screens', () => {
        const clamps = [];
        for (const file of files) {
            const src = readFileSync(file, 'utf8');
            if (/line-clamp/.test(src)) clamps.push(file);
            expect(src, file).not.toMatch(/text-overflow:\s*ellipsis|white-space:\s*nowrap|whitespace-nowrap|\btruncate\b/);
        }
        expect(clamps).toEqual(['resources/js/Health/Search/SearchSnippet.vue']);
        expect(readFileSync('resources/js/Health/Search/SearchSnippet.vue', 'utf8')).toMatch(/line-clamp:\s*3/);
    });
});

// 15-09: the upload batch screens (Phase 15) with +40 percent strings at phone width.
const BID = '22222222-2222-4222-8222-222222222222';
const bItem = (n, state, over = {}) => ({ id: `item-${n}`, position: n, documentId: `doc-${n}`, title: `Document ${n} with a rather long title`, kind: 'pdf', state, rescanCount: 0, duplicateOf: null, ...over });
const B_ITEMS = [
    bItem(1, 'done'), bItem(2, 'review', { pending: 3 }), bItem(3, 'processing'), bItem(4, 'waiting'),
    bItem(5, 'paused', { availableAt: '2099-01-01T10:30:00+00:00' }), bItem(6, 'failed', { failureCode: 'unknown' }),
    bItem(7, 'stopped'), bItem(8, 'consent_off'), bItem(9, 'done', { duplicateOf: 'doc-1' }),
];
const B_AI = { available: true, providers: ['codex'], noticeAcknowledged: true, textNotice: false };
const bCurrent = (item, over = {}) => ({
    item, document: { id: item.documentId, kind: 'pdf', title: item.title, pageCount: 1 },
    review: item.state === 'review'
        ? { extraction: { sections: { visits: [{ key: 'visit-0', fields: { doctor: 'Dr Kova', visited_on: '2025-05-02', specialty_key: 'cardiology', diagnosis: 'Mild murmur' } }], medications: [{ key: 'medication-0', fields: { name: 'Ibuprofen', dose: '200 mg' } }], allergies: [], vaccinations: [] } }, provenance: null, specialties: ['cardiology'] }
        : null,
    choices: {}, adjustments: { brightness: 100, contrast: 100, bw: false }, ...over,
});

describe.each(['sk', 'pl'])('Phase 15 upload batch screens with pseudo-long text (%s)', (locale) => {
    it('keeps the batch page header, progress, expiry, every item title and status tag whole', async () => {
        const { messages } = mountLong(UploadBatch, {
            batch: { id: BID, status: 'open', expiresAt: '2026-12-03T10:00:00+00:00', currentItemId: 'item-2', counts: { total: 9, done: 2, review: 1, processing: 4, failed: 2 } },
            items: B_ITEMS, current: bCurrent(B_ITEMS[1]), limits: { maxFiles: 50, maxMb: 10, maxRescans: 5 }, ai: B_AI, person: { id: ID, name: 'Ada Novak', aiConsent: true },
        }, locale);
        await settle();
        const rows = [...document.querySelectorAll('[data-testid="batch-item"]')];
        expect(rows).toHaveLength(9);
        B_ITEMS.forEach((item, i) => expect(rows[i].textContent).toContain(item.title));
        const tags = [...document.querySelectorAll('[data-testid="batch-item-state"]')].map((n) => n.textContent.trim());
        expect(tags).toHaveLength(9);
        for (const tag of tags) expect(tag).toContain('ŵ');
        expect(text('[data-testid="batch-expires"]').length).toBeGreaterThan(20);
        expect(text('[data-testid="batch-progress"]')).toContain('2');
        expect(text('[data-testid="batch-item-duplicate"]')).toBe(get(messages, 'health.batch.already_stored').replace(/\s+/g, ' ').trim());
        expect(noWrapButtons()).toHaveLength(0);
        expect(clamped()).toEqual([]);
    });

    it('keeps the uploader hint and the refusal reason of a file whole', async () => {
        const { messages } = mountLong(BatchUploader, { personId: ID, batchId: BID, existing: 3, limits: { maxMb: 10, batchMaxFiles: 50 } }, locale);
        const input = $('[data-testid="batch-input"]');
        Object.defineProperty(input, 'files', { value: [new File([new Uint8Array(10)], 'virus.exe')], configurable: true });
        input.dispatchEvent(new Event('change', { bubbles: true }));
        await settle();
        const row = $('[data-testid="batch-queue"] [data-status="failed"]');
        expect(row.textContent).toContain('virus.exe');
        expect(row.textContent.replace(/\s+/g, ' ').length).toBeGreaterThan('virus.exe'.length + 20);
        expect(noWrapButtons()).toHaveLength(0);
        expect(clamped()).toEqual([]);
        expect(messages.health.batch).toBeTruthy();
    });

    it('keeps the entry cards and the Continue bar with all three counts whole', async () => {
        const { messages } = mountLong(BatchReview, {
            personId: ID, batchId: BID, limits: { maxRescans: 5 }, ai: B_AI,
            current: bCurrent(bItem(2, 'review'), { choices: { 'visit-0': { decision: 'approve' }, 'medication-0': { decision: 'reject' } } }),
        }, locale);
        await settle();
        expect(document.querySelectorAll('[data-testid="batch-entry"]')).toHaveLength(2);
        const counts = text('[data-testid="batch-counts"]');
        expect(counts).toContain(get(messages, 'health.batch.approved'));
        expect(counts).toContain(get(messages, 'health.batch.rejected'));
        expect(text('[data-testid="batch-continue"]')).toBe(get(messages, 'health.batch.continue').replace(/\s+/g, ' ').trim());
        expect(text('[data-testid="batch-continue-bar"]')).toContain(get(messages, 'health.batch.continue_hint').replace(/\s+/g, ' ').trim());
        expect(noWrapButtons()).toHaveLength(0);
        expect(clamped()).toEqual([]);
    });

    it('keeps the adjustment labels and the note whole', async () => {
        const { messages } = mountLong(AdjustmentPanel, { personId: ID, batchId: BID, itemId: 'item-2', adjustments: { brightness: 120, contrast: 140, bw: true } }, locale);
        await settle();
        expect(text('[data-testid="adjust-note"]')).toBe(get(messages, 'health.batch.adjust_note').replace(/\s+/g, ' ').trim());
        expect(text('[data-testid="adjust-panel"]')).toContain(get(messages, 'health.batch.black_white'));
        expect(text('[data-testid="adjust-reset"]')).toBe(get(messages, 'health.batch.adjust_reset').replace(/\s+/g, ' ').trim());
        expect(noWrapButtons()).toHaveLength(0);
        expect(clamped()).toEqual([]);
    });

    it('keeps the failed-document actions whole and wrapping', async () => {
        const { messages } = mountLong(BatchItemActions, { personId: ID, batchId: BID, item: bItem(6, 'failed', { failureCode: 'unreadable_document' }), limits: { maxRescans: 5 }, ai: B_AI }, locale);
        await settle();
        expect(text('[data-testid="item-failed"]')).toBe(get(messages, 'health.batch.failed_hint').replace(/\s+/g, ' ').trim());
        expect(text('[data-testid="item-keep"]')).toBe(get(messages, 'health.batch.keep_without').replace(/\s+/g, ' ').trim());
        expect(text('[data-testid="item-remove"]')).toBe(get(messages, 'health.batch.remove_doc').replace(/\s+/g, ' ').trim());
        expect(text('[data-testid="item-rescan-hint"]')).toBe(get(messages, 'health.batch.rescan_hint').replace(/\s+/g, ' ').trim());
        expect(noWrapButtons()).toHaveLength(0);
        expect(clamped()).toEqual([]);
    });

    it('keeps the person page banner whole', async () => {
        const { messages } = mountLong(OpenBatchBanner, { personId: ID, batch: { id: BID, done: 2, total: 9, expiresAt: '2026-12-03T10:00:00+00:00' } }, locale);
        await settle();
        expect(text('[data-testid="open-batch-link"]')).toBe(get(messages, 'health.batch.open_banner_action').replace(/\s+/g, ' ').trim());
        expect(text('[data-testid="open-batch-banner"]')).toContain(get(messages, 'health.batch.open_banner_title').replace(/\s+/g, ' ').trim());
        expect(noWrapButtons()).toHaveLength(0);
        expect(clamped()).toEqual([]);
    });
});

describe('Phase 15 long-string and source scan', () => {
    const files = readdirSync('resources/js/Health/Batch').filter((f) => f.endsWith('.vue')).map((f) => `resources/js/Health/Batch/${f}`).concat('resources/js/Pages/Health/UploadBatch.vue');

    it('has no ellipsis, nowrap, line clamp or v-html on the batch screens', () => {
        expect(files.length).toBeGreaterThanOrEqual(9);
        for (const file of files) {
            const src = readFileSync(file, 'utf8');
            expect(src, file).not.toMatch(/text-overflow:\s*ellipsis|white-space:\s*nowrap|whitespace-nowrap|\btruncate\b|line-clamp|v-html/);
        }
    });
});
