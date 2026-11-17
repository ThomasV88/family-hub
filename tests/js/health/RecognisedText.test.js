import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick, ref } from 'vue';
import { readFileSync, readdirSync } from 'node:fs';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

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
import DocumentPanel from '../../../resources/js/Health/DocumentPanel.vue';
import { copyFormat } from '../../../resources/js/Health/Text/recognisedText.js';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const PID = '11111111-1111-4111-8111-111111111111';
const DOC = { id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', kind: 'pdf', title: 'Lab <b>result</b>', visitId: null, uploadedOn: '2025-05-02', sizeBytes: 2048, hasText: true };
const TEXT_URL = `/health/people/${PID}/documents/${DOC.id}/text`;
const one = { pages: [{ page: 1, text: 'Hemoglobin 140 g/l\nGlucose 5.1' }], pagesRead: 1, pagesTotal: 1, complete: true, language: 'sk' };
const three = { pages: [{ page: 1, text: 'First page' }, { page: 2, text: '' }, { page: 3, text: 'Third page' }], pagesRead: 3, pagesTotal: 3, complete: true, language: null };
const ok = (data) => ({ ok: true, status: 200, data });
const fail = (status, error = 'failed') => ({ ok: false, status, error, data: {} });
const deferred = () => { let resolve; const promise = new Promise((r) => { resolve = r; }); return { promise, resolve }; };
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

beforeEach(() => { fetchMock.mockReset(); refusal.mockReset(); Object.values(router).forEach((fn) => fn.mockClear?.()); useToast().toasts.splice(0); });
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; vi.restoreAllMocks(); });

describe('copyFormat', () => {
    it('is the bare text for one page', () => {
        expect(copyFormat([{ page: 1, text: 'Only page' }])).toBe('Only page');
        expect(copyFormat([])).toBe('');
    });
    it('introduces every later page with a blank line and its label, and nothing before the first page', () => {
        expect(copyFormat(three.pages, (n) => `Page ${n}`)).toBe('First page\n\nPage 2\n\n\nPage 3\nThird page');
        expect(copyFormat(three.pages, (n) => `Strana ${n}`).match(/Strana \d/g)).toEqual(['Strana 2', 'Strana 3']);
    });
});

describe('recognised text section', () => {
    it('is hidden without text and without a run, and collapsed by default with the text not fetched', () => {
        mountText({ document: { ...DOC, hasText: false } });
        expect($('[data-testid="recognised-text"]')).toBeNull();
        wrapper.unmount();
        mountText();
        const toggle = $('[data-testid="rtext-toggle"]');
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        expect(toggle.getAttribute('aria-controls')).toBe($('[data-testid="rtext-body"]').id);
        expect($('[data-testid="rtext-body"]').hidden).toBe(true);
        expect(fetchMock).not.toHaveBeenCalled();
        expect($('[data-testid="recognised-text"]').getAttribute('aria-labelledby')).toBe($('h3').id);
    });

    it('fetches once on expand (plain GET, no query string) with a busy skeleton, then shows the text', async () => {
        const pending = deferred();
        fetchMock.mockReturnValueOnce(pending.promise);
        mountText();
        $('[data-testid="rtext-toggle"]').click();
        await flush();
        expect($('[data-testid="rtext-loading"]').getAttribute('aria-busy')).toBe('true');
        expect($$('[data-testid="rtext-loading"] .ui-skeleton')).toHaveLength(5);
        pending.resolve(ok(one));
        await flush();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledWith(TEXT_URL, expect.objectContaining({ method: 'GET', replay: true }));
        expect(fetchMock.mock.calls[0][0]).not.toContain('?');
        expect($('[data-testid="rtext-toggle"]').getAttribute('aria-expanded')).toBe('true');
        expect($('[data-testid="rtext-text"]').textContent).toContain('Hemoglobin 140 g/l');
        expect($('[data-testid="rtext-info"]').textContent).toContain('Read by AI from the document.');
    });

    it('draws the text as plain pre-wrap strings with a named, focusable, language-tagged region', async () => {
        fetchMock.mockResolvedValue(ok({ ...one, pages: [{ page: 1, text: '<script>alert(1)</script> <b>x</b>' }] }));
        mountText();
        await expand();
        const box = $('[data-testid="rtext-text"]');
        expect(box.getAttribute('role')).toBe('region');
        expect(box.getAttribute('tabindex')).toBe('0');
        expect(box.getAttribute('aria-label')).toBe('Recognised text of Lab <b>result</b>');
        expect(box.getAttribute('lang')).toBe('sk');
        expect(box.textContent).toContain('<script>alert(1)</script> <b>x</b>');
        expect(box.querySelector('script')).toBeNull();
        expect(box.querySelector('b')).toBeNull();
        expect(box.querySelector('.rtext__pagelabel')).toBeNull();
    });

    it('omits lang for an unknown language and labels the pages of a multi-page text, with a note for an empty page', async () => {
        fetchMock.mockResolvedValue(ok(three));
        mountText();
        await expand();
        const box = $('[data-testid="rtext-text"]');
        expect(box.hasAttribute('lang')).toBe(false);
        expect($$('.rtext__pagelabel').map((n) => n.textContent)).toEqual(['Page 1', 'Page 2', 'Page 3']);
        expect(box.textContent).toContain('Nothing legible on this page.');
    });

    it('shows "May contain errors" only when uncertain and the partial tag and alert only for a partial reading', async () => {
        fetchMock.mockResolvedValue(ok({ ...one, complete: false, pagesRead: 3, pagesTotal: 8 }));
        mountText({ reliability: 'uncertain' });
        await expand();
        expect($('[data-testid="rtext-tag-errors"]').textContent).toContain('May contain errors');
        expect($('[data-testid="rtext-tag-partial"]').textContent).toContain('First 3 of 8 pages');
        expect($('[data-testid="rtext-partial-alert"]').textContent).toContain('Only the first 3 of 8 pages were read');
        wrapper.unmount();
        fetchMock.mockResolvedValue(ok(one));
        mountText({ reliability: 'reliable' });
        await expand();
        expect($('[data-testid="rtext-tag-errors"]')).toBeNull();
        expect($('[data-testid="rtext-tag-partial"]')).toBeNull();
        expect($('[data-testid="rtext-partial-alert"]')).toBeNull();
    });

    it('drops the text from memory when collapsed and fetches again on the next expand', async () => {
        fetchMock.mockResolvedValue(ok(one));
        mountText();
        await expand();
        await expand();
        expect($('[data-testid="rtext-text"]')).toBeNull();
        expect(wrapper.html()).not.toContain('Hemoglobin');
        await expand();
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('drops the text when another document is shown', async () => {
        fetchMock.mockResolvedValue(ok(one));
        mountText();
        await expand();
        await wrapper.setProps({ document: { ...DOC, id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2', hasText: true } });
        await flush();
        expect(wrapper.html()).not.toContain('Hemoglobin');
        expect($('[data-testid="rtext-toggle"]').getAttribute('aria-expanded')).toBe('false');
    });

    it('ignores an answer that arrives after the section was collapsed', async () => {
        const pending = deferred();
        fetchMock.mockReturnValueOnce(pending.promise);
        mountText();
        $('[data-testid="rtext-toggle"]').click();
        await flush();
        $('[data-testid="rtext-toggle"]').click();
        await flush();
        pending.resolve(ok(one));
        await flush();
        expect(wrapper.html()).not.toContain('Hemoglobin');
    });
});

describe('recognised text failures', () => {
    it.each([403, 404])('makes the section disappear silently on a %i', async (status) => {
        fetchMock.mockResolvedValue(fail(status));
        mountText();
        await expand();
        expect($('[data-testid="recognised-text"]')).toBeNull();
        expect(document.body.textContent).not.toContain('could not');
    });

    it('shows the load failure with Try again on a 500 and recovers', async () => {
        fetchMock.mockResolvedValueOnce(fail(500)).mockResolvedValueOnce(ok(one));
        mountText();
        await expand();
        expect($('[data-testid="rtext-load-failed"]').textContent).toContain('We could not load the text.');
        $('[data-testid="rtext-retry"]').click();
        await flush();
        expect($('[data-testid="rtext-text"]').textContent).toContain('Hemoglobin');
    });

    it('says the session expired on a 419, with no retry', async () => {
        fetchMock.mockResolvedValue(fail(419));
        mountText();
        await expand();
        expect($('[data-testid="rtext-load-failed"]').textContent).toContain('Your session expired.');
        expect($('[data-testid="rtext-retry"]')).toBeNull();
    });

    it('hands a 423 to handleHealthRefusal and shows nothing', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 423, error: 'reauth_required', data: {} });
        refusal.mockReturnValue(true);
        mountText();
        await expand();
        expect(refusal).toHaveBeenCalledWith('reauth_required');
        expect($('[data-testid="rtext-text"]')).toBeNull();
        expect($('[data-testid="rtext-load-failed"]')).toBeNull();
    });

    it('treats a network failure and 429 as the generic load failure', async () => {
        fetchMock.mockResolvedValueOnce({ ok: false, status: 0, error: 'network', data: {} });
        mountText();
        await expand();
        expect($('[data-testid="rtext-load-failed"]')).not.toBeNull();
        fetchMock.mockResolvedValueOnce({ ok: false, status: 429, error: 'throttle', data: {} });
        $('[data-testid="rtext-retry"]').click();
        await flush();
        expect($('[data-testid="rtext-load-failed"]').textContent).toContain('We could not load the text.');
    });
});

describe('copy', () => {
    it('writes the formatted text to the clipboard and toasts', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        fetchMock.mockResolvedValue(ok(three));
        mountText();
        await expand();
        $('[data-testid="rtext-copy"]').click();
        await flush();
        expect(writeText).toHaveBeenCalledWith('First page\n\nPage 2\n\n\nPage 3\nThird page');
        expect(useToast().toasts.map((x) => x.message)).toContain('Text copied');
    });

    it('toasts the manual-copy message when the clipboard refuses', async () => {
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn().mockRejectedValue(new Error('denied')) }, configurable: true });
        fetchMock.mockResolvedValue(ok(one));
        mountText();
        await expand();
        $('[data-testid="rtext-copy"]').click();
        await flush();
        expect(useToast().toasts.map((x) => x.message)).toContain('Could not copy. Select the text and copy it yourself.');
    });

    it('works without a clipboard object at all', async () => {
        Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
        fetchMock.mockResolvedValue(ok(one));
        mountText();
        await expand();
        $('[data-testid="rtext-copy"]').click();
        await flush();
        expect(useToast().toasts.map((x) => x.message)).toContain('Could not copy. Select the text and copy it yourself.');
    });
});

describe('remove', () => {
    it('is not rendered for a view-only member', async () => {
        fetchMock.mockResolvedValue(ok(one));
        mountText({ canEdit: false });
        await expand();
        expect($('[data-testid="rtext-remove"]')).toBeNull();
        expect($('[data-testid="rtext-copy"]')).not.toBeNull();
        expect(document.body.textContent).not.toContain('Remove the recognised text?');
    });

    it('asks first, then sends one DELETE, toasts, hides the section and refreshes the page props', async () => {
        fetchMock.mockResolvedValueOnce(ok(one)).mockResolvedValueOnce({ ok: true, status: 204, data: {} });
        mountText();
        await expand();
        $('[data-testid="rtext-remove"]').click();
        await flush();
        const dialog = $('[role="dialog"]');
        expect(dialog.textContent).toContain('Remove the recognised text?');
        expect(dialog.textContent).toContain('Copies in backups are removed when the backups expire.');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        [...dialog.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Remove text').click();
        await flush();
        expect(fetchMock).toHaveBeenLastCalledWith(TEXT_URL, expect.objectContaining({ method: 'DELETE', replay: true }));
        expect(useToast().toasts.map((x) => x.message)).toContain('Recognised text removed');
        expect($('[data-testid="recognised-text"]')).toBeNull();
        expect(router.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['documents', 'search'] }));
    });

    it('"Keep text" sends nothing', async () => {
        fetchMock.mockResolvedValueOnce(ok(one));
        mountText();
        await expand();
        $('[data-testid="rtext-remove"]').click();
        await flush();
        [...$('[role="dialog"]').querySelectorAll('button')].find((b) => b.textContent.trim() === 'Keep text').click();
        await flush();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect($('[data-testid="rtext-text"]')).not.toBeNull();
    });

    it('shows the inline failure on a 500 and a session text on a 419, and keeps the text', async () => {
        fetchMock.mockResolvedValueOnce(ok(one)).mockResolvedValueOnce(fail(500)).mockResolvedValueOnce(fail(419));
        mountText();
        await expand();
        const confirm = async () => {
            $('[data-testid="rtext-remove"]').click();
            await flush();
            [...$('[role="dialog"]').querySelectorAll('button')].find((b) => b.textContent.trim() === 'Remove text').click();
            await flush();
        };
        await confirm();
        expect($('[data-testid="rtext-remove-failed"]').textContent).toContain('The text could not be removed. Try again.');
        expect($('[data-testid="rtext-text"]')).not.toBeNull();
        await confirm();
        expect($('[data-testid="rtext-remove-failed"]').textContent).toContain('Your session expired.');
    });

    it('goes to handleHealthRefusal on a 423', async () => {
        fetchMock.mockResolvedValueOnce(ok(one)).mockResolvedValueOnce({ ok: false, status: 423, error: 'confirm_required', data: {} });
        refusal.mockReturnValue(true);
        mountText();
        await expand();
        $('[data-testid="rtext-remove"]').click();
        await flush();
        [...$('[role="dialog"]').querySelectorAll('button')].find((b) => b.textContent.trim() === 'Remove text').click();
        await flush();
        expect(refusal).toHaveBeenCalledWith('confirm_required');
        expect($('[data-testid="rtext-remove-failed"]')).toBeNull();
    });
});

describe('runs in progress', () => {
    it('says the text is being read when a run is active and no text exists, without a disclosure', () => {
        mountText({ document: { ...DOC, hasText: false }, runActive: true });
        expect($('[data-testid="rtext-being-read"]').textContent).toBe('The text is being read. It will appear here when it is saved.');
        expect($('[data-testid="rtext-toggle"]')).toBeNull();
    });

    it('keeps the earlier text readable with a note above it while a new reading runs', async () => {
        fetchMock.mockResolvedValue(ok(one));
        mountText({ runActive: true });
        expect($('[data-testid="rtext-rereading"]').textContent).toBe('A new reading is in progress. This is the earlier text.');
        await expand();
        expect($('[data-testid="rtext-text"]').textContent).toContain('Hemoglobin');
    });
});

describe('document panel mount', () => {
    const poller = (statuses = {}) => ({ statuses: ref(statuses), available: ref(null), failedPolls: ref(0), stoppedLongWait: ref(false), stalled: ref(false), refresh: vi.fn(async () => {}), markQueued: vi.fn() });
    const mountPanel = (props = {}) => {
        wrapper = mount(DocumentPanel, {
            props: { open: true, personId: PID, document: DOC, visits: [], canEdit: true, ai: { canRun: true, available: true, noticeAcknowledged: true }, aiStatus: poller(), consentOn: true, textReliability: 'uncertain', ...props },
            attachTo: document.body,
            global: { plugins: [createAppI18n('en', loadMessages('en'))] },
        });
    };

    it('sits between Run AI and the edit form for editors', () => {
        mountPanel();
        const order = $$('[data-testid="ai-run"], [data-testid="recognised-text"], [data-testid="document-form"]').map((n) => n.dataset.testid);
        expect(order).toEqual(['ai-run', 'recognised-text', 'document-form']);
        // the reliability prop of the page reaches the header tag
        expect($('[data-testid="rtext-tag-errors"]')).not.toBeNull();
    });

    it('sits before the read-only list for view-only members, without Remove', async () => {
        fetchMock.mockResolvedValue(ok(one));
        mountPanel({ canEdit: false, ai: { canRun: false, available: true, noticeAcknowledged: true } });
        const order = $$('[data-testid="recognised-text"], [data-testid="document-readonly"]').map((n) => n.dataset.testid);
        expect(order).toEqual(['recognised-text', 'document-readonly']);
        await expand();
        expect($('[data-testid="rtext-remove"]')).toBeNull();
    });

    it('shows the being-read line while a run is queued and no text exists', () => {
        mountPanel({ document: { ...DOC, hasText: false }, aiStatus: poller({ [DOC.id]: { status: 'queued', extractionId: 'e1' } }) });
        expect($('[data-testid="rtext-being-read"]')).not.toBeNull();
    });

    it('moves focus to Run AI after the text was removed', async () => {
        fetchMock.mockResolvedValueOnce(ok(one)).mockResolvedValueOnce({ ok: true, status: 204, data: {} });
        mountPanel();
        await expand();
        $('[data-testid="rtext-remove"]').click();
        await flush();
        [...$$('[role="dialog"]').find((d) => d.textContent.includes('Remove the recognised text?')).querySelectorAll('button')].find((b) => b.textContent.trim() === 'Remove text').click();
        await flush();
        expect(document.activeElement).toBe($('[data-testid="ai-run"]'));
    });
});

describe('source', () => {
    it('has no v-html, storage, history or console use in the text components', () => {
        const dir = `${process.cwd()}/resources/js/Health/Text`;
        for (const file of readdirSync(dir)) {
            expect(readFileSync(`${dir}/${file}`, 'utf8'), file).not.toMatch(/v-html|localStorage|sessionStorage|indexedDB|caches\.|history\.|console\./);
        }
    });
});
