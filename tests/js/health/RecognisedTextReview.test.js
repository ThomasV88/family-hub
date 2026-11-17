import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick } from 'vue';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

// Phase 13 review fixes of the recognised text section: nothing stale in memory, no browser caching, paragraphs, plurals.
const { fetchMock, refusal, router } = vi.hoisted(() => ({
    fetchMock: vi.fn(),
    refusal: vi.fn(),
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn() },
}));
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: fetchMock }));
vi.mock('../../../resources/js/Health/healthRefusal.js', () => ({ handleHealthRefusal: refusal }));
vi.mock('@inertiajs/vue3', () => ({
    router,
    usePage: () => ({ props: {}, url: '/health' }),
    Head: { props: ['title'], setup: () => () => null },
    Link: { props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) },
}));

import RecognisedText from '../../../resources/js/Health/Text/RecognisedText.vue';
import { paragraphs } from '../../../resources/js/Health/Text/recognisedText.js';

const PID = '11111111-1111-4111-8111-111111111111';
const DOC = { id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', kind: 'pdf', title: 'Lab result', visitId: null, uploadedOn: '2025-05-02', sizeBytes: 2048, hasText: true };
const TEXT_URL = `/health/people/${PID}/documents/${DOC.id}/text`;
const one = { pages: [{ page: 1, text: 'Hemoglobin 140 g/l' }], pagesRead: 1, pagesTotal: 1, complete: true, language: 'sk' };
const ok = (data) => ({ ok: true, status: 200, data });
const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => [...document.querySelectorAll(sel)];
const flush = async () => { for (let i = 0; i < 6; i += 1) { await Promise.resolve(); await nextTick(); } };

let wrapper;
function mountText(props = {}, locale = 'en') {
    wrapper = mount(RecognisedText, {
        props: { personId: PID, document: DOC, canEdit: true, reliability: 'reliable', runActive: false, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, loadMessages(locale))] },
    });
    return wrapper;
}
const expand = async () => { $('[data-testid="rtext-toggle"]').click(); await flush(); };

beforeEach(() => { fetchMock.mockReset(); refusal.mockReset(); });
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; });

describe('paragraphs()', () => {
    it('splits on blank lines and keeps single line breaks inside a paragraph', () => {
        expect(paragraphs('a\nb\n\nc\n\n\n\nd')).toEqual(['a\nb', 'c', 'd']);
        expect(paragraphs('')).toEqual([]);
        expect(paragraphs(null)).toEqual([]);
        expect(paragraphs('  \n\n  ')).toEqual([]);
    });
});

describe('recognised text section: review fixes', () => {
    it('asks the browser never to keep the text answer', async () => {
        fetchMock.mockResolvedValue(ok(one));
        mountText();
        await expand();
        expect(fetchMock).toHaveBeenCalledWith(TEXT_URL, expect.objectContaining({ method: 'GET', cache: 'no-store' }));
    });

    it('drops the text from memory when the server no longer has one, and fetches again for a text saved later', async () => {
        fetchMock.mockResolvedValue(ok(one));
        mountText();
        await expand();
        expect($('[data-testid="rtext-text"]').textContent).toContain('Hemoglobin');
        await wrapper.setProps({ document: { ...DOC, hasText: false } });
        await flush();
        expect($('[data-testid="recognised-text"]')).toBeNull();
        expect(document.body.textContent).not.toContain('Hemoglobin');
        fetchMock.mockClear();
        await wrapper.setProps({ document: { ...DOC, hasText: true } });
        await flush();
        // the section is closed again and nothing is shown or fetched until it is opened
        expect(fetchMock).not.toHaveBeenCalled();
        expect($('[data-testid="rtext-toggle"]').getAttribute('aria-expanded')).toBe('false');
        expect(document.body.textContent).not.toContain('Hemoglobin');
        await expand();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('draws a long page as one block per paragraph, never one huge text node', async () => {
        const parts = Array.from({ length: 40 }, (_, i) => `Paragraph ${i} line a\nline b`);
        fetchMock.mockResolvedValue(ok({ pages: [{ page: 1, text: parts.join('\n\n') }], pagesRead: 1, pagesTotal: 1, complete: true, language: 'en' }));
        mountText();
        await expand();
        const blocks = $$('[data-testid="rtext-text"] p.rtext__text');
        expect(blocks).toHaveLength(40);
        expect(blocks[3].textContent).toBe('Paragraph 3 line a\nline b');
        expect(Math.max(...blocks.map((b) => b.textContent.length))).toBeLessThan(100);
    });

    it('says "first page" for one page and uses the plural forms in Slovak and Polish', async () => {
        const cases = [
            ['en', 1, 'First page of 9 pages'], ['en', 4, 'First 4 of 9 pages'],
            ['sk', 1, 'Prvá strana z 9 strán'], ['sk', 3, 'Prvé 3 z 9 strán'], ['sk', 6, 'Prvých 6 z 9 strán'],
            ['pl', 1, 'Pierwsza strona z 9 stron'], ['pl', 3, 'Pierwsze 3 z 9 stron'], ['pl', 6, 'Pierwszych 6 z 9 stron'],
        ];
        for (const [locale, pagesRead, expected] of cases) {
            const pages = Array.from({ length: pagesRead }, (_, i) => ({ page: i + 1, text: 'x' }));
            fetchMock.mockResolvedValue(ok({ pages, pagesRead, pagesTotal: 9, complete: false, language: null }));
            mountText({}, locale);
            await expand();
            expect($('[data-testid="rtext-tag-partial"]').textContent.trim()).toBe(expected);
            expect($('[data-testid="rtext-partial-alert"]').textContent).not.toMatch(/\{|health\./);
            wrapper.unmount();
            document.body.innerHTML = '';
        }
    });
});
