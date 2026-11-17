import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick, reactive } from 'vue';
import { readFileSync, readdirSync } from 'node:fs';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

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
const PERSON = { id: PEOPLE[0].id, name: 'Ada Novak', birthDate: '2015-03-04', ageYears: 11, sex: 'female', bloodType: 'AB-', hasAccount: false, aiConsent: false, canEdit: true, notes: null };
const DOC = (n, over = {}) => ({
    documentId: `aaaaaaaa-aaaa-4aaa-8aaa-${String(n).padStart(12, '0')}`, personId: PEOPLE[0].id, personName: 'Ada Novak', title: `Report ${n}`, uploadedOn: '2026-03-04',
    visitId: null, visitName: null, visitOn: null, visitSpecialty: null, page: null, snippets: [], textComplete: true, pagesRead: null, pagesTotal: null, ...over,
});
const answer = (items, over = {}) => ({ ok: true, status: 200, data: { items, hasMore: false, page: 1, ...over } });
const deferred = () => { let resolve; const promise = new Promise((r) => { resolve = r; }); return { promise, resolve }; };
const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => [...document.querySelectorAll(sel)];
const flush = async () => { for (let i = 0; i < 6; i += 1) { await Promise.resolve(); await nextTick(); } };

let wrapper;
function mountSearch({ locale = 'en', people = 2, reliability = 'reliable', person = PERSON } = {}) {
    wrapper = mount(DocumentSearch, {
        props: { person, peopleCount: people, reliability },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, loadMessages(locale))] },
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

describe('document search: input and requests', () => {
    it('is a labelled search landmark with a visually hidden label, off-switches and the idle hint with its help tip', () => {
        mountSearch();
        const form = $('form[role="search"]');
        expect(form.getAttribute('aria-label')).toBe('Search documents');
        const input = $('[data-testid="search-input"]');
        expect(input.getAttribute('type')).toBe('search');
        expect(input.getAttribute('autocomplete')).toBe('off');
        expect(input.getAttribute('autocapitalize')).toBe('off');
        expect(input.getAttribute('spellcheck')).toBe('false');
        expect(input.getAttribute('enterkeyhint')).toBe('search');
        expect(input.getAttribute('maxlength')).toBe('100');
        expect(input.getAttribute('placeholder')).toBe('Name or words in the document');
        expect($('[data-testid="search-idle"]').textContent).toContain('Search by document name or by words inside the documents.');
        expect(readFileSync(`${process.cwd()}/resources/js/Health/Search/DocumentSearch.vue`, 'utf8')).toContain('<HelpTip topic="health.search"');
    });

    it('renders the scope select only with more than one visible person', () => {
        mountSearch({ people: 1 });
        expect($('[data-testid="search-scope"]')).toBeNull();
        wrapper.unmount();
        mountSearch({ people: 2 });
        const options = $$('[data-testid="search-scope"] option').map((o) => [o.value, o.textContent.trim()]);
        expect(options).toEqual([['all', 'All people'], ['person', 'Ada Novak']]);
    });

    it('sends nothing for one character and says so politely', async () => {
        mountSearch();
        await type('z');
        vi.advanceTimersByTime(2000);
        await flush();
        expect(fetchMock).not.toHaveBeenCalled();
        expect($('[data-testid="search-hint"]').textContent).toBe('Type at least 2 letters.');
        expect($('[data-testid="search-hint"]').getAttribute('aria-live')).toBe('polite');
        await submit();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('sends one POST 300 ms after typing stops, with the body only, replay and an abort signal', async () => {
        fetchMock.mockResolvedValue(answer([DOC(1)]));
        mountSearch();
        await type('z');
        await type('zl');
        await type('zlu');
        vi.advanceTimersByTime(299);
        expect(fetchMock).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);
        await flush();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toBe('/health/search');
        expect(options.method).toBe('POST');
        expect(options.body).toEqual({ q: 'zlu', page: 1 });
        expect(options.replay).toBe(true);
        expect(options.signal).toBeInstanceOf(AbortSignal);
        expect(Object.keys(options.body)).not.toContain('person');
    });

    it('trims the query and sends the person id when the scope is the open person', async () => {
        fetchMock.mockResolvedValue(answer([]));
        mountSearch();
        const select = $('[data-testid="search-scope"]');
        select.value = 'person';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        await nextTick();
        await type('  zlato  ');
        await submit();
        expect(fetchMock.mock.calls[0][1].body).toEqual({ q: 'zlato', person: PERSON.id, page: 1 });
    });

    it('submits at once on Enter or the button and cancels the pending debounce', async () => {
        fetchMock.mockResolvedValue(answer([DOC(1)]));
        mountSearch();
        await type('lab');
        await submit();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        vi.advanceTimersByTime(1000);
        await flush();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('does not send the same search twice while it is on its way', async () => {
        const first = deferred();
        fetchMock.mockReturnValueOnce(first.promise);
        mountSearch();
        await type('lab');
        await submit();
        await submit();
        await submit();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        first.resolve(answer([DOC(1)]));
        await flush();
        expect($$('[data-testid="search-row"]')).toHaveLength(1);
    });

    it('aborts the older request and ignores its late answer', async () => {
        const first = deferred();
        const second = deferred();
        fetchMock.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);
        mountSearch();
        await type('alpha');
        await submit();
        const firstSignal = fetchMock.mock.calls[0][1].signal;
        await type('beta');
        await submit();
        expect(firstSignal.aborted).toBe(true);
        second.resolve(answer([DOC(2, { title: 'Beta result' })]));
        await flush();
        first.resolve(answer([DOC(1, { title: 'Alpha result' })]));
        await flush();
        const text = $('[data-testid="search-results"]').textContent;
        expect(text).toContain('Beta result');
        expect(text).not.toContain('Alpha result');
    });

    it('drops a pending answer when the query is cleared or shortened', async () => {
        const first = deferred();
        fetchMock.mockReturnValueOnce(first.promise);
        mountSearch();
        await type('alpha');
        await submit();
        await type('a');
        first.resolve(answer([DOC(1)]));
        await flush();
        expect($('[data-testid="search-results"]')).toBeNull();
        expect($('[data-testid="search-hint"]').textContent).toBe('Type at least 2 letters.');
    });

    it('searches again when the scope changes', async () => {
        fetchMock.mockResolvedValue(answer([DOC(1)]));
        mountSearch();
        await type('alpha');
        await submit();
        const select = $('[data-testid="search-scope"]');
        select.value = 'person';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        await flush();
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls[1][1].body).toEqual({ q: 'alpha', person: PERSON.id, page: 1 });
    });
});

describe('document search: results', () => {
    it('renders title, person, date, visit, page, marks and spans, and never HTML', async () => {
        const hostile = '<img src=x onerror=alert(1)>';
        fetchMock.mockResolvedValue(answer([
            DOC(1, { title: hostile, visitId: 'v1', visitName: 'Dr Heart', page: 2, snippets: [[{ text: 'results show ', hit: false }, { text: 'zlato', hit: true }, { text: ' <b>x</b>', hit: false }], [{ text: 'second', hit: false }], [{ text: 'third', hit: false }]] }),
            DOC(2, { title: 'Title only' }),
        ]));
        mountSearch();
        await type('zlato');
        await submit();
        const rows = $$('[data-testid="search-row"]');
        expect(rows).toHaveLength(2);
        const first = rows[0];
        expect(first.textContent).toContain(hostile);
        expect(first.querySelector('img')).toBeNull();
        expect(first.querySelector('b')).toBeNull();
        expect(first.textContent).toContain('Ada Novak');
        expect(first.textContent).toContain('4 Mar 2026');
        expect(first.textContent).toContain('Dr Heart');
        expect(first.textContent).toContain('Page 2');
        expect([...first.querySelectorAll('mark')].map((m) => m.textContent)).toEqual(['zlato']);
        expect(first.querySelectorAll('[data-testid="search-snippet"]')).toHaveLength(2);
        expect(first.querySelector('a').getAttribute('href')).toBe(`/health/people/${PEOPLE[0].id}?panel=document&id=${DOC(1).documentId}`);
        expect(rows[1].textContent).toContain('Matches the document name.');
        expect(rows[1].querySelector('mark')).toBeNull();
        // no visit tag without a visit name
        expect(rows[1].querySelectorAll('.ui-tag')).toHaveLength(1);
    });

    it('shows the partial note with the pages read', async () => {
        fetchMock.mockResolvedValue(answer([DOC(1, { textComplete: false, pagesRead: 3, pagesTotal: 8, snippets: [[{ text: 'x', hit: true }]] })]));
        mountSearch();
        await type('xx');
        await submit();
        expect($('[data-testid="search-partial"]').textContent).toContain('Only the first 3 pages are searched.');
    });

    it('titles the list with the count, and with "Documents found" plus "Showing n" when more pages exist', async () => {
        fetchMock.mockResolvedValue(answer([DOC(1), DOC(2), DOC(3)]));
        mountSearch();
        await type('rep');
        await submit();
        expect($('[data-testid="search-heading"]').textContent).toBe('3 documents found');
        expect($('[data-testid="search-heading"]').tagName).toBe('H2');
        expect($('[data-testid="search-count"]')).toBeNull();
        wrapper.unmount();
        fetchMock.mockResolvedValue(answer([DOC(1), DOC(2)], { hasMore: true }));
        mountSearch();
        await type('rep');
        await submit();
        expect($('[data-testid="search-heading"]').textContent).toBe('Documents found');
        expect($('[data-testid="search-count"]').textContent).toBe('Showing 2');
    });

    it.each([
        ['sk', 1, 'Nájdený 1 dokument'], ['sk', 2, 'Nájdené 2 dokumenty'], ['sk', 5, 'Nájdených 5 dokumentov'],
        ['pl', 1, 'Znaleziono 1 dokument'], ['pl', 2, 'Znaleziono 2 dokumenty'], ['pl', 5, 'Znaleziono 5 dokumentów'],
    ])('uses the %s plural form for %i', async (locale, n, expected) => {
        fetchMock.mockResolvedValue(answer(Array.from({ length: n }, (_, i) => DOC(i + 1))));
        mountSearch({ locale });
        await type('rep');
        await submit();
        expect($('[data-testid="search-heading"]').textContent).toBe(expected);
    });

    it('appends the next page on Load more, focuses the first new row and announces the count', async () => {
        fetchMock.mockResolvedValueOnce(answer([DOC(1), DOC(2)], { hasMore: true })).mockResolvedValueOnce(answer([DOC(3), DOC(2)], { hasMore: false, page: 2 }));
        mountSearch();
        await type('rep');
        await submit();
        $('[data-testid="search-more"]').click();
        await flush();
        expect(fetchMock.mock.calls[1][1].body).toEqual({ q: 'rep', page: 2 });
        const rows = $$('[data-testid="search-row"]');
        expect(rows).toHaveLength(3);
        expect(document.activeElement).toBe(rows[2].querySelector('a'));
        expect($('[data-testid="search-announce"]').textContent).toBe('1 more documents loaded');
        expect($('[data-testid="search-more"]')).toBeNull();
    });

    it('does not send Load more twice and uses the query of the search, not the edited input', async () => {
        const more = deferred();
        fetchMock.mockResolvedValueOnce(answer([DOC(1)], { hasMore: true })).mockReturnValueOnce(more.promise);
        mountSearch();
        await type('rep');
        await submit();
        $('[data-testid="search-more"]').click();
        $('[data-testid="search-more"]').click();
        await flush();
        expect(fetchMock).toHaveBeenCalledTimes(2);
        more.resolve(answer([DOC(2)], { page: 2 }));
        await flush();
        expect($$('[data-testid="search-row"]')).toHaveLength(2);
    });

    it('keeps the list and offers Try again when Load more fails', async () => {
        fetchMock.mockResolvedValueOnce(answer([DOC(1)], { hasMore: true })).mockResolvedValueOnce({ ok: false, status: 500, error: 'failed', data: {} });
        mountSearch();
        await type('rep');
        await submit();
        $('[data-testid="search-more"]').click();
        await flush();
        expect($$('[data-testid="search-row"]')).toHaveLength(1);
        expect($('[data-testid="search-more-failed"]').textContent).toContain('The next documents could not be loaded.');
        expect($('[data-testid="search-more-failed"]').getAttribute('role')).toBe('alert');
    });

    it('shows the info alert only when the text is uncertain', async () => {
        fetchMock.mockResolvedValue(answer([DOC(1)]));
        mountSearch({ reliability: 'uncertain' });
        await type('rep');
        await submit();
        expect($('[data-testid="search-uncertain"]').textContent).toContain('may contain mistakes');
        wrapper.unmount();
        mountSearch({ reliability: 'reliable' });
        await type('rep');
        await submit();
        expect($('[data-testid="search-uncertain"]')).toBeNull();
    });

    it('moves focus to the results heading after Enter and keeps it in the input while typing', async () => {
        fetchMock.mockResolvedValue(answer([DOC(1)]));
        mountSearch();
        $('[data-testid="search-input"]').focus();
        await type('rep');
        vi.advanceTimersByTime(300);
        await flush();
        expect(document.activeElement).toBe($('[data-testid="search-input"]'));
        await submit();
        expect(document.activeElement).toBe($('[data-testid="search-heading"]'));
    });

    it('announces counts and states only, never titles or snippets', async () => {
        fetchMock.mockResolvedValue(answer([DOC(1, { title: 'Secret diagnosis', snippets: [[{ text: 'Secret words', hit: true }]] })]));
        mountSearch();
        await type('secret');
        await submit();
        const status = $('[data-testid="search-announce"]');
        expect(status.getAttribute('role')).toBe('status');
        expect(status.textContent).toBe('1 document found');
        expect(status.textContent).not.toMatch(/secret/i);
    });

    it('draws a skeleton first and keeps the list with a progress bar on a later search', async () => {
        const first = deferred();
        const second = deferred();
        fetchMock.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);
        mountSearch();
        await type('alpha');
        await submit();
        expect($('[data-testid="search-results"]').getAttribute('aria-busy')).toBe('true');
        expect($$('[data-testid="search-loading"] li')).toHaveLength(3);
        first.resolve(answer([DOC(1, { title: 'Old title' })]));
        await flush();
        await type('beta');
        await submit();
        expect($('[data-testid="search-results"]').textContent).toContain('Old title');
        expect($('[role="progressbar"]')).not.toBeNull();
        second.resolve(answer([DOC(2, { title: 'New title' })]));
        await flush();
        expect($('[data-testid="search-results"]').textContent).toContain('New title');
        expect($('[role="progressbar"]')).toBeNull();
    });
});

describe('document search: empty, failure and clearing', () => {
    it('shows the same empty state for every empty answer', async () => {
        fetchMock.mockResolvedValue(answer([]));
        mountSearch();
        await type('nothing');
        await submit();
        const a = $('[data-testid="search-empty"]').outerHTML;
        expect(a).toContain('No documents found');
        expect(a).toContain('Only documents you may open are searched.');
        wrapper.unmount();
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: { items: [], hasMore: false, page: 1 } });
        mountSearch();
        await type('somethingelse');
        await submit();
        expect($('[data-testid="search-empty"]').outerHTML).toBe(a);
    });

    it('shows the error state on a server error and Try again sends the same body', async () => {
        fetchMock.mockResolvedValueOnce({ ok: false, status: 500, error: 'failed', data: {} }).mockResolvedValueOnce(answer([DOC(1)]));
        mountSearch();
        await type('rep');
        await submit();
        expect($('[data-testid="search-error"]').textContent).toContain('We could not search.');
        expect($('[data-testid="search-error"]').textContent).not.toContain('500');
        $('[data-testid="search-retry"]').click();
        await flush();
        expect(fetchMock.mock.calls[1][1].body).toEqual(fetchMock.mock.calls[0][1].body);
        expect($$('[data-testid="search-row"]')).toHaveLength(1);
    });

    it.each([
        [419, 'Your session expired.'],
        [403, 'You can no longer search these documents.'],
        [404, 'You can no longer search these documents.'],
    ])('answers %i with its own text and no retry loop', async (status, text) => {
        fetchMock.mockResolvedValue({ ok: false, status, error: 'failed', data: {} });
        mountSearch();
        await type('rep');
        await submit();
        expect($('[data-testid="search-error"]').textContent).toContain(text);
        expect($('[data-testid="search-retry"]')).toBeNull();
    });

    it('treats a network failure and a 422 as the generic error', async () => {
        fetchMock.mockResolvedValueOnce({ ok: false, status: 0, error: 'network', data: {} });
        mountSearch();
        await type('rep');
        await submit();
        expect($('[data-testid="search-error"]')).not.toBeNull();
        fetchMock.mockResolvedValueOnce({ ok: false, status: 422, error: 'invalid', errors: {}, data: {} });
        await type('repo');
        await submit();
        expect($('[data-testid="search-error"]').textContent).toContain('We could not search.');
    });

    it('answers 429 with the throttled alert and ignores further searches for five seconds', async () => {
        fetchMock.mockResolvedValueOnce({ ok: false, status: 429, error: 'throttle', minutes: 1, data: {} }).mockResolvedValue(answer([DOC(1)]));
        mountSearch();
        await type('rep');
        await submit();
        expect($('[data-testid="search-throttled"]').textContent).toContain('You searched too often.');
        await submit();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        vi.advanceTimersByTime(5000);
        await flush();
        // the search that was asked for during the pause is run when the pause ends
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect($$('[data-testid="search-row"]')).toHaveLength(1);
    });

    it('hands a 423 to handleHealthRefusal and clears everything', async () => {
        fetchMock.mockResolvedValueOnce(answer([DOC(1)])).mockResolvedValueOnce({ ok: false, status: 423, error: 'reauth_required', data: {} });
        refusal.mockReturnValue(true);
        mountSearch();
        await type('rep');
        await submit();
        await type('repo');
        await submit();
        expect(refusal).toHaveBeenCalledWith('reauth_required');
        expect($('[data-testid="search-results"]')).toBeNull();
        expect($('[data-testid="search-input"]').value).toBe('');
        expect(wrapper.html()).not.toContain('Report 1');
    });

    it('clears with the button, the X, Escape and the empty-state button, and refocuses the input', async () => {
        fetchMock.mockResolvedValue(answer([DOC(1)]));
        mountSearch();
        await type('rep');
        await submit();
        $('[data-testid="search-clear-results"]').click();
        await flush();
        expect($('[data-testid="search-results"]')).toBeNull();
        expect($('[data-testid="search-input"]').value).toBe('');
        expect(document.activeElement).toBe($('[data-testid="search-input"]'));

        await type('rep');
        await submit();
        $('[data-testid="search-x"]').click();
        await flush();
        expect($('[data-testid="search-results"]')).toBeNull();

        await type('rep');
        await submit();
        $('[data-testid="search-input"]').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flush();
        expect($('[data-testid="search-results"]')).toBeNull();
        expect($('[data-testid="search-input"]').value).toBe('');

        fetchMock.mockResolvedValue(answer([]));
        await type('none');
        await submit();
        $('[data-testid="search-empty-clear"]').click();
        await flush();
        expect($('[data-testid="search-results"]')).toBeNull();
    });

    it('forgets a person-scoped search when another person is opened, and keeps "All people"', async () => {
        fetchMock.mockResolvedValue(answer([DOC(1)]));
        mountSearch();
        await type('rep');
        await submit();
        await wrapper.setProps({ person: { ...PERSON, id: PEOPLE[1].id, name: 'Boris' } });
        expect($$('[data-testid="search-row"]')).toHaveLength(1);
        const select = $('[data-testid="search-scope"]');
        select.value = 'person';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        await flush();
        await wrapper.setProps({ person: PERSON });
        await flush();
        expect($('[data-testid="search-results"]')).toBeNull();
    });

    it('emits the opening of a result instead of following the link', async () => {
        fetchMock.mockResolvedValue(answer([DOC(1)]));
        mountSearch();
        await type('rep');
        await submit();
        const click = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
        $('[data-testid="search-row"] a').dispatchEvent(click);
        expect(click.defaultPrevented).toBe(true);
        expect(wrapper.emitted('open')[0][0].documentId).toBe(DOC(1).documentId);
    });
});

describe('document search: privacy of the query', () => {
    it('never touches the router, history, location or browser storage, and keeps the query out of the page', async () => {
        const push = vi.spyOn(window.history, 'pushState');
        const replace = vi.spyOn(window.history, 'replaceState');
        const setItem = vi.spyOn(Storage.prototype, 'setItem');
        const before = window.location.href;
        fetchMock.mockResolvedValueOnce(answer([DOC(1, { snippets: [[{ text: 'secretword', hit: true }]] })], { hasMore: true })).mockResolvedValueOnce(answer([DOC(2)]));
        mountSearch();
        await type('secretword');
        await submit();
        $('[data-testid="search-more"]').click();
        await flush();
        $('[data-testid="search-clear-results"]').click();
        await flush();
        for (const fn of Object.values(router)) expect(fn).not.toHaveBeenCalled();
        expect(push).not.toHaveBeenCalled();
        expect(replace).not.toHaveBeenCalled();
        expect(setItem).not.toHaveBeenCalled();
        expect(window.location.href).toBe(before);
        expect(window.localStorage.length + window.sessionStorage.length).toBe(0);
        push.mockRestore(); replace.mockRestore(); setItem.mockRestore();
    });

    it('has no browser storage, history, router, v-html or console use in the search sources', () => {
        const dir = `${process.cwd()}/resources/js/Health/Search`;
        for (const file of readdirSync(dir)) {
            const src = readFileSync(`${dir}/${file}`, 'utf8');
            expect(src, file).not.toMatch(/v-html|localStorage|sessionStorage|indexedDB|caches\.|history\.|location\.|console\./);
            if (file.endsWith('.js')) expect(src, file).not.toMatch(/\brouter\b/);
        }
    });
});

describe('Health person page mount', () => {
    let page;
    function mountPage(props = {}) {
        window.innerWidth = 1280;
        window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
        mockProps = reactive({
            locale: 'en', flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
            auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: 'none', health: 'edit' } },
            app: { env: 'local', styleGuide: false },
        });
        page = mount(Person, { props: { people: PEOPLE, person: PERSON, canCreate: true, panel: null, documents: [], visits: [], ...props }, attachTo: document.body, global: { plugins: [createAppI18n('en', loadMessages('en'))] } });
        return page;
    }
    afterEach(() => { page?.unmount(); page = null; });

    it('is not rendered when search.enabled is false or there is no person', () => {
        mountPage({ search: { enabled: false, textReliability: 'reliable' } });
        expect($('[data-testid="doc-search"]')).toBeNull();
        page.unmount();
        mountPage({ person: null, people: [], search: { enabled: true, textReliability: 'reliable' } });
        expect($('[data-testid="doc-search"]')).toBeNull();
    });

    it('is mounted once above the views and replaces them while results are shown, then restores them', async () => {
        fetchMock.mockResolvedValue(answer([DOC(1)]));
        mountPage({ search: { enabled: true, textReliability: 'reliable' }, documents: [{ id: 'd1', title: 'Doc', kind: 'pdf', uploadedOn: '2026-01-01', visitId: null }], visits: [] });
        expect($$('[data-testid="doc-search"]')).toHaveLength(1);
        expect($('[data-testid="folders"]')).not.toBeNull();
        await type('rep');
        await submit();
        expect($('[data-testid="folders"]')).toBeNull();
        expect($('[data-testid="search-results"]')).not.toBeNull();
        $('[data-testid="search-clear-results"]').click();
        await flush();
        expect($('[data-testid="folders"]')).not.toBeNull();
    });

    it('opens a result of the open person as a panel visit and one of another person as that person\'s page', async () => {
        fetchMock.mockResolvedValue(answer([DOC(1), DOC(2, { personId: PEOPLE[1].id, personName: 'Boris' })]));
        mountPage({ search: { enabled: true, textReliability: 'reliable' } });
        await type('rep');
        await submit();
        const links = $$('[data-testid="search-row"] a');
        links[0].click();
        expect(router.get).toHaveBeenLastCalledWith(expect.any(String), { panel: 'document', id: DOC(1).documentId }, expect.objectContaining({ only: ['panel'] }));
        links[1].click();
        expect(router.get).toHaveBeenLastCalledWith(`/health/people/${PEOPLE[1].id}`, { panel: 'document', id: DOC(2).documentId }, expect.objectContaining({ preserveState: true }));
    });
});
