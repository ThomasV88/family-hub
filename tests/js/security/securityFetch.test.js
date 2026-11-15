import { describe, it, expect, vi, afterEach } from 'vitest';
import { securityFetch } from '../../../resources/js/Security/securityFetch.js';

const reply = (status, body = {}, headers = {}) =>
    vi.fn().mockResolvedValue({ ok: status >= 200 && status < 300, status, headers: { get: (k) => headers[k] ?? null }, json: async () => body });

afterEach(() => {
    vi.unstubAllGlobals();
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('securityFetch', () => {
    it('sends credentials, JSON headers and the decoded XSRF token', async () => {
        document.cookie = 'XSRF-TOKEN=ab%3Dcd';
        const f = reply(200, { a: 1 });
        vi.stubGlobal('fetch', f);
        const out = await securityFetch('/settings/security/totp', { method: 'POST', body: { code: '123456' } });
        expect(out).toEqual({ ok: true, status: 200, data: { a: 1 } });
        const [url, init] = f.mock.calls[0];
        expect(url).toBe('/settings/security/totp');
        expect(init.credentials).toBe('same-origin');
        expect(init.method).toBe('POST');
        expect(init.headers).toMatchObject({ Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': 'ab=cd', 'X-Requested-With': 'XMLHttpRequest' });
        expect(init.body).toBe('{"code":"123456"}');
    });

    it('does not send a CSRF token on GET', async () => {
        const f = reply(200);
        vi.stubGlobal('fetch', f);
        await securityFetch('/x');
        expect(f.mock.calls[0][1].headers['X-XSRF-TOKEN']).toBeUndefined();
    });

    it.each(['confirm_required', 'reauth_required', 'second_factor_required'])('maps 423 %s', async (error) => {
        vi.stubGlobal('fetch', reply(423, { error }));
        expect(await securityFetch('/x', { method: 'POST' })).toMatchObject({ ok: false, status: 423, error });
    });

    it('treats an unnamed 423 as confirm_required', async () => {
        vi.stubGlobal('fetch', reply(423, {}));
        expect(await securityFetch('/x', { method: 'POST' })).toMatchObject({ ok: false, error: 'confirm_required' });
    });

    it('maps 429 to throttle with minutes rounded up, minimum 1', async () => {
        vi.stubGlobal('fetch', reply(429, {}, { 'Retry-After': '61' }));
        expect(await securityFetch('/x', { method: 'POST' })).toMatchObject({ ok: false, error: 'throttle', minutes: 2 });
        vi.stubGlobal('fetch', reply(429, {}, { 'Retry-After': '3' }));
        expect((await securityFetch('/x', { method: 'POST' })).minutes).toBe(1);
        vi.stubGlobal('fetch', reply(429, {}));
        expect((await securityFetch('/x', { method: 'POST' })).minutes).toBe(1);
    });

    it('maps 422 to invalid with the errors', async () => {
        vi.stubGlobal('fetch', reply(422, { errors: { code: ['bad'] } }));
        expect(await securityFetch('/x', { method: 'POST' })).toMatchObject({ ok: false, error: 'invalid', errors: { code: ['bad'] } });
    });

    it('maps a network failure', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')));
        expect(await securityFetch('/x')).toMatchObject({ ok: false, error: 'network' });
    });

    it('refuses cross-origin urls without calling fetch', async () => {
        const f = reply(200);
        vi.stubGlobal('fetch', f);
        for (const url of ['https://evil.test/x', '//evil.test/x', 'x']) {
            expect(await securityFetch(url, { method: 'POST' })).toMatchObject({ ok: false, error: 'failed' });
        }
        expect(f).not.toHaveBeenCalled();
    });
});
