import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';

const { fetchMock } = vi.hoisted(() => ({ fetchMock: vi.fn() }));
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: fetchMock }));

import { useImportStatus, POLL_INTERVAL, GIVE_UP_AFTER } from '../../../resources/js/Components/Recipes/useImportStatus.js';

const ID = '11111111-1111-4111-8111-111111111111';
const answer = (status, extra = {}) => ({ ok: true, status: 200, data: { id: ID, status, reason: null, host: 'recepty.example', textCut: false, ...extra } });
let scope;
const make = (id = ID, options = {}) => {
    scope = effectScope();
    return scope.run(() => useImportStatus(ref(id), options));
};
const tick = async (ms) => { await vi.advanceTimersByTimeAsync(ms); };
const setHidden = (hidden) => {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden });
    document.dispatchEvent(new Event('visibilitychange'));
};

beforeEach(() => { vi.useFakeTimers(); fetchMock.mockReset(); setHidden(false); });
afterEach(() => { scope?.stop(); scope = null; vi.useRealTimers(); });

describe('useImportStatus', () => {
    it('polls every 2 seconds and stops on a terminal status', async () => {
        fetchMock.mockResolvedValueOnce(answer('queued')).mockResolvedValueOnce(answer('running')).mockResolvedValueOnce(answer('ready'));
        const poll = make();
        expect(fetchMock).not.toHaveBeenCalled();
        await tick(POLL_INTERVAL);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledWith(`/recipes/imports/${ID}`);
        await tick(POLL_INTERVAL);
        await tick(POLL_INTERVAL);
        expect(fetchMock).toHaveBeenCalledTimes(3);
        expect(poll.status.value).toBe('ready');
        expect(poll.terminal.value).toBe(true);
        await tick(POLL_INTERVAL * 5);
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    it('keeps the reason and the host of a failed import', async () => {
        fetchMock.mockResolvedValueOnce(answer('failed', { reason: 'no_recipe' }));
        const poll = make();
        await tick(POLL_INTERVAL);
        expect(poll.state.value).toMatchObject({ status: 'failed', reason: 'no_recipe', host: 'recepty.example' });
        expect(poll.terminal.value).toBe(true);
    });

    it('never has two requests in flight', async () => {
        let release;
        fetchMock.mockImplementationOnce(() => new Promise((resolve) => { release = () => resolve(answer('running')); })).mockResolvedValue(answer('running'));
        make();
        await tick(POLL_INTERVAL);
        await tick(POLL_INTERVAL * 4);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        release();
        await tick(POLL_INTERVAL);
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('pauses while the tab is hidden and checks once when it shows again', async () => {
        fetchMock.mockResolvedValue(answer('running'));
        make();
        await tick(POLL_INTERVAL);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        setHidden(true);
        await tick(POLL_INTERVAL * 5);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        setHidden(false);
        await tick(0);
        expect(fetchMock).toHaveBeenCalledTimes(2);
        await tick(POLL_INTERVAL);
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    it('gives up after three minutes', async () => {
        fetchMock.mockResolvedValue(answer('running'));
        const poll = make();
        await tick(GIVE_UP_AFTER + POLL_INTERVAL * 2);
        expect(poll.timedOut.value).toBe(true);
        expect(poll.terminal.value).toBe(true);
        const calls = fetchMock.mock.calls.length;
        await tick(POLL_INTERVAL * 10);
        expect(fetchMock.mock.calls.length).toBe(calls);
    });

    it.each([403, 404, 419])('stops at once on HTTP %s', async (status) => {
        fetchMock.mockResolvedValue({ ok: false, status, error: 'failed', data: {} });
        const poll = make();
        await tick(POLL_INTERVAL);
        expect(poll.error.value).toBe(status);
        await tick(POLL_INTERVAL * 5);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('survives a dropped answer but stops after five failures in a row', async () => {
        fetchMock.mockResolvedValueOnce({ ok: false, status: 0, error: 'network', data: {} }).mockResolvedValueOnce(answer('running')).mockResolvedValue({ ok: false, status: 500, error: 'failed', data: {} });
        const poll = make();
        await tick(POLL_INTERVAL * 3);
        expect(poll.error.value).toBeNull();
        await tick(POLL_INTERVAL * 10);
        expect(poll.error.value).toBe(500);
        const calls = fetchMock.mock.calls.length;
        await tick(POLL_INTERVAL * 10);
        expect(fetchMock.mock.calls.length).toBe(calls);
    });

    it('stops with its scope (unmount) and never answers into a stopped poller', async () => {
        let release;
        fetchMock.mockImplementationOnce(() => new Promise((resolve) => { release = () => resolve(answer('ready')); }));
        const poll = make();
        await tick(POLL_INTERVAL);
        scope.stop();
        release();
        await tick(POLL_INTERVAL * 5);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(poll.status.value).not.toBe('ready');
    });

    it('follows a changing id and does nothing without one', async () => {
        const id = ref(null);
        fetchMock.mockResolvedValue(answer('running'));
        scope = effectScope();
        scope.run(() => useImportStatus(id));
        await tick(POLL_INTERVAL * 3);
        expect(fetchMock).not.toHaveBeenCalled();
        id.value = ID;
        await nextTick();
        await tick(POLL_INTERVAL);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        id.value = null;
        await nextTick();
        await tick(POLL_INTERVAL * 3);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});
