import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick, reactive } from 'vue';
import { readFileSync, readdirSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

let mockProps = {};
const { fetchMock, refusal, router, headProps } = vi.hoisted(() => ({
    fetchMock: vi.fn(),
    refusal: vi.fn(),
    headProps: [],
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn() },
}));
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: fetchMock }));
vi.mock('../../../resources/js/Health/healthRefusal.js', () => ({ handleHealthRefusal: refusal }));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps, get url() { return '/health'; } }),
    router,
    Head: { props: ['title'], setup(props) { headProps.push(props); return () => null; } },
    Link: { props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) },
}));

import AiReview from '../../../resources/js/Pages/Health/AiReview.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const PID = '11111111-1111-4111-8111-111111111111';
const DOC_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
const EX = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeee1';
const NEWEST = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeee2';

const visit = { visited_on: '2025-05-02', doctor: 'Dr Kova', specialty_key: 'cardiology', diagnosis: 'Mild murmur', notes: null };
const med = { name: 'Ibuprofen', dose: '200 mg', schedule: null, started_on: null, ended_on: null, notes: null };
const allergy = { substance: 'Penicillin', reaction: 'Rash', severity: 'unknown' };
const vaccine = { vaccine: 'MMR', given_on: '2024-01-10', next_due_on: null, notes: null };

const item = (key, fields, over = {}) => ({ key, fields, state: 'pending', recordId: null, edited: false, ...over });
const extraction = (over = {}) => ({
    id: EX, status: 'ready', runAt: '2026-11-14T09:30:00+00:00', pagesRead: null, pagesTotal: null, documentDate: null, specialtyKey: null,
    readOnly: false, pending: 4,
    sections: {
        visits: [item('visit-0', visit)],
        medications: [item('medication-0', med)],
        allergies: [item('allergy-0', allergy)],
        vaccinations: [item('vaccination-0', vaccine)],
    },
    ...over,
});
const baseProps = (over = {}) => ({
    person: { id: PID },
    document: { id: DOC_ID, kind: 'image', title: 'Discharge letter', pageCount: null },
    extraction: extraction(),
    newestExtractionId: null,
    consentOn: true,
    specialties: ['cardiology'],
    pane: null,
    ...over,
});

const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => [...document.querySelectorAll(sel)];
const flush = async () => { for (let i = 0; i < 5; i += 1) { await Promise.resolve(); await nextTick(); } };
const entryEl = (key) => $(`[data-entry-key="${key}"]`);
const action = (key, name) => entryEl(key).querySelector(`[data-action="${name}"]`);

let wrapper;
function mountPage(over = {}, { locale = 'en', width = 1280 } = {}) {
    window.innerWidth = width;
    mockProps = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: 'none', health: 'edit' } },
        app: { env: 'local', styleGuide: false },
    });
    wrapper = mount(AiReview, { props: baseProps(over), attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return wrapper;
}
beforeEach(() => {
    fetchMock.mockReset();
    refusal.mockReset();
    useToast().toasts.splice(0);
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
});
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    document.documentElement.classList.remove('dark');
    headProps.length = 0;
    vi.clearAllMocks();
    window.history.replaceState(null, '', '/');
});

const accepted = (type, recordId, extra = {}) => ({ ok: true, status: 200, data: { state: 'accepted', recordType: type, recordId, edited: false, pending: 0, ...extra } });

describe('layout', () => {
    it('shows two panes with h2 headings, no toggle and the skip link before the panes on wide screens', () => {
        mountPage();
        expect($('[data-testid="pane-toggle"]')).toBeNull();
        expect($$('h2').map((e) => e.textContent.trim())).toEqual(['Document', 'Suggestions']);
        expect($('[data-testid="pane-document"]').hidden).toBe(false);
        expect($('[data-testid="pane-entries"]').hidden).toBe(false);
        const skip = $('[data-testid="skip-link"]');
        expect(skip.textContent).toBe('Skip to suggestions');
        expect(skip.compareDocumentPosition($('[data-testid="pane-document"]')) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        skip.click();
        expect(document.activeElement.id).toBe('ai-suggestions-h');
    });

    it('stacks with a toggle on narrow screens, defaulting to Suggestions with both panes mounted', () => {
        mountPage({}, { width: 600 });
        const toggle = $('[data-testid="pane-toggle"]');
        expect(toggle.getAttribute('role')).toBe('radiogroup');
        expect(toggle.querySelector('[aria-checked="true"]').textContent).toBe('Suggestions');
        expect($('[data-testid="skip-link"]')).toBeNull();
        expect($('[data-testid="pane-document"]').hidden).toBe(true);
        expect($('[data-testid="pane-entries"]').hidden).toBe(false);
        expect($('[data-testid="document-image"]')).not.toBeNull();
    });

    it('switches panes with the arrow keys, focuses the pane heading and writes ?pane= without an Inertia visit', async () => {
        mountPage({}, { width: 600 });
        const checked = () => $('[data-testid="pane-toggle"] [aria-checked="true"]');
        checked().focus();
        checked().dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
        await flush();
        expect(checked().textContent).toBe('Document');
        expect($('[data-testid="pane-document"]').hidden).toBe(false);
        expect($('[data-testid="pane-entries"]').hidden).toBe(true);
        expect(window.location.search).toBe('?pane=document');
        expect(router.visit).not.toHaveBeenCalled();
        expect(router.get).not.toHaveBeenCalled();
        // the toggle keeps focus for arrow navigation; a click moves focus to the pane heading
        $('[data-testid="pane-toggle"] [data-value="entries"]').click();
        await flush();
        expect(document.activeElement.id).toBe('ai-suggestions-h');
        expect(window.location.search).toBe('?pane=entries');
    });

    it('keeps an entry busy state and focus target mounted while toggling', async () => {
        mountPage({}, { width: 600 });
        const accept = action('visit-0', 'accept');
        $('[data-testid="pane-toggle"] [data-value="document"]').click();
        await flush();
        expect(action('visit-0', 'accept')).toBe(accept);
        expect(accept.isConnected).toBe(true);
    });

    it('opens on the document when ?pane=document was requested and survives a rotation to wide and back', async () => {
        mountPage({ pane: 'document' }, { width: 600 });
        expect($('[data-testid="pane-document"]').hidden).toBe(false);
        expect($('[data-testid="pane-entries"]').hidden).toBe(true);
        window.innerWidth = 1280;
        window.dispatchEvent(new Event('resize'));
        await flush();
        expect($('[data-testid="pane-toggle"]')).toBeNull();
        expect($('[data-testid="pane-entries"]').hidden).toBe(false);
        window.innerWidth = 600;
        window.dispatchEvent(new Event('resize'));
        await flush();
        expect($('[data-testid="pane-document"]').hidden).toBe(false);
        expect($('[data-testid="pane-entries"]').hidden).toBe(true);
    });

    it('titles the page "Health" and keeps the person out of the URL and title', () => {
        mountPage();
        expect(headProps.at(-1).title).toBe('Health');
        expect(document.body.innerHTML).not.toMatch(/Ada Novak/);
        expect($$('a[href]').every((a) => !/Ada|Novak/.test(a.getAttribute('href')))).toBe(true);
    });
});

describe('document pane', () => {
    it('renders an image with alt = the document title that opens in a new tab', () => {
        mountPage();
        const img = $('[data-testid="document-image"]');
        expect(img.getAttribute('alt')).toBe('Discharge letter');
        expect(img.getAttribute('src')).toBe(`/health/people/${PID}/documents/${DOC_ID}/preview/view`);
        const link = img.closest('a');
        expect(link.getAttribute('target')).toBe('_blank');
        expect(link.getAttribute('rel')).toBe('noopener');
    });

    it('pages a PDF through health.documents.page with disabled ends and a status line', async () => {
        mountPage({ document: { id: DOC_ID, kind: 'pdf', title: 'Lab', pageCount: 3 } });
        const src = () => $('[data-testid="document-image"]').getAttribute('src');
        const prev = $('[data-testid="page-prev"]');
        const next = $('[data-testid="page-next"]');
        expect(src()).toBe(`/health/people/${PID}/documents/${DOC_ID}/pages/1`);
        expect(prev.disabled).toBe(true);
        expect($('[data-testid="page-status"]').textContent).toBe('Page 1 of 3');
        expect($('[data-testid="page-status"]').getAttribute('role')).toBe('status');
        next.click();
        await flush();
        expect(src()).toBe(`/health/people/${PID}/documents/${DOC_ID}/pages/2`);
        expect($('[data-testid="page-status"]').textContent).toBe('Page 2 of 3');
        expect(prev.disabled).toBe(false);
        next.click();
        await flush();
        expect(src()).toBe(`/health/people/${PID}/documents/${DOC_ID}/pages/3`);
        expect(next.disabled).toBe(true);
        prev.click();
        await flush();
        expect($('[data-testid="page-status"]').textContent).toBe('Page 2 of 3');
    });

    it.each([
        ['a PDF with no page images', { kind: 'pdf', pageCount: 0 }],
        ['a HEIC file', { kind: 'heic', pageCount: null }],
    ])('shows the "No preview" card with "Open in new tab" for %s', (_label, doc) => {
        mountPage({ document: { id: DOC_ID, title: 'Scan', ...doc } });
        expect($('[data-testid="document-image"]')).toBeNull();
        const card = $('[data-testid="preview-card"]');
        expect(card.textContent).toContain('Scan');
        expect(card.textContent).toContain('No preview');
        const open = $('[data-testid="doc-open"]');
        expect(open.textContent).toContain('Open in new tab');
        expect(open.getAttribute('href')).toBe(`/health/people/${PID}/documents/${DOC_ID}/file`);
        expect(open.getAttribute('target')).toBe('_blank');
    });

    it('recovers from a failed image with "Confirm it\'s you" and "Try loading again"', async () => {
        mountPage();
        $('[data-testid="document-image"]').dispatchEvent(new Event('error'));
        await flush();
        expect($('[data-testid="preview-failed"]').textContent).toContain('The preview could not be loaded.');
        expect($('[data-testid="preview-confirm"]').getAttribute('href')).toBe('/confirm-access');
        $('[data-testid="preview-retry"]').click();
        await flush();
        expect($('[data-testid="document-image"]').getAttribute('src')).toContain('?retry=1');
    });
});

describe('header', () => {
    it('shows the warning (not an alert), progress, run date and back link', () => {
        mountPage();
        expect($('h1').textContent).toBe('Review AI suggestions');
        const warning = $('[data-testid="warning"]');
        expect(warning.textContent).toContain('may be wrong');
        expect(warning.getAttribute('role')).toBeNull();
        expect($('[data-testid="progress"]').textContent).toBe('4 suggestions left to review');
        expect($('[data-testid="run-date"]').getAttribute('datetime')).toBe('2026-11-14T09:30:00+00:00');
        expect($('[data-testid="ai-status-tag"]').textContent).toBe('Suggestions ready');
        expect($('[data-testid="back-document"]').getAttribute('href')).toBe(`/health/people/${PID}?panel=document&id=${DOC_ID}`);
    });

    it('shows the pages-read line only when fewer pages were read than exist', () => {
        mountPage({ extraction: extraction({ pagesRead: 10, pagesTotal: 12 }) });
        expect($('[data-testid="pages-read"]').textContent).toBe('Only the first 10 pages were read.');
        wrapper.unmount();
        mountPage({ extraction: extraction({ pagesRead: 12, pagesTotal: 12 }) });
        expect($('[data-testid="pages-read"]')).toBeNull();
    });

    it('shows the AI-off line only when consent is off', () => {
        mountPage({ consentOn: false });
        expect($('[data-testid="consent-off"]').textContent).toContain('AI is off for this person.');
        wrapper.unmount();
        mountPage({ consentOn: true });
        expect($('[data-testid="consent-off"]')).toBeNull();
    });

    it('shows the superseded banner with the newest-run link and hides every action', () => {
        mountPage({ extraction: extraction({ readOnly: true, sections: { visits: [item('visit-0', visit), item('visit-1', visit, { state: 'accepted', recordId: 'rec-1' })], medications: [], allergies: [], vaccinations: [] } }), newestExtractionId: NEWEST });
        expect($('[data-testid="superseded-banner"]').textContent).toContain('A newer run replaced these suggestions.');
        expect($('[data-testid="open-newest"]').getAttribute('href')).toBe(`/health/people/${PID}/documents/${DOC_ID}/review?extraction=${NEWEST}`);
        expect($('[data-testid="ai-status-tag"]').textContent).toBe('Replaced by a newer run');
        expect($('[data-testid="entry-accept"]')).toBeNull();
        expect($('[data-testid="entry-edit"]')).toBeNull();
        expect($('[data-testid="entry-reject"]')).toBeNull();
        expect(entryEl('visit-1').querySelector('[data-testid="entry-open"]').getAttribute('href')).toBe(`/health/people/${PID}?panel=visit&id=rec-1`);
    });
});

describe('suggestions list', () => {
    it('lists sections in the fixed order with counts and omits empty types', () => {
        mountPage({ extraction: extraction({ sections: { vaccinations: [item('vaccination-0', vaccine)], allergies: [], medications: [item('medication-0', med), item('medication-1', { ...med, name: 'Aspirin' })], visits: [] } }) });
        expect($$('h3').map((e) => e.textContent.replace(/\s+/g, ' ').trim())).toEqual(['Medications (2 suggestions)', 'Vaccinations (1 suggestion)']);
        expect($$('[data-entry-key]').map((e) => e.getAttribute('data-entry-key'))).toEqual(['medication-0', 'medication-1', 'vaccination-0']);
    });

    it('shows the details block only when a date or specialty is present', () => {
        mountPage();
        expect($('[data-testid="suggested-details"]')).toBeNull();
        wrapper.unmount();
        mountPage({ extraction: extraction({ documentDate: '2025-05-02', specialtyKey: 'cardiology' }) });
        const block = $('[data-testid="suggested-details"]');
        expect(block.textContent).toContain('Document date:');
        expect(block.textContent).toContain('Specialty: Cardiology');
        expect(block.querySelector('button, a')).toBeNull();
    });

    it('renders model text literally, omits empty fields and names the entry group by its title', () => {
        mountPage({ extraction: extraction({ sections: { visits: [], allergies: [], vaccinations: [], medications: [item('medication-0', { ...med, name: '<b>x</b>', dose: '<img src=x onerror=alert(1)>' })] } }) });
        const el = entryEl('medication-0');
        expect(el.querySelector('b, img')).toBeNull();
        expect(el.querySelector('strong').textContent).toBe('<b>x</b>');
        expect(el.textContent).toContain('<img src=x onerror=alert(1)>');
        expect([...el.querySelectorAll('dt')].map((d) => d.textContent)).toEqual(['Dose']);
        const group = el.querySelector('[role="group"]');
        expect(document.getElementById(group.getAttribute('aria-labelledby')).textContent).toBe('<b>x</b>');
        expect(document.getElementById(group.getAttribute('aria-describedby')).textContent).toBe('Not reviewed yet');
    });

    it('titles a visit by doctor, then specialty, then the type word', () => {
        const sections = { medications: [], allergies: [], vaccinations: [], visits: [item('visit-0', visit), item('visit-1', { ...visit, doctor: null }), item('visit-2', { visited_on: '2025-01-01', specialty_key: null })] };
        mountPage({ extraction: extraction({ sections }) });
        expect(['visit-0', 'visit-1', 'visit-2'].map((k) => entryEl(k).querySelector('strong').textContent)).toEqual(['Dr Kova', 'Cardiology', 'Visits']);
    });

    it('gives each action a unique accessible name and no tabindex above zero', () => {
        mountPage();
        expect(action('medication-0', 'accept').getAttribute('aria-label')).toBe('Accept Medications: Ibuprofen');
        expect(action('medication-0', 'edit').getAttribute('aria-label')).toBe('Edit Medications: Ibuprofen');
        expect(action('medication-0', 'reject').getAttribute('aria-label')).toBe('Reject Medications: Ibuprofen');
        const names = $$('[data-entry-key] button').map((b) => b.getAttribute('aria-label'));
        expect(new Set(names).size).toBe(names.length);
        expect($$('[tabindex]').every((e) => Number(e.getAttribute('tabindex')) <= 0)).toBe(true);
    });

    it('shows the empty state with a way back, and no panes', () => {
        mountPage({ extraction: extraction({ sections: { visits: [], medications: [], allergies: [], vaccinations: [] } }) });
        const empty = $('[data-testid="review-empty"]');
        expect(empty.textContent).toContain('Nothing to suggest');
        expect(empty.textContent).toContain('Back to document');
        expect($('[data-testid="pane-entries"]')).toBeNull();
    });

    it('renders the loading skeleton and the load-error state with a retry', async () => {
        mountPage({ loading: true });
        expect($('[data-testid="review-loading"]').getAttribute('aria-busy')).toBe('true');
        wrapper.unmount();
        mountPage({ extraction: null });
        expect($('[data-testid="review-error"]').textContent).toContain('Something went wrong');
        $('[data-testid="review-error"] button').click();
        expect(router.reload).toHaveBeenCalled();
    });
});

describe('accept', () => {
    it('shows the busy state, then "Added to the record" with the record link, a toast and an announcement', async () => {
        let resolve;
        fetchMock.mockReturnValue(new Promise((r) => { resolve = r; }));
        mountPage();
        action('medication-0', 'accept').click();
        await flush();
        const group = entryEl('medication-0').querySelector('[role="group"]');
        expect(group.getAttribute('aria-busy')).toBe('true');
        expect(action('medication-0', 'accept').disabled).toBe(true);
        expect(action('medication-0', 'reject').disabled).toBe(true);
        expect(fetchMock).toHaveBeenCalledWith(`/health/people/${PID}/ai/${EX}/entries/medication-0/accept`, { method: 'POST' });
        resolve(accepted('medication', 'rec-9'));
        await flush();
        expect(group.getAttribute('aria-busy')).toBeNull();
        expect(entryEl('medication-0').querySelector('[data-testid="entry-tag"]').textContent).toBe('Added to the record');
        expect(entryEl('medication-0').querySelector('[data-testid="entry-open"]').getAttribute('href')).toBe(`/health/people/${PID}?panel=medication&id=rec-9`);
        expect(action('medication-0', 'accept')).toBeNull();
        expect(useToast().toasts.map((x) => x.message)).toEqual(['Medication added to the record']);
        expect($('[data-testid="ai-live"]').textContent).toBe('Added to the record. 3 suggestions left to review');
        expect($('[data-testid="progress"]').textContent).toBe('3 suggestions left to review');
    });

    it('focuses the next pending Accept, and the completion alert after the last one', async () => {
        mountPage({ extraction: extraction({ sections: { visits: [], allergies: [], vaccinations: [], medications: [item('medication-0', med), item('medication-1', { ...med, name: 'Aspirin' })] } }) });
        fetchMock.mockResolvedValueOnce(accepted('medication', 'r1'));
        action('medication-0', 'accept').click();
        await flush();
        expect(document.activeElement).toBe(action('medication-1', 'accept'));
        expect($('[data-testid="completion"]')).toBeNull();
        fetchMock.mockResolvedValueOnce(accepted('medication', 'r2'));
        action('medication-1', 'accept').click();
        await flush();
        const completion = $('[data-testid="completion"]');
        expect(document.activeElement).toBe(completion);
        expect(completion.getAttribute('tabindex')).toBe('-1');
        expect(completion.textContent).toContain('All suggestions are handled.');
        expect(completion.textContent).toContain('Back to person');
        expect(completion.textContent).toContain('Back to document');
        expect($('[data-testid="progress"]').textContent).toBe('All handled');
        expect($('[data-testid="ai-live"]').textContent).toBe('All suggestions are handled.');
    });

    it('shows a duplicate (already accepted or rejected) answer as the real state, without an error', async () => {
        mountPage();
        fetchMock.mockResolvedValueOnce(accepted('visit', 'rec-1', { edited: true }));
        action('visit-0', 'accept').click();
        await flush();
        expect(entryEl('visit-0').querySelector('[data-testid="entry-tag"]').textContent).toBe('Added to the record');
        expect(entryEl('visit-0').querySelector('[data-testid="entry-edited"]').textContent).toBe('You edited this suggestion.');
        expect($('[role="alert"]')).toBeNull();
        fetchMock.mockResolvedValueOnce({ ok: true, status: 200, data: { state: 'rejected', pending: 1 } });
        action('medication-0', 'accept').click();
        await flush();
        expect(entryEl('medication-0').querySelector('[data-testid="entry-tag"]').textContent).toBe('Rejected');
        expect($('[role="alert"]')).toBeNull();
    });

    it('shows the superseded banner and hides the actions on 409', async () => {
        mountPage();
        fetchMock.mockResolvedValue({ ok: false, status: 409, error: 'failed', data: { state: 'superseded' } });
        action('visit-0', 'accept').click();
        await flush();
        expect($('[data-testid="superseded-banner"]')).not.toBeNull();
        expect($('[data-testid="entry-accept"]')).toBeNull();
        expect($('[data-testid="entry-reject"]')).toBeNull();
    });

    it('opens the edit form with the field flagged on a 422 and keeps the entry pending', async () => {
        mountPage();
        fetchMock.mockResolvedValue({ ok: false, status: 422, error: 'invalid', errors: { severity: ['health.allergy.err_severity'] }, data: {} });
        action('allergy-0', 'accept').click();
        await flush();
        const form = entryEl('allergy-0').querySelector('[data-testid="edit-form-allergy"]');
        expect(form.textContent).toContain('Choose a severity to add this allergy.');
        expect(entryEl('allergy-0').querySelector('[data-testid="entry-tag"]').textContent).toBe('Not reviewed yet');
        expect($('[role="alert"]')).toBeNull();
    });

    it('shows one action_failed alert on a network error and keeps the entry pending', async () => {
        mountPage();
        fetchMock.mockResolvedValue({ ok: false, status: 0, error: 'network', data: {} });
        action('visit-0', 'accept').click();
        await flush();
        const alerts = $$('[role="alert"]');
        expect(alerts).toHaveLength(1);
        expect(alerts[0].textContent).toContain('We could not save this. Nothing was added. Try again.');
        expect(entryEl('visit-0').querySelector('[data-testid="entry-tag"]').textContent).toBe('Not reviewed yet');
        expect(action('visit-0', 'accept').disabled).toBe(false);
        expect(document.activeElement).toBe(action('visit-0', 'accept'));
        expect(useToast().toasts).toHaveLength(0);
    });

    it('hands a 423 to handleHealthRefusal and replays nothing', async () => {
        mountPage();
        fetchMock.mockResolvedValue({ ok: false, status: 423, error: 'reauth_required', data: { error: 'reauth_required' } });
        action('visit-0', 'accept').click();
        await flush();
        expect(refusal).toHaveBeenCalledWith('reauth_required');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(entryEl('visit-0').querySelector('[data-testid="entry-tag"]').textContent).toBe('Not reviewed yet');
    });

    it('opens the edit form when Edit is pressed without any request', async () => {
        mountPage();
        action('visit-0', 'edit').click();
        await flush();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(entryEl('visit-0').querySelector('[data-testid="edit-form-visit"]')).not.toBeNull();
        expect(entryEl('visit-0').querySelector('[data-testid="entry-tag"]').textContent).toBe('Not reviewed yet');
    });
});

describe('reject and undo', () => {
    it('collapses to title and tag with Undo focused, then restores pending with Accept focused', async () => {
        mountPage();
        fetchMock.mockResolvedValueOnce({ ok: true, status: 200, data: { state: 'rejected', pending: 3 } });
        action('medication-0', 'reject').click();
        await flush();
        expect(fetchMock).toHaveBeenCalledWith(`/health/people/${PID}/ai/${EX}/entries/medication-0/reject`, { method: 'POST' });
        const el = entryEl('medication-0');
        expect(el.querySelector('[data-testid="entry-tag"]').textContent).toBe('Rejected');
        expect(el.querySelector('dl')).toBeNull();
        expect(action('medication-0', 'accept')).toBeNull();
        expect(document.activeElement).toBe(action('medication-0', 'undo'));
        expect(action('medication-0', 'undo').getAttribute('aria-label')).toBe('Undo Medications: Ibuprofen');
        expect($('[data-testid="ai-live"]').textContent).toBe('Suggestion rejected');
        expect($('[data-testid="progress"]').textContent).toBe('3 suggestions left to review');

        fetchMock.mockResolvedValueOnce({ ok: true, status: 200, data: { state: 'pending', pending: 4 } });
        action('medication-0', 'undo').click();
        await flush();
        expect(fetchMock).toHaveBeenLastCalledWith(`/health/people/${PID}/ai/${EX}/entries/medication-0/reject`, { method: 'DELETE' });
        expect(el.querySelector('[data-testid="entry-tag"]').textContent).toBe('Not reviewed yet');
        expect(el.querySelector('dl')).not.toBeNull();
        expect(document.activeElement).toBe(action('medication-0', 'accept'));
        expect($('[data-testid="ai-live"]').textContent).toBe('Decision undone');
    });

    it('does not offer Undo on a superseded page', () => {
        mountPage({ extraction: extraction({ readOnly: true, sections: { visits: [item('visit-0', visit, { state: 'rejected' })], medications: [], allergies: [], vaccinations: [] } }) });
        expect(entryEl('visit-0').querySelector('[data-testid="entry-tag"]').textContent).toBe('Rejected');
        expect($('[data-testid="entry-undo"]')).toBeNull();
    });
});

describe('storage', () => {
    it('writes nothing to localStorage, sessionStorage or IndexedDB during any action', async () => {
        const local = vi.spyOn(Storage.prototype, 'setItem');
        const idb = { open: vi.fn() };
        const original = window.indexedDB;
        Object.defineProperty(window, 'indexedDB', { value: idb, configurable: true });
        mountPage();
        fetchMock.mockResolvedValue(accepted('visit', 'r1'));
        action('visit-0', 'accept').click();
        await flush();
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: { state: 'rejected', pending: 2 } });
        action('medication-0', 'reject').click();
        await flush();
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: { state: 'pending', pending: 3 } });
        action('medication-0', 'undo').click();
        await flush();
        expect(local).not.toHaveBeenCalled();
        expect(idb.open).not.toHaveBeenCalled();
        Object.defineProperty(window, 'indexedDB', { value: original, configurable: true });
        local.mockRestore();
    });

    it('has no v-html and no storage API in the review sources', () => {
        const files = ['resources/js/Pages/Health/AiReview.vue', ...readdirSync(`${process.cwd()}/resources/js/Health/Ai`).filter((f) => f.endsWith('.vue')).map((f) => `resources/js/Health/Ai/${f}`)];
        for (const file of files) {
            const source = readFileSync(`${process.cwd()}/${file}`, 'utf8');
            expect(source, file).not.toContain('v-html');
            expect(source, file).not.toMatch(/localStorage|sessionStorage|indexedDB/);
        }
    });
});

describe('accessibility', () => {
    const states = {
        pending: {},
        accepted: { extraction: extraction({ sections: { visits: [item('visit-0', visit, { state: 'accepted', recordId: 'r1', edited: true })], medications: [item('medication-0', med, { state: 'rejected' })], allergies: [], vaccinations: [] } }) },
        superseded: { extraction: extraction({ readOnly: true }), newestExtractionId: NEWEST },
        'consent off and pages': { consentOn: false, extraction: extraction({ pagesRead: 10, pagesTotal: 12 }) },
        pdf: { document: { id: DOC_ID, kind: 'pdf', title: 'Lab', pageCount: 3 } },
        card: { document: { id: DOC_ID, kind: 'heic', title: 'Scan', pageCount: null } },
        empty: { extraction: extraction({ sections: { visits: [], medications: [], allergies: [], vaccinations: [] } }) },
        loading: { loading: true },
        error: { extraction: null },
    };
    const cases = [];
    for (const locale of ['en', 'sk', 'pl']) for (const [name, over] of Object.entries(states)) cases.push([locale, name, over]);

    it.each(cases)('is axe clean in %s: %s', async (locale, _name, over) => {
        mountPage(over, { locale });
        await flush();
        await expectNoAxeViolations(document.body);
    });

    it.each([['light', false], ['dark', true]])('is axe clean in %s theme', async (_label, dark) => {
        document.documentElement.classList.toggle('dark', dark);
        mountPage();
        await flush();
        await expectNoAxeViolations(document.body);
    });

    it('is axe clean stacked at 320 px, on both panes, with +40% pseudo-long titles and a busy and a failed entry', async () => {
        const long = (s) => `${s} ${s.slice(0, Math.ceil(s.length * 0.4))}`;
        const sections = { visits: [item('visit-0', { ...visit, doctor: long('Dr Kovalčíková-Nowakowska'), diagnosis: long('Mild murmur noted at rest') })], medications: [item('medication-0', { ...med, name: long('Ibuprofen retard forte') })], allergies: [], vaccinations: [] };
        mountPage({ extraction: extraction({ sections }) }, { width: 320 });
        fetchMock.mockResolvedValue({ ok: false, status: 0, error: 'network', data: {} });
        action('visit-0', 'accept').click();
        await flush();
        await expectNoAxeViolations(document.body);
        $('[data-testid="pane-toggle"] [data-value="document"]').click();
        await flush();
        await expectNoAxeViolations(document.body);
    });
});

describe('copy', () => {
    it('has every key the page reads in en, sk and pl', () => {
        const keys = [
            'back_document', 'back_person', 'title', 'warning', 'run_at', 'left', 'all_handled_short', 'pane_document', 'pane_suggestions', 'pane_toggle_label', 'skip_link',
            'details_title', 'document_date', 'specialty', 'section_visits', 'section_medications', 'section_allergies', 'section_vaccinations', 'count', 'entry_pending',
            'entry_accepted', 'entry_rejected', 'accept', 'edit', 'reject', 'undo', 'accept_aria', 'edit_aria', 'reject_aria', 'undo_aria', 'open_in_record', 'edited_detail',
            'saved_visit', 'saved_medication', 'saved_allergy', 'saved_vaccination', 'completion', 'empty_title', 'empty_body', 'superseded_banner', 'open_newest',
            'consent_off_review', 'action_failed', 'needs_severity', 'announce_added', 'announce_rejected', 'announce_undone', 'page_prev', 'page_next', 'page_status',
        ];
        for (const locale of ['en', 'sk', 'pl']) {
            const review = loadMessages(locale).health.ai.review;
            for (const key of keys) expect(review[key], `${locale}:${key}`).toBeTruthy();
        }
    });
});
