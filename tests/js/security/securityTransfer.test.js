import { describe, it, expect, vi, afterEach } from 'vitest';
import { securityDownload, securityUpload } from '../../../resources/js/Security/securityTransfer.js';

afterEach(() => {
    vi.unstubAllGlobals();
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

const reply = (status, { body = {}, headers = {}, blob = new Blob(['x']) } = {}) =>
    vi.fn().mockResolvedValue({
        ok: status >= 200 && status < 300,
        status,
        headers: { get: (k) => headers[k] ?? null },
        json: async () => body,
        blob: async () => blob,
    });

describe('securityDownload', () => {
    it.each(['https://evil.test/x', 'http://x', '//evil.test/x', 'relative/path', '/\\evil.test', '', null, undefined])('refuses %s without a request', async (url) => {
        const f = vi.fn();
        vi.stubGlobal('fetch', f);
        expect(await securityDownload(url)).toEqual({ ok: false, status: 0, error: 'invalid_url' });
        expect(f).not.toHaveBeenCalled();
    });

    it('returns the blob and the plain filename, sending credentials and no CSRF token on GET', async () => {
        const blob = new Blob(['pdf']);
        const f = reply(200, { blob, headers: { 'Content-Disposition': 'attachment; filename=kontrola-2024.pdf' } });
        vi.stubGlobal('fetch', f);

        const out = await securityDownload('/health/people/1/documents/2/file');

        expect(out).toMatchObject({ ok: true, status: 200, filename: 'kontrola-2024.pdf' });
        expect(out.blob).toBe(blob);
        const [url, init] = f.mock.calls[0];
        expect(url).toBe('/health/people/1/documents/2/file');
        expect(init.credentials).toBe('same-origin');
        expect(init.headers['X-XSRF-TOKEN']).toBeUndefined();
    });

    it('reads a quoted filename, ignores filename*, and leaves it undefined when absent', async () => {
        vi.stubGlobal('fetch', reply(200, { headers: { 'Content-Disposition': 'attachment; filename="a b.pdf"; filename*=UTF-8\'\'evil.pdf' } }));
        expect((await securityDownload('/x')).filename).toBe('a b.pdf');
        vi.stubGlobal('fetch', reply(200, { headers: { 'Content-Disposition': "attachment; filename*=UTF-8''evil.pdf" } }));
        expect((await securityDownload('/x')).filename).toBeUndefined();
        vi.stubGlobal('fetch', reply(200));
        expect((await securityDownload('/x')).filename).toBeUndefined();
    });

    it.each(['confirm_required', 'reauth_required', 'second_factor_required'])('maps a 423 %s body', async (error) => {
        vi.stubGlobal('fetch', reply(423, { body: { error } }));
        expect(await securityDownload('/x')).toMatchObject({ ok: false, status: 423, error });
    });

    it('maps an unnamed 423, 413, 422, 429, other statuses and a network error', async () => {
        vi.stubGlobal('fetch', reply(423, { body: {} }));
        expect((await securityDownload('/x')).error).toBe('confirm_required');
        vi.stubGlobal('fetch', reply(413));
        expect((await securityDownload('/x')).error).toBe('too_large');
        vi.stubGlobal('fetch', reply(422, { body: { errors: { a: ['b'] } } }));
        expect(await securityDownload('/x')).toMatchObject({ error: 'validation', data: { errors: { a: ['b'] } } });
        vi.stubGlobal('fetch', reply(429));
        expect((await securityDownload('/x')).error).toBe('throttled');
        vi.stubGlobal('fetch', reply(403));
        expect((await securityDownload('/x')).error).toBe('failed');
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));
        expect(await securityDownload('/x')).toEqual({ ok: false, status: 0, error: 'network' });
    });
});

class FakeXhr {
    static last;
    constructor() {
        FakeXhr.last = this;
        this.headers = {};
        this.upload = {};
        this.aborted = false;
    }
    open(method, url) { this.method = method; this.url = url; }
    setRequestHeader(k, v) { this.headers[k] = v; }
    send(body) { this.body = body; }
    abort() { this.aborted = true; this.onabort?.(); }
    respond(status, body) { this.status = status; this.responseText = typeof body === 'string' ? body : JSON.stringify(body); this.onload(); }
}

describe('securityUpload', () => {
    const stub = () => vi.stubGlobal('XMLHttpRequest', FakeXhr);

    it.each(['https://evil.test/x', '//evil.test/x', 'relative', null])('refuses %s without a request', async (url) => {
        stub();
        FakeXhr.last = undefined;
        expect(await securityUpload(url, new FormData())).toEqual({ ok: false, status: 0, error: 'invalid_url' });
        expect(FakeXhr.last).toBeUndefined();
    });

    it('posts the form with credentials, JSON headers and the decoded XSRF token, resolving the data', async () => {
        stub();
        document.cookie = 'XSRF-TOKEN=ab%3Dcd';
        const form = new FormData();
        const promise = securityUpload('/health/people/1/documents', form);
        const xhr = FakeXhr.last;
        expect(xhr.method).toBe('POST');
        expect(xhr.url).toBe('/health/people/1/documents');
        expect(xhr.withCredentials).toBe(true);
        expect(xhr.headers).toMatchObject({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': 'ab=cd' });
        expect(xhr.body).toBe(form);
        xhr.respond(201, { document: { id: 'a' } });
        expect(await promise).toEqual({ ok: true, status: 201, data: { document: { id: 'a' } } });
    });

    it('reports progress as fractions', async () => {
        stub();
        const onProgress = vi.fn();
        const promise = securityUpload('/x', new FormData(), { onProgress });
        const xhr = FakeXhr.last;
        xhr.upload.onprogress({ lengthComputable: true, loaded: 25, total: 100 });
        xhr.upload.onprogress({ lengthComputable: true, loaded: 100, total: 100 });
        xhr.upload.onprogress({ lengthComputable: false, loaded: 1, total: 0 });
        xhr.respond(200, {});
        await promise;
        expect(onProgress.mock.calls).toEqual([[0.25], [1]]);
    });

    it.each(['confirm_required', 'reauth_required', 'second_factor_required'])('maps a 423 %s body', async (error) => {
        stub();
        const promise = securityUpload('/x', new FormData());
        FakeXhr.last.respond(423, { error });
        expect(await promise).toMatchObject({ ok: false, status: 423, error });
    });

    it('maps 413, 422 (with data), 429, a non-JSON failure and network errors', async () => {
        stub();
        const run = async (status, body) => {
            const p = securityUpload('/x', new FormData());
            FakeXhr.last.respond(status, body);
            return p;
        };
        expect((await run(413, {})).error).toBe('too_large');
        expect(await run(422, { errors: { file: ['health.upload.err_size'] } })).toMatchObject({ error: 'validation', data: { errors: { file: ['health.upload.err_size'] } } });
        expect((await run(429, {})).error).toBe('throttled');
        expect(await run(500, '<html>')).toMatchObject({ ok: false, error: 'failed', data: {} });

        const p = securityUpload('/x', new FormData());
        FakeXhr.last.onerror();
        expect(await p).toEqual({ ok: false, status: 0, error: 'network' });
    });

    it('resolves aborted, without rejecting, when the signal fires', async () => {
        stub();
        const controller = new AbortController();
        const promise = securityUpload('/x', new FormData(), { signal: controller.signal });
        controller.abort();
        expect(await promise).toEqual({ ok: false, status: 0, error: 'aborted' });
        expect(FakeXhr.last.aborted).toBe(true);
    });

    it('does not start when the signal is already aborted', async () => {
        stub();
        FakeXhr.last = undefined;
        const controller = new AbortController();
        controller.abort();
        expect(await securityUpload('/x', new FormData(), { signal: controller.signal })).toEqual({ ok: false, status: 0, error: 'aborted' });
        expect(FakeXhr.last).toBeUndefined();
    });
});
