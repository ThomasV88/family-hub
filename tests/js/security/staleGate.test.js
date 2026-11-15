import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
    REPLAY_WINDOW_MS, cancel, confirmed, gate, installStaleGate, requestConfirmation, resetStaleGate,
    runWithGate, setClock, setHosted, setIdentity, setNotifier,
} from '../../../resources/js/Security/staleGate.js';
import { securityFetch } from '../../../resources/js/Security/securityFetch.js';

const stale = { ok: false, status: 423, error: 'confirm_required', data: {}, methods: { password: true, totp: false, passkey: false } };
const saved = { ok: true, status: 200, data: { id: 1 } };
let user;
let notice;
let clock;

beforeEach(() => {
    resetStaleGate();
    user = 7;
    clock = 1_000_000;
    notice = vi.fn();
    setIdentity(() => user);
    setNotifier(notice);
    setClock(() => clock);
    setHosted(true);
});
afterEach(() => { resetStaleGate(); vi.unstubAllGlobals(); });

describe('runWithGate', () => {
    it('passes a successful call through without opening anything', async () => {
        const attempt = vi.fn().mockResolvedValue(saved);
        expect(await runWithGate(attempt)).toBe(saved);
        expect(attempt).toHaveBeenCalledTimes(1);
        expect(gate.open).toBe(false);
    });

    it('opens the dialog on a refusal, replays exactly once after confirming and returns the replayed result', async () => {
        const attempt = vi.fn().mockResolvedValueOnce(stale).mockResolvedValueOnce(saved);
        const pending = runWithGate(attempt);
        await Promise.resolve();
        await Promise.resolve();
        expect(gate.open).toBe(true);
        expect(gate.methods).toEqual(stale.methods);
        expect(attempt).toHaveBeenCalledTimes(1);

        await confirmed();
        expect(await pending).toBe(saved);
        expect(attempt).toHaveBeenCalledTimes(2);
        expect(gate.open).toBe(false);
    });

    it('sends nothing when the member cancels and reports cancelled', async () => {
        const attempt = vi.fn().mockResolvedValue(stale);
        const pending = runWithGate(attempt);
        await Promise.resolve();
        await Promise.resolve();
        cancel();
        const result = await pending;
        expect(result).toMatchObject({ ok: false, error: 'cancelled', cancelled: true });
        expect(attempt).toHaveBeenCalledTimes(1);
        expect(gate.open).toBe(false);
    });

    it('never replays a failure that is not a gate refusal', async () => {
        for (const failure of [
            { ok: false, status: 422, error: 'invalid', errors: {} },
            { ok: false, status: 500, error: 'failed' },
            { ok: false, status: 423, error: 'second_factor_required' },
            { ok: false, status: 0, error: 'network' },
        ]) {
            const attempt = vi.fn().mockResolvedValue(failure);
            expect(await runWithGate(attempt)).toBe(failure);
            expect(attempt).toHaveBeenCalledTimes(1);
            expect(gate.open).toBe(false);
        }
    });

    it('does not loop when the replay is refused again: one dialog, one replay, the refusal comes back', async () => {
        const attempt = vi.fn().mockResolvedValue(stale);
        const pending = runWithGate(attempt);
        await Promise.resolve();
        await Promise.resolve();
        await confirmed();
        expect(await pending).toBe(stale);
        expect(attempt).toHaveBeenCalledTimes(2);
        expect(gate.open).toBe(false);
    });

    it('returns the plain refusal when no dialog host is mounted', async () => {
        setHosted(false);
        const attempt = vi.fn().mockResolvedValue(stale);
        expect(await runWithGate(attempt)).toBe(stale);
        expect(attempt).toHaveBeenCalledTimes(1);
    });

    it('shares one dialog between concurrent refused submissions and replays each once, in order', async () => {
        const order = [];
        const a = vi.fn().mockResolvedValueOnce(stale).mockImplementationOnce(async () => { order.push('a'); return saved; });
        const b = vi.fn().mockResolvedValueOnce(stale).mockImplementationOnce(async () => { order.push('b'); return saved; });
        const pa = runWithGate(a);
        const pb = runWithGate(b);
        await new Promise((r) => setTimeout(r, 0));
        expect(gate.count).toBe(2);
        await confirmed();
        await Promise.all([pa, pb]);
        expect(order).toEqual(['a', 'b']);
        expect(a).toHaveBeenCalledTimes(2);
        expect(b).toHaveBeenCalledTimes(2);
    });

    it('cancelling resolves every waiting submission as cancelled', async () => {
        const make = () => vi.fn().mockResolvedValue(stale);
        const [a, b] = [make(), make()];
        const pa = runWithGate(a);
        const pb = runWithGate(b);
        await new Promise((r) => setTimeout(r, 0));
        cancel();
        expect((await pa).error).toBe('cancelled');
        expect((await pb).error).toBe('cancelled');
        expect(a).toHaveBeenCalledTimes(1);
        expect(b).toHaveBeenCalledTimes(1);
    });
});

describe('replay limits', () => {
    it('does not replay a request older than five minutes', async () => {
        const replay = vi.fn().mockResolvedValue(saved);
        const outcome = requestConfirmation({ methods: {}, replay });
        clock += REPLAY_WINDOW_MS + 1;
        await confirmed();
        expect(await outcome).toEqual({ status: 'expired' });
        expect(replay).not.toHaveBeenCalled();
    });

    it('replays at exactly the limit', async () => {
        const replay = vi.fn().mockResolvedValue(saved);
        const outcome = requestConfirmation({ methods: {}, replay });
        clock += REPLAY_WINDOW_MS;
        await confirmed();
        expect((await outcome).status).toBe('replayed');
        expect(replay).toHaveBeenCalledTimes(1);
    });

    it('does not replay for another member or after sign-out', async () => {
        for (const next of [8, null]) {
            const replay = vi.fn().mockResolvedValue(saved);
            const outcome = requestConfirmation({ methods: {}, replay });
            user = next;
            await confirmed();
            expect(await outcome).toEqual({ status: 'expired' });
            expect(replay).not.toHaveBeenCalled();
            user = 7;
        }
    });

    it('does not replay when nobody is signed in at the time of the refusal', async () => {
        user = null;
        const replay = vi.fn().mockResolvedValue(saved);
        const outcome = requestConfirmation({ methods: {}, replay });
        await confirmed();
        expect(await outcome).toEqual({ status: 'expired' });
        expect(replay).not.toHaveBeenCalled();
    });

    it('turns a throwing replay into a failed result instead of rejecting', async () => {
        const outcome = requestConfirmation({ methods: {}, replay: () => { throw new Error('boom'); } });
        await confirmed();
        expect((await outcome).result).toMatchObject({ ok: false, error: 'failed' });
    });

    it('never touches web storage', async () => {
        const spy = vi.spyOn(Storage.prototype, 'setItem');
        const pending = runWithGate(vi.fn().mockResolvedValue(stale));
        await Promise.resolve();
        await Promise.resolve();
        cancel();
        await pending;
        expect(spy).not.toHaveBeenCalled();
        spy.mockRestore();
    });
});

describe('Inertia writes', () => {
    const refusal = { status: 423, headers: {}, data: { error: 'confirm_required', methods: { password: true, totp: false, passkey: false } } };
    let original;
    let router;
    const visits = () => original.mock.calls;

    beforeEach(() => {
        original = vi.fn();
        router = { visit: original };
        installStaleGate(router);
    });

    it('is idempotent', () => {
        const wrapped = router.visit;
        installStaleGate(router);
        expect(router.visit).toBe(wrapped);
    });

    it('leaves GET navigations and cross-origin or protocol-relative targets alone', () => {
        const options = { method: 'get' };
        router.visit('/finance', options);
        expect(visits()[0][1]).toBe(options);
        const post = { method: 'post', data: { a: 1 } };
        router.visit('https://evil.example/x', post);
        router.visit('//evil.example/x', post);
        expect(visits()[1][1]).toBe(post);
        expect(visits()[2][1]).toBe(post);
    });

    it('swallows the gate refusal, opens the dialog and replays the same payload once with the original callbacks', async () => {
        const onSuccess = vi.fn();
        const onBefore = vi.fn();
        const payload = { name: 'Joint', members: [1, 2] };
        router.visit('/settings/finance-accounts', { method: 'post', data: payload, onSuccess, onBefore, preserveScroll: true });

        const first = visits()[0][1];
        expect(first.onHttpException(refusal)).toBe(false);
        await Promise.resolve();
        expect(gate.open).toBe(true);
        expect(gate.methods).toEqual(refusal.data.methods);
        expect(visits()).toHaveLength(1);

        await confirmed();
        expect(visits()).toHaveLength(2);
        const [href, second] = visits()[1];
        expect(href).toBe('/settings/finance-accounts');
        expect(second).toMatchObject({ method: 'post', data: { name: 'Joint', members: [1, 2] }, onSuccess, preserveScroll: true });
        expect(second.onBefore).toBeUndefined();
        expect(second.data).not.toBe(payload); // a snapshot, so later edits cannot change what is replayed
    });

    it('reports a second refusal after the replay and never reopens the dialog', async () => {
        router.visit('/health/people', { method: 'post', data: { name: 'Ada' } });
        visits()[0][1].onHttpException(refusal);
        await Promise.resolve();
        await confirmed();
        expect(visits()).toHaveLength(2);

        expect(visits()[1][1].onHttpException(refusal)).toBe(false);
        expect(notice).toHaveBeenCalledWith('replay_failed');
        expect(gate.open).toBe(false);
        expect(visits()).toHaveLength(2);
    });

    it('passes every other HTTP failure to the page and never replays it', async () => {
        const own = vi.fn().mockReturnValue(undefined);
        router.visit('/finance/categories', { method: 'post', data: { name: 'x' }, onHttpException: own });
        const wrapped = visits()[0][1];
        for (const response of [
            { status: 500, headers: {}, data: 'boom' },
            { status: 419, headers: {}, data: { message: 'expired' } },
            { status: 423, headers: {}, data: { error: 'second_factor_required' } },
            { status: 403, headers: {}, data: { error: 'confirm_required' } },
        ]) {
            wrapped.onHttpException(response);
        }
        expect(own).toHaveBeenCalledTimes(4);
        expect(gate.open).toBe(false);
        expect(visits()).toHaveLength(1);
    });

    it('cancelling leaves the form alone: no second request, no notice', async () => {
        router.visit('/finance/budgets', { method: 'patch', data: { amount: '5' } });
        visits()[0][1].onHttpException(refusal);
        await Promise.resolve();
        cancel();
        await Promise.resolve();
        expect(visits()).toHaveLength(1);
        expect(notice).not.toHaveBeenCalled();
    });

    it('tells the member nothing was sent when the refusal is too old to replay', async () => {
        router.visit('/finance/budgets', { method: 'put', data: { amount: '5' } });
        visits()[0][1].onHttpException(refusal);
        await Promise.resolve();
        clock += REPLAY_WINDOW_MS + 1;
        await confirmed();
        await Promise.resolve();
        expect(visits()).toHaveLength(1);
        expect(notice).toHaveBeenCalledWith('expired');
    });

    it('queues concurrent refused writes behind one dialog and replays each once', async () => {
        router.visit('/finance/transactions', { method: 'post', data: { n: 1 } });
        router.visit('/finance/categories', { method: 'post', data: { n: 2 } });
        visits()[0][1].onHttpException(refusal);
        visits()[1][1].onHttpException(refusal);
        await Promise.resolve();
        expect(gate.count).toBe(2);
        await confirmed();
        expect(visits().slice(2).map(([href]) => href)).toEqual(['/finance/transactions', '/finance/categories']);
    });
});

describe('securityFetch with replay', () => {
    const response = (status, body) => ({ ok: status < 400, status, headers: { get: () => null }, json: async () => body });

    it('replays the same request body once after confirming and returns the saved result', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(response(423, { error: 'confirm_required', methods: { password: true, totp: false, passkey: false } }))
            .mockResolvedValueOnce(response(200, { id: 5 }));
        vi.stubGlobal('fetch', fetchMock);
        const pending = securityFetch('/settings/security/timings', { method: 'PUT', body: { idle_seconds: 7200 }, replay: true });
        await new Promise((r) => setTimeout(r, 0));
        expect(gate.open).toBe(true);
        await confirmed();
        expect(await pending).toMatchObject({ ok: true, data: { id: 5 } });
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls[1][1].body).toBe(fetchMock.mock.calls[0][1].body);
    });

    it('does not open the dialog unless the caller asked for replay', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response(423, { error: 'confirm_required' })));
        const result = await securityFetch('/finance/import/preview', { method: 'POST', body: {} });
        expect(result.error).toBe('confirm_required');
        expect(gate.open).toBe(false);
    });

    it('refuses non same-origin URLs before anything is sent', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const result = await securityFetch('https://evil.example/x', { method: 'POST', body: {}, replay: true });
        expect(result.ok).toBe(false);
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
