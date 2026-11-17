import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick, reactive } from 'vue';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

// Phase 13 review fixes of the document search: dropped-word hint, dismissed "Confirm it's you", Retry-After, the pause
// after a 429, and the search following text that was deleted (removal, consent turned off, a result that cannot be opened).
let mockProps = {};
const { fetchMock, refusal, router } = vi.hoisted(() => ({
    fetchMock: vi.fn(),
    refusal: vi.fn(),
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn(() => vi.fn()) },
}));
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: fetchMock }));
vi.mock('../../../resources/js/Health/healthRefusal.js', () => ({ handleHealthRefusal: refusal }));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps, get url() { return '/health'; } }),
    router,
    Head: { props: ['title'], setup: () => () => null },
    Link: { props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) },
}));

import DocumentSearch from '../../../resources/js/Health/Search/DocumentSearch.vue';
import Person from '../../../resources/js/Pages/Health/Person.vue';

const PEOPLE = [
    { id: '11111111-1111-4111-8111-111111111111', name: 'Ada Novak', initials: 'AN' },
    { id: '22222222-2222-4222-8222-222222222222', name: 'Boris', initials: 'B' },
];
const PERSON = { id: PEOPLE[0].id, name: 'Ada Novak', birthDate: '2015-03-04', ageYears: 11, sex: 'female', bloodType: 'AB-', hasAccount: false, aiConsent: true, canEdit: true, notes: null };
const DOC = (n, over = {}) => ({
    documentId: `aaaaaaaa-aaaa-4aaa-8aaa-${String(n).padStart(12, '0')}`, personId: PEOPLE[0].id, personName: 'Ada Novak', title: `Report ${n}`, uploadedOn: '2026-03-04',
    visitId: null, visitName: null, visitOn: null, visitSpecialty: null, page: null, snippets: [], textComplete: true, pagesRead: null, pagesTotal: null, ...over,
});
const answer = (items, over = {}) => ({ ok: true, status: 200, data: { items, hasMore: false, page: 1, ...over } });
const deferred = () => { let resolve; const promise = new Promise((r) => { resolve = r; }); return { promise, resolve }; };
const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => [...document.querySelectorAll(sel)];
const flush = async () => { for (let i = 0; i < 6; i += 1) { await Promise.resolve(); await nextTick(); } };
const cancelledGate = { ok: false, status: 423, error: 'cancelled', cancelled: true, data: {} };

let wrapper;
function mountSearch() {
    wrapper = mount(DocumentSearch, {
        props: { person: PERSON, peopleCount: 2, reliability: 'reliable' },
        attachTo: document.body,
        global: { plugins: [createAppI18n('en', loadMessages('en'))] },
    });
    return wrapper;
}
async function type(value) {
    const input = $('[data-testid="search-input"]');
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await nextTick();
}
async function submit() {
    $('[data-testid="doc-search-form"]').dispatchEvent(new Event('submit', { cancelable: true }));
    await flush();
}

beforeEach(() => { vi.useFakeTimers(); fetchMock.mockReset(); refusal.mockReset(); Object.values(router).forEach((fn) => fn.mockClear?.()); });
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; vi.useRealTimers(); });

describe('document search: review fixes', () => {
    it('shows a small hint only when the server says words were dropped', async () => {
        fetchMock.mockResolvedValueOnce(answer([DOC(1)], { dropped: 1 })).mockResolvedValueOnce(answer([DOC(1)], { dropped: 0 }));
        mountSearch();
        await type('vitamin c');
        await submit();
        expect($('[data-testid="search-dropped"]').textContent).toContain('1 word of your search was not used');
        expect($('[data-testid="search-dropped"]').textContent).toContain('One-letter words');
        await type('vitamin d3');
        await submit();
        expect($('[data-testid="search-dropped"]')).toBeNull();
    });

    it('keeps the query and the results when "Confirm it\'s you" is dismissed', async () => {
        fetchMock.mockResolvedValueOnce(answer([DOC(1)])).mockResolvedValueOnce(cancelledGate);
        refusal.mockReturnValue(true);
        mountSearch();
        await type('rep');
        await submit();
        await type('repo');
        await submit();
        expect($('[data-testid="search-input"]').value).toBe('repo');
        expect($$('[data-testid="search-row"]')).toHaveLength(1);
        expect($('[data-testid="search-error"]')).toBeNull();
    });

    it('keeps everything when the dialog is dismissed during Load more', async () => {
        fetchMock.mockResolvedValueOnce(answer([DOC(1)], { hasMore: true })).mockResolvedValueOnce(cancelledGate);
        mountSearch();
        await type('rep');
        await submit();
        $('[data-testid="search-more"]').click();
        await flush();
        expect($$('[data-testid="search-row"]')).toHaveLength(1);
        expect($('[data-testid="search-input"]').value).toBe('rep');
        expect($('[data-testid="search-more-failed"]')).toBeNull();
    });

    it('honours Retry-After for the pause and shows that a held-back search is waiting', async () => {
        fetchMock.mockResolvedValueOnce({ ok: false, status: 429, error: 'throttle', minutes: 1, seconds: 2, data: {} }).mockResolvedValue(answer([DOC(1)]));
        mountSearch();
        await type('rep');
        await submit();
        expect($('[data-testid="search-waiting"]')).toBeNull();
        await submit();
        expect($('[data-testid="search-waiting"]')).not.toBeNull();
        vi.advanceTimersByTime(1999);
        await flush();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        vi.advanceTimersByTime(1);
        await flush();
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect($('[data-testid="search-waiting"]')).toBeNull();
    });

    it('starts the pause after a 429 on Load more and sends no other page inside it', async () => {
        fetchMock.mockResolvedValueOnce(answer([DOC(1)], { hasMore: true })).mockResolvedValueOnce({ ok: false, status: 429, error: 'throttle', minutes: 1, seconds: null, data: {} }).mockResolvedValue(answer([DOC(2)], { page: 2 }));
        mountSearch();
        await type('rep');
        await submit();
        $('[data-testid="search-more"]').click();
        await flush();
        expect($('[data-testid="search-more-failed"]').textContent).toContain('You searched too often.');
        $('[data-testid="search-more-retry"]').click();
        await flush();
        expect(fetchMock).toHaveBeenCalledTimes(2);
        vi.advanceTimersByTime(5000);
        await flush();
        $('[data-testid="search-more-retry"]').click();
        await flush();
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    it('leaves a Ctrl click on a result to the browser', async () => {
        fetchMock.mockResolvedValue(answer([DOC(1)]));
        mountSearch();
        await type('rep');
        await submit();
        const event = new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true, button: 0 });
        $('[data-testid="search-row"] a').dispatchEvent(event);
        expect(event.defaultPrevented).toBe(false);
        expect(wrapper.emitted('open')).toBeUndefined();
    });
});

describe('Health person page: the search follows deleted text', () => {
    let page;
    function mountPage(props = {}) {
        window.innerWidth = 1280;
        window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
        mockProps = reactive({
            locale: 'en', flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
            auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: 'none', health: 'edit' } },
            app: { env: 'local', styleGuide: false },
        });
        page = mount(Person, {
            props: { people: PEOPLE, person: PERSON, canCreate: true, panel: null, documents: [], visits: [], search: { enabled: true, textReliability: 'reliable' }, ...props },
            attachTo: document.body,
            global: { plugins: [createAppI18n('en', loadMessages('en'))] },
        });
        return page;
    }
    afterEach(() => { page?.unmount(); page = null; });
    const SNIPPET = [[{ text: 'old ', hit: false }, { text: 'metformin', hit: true }]];
    const docs = (hasText) => [{ id: DOC(1).documentId, title: 'Report 1', kind: 'pdf', uploadedOn: '2026-01-01', visitId: null, hasText }];

    it('clears the snippets at once and runs the same query again when a text is removed', async () => {
        const second = deferred();
        fetchMock.mockResolvedValueOnce(answer([DOC(1, { snippets: SNIPPET })])).mockReturnValueOnce(second.promise);
        mountPage({ documents: docs(true) });
        await type('metformin');
        await submit();
        expect($('[data-testid="search-row"]').textContent).toContain('metformin');
        await page.setProps({ documents: docs(false) });
        await flush();
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls[1][1].body).toMatchObject({ q: 'metformin', page: 1 });
        expect(document.body.textContent).not.toContain('old metformin');
        second.resolve(answer([DOC(1)]));
        await flush();
        expect($('[data-testid="search-row"]').textContent).not.toContain('metformin');
        expect($('[data-testid="search-title-only"]')).not.toBeNull();
    });

    it('runs the query again when AI consent of the person is turned off', async () => {
        fetchMock.mockResolvedValue(answer([DOC(1, { snippets: SNIPPET })]));
        mountPage({ documents: docs(true) });
        await type('metformin');
        await submit();
        await page.setProps({ person: { ...PERSON, aiConsent: false } });
        await flush();
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('runs the query again when the document of the panel turns out to be gone', async () => {
        fetchMock.mockResolvedValue(answer([DOC(1)]));
        mountPage({ documents: docs(true) });
        await type('rep');
        await submit();
        await page.setProps({ panel: { kind: 'document', id: 'ffffffff-ffff-4fff-8fff-ffffffffffff' } });
        await flush();
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('does nothing while no search is shown', async () => {
        mountPage({ documents: docs(true) });
        await page.setProps({ documents: docs(false) });
        await flush();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('asks about deleting text when turning AI off only if this person has text', async () => {
        mountPage({ documents: docs(false) });
        $('[data-testid="ai-switch"]').click();
        await flush();
        expect($('[data-testid="ai-off-purge"]')).toBeNull();
        expect(router.patch).toHaveBeenCalledTimes(1);
        page.unmount();
        router.patch.mockClear();
        mountPage({ documents: docs(true) });
        $('[data-testid="ai-switch"]').click();
        await flush();
        expect($('[data-testid="ai-off-purge"]')).not.toBeNull();
        expect(router.patch).not.toHaveBeenCalled();
    });
});
