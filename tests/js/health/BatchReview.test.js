import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { h } from 'vue';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { readFileSync } from 'node:fs';

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
const $$ = (sel) => [...document.querySelectorAll(sel)];

const entries = (over = {}) => ({
    sections: {
        visits: [{ key: 'visit-0', fields: { visited_on: '2025-05-02', doctor: 'Dr <b>Kova</b>', specialty_key: 'cardiology', diagnosis: 'Mild murmur' } }],
        medications: [{ key: 'medication-0', fields: { name: 'Ibuprofen', dose: '200 mg' } }],
        allergies: [], vaccinations: [], ...over,
    },
});
const current = (over = {}, review = {}) => ({
    item: { id: 'item-1', state: 'review', pending: 2, documentId: 'doc-1', title: 'Letter', kind: 'image' },
    document: { id: 'doc-1', kind: 'image', title: 'Letter', pageCount: null },
    review: { extraction: entries(review), provenance: { agent: 'codex', model: null }, specialties: ['cardiology', 'other'] },
    choices: {}, adjustments: { brightness: 100, contrast: 100, bw: false }, ...over,
});

let wrapper;
function mountReview(cur = current()) {
    wrapper = mount(BatchReview, { props: { personId: PID, batchId: BID, current: cur }, attachTo: document.body, global: { plugins: [createAppI18n('en', loadMessages('en'))] } });
    return wrapper;
}
beforeEach(() => {
    fetchMock.mockReset();
    refusal.mockReset();
    useToast().toasts.splice(0);
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
});
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; vi.restoreAllMocks(); });

describe('batch review', () => {
    it('shows the document and every finding grouped by section with Approve and Reject toggles', () => {
        mountReview();
        expect($('[data-testid="document-pane"]')).not.toBeNull();
        expect($$('[data-testid="batch-entry"]')).toHaveLength(2);
        expect($$('[data-section]').map((s) => s.dataset.section)).toEqual(['visit', 'medication']);
        const approve = $('[data-testid="entry-approve"]');
        expect(approve.getAttribute('aria-pressed')).toBe('false');
        expect($('[data-testid="entry-reject"]').getAttribute('aria-pressed')).toBe('false');
        expect($('[data-testid="entry-edit"]')).toBeNull();
    });

    it('renders model text as text, never as HTML', () => {
        mountReview();
        expect($('[data-entry-key="visit-0"]').textContent).toContain('Dr <b>Kova</b>');
        expect($('[data-entry-key="visit-0"] b')).toBeNull();
    });

    it('restores the saved marks and edited values from props only', () => {
        mountReview(current({ choices: { 'visit-0': { decision: 'approve', values: { doctor: 'Dr Edited' } }, 'medication-0': { decision: 'reject' } } }));
        expect($('[data-entry-key="visit-0"] [data-testid="entry-approve"]').getAttribute('aria-pressed')).toBe('true');
        expect($('[data-entry-key="visit-0"]').textContent).toContain('Dr Edited');
        expect($('[data-entry-key="visit-0"] [data-testid="entry-edit"]')).not.toBeNull();
        expect($('[data-entry-key="medication-0"] [data-testid="entry-reject"]').getAttribute('aria-pressed')).toBe('true');
        expect($('[data-testid="batch-counts"]').textContent).toContain('Approved: 1');
        expect($('[data-testid="batch-counts"]').textContent).toContain('Rejected: 1');
        expect($('[data-testid="batch-undecided"]')).toBeNull();
    });

    it('saves a mark on the server at once and shows the answer', async () => {
        mountReview();
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: { choices: { 'visit-0': { decision: 'approve' } } } });
        await $('[data-entry-key="visit-0"] [data-testid="entry-approve"]').click();
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledWith(`${base}/choices`, expect.objectContaining({ method: 'PUT', body: { choices: { 'visit-0': { decision: 'approve' } } } }));
        expect($('[data-entry-key="visit-0"] [data-testid="entry-approve"]').getAttribute('aria-pressed')).toBe('true');
        expect($('[data-testid="batch-undecided"]').textContent).toBe('1 not decided');
    });

    it('pressing the pressed mark again clears the decision', async () => {
        mountReview(current({ choices: { 'visit-0': { decision: 'approve' } } }));
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: { choices: {} } });
        await $('[data-entry-key="visit-0"] [data-testid="entry-approve"]').click();
        await flushPromises();
        expect(fetchMock.mock.calls[0][1].body).toEqual({ choices: {} });
        expect($('[data-entry-key="visit-0"] [data-testid="entry-approve"]').getAttribute('aria-pressed')).toBe('false');
    });

    it('stores edited values as the approved choice', async () => {
        mountReview(current({ choices: { 'medication-0': { decision: 'approve' } } }));
        await $('[data-entry-key="medication-0"] [data-testid="entry-edit"]').click();
        await flushPromises();
        const dose = $$('[data-testid="edit-form-medication"] input').find((i) => i.value === '200 mg');
        dose.value = '400 mg';
        dose.dispatchEvent(new Event('input'));
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: { choices: { 'medication-0': { decision: 'approve', values: { dose: '400 mg' } } } } });
        await $('[data-testid="edit-save"]').click();
        await flushPromises();
        const body = fetchMock.mock.calls[0][1].body;
        expect(body.choices['medication-0'].decision).toBe('approve');
        expect(body.choices['medication-0'].values.dose).toBe('400 mg');
        expect($('[data-entry-key="medication-0"]').textContent).toContain('400 mg');
    });

    it('Continue posts, tells how many were saved and discarded, and asks for the next document', async () => {
        const w = mountReview(current({ choices: { 'visit-0': { decision: 'approve' }, 'medication-0': { decision: 'reject' } } }));
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: { result: 'next', nextItemId: 'item-2', approved: 1, rejected: 1 } });
        await $('[data-testid="batch-continue"]').click();
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledWith(`${base}/continue`, expect.objectContaining({ method: 'POST' }));
        expect(useToast().toasts.map((x) => x.message)).toEqual(['Saved 1 and discarded 1']);
        expect(w.emitted('changed')).toHaveLength(1);
    });

    it('warns about undecided findings', () => {
        mountReview();
        expect($('[data-testid="batch-undecided"]').textContent).toBe('2 not decided');
    });

    it('opens the entry with the server message when Continue is refused with 422', async () => {
        mountReview(current({ choices: { 'medication-0': { decision: 'approve' } } }));
        fetchMock.mockResolvedValue({ ok: false, status: 422, errors: { 'entries.medication-0.name': ['health.error.required'] } });
        await $('[data-testid="batch-continue"]').click();
        await flushPromises();
        expect($('[data-testid="edit-form-medication"]')).not.toBeNull();
        expect(useToast().toasts).toHaveLength(0);
    });

    it('shows the not-ready text and no Continue while the document is processing or paused', () => {
        mountReview(current({ item: { id: 'item-1', state: 'processing', title: 'Letter', kind: 'image' }, review: null }));
        expect($('[data-testid="batch-not-ready"]').textContent).toBe('AI is still reading this document');
        expect($('[data-testid="batch-continue"]')).toBeNull();
        wrapper.unmount();
        mountReview(current({ item: { id: 'item-1', state: 'paused', title: 'Letter', kind: 'image' }, review: null }));
        expect($('[data-testid="batch-continue"]')).toBeNull();
    });

    it('shows no findings and still offers Continue to keep the document', () => {
        mountReview(current({}, { visits: [], medications: [] }));
        expect($('[data-testid="batch-no-findings"]').textContent).toBe('AI found nothing to add');
        expect($('[data-testid="batch-undecided"]')).toBeNull();
        expect($('[data-testid="batch-continue"]')).not.toBeNull();
    });

    it('has no axe violations', async () => {
        mountReview();
        await expectNoAxeViolations(document.body);
    });

    it('keeps browser storage and v-html out of the components', () => {
        for (const f of ['BatchEntry', 'BatchReview']) {
            const src = readFileSync(`resources/js/Health/Batch/${f}.vue`, 'utf8');
            expect(src).not.toMatch(/v-html|localStorage|sessionStorage|indexedDB/);
        }
    });
});
