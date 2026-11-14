import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { defineComponent, h, nextTick } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';

const { fetchMock, refusal } = vi.hoisted(() => ({ fetchMock: vi.fn(), refusal: vi.fn() }));
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: fetchMock }));
vi.mock('../../../resources/js/Health/healthRefusal.js', () => ({ handleHealthRefusal: refusal }));

import { useAiStatus } from '../../../resources/js/Health/Ai/useAiStatus.js';
import AiStatusTag from '../../../resources/js/Health/Ai/AiStatusTag.vue';
import AiNoticeDialog from '../../../resources/js/Health/Ai/AiNoticeDialog.vue';

const PID = '11111111-1111-4111-8111-111111111111';
const DOC = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
const running = { [DOC]: { status: 'running', extractionId: 'x1', readyExtractionId: null, cancelReason: null, pagesRead: null, pagesTotal: null } };
const ready = { [DOC]: { ...running[DOC], status: 'ready' } };
const ok = (documents) => ({ ok: true, status: 200, data: { documents, available: true } });

let wrapper;
function setup(initial) {
    let api;
    wrapper = mount(defineComponent({ setup() { api = useAiStatus(PID, initial); return () => h('div'); } }));
    return api;
}
function setHidden(value) {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => value });
    document.dispatchEvent(new Event('visibilitychange'));
}

afterEach(() => { wrapper?.unmount(); wrapper = null; setHidden(false); vi.useRealTimers(); document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); });

describe('useAiStatus polling', () => {
    beforeEach(() => { vi.useFakeTimers(); fetchMock.mockReset(); refusal.mockReset(); });
    it('polls the status route every 5 s while a run is active and stops once everything is terminal', async () => {
        fetchMock.mockResolvedValueOnce(ok(running)).mockResolvedValueOnce(ok(ready));
        const api = setup(running);
        await vi.advanceTimersByTimeAsync(4999);
        expect(fetchMock).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledWith(`/health/people/${PID}/ai-status`);
        await vi.advanceTimersByTimeAsync(5000);
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(api.statuses.value[DOC].status).toBe('ready');
        await vi.advanceTimersByTimeAsync(60000);
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('does not poll at all when nothing is queued or running', async () => {
        setup(ready);
        await vi.advanceTimersByTimeAsync(60000);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('backs off to 15 s after 2 minutes and gives up after 15 minutes', async () => {
        fetchMock.mockResolvedValue(ok(running));
        const api = setup(running);
        await vi.advanceTimersByTimeAsync(2 * 60 * 1000);
        const early = fetchMock.mock.calls.length;
        expect(early).toBeGreaterThanOrEqual(23);
        const before = fetchMock.mock.calls.length;
        await vi.advanceTimersByTimeAsync(60000);
        const perMinute = fetchMock.mock.calls.length - before;
        expect(perMinute).toBeLessThanOrEqual(5);
        expect(perMinute).toBeGreaterThanOrEqual(3);
        await vi.advanceTimersByTimeAsync(14 * 60 * 1000);
        expect(api.stoppedLongWait.value).toBe(true);
        const stopped = fetchMock.mock.calls.length;
        await vi.advanceTimersByTimeAsync(5 * 60 * 1000);
        expect(fetchMock.mock.calls.length).toBe(stopped);
    });

    it('pauses while the tab is hidden and resumes on visibilitychange', async () => {
        fetchMock.mockResolvedValue(ok(running));
        setup(running);
        setHidden(true);
        await vi.advanceTimersByTimeAsync(60000);
        expect(fetchMock).not.toHaveBeenCalled();
        setHidden(false);
        await vi.advanceTimersByTimeAsync(0);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(5000);
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('sends a 423 to the refusal handler and stops polling', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 423, error: 'reauth_required', data: {} });
        setup(running);
        await vi.advanceTimersByTimeAsync(5000);
        expect(refusal).toHaveBeenCalledWith('reauth_required');
        await vi.advanceTimersByTimeAsync(60000);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('keeps the last state on an error and flags three consecutive errors', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 0, error: 'network', data: {} });
        const api = setup(running);
        await vi.advanceTimersByTimeAsync(5000);
        await vi.advanceTimersByTimeAsync(5000);
        expect(api.failedPolls.value).toBe(2);
        await vi.advanceTimersByTimeAsync(5000);
        expect(api.failedPolls.value).toBe(3);
        expect(api.statuses.value[DOC].status).toBe('running');
        fetchMock.mockResolvedValue(ok(running));
        await vi.advanceTimersByTimeAsync(5000);
        expect(api.failedPolls.value).toBe(0);
    });

    it('refresh() polls immediately and markQueued() shows the run as waiting', async () => {
        fetchMock.mockResolvedValue(ok(running));
        const api = setup({});
        api.markQueued(DOC, 'x9');
        expect(api.statuses.value[DOC].status).toBe('queued');
        await api.refresh();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('writes nothing to any browser storage', async () => {
        const local = vi.spyOn(Storage.prototype, 'setItem');
        const idb = vi.fn();
        const original = window.indexedDB;
        Object.defineProperty(window, 'indexedDB', { configurable: true, value: { open: idb } });
        fetchMock.mockResolvedValue(ok(ready));
        setup(running);
        await vi.advanceTimersByTimeAsync(10000);
        expect(local).not.toHaveBeenCalled();
        expect(idb).not.toHaveBeenCalled();
        local.mockRestore();
        Object.defineProperty(window, 'indexedDB', { configurable: true, value: original });
    });
});

const mountTag = (props, locale = 'en', messages = loadMessages(locale)) =>
    mount(AiStatusTag, { props, attachTo: document.body, global: { plugins: [createAppI18n(locale, messages)] } });

describe('AiStatusTag', () => {
    const cases = [
        [{ status: 'queued' }, 'Waiting for AI'],
        [{ status: 'running' }, 'AI is reading'],
        [{ status: 'ready', entryCount: 3 }, 'Suggestions ready'],
        [{ status: 'ready', entryCount: 0 }, 'Nothing to suggest'],
        [{ status: 'failed' }, 'AI could not read this'],
        [{ status: 'cancelled', cancelReason: 'user' }, 'Cancelled'],
        [{ status: 'cancelled', cancelReason: 'consent' }, 'Stopped: AI is off'],
        [{ status: 'superseded' }, 'Replaced by a newer run'],
    ];
    it.each(cases)('renders text and an aria-hidden icon for %j', (props, text) => {
        const w = mountTag(props);
        expect(w.text()).toBe(text);
        expect(w.find('svg').attributes('aria-hidden')).toBe('true');
        w.unmount();
    });

    it('uses a neutral tag for "Nothing to suggest" and success otherwise', () => {
        const empty = mountTag({ status: 'ready', entryCount: 0 });
        expect(empty.classes()).toContain('ui-tag--neutral');
        empty.unmount();
        const some = mountTag({ status: 'ready', entryCount: 2 });
        expect(some.classes()).toContain('ui-tag--success');
        some.unmount();
    });

    it('lets the running loader turn only under the no-preference motion query', () => {
        const source = readStyle();
        const block = source.slice(source.indexOf('@media'));
        expect(block).toContain('prefers-reduced-motion: no-preference');
        expect(source.slice(0, source.indexOf('@media'))).not.toContain('animation:');
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`has no axe violations for every state in ${locale} ${dark ? 'dark' : 'light'}`, async () => {
                document.documentElement.classList.toggle('dark', dark);
                const wrappers = cases.map(([props]) => mountTag(props, locale));
                for (const w of wrappers) await expectNoAxeViolations(w.element);
                wrappers.forEach((w) => w.unmount());
            });
        }
    }

    it('wraps pseudo-long text instead of truncating it', () => {
        const w = mountTag({ status: 'cancelled', cancelReason: 'consent' }, 'en', pseudoLong(loadMessages('en')));
        expect(w.text().length).toBeGreaterThan('Stopped: AI is off'.length * 1.39);
        const style = readStyle();
        expect(style).toContain('white-space: normal');
        expect(style).not.toContain('text-overflow');
        w.unmount();
    });
});

function readStyle() {
    return readFileSync(`${process.cwd()}/resources/js/Health/Ai/AiStatusTag.vue`, 'utf8');
}

describe('AiNoticeDialog', () => {
    const mountDialog = (locale = 'en') => mount(AiNoticeDialog, { props: { open: true }, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    const $ = (sel) => document.querySelector(sel);

    it('focuses "Keep document private" first and cancels on it, Esc and the scrim', async () => {
        const w = mountDialog();
        await nextTick();
        await nextTick();
        expect(document.activeElement).toBe($('[data-testid="ai-notice-cancel"]'));
        expect($('[role="dialog"]').textContent).toContain('Send this document to OpenAI?');
        $('[data-testid="ai-notice-cancel"]').click();
        expect(w.emitted('cancel')).toHaveLength(1);
        $('.ui-dialog__scrim').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(w.emitted('cancel')).toHaveLength(2);
        $('.ui-dialog__scrim').click();
        expect(w.emitted('cancel')).toHaveLength(3);
        w.unmount();
    });

    it('emits confirm from the primary button only', async () => {
        const w = mountDialog();
        await nextTick();
        $('[data-testid="ai-notice-confirm"]').click();
        expect(w.emitted('confirm')).toHaveLength(1);
        expect(w.emitted('cancel')).toBeUndefined();
        w.unmount();
    });

    for (const locale of ['en', 'sk', 'pl']) {
        it(`has no axe violations in ${locale}`, async () => {
            const w = mountDialog(locale);
            await nextTick();
            await expectNoAxeViolations(document.body);
            w.unmount();
        });
    }
});
