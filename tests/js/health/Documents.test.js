import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick, reactive } from 'vue';
import { readFileSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

let mockProps = {};
const { router, download, refusal } = vi.hoisted(() => ({
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn() },
    download: vi.fn(),
    refusal: vi.fn(),
}));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps, get url() { return '/health'; } }),
    router,
    Head: { props: ['title'], setup: () => () => null },
    Link: { props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) },
}));
vi.mock('../../../resources/js/Security/securityTransfer.js', () => ({ securityDownload: download, securityUpload: vi.fn() }));
vi.mock('../../../resources/js/Health/healthRefusal.js', () => ({ handleHealthRefusal: refusal }));

import Person from '../../../resources/js/Pages/Health/Person.vue';

const ID = '11111111-1111-4111-8111-111111111111';
const PERSON = { id: ID, name: 'Ada Novak', birthDate: null, ageYears: 11, sex: 'female', bloodType: null, hasAccount: false, aiConsent: false, canEdit: true, notes: null };
const VISIT = { id: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeee1', visitedOn: '2025-05-01', doctor: 'Dr. Kova', specialtyKey: 'dentistry', specialtyOther: null, diagnosis: null, notes: null, documentCount: 2 };
const D1 = { id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', kind: 'pdf', title: 'Lab <b>result</b>', visitId: VISIT.id, uploadedOn: '2025-05-02', sizeBytes: 2048 };
const D2 = { id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2', kind: 'image', title: 'X-ray photo', visitId: null, uploadedOn: '2025-06-02', sizeBytes: 5000 };
const D3 = { id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa3', kind: 'heic', title: 'Phone photo', visitId: null, uploadedOn: '2025-06-03', sizeBytes: 900000 };
const D4 = { id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa4', kind: 'pdf', title: 'Second', visitId: VISIT.id, uploadedOn: '2025-05-03', sizeBytes: 100 };
const base = (over = {}) => ({
    people: [{ id: ID, name: 'Ada Novak', initials: 'AN' }], person: PERSON, canCreate: true, panel: null,
    visits: [VISIT], folders: [{ key: 'dentistry', otherLabel: null, count: 1, latestOn: '2025-05-01', visitIds: [VISIT.id] }],
    timeline: [], timelineFilters: ['dentistry', 'documents'], view: 'folders', filter: 'all', specialties: ['dentistry', 'other'],
    allergies: [], medications: [], vaccinations: [], vaccinationAttention: 0,
    documents: [D1, D2, D3, D4], unattachedDocumentIds: [D2.id, D3.id], limits: { maxMb: 10, filesAtOnce: 10 },
    ...over,
});

let current;
function mountPage(props = {}, { locale = 'en', canEdit = true } = {}) {
    window.innerWidth = 1280;
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    mockProps = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: 'none', health: canEdit ? 'edit' : 'view' } },
        app: { env: 'local', styleGuide: false },
    });
    current = mount(Person, { props: base({ person: { ...PERSON, canEdit }, ...props }), attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return current;
}
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); vi.clearAllMocks(); });

const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => [...document.querySelectorAll(sel)];
const docUrl = (id, tail) => `/health/people/${ID}/documents/${id}/${tail}`;

describe('folders and timeline', () => {
    it('adds a "Documents without a visit" folder with its count and lists the unattached documents', () => {
        mountPage();
        const folder = $('[data-folder="documents"]');
        expect(folder.querySelector('h3').textContent).toContain('Documents without a visit');
        expect(folder.querySelector('.folder__count').textContent).toBe('2 documents');
        expect(folder.querySelectorAll('[data-testid="timeline-entry"]')).toHaveLength(2);
        folder.querySelector('[data-testid="timeline-entry"]').click();
        expect(router.get).toHaveBeenLastCalledWith(expect.any(String), { panel: 'document', id: D2.id }, expect.any(Object));
    });

    it('shows "{n} documents" with a paperclip on visit rows that have documents, singular for one', () => {
        mountPage({ timeline: [{ type: 'visit', id: VISIT.id, date: VISIT.visitedOn }], view: 'timeline' });
        expect($('.timeline__docs').textContent).toContain('2 documents');
        current.unmount();
        mountPage({ timeline: [{ type: 'visit', id: VISIT.id, date: VISIT.visitedOn }], view: 'timeline', visits: [{ ...VISIT, documentCount: 1 }] });
        expect($('.timeline__docs').textContent).toContain('1 document');
        expect($('.timeline__docs').textContent).not.toContain('1 documents');
    });

    it('lists unattached documents on the timeline with the title and an icon, and only offers the filter when present', () => {
        mountPage({ view: 'timeline', timeline: [{ type: 'document', id: D3.id, date: D3.uploadedOn }, { type: 'document', id: D2.id, date: D2.uploadedOn }] });
        const entries = $$('[data-testid="timeline-entry"]');
        expect(entries.map((e) => e.querySelector('.timeline__title span').textContent)).toEqual(['Phone photo', 'X-ray photo']);
        expect(entries[0].querySelector('svg')).not.toBeNull();
        expect($$('[data-testid="timeline-filters"] button').map((b) => b.textContent.trim())).toContain('Documents');
        entries[1].click();
        expect(router.get).toHaveBeenLastCalledWith(expect.any(String), { view: 'timeline', panel: 'document', id: D2.id }, expect.any(Object));
        current.unmount();
        mountPage({ view: 'timeline', timelineFilters: ['dentistry'], documents: [D1], unattachedDocumentIds: [], timeline: [{ type: 'visit', id: VISIT.id, date: VISIT.visitedOn }] });
        expect($$('[data-testid="timeline-filters"] button').map((b) => b.textContent.trim())).not.toContain('Documents');
    });

    it('still lists documents when a person has documents but no visits', () => {
        mountPage({ visits: [], folders: [], timelineFilters: ['documents'] });
        expect($('[data-folder="documents"]')).not.toBeNull();
    });
});

describe('document panel', () => {
    it('shows an image preview through the authenticated route and recovers from a load failure without looping', async () => {
        mountPage({ panel: { kind: 'document', id: D2.id } });
        await nextTick();
        const img = $('[data-testid="preview-image"]');
        expect(img.getAttribute('src')).toBe(docUrl(D2.id, 'preview/view'));
        expect(img.getAttribute('alt')).toBe('X-ray photo');
        expect($('[role="dialog"], [data-testid="document-panel"]')).not.toBeNull();
        img.dispatchEvent(new Event('error'));
        await nextTick();
        expect($('[data-testid="preview-failed"]').textContent).toContain('The preview could not be loaded.');
        expect($('[data-testid="preview-confirm"]').getAttribute('href')).toBe('/confirm-access');
        expect($('[data-testid="preview-confirm"]').textContent).toBe("Confirm it's you");
        $('[data-testid="preview-retry"]').click();
        await nextTick();
        expect($('[data-testid="preview-image"]').getAttribute('src')).toBe(`${docUrl(D2.id, 'preview/view')}?retry=1`);
        expect($('[data-testid="preview-failed"]')).toBeNull();
        await expectNoAxeViolations(document.body);
    });

    it('shows a PDF as a file card with Open in new tab and Download, never an inline embed', async () => {
        mountPage({ panel: { kind: 'document', id: D1.id } });
        await nextTick();
        expect($('[data-testid="preview-card"]').textContent).toContain('PDF, 2 KB');
        expect($('[data-testid="preview-card"]').textContent).toContain('Lab <b>result</b>');
        expect($('[data-testid="preview-card"] b')).toBeNull();
        expect($('iframe, embed, object')).toBeNull();
        const open = $('[data-testid="doc-open"]');
        expect(open.getAttribute('href')).toBe(docUrl(D1.id, 'file'));
        expect(open.getAttribute('target')).toBe('_blank');
        expect(open.getAttribute('rel')).toBe('noopener');
        expect($('[data-testid="doc-download"]').textContent).toBe('Download document');
        await expectNoAxeViolations(document.body);
    });

    it('shows HEIC as a No preview card with the download action', async () => {
        mountPage({ panel: { kind: 'document', id: D3.id } });
        await nextTick();
        const card = $('[data-testid="preview-card"]');
        expect(card.textContent).toContain('No preview');
        expect(card.textContent).toContain('This photo format cannot be previewed here');
        expect($('img[data-testid="preview-image"]')).toBeNull();
        expect($('[data-testid="doc-download"]')).not.toBeNull();
        await expectNoAxeViolations(document.body);
    });

    it('downloads through securityDownload and revokes the object URL only after a delay', async () => {
        const created = [];
        const revoked = [];
        const delayed = [];
        const realSetTimeout = globalThis.setTimeout;
        vi.spyOn(globalThis, 'setTimeout').mockImplementation((fn, ms, ...rest) => (ms === 30_000 ? (delayed.push(fn), 0) : realSetTimeout(fn, ms, ...rest)));
        URL.createObjectURL = vi.fn((blob) => { created.push(blob); return 'blob:x'; });
        URL.revokeObjectURL = vi.fn((url) => { revoked.push(url); });
        download.mockResolvedValue({ ok: true, status: 200, blob: new Blob(['x']), filename: 'lab.pdf' });
        mountPage({ panel: { kind: 'document', id: D1.id } });
        await nextTick();
        $('[data-testid="doc-download"]').click();
        await vi.waitFor(() => expect(delayed).toHaveLength(1));
        expect(revoked).toEqual([]);
        delayed[0]();
        expect(revoked).toEqual(['blob:x']);
        expect(download).toHaveBeenCalledWith(docUrl(D1.id, 'file'));
        expect(created).toHaveLength(1);
    });

    it('sends a download refusal (423) to the shared handler without replay', async () => {
        download.mockResolvedValue({ ok: false, status: 423, error: 'reauth_required' });
        mountPage({ panel: { kind: 'document', id: D1.id } });
        await nextTick();
        $('[data-testid="doc-download"]').click();
        await vi.waitFor(() => expect(refusal).toHaveBeenCalledWith('reauth_required'));
        expect(download).toHaveBeenCalledTimes(1);
    });

    it('renames and always sends visit_id explicitly (null detaches, a value attaches)', async () => {
        mountPage({ panel: { kind: 'document', id: D1.id } });
        await nextTick();
        const form = $('[data-testid="document-form"]');
        const input = form.querySelector('input');
        input.value = 'Renamed';
        input.dispatchEvent(new Event('input'));
        await nextTick();
        $('[data-testid="doc-save"]').click();
        expect(router.patch).toHaveBeenLastCalledWith(docUrl(D1.id, '').slice(0, -1), { title: 'Renamed', visit_id: VISIT.id }, expect.any(Object));
        const select = form.querySelector('select');
        select.value = '';
        select.dispatchEvent(new Event('change'));
        await nextTick();
        $('[data-testid="doc-save"]').click();
        const body = router.patch.mock.calls.at(-1)[1];
        expect(Object.hasOwn(body, 'visit_id')).toBe(true);
        expect(body.visit_id).toBeNull();
    });

    it('deletes after a confirmation and is read-only for view-only members', async () => {
        mountPage({ panel: { kind: 'document', id: D1.id } });
        await nextTick();
        $('[data-testid="doc-delete"]').click();
        await nextTick();
        $$('button').filter((b) => b.textContent.trim() === 'Delete document').at(-1).click();
        expect(router.delete).toHaveBeenCalledWith(docUrl(D1.id, '').slice(0, -1), expect.any(Object));
        current.unmount();
        mountPage({ panel: { kind: 'document', id: D1.id } }, { canEdit: false });
        await nextTick();
        expect($('[data-testid="document-form"]')).toBeNull();
        expect($('[data-testid="doc-delete"]')).toBeNull();
        expect($('[data-testid="doc-save"]')).toBeNull();
        expect($('[data-testid="document-readonly"]').textContent).toContain('Lab <b>result</b>');
        expect($('[data-testid="doc-open"]')).not.toBeNull();
        expect($('[data-testid="doc-download"]')).not.toBeNull();
        await expectNoAxeViolations(document.body);
    });

    it('hosts the uploader in create mode with the visit preselected, and the header opens it', async () => {
        mountPage({ panel: { kind: 'new-document', id: null, visit: VISIT.id } });
        await nextTick();
        expect($('[data-testid="document-uploader"]')).not.toBeNull();
        expect($('[data-testid="document-uploader"] select').value).toBe(VISIT.id);
        current.unmount();
        mountPage();
        $('[data-testid="header-add-document"]').click();
        expect(router.get).toHaveBeenLastCalledWith(expect.any(String), { panel: 'new-document' }, expect.any(Object));
    });

    it('never uses browser storage or v-html in the document components', () => {
        for (const file of ['DocumentPanel', 'DocumentPreview', 'DocumentUploader', 'AttachDocumentDialog']) {
            const src = readFileSync(`${process.cwd()}/resources/js/Health/${file}.vue`, 'utf8');
            expect(src, file).not.toMatch(/localStorage|sessionStorage|indexedDB|v-html|remember/);
        }
    });
});

describe('visit documents section', () => {
    const open = (over = {}, opts) => mountPage({ panel: { kind: 'visit', id: VISIT.id }, ...over }, opts);

    it('lists the attached documents with a non-destructive Remove from visit that sends visit_id null', async () => {
        open();
        await nextTick();
        const section = $('[data-testid="visit-documents"]');
        expect(section.querySelector('h3').textContent).toBe('Documents');
        expect([...section.querySelectorAll('.ui-row__title')].map((e) => e.textContent.trim())).toEqual(['Lab <b>result</b>', 'Second']);
        section.querySelector('[data-testid="doc-detach"]').click();
        expect(router.patch).toHaveBeenLastCalledWith(docUrl(D1.id, '').slice(0, -1), { title: D1.title, visit_id: null }, expect.any(Object));
        expect(router.delete).not.toHaveBeenCalled();
        section.querySelector('.ui-row__main').click();
        expect(router.get).toHaveBeenLastCalledWith(expect.any(String), { panel: 'document', id: D1.id }, expect.any(Object));
    });

    it('attaches an unattached document via the dialog (visit_id sent) and can jump to uploading a new one', async () => {
        open();
        await nextTick();
        $('[data-testid="visit-attach"]').click();
        await nextTick();
        const radios = $$('[data-testid="attach-list"] input[type="radio"]');
        expect(radios).toHaveLength(2);
        expect($('[data-testid="attach-list"]').textContent).toContain('X-ray photo');
        expect($('[data-testid="attach-confirm"]').disabled).toBe(true);
        await expectNoAxeViolations(document.body);
        radios[0].click();
        radios[0].dispatchEvent(new Event('change'));
        await nextTick();
        $('[data-testid="attach-confirm"]').click();
        expect(router.patch).toHaveBeenLastCalledWith(docUrl(D2.id, '').slice(0, -1), { title: 'X-ray photo', visit_id: VISIT.id }, expect.any(Object));
        $('[data-testid="attach-upload"]').click();
        expect(router.get).toHaveBeenLastCalledWith(expect.any(String), { panel: 'new-document', visit: VISIT.id }, expect.any(Object));
    });

    it('shows view-only members the list without actions', async () => {
        open({}, { canEdit: false });
        await nextTick();
        const section = $('[data-testid="visit-documents"]');
        expect(section.querySelectorAll('.ui-row__title')).toHaveLength(2);
        expect($('[data-testid="doc-detach"]')).toBeNull();
        expect($('[data-testid="visit-attach"]')).toBeNull();
    });
});

describe('translations', () => {
    it.each(['sk', 'pl'])('renders the folder and document panel in %s', async (locale) => {
        mountPage({ panel: { kind: 'document', id: D3.id } }, { locale });
        await nextTick();
        const messages = loadMessages(locale);
        expect($('[data-folder="documents"] h3').textContent).toContain(messages.health.folder.no_visit_docs);
        expect($('[data-testid="preview-card"]').textContent).toContain(messages.health.doc.no_preview);
        expect($('[data-testid="doc-download"]').textContent).toBe(messages.health.doc.download);
    });

    it('defines the toast keys in all three locales', () => {
        for (const locale of ['en', 'sk', 'pl']) {
            const m = loadMessages(locale).health;
            expect(typeof m.doc.saved, locale).toBe('string');
            expect(typeof m.doc.deleted, locale).toBe('string');
            expect(typeof m.upload.err_cap, locale).toBe('string');
            expect(m.upload.err_cap, locale).toContain('{count}');
        }
    });
});
