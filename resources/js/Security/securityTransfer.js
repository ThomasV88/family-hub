// Binary transfers for the sensitive modules (document upload with progress, document and export download).
// Same rules as securityFetch: same-origin relative paths only (nothing is sent anywhere else), CSRF header from the
// XSRF-TOKEN cookie, resolves and never rejects:
//   securityDownload(url)                          -> { ok, status, blob?, filename?, error?, data? }
//   securityUpload(url, formData, { onProgress, signal }) -> { ok, status, data?, error? }
// error: confirm_required | reauth_required | second_factor_required (423) | too_large (413) | validation (422, with
// data) | throttled (429) | aborted | invalid_url | network | failed.
// Blobs live in memory only; this module never stores anything.

const REFUSALS = ['confirm_required', 'reauth_required', 'second_factor_required'];

function xsrfToken() {
    const match = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : '';
}

function sameOriginPath(url) {
    return typeof url === 'string' && url.startsWith('/') && !url.startsWith('//') && !url.includes('\\');
}

function failure(status, data) {
    if (status === 423) return { ok: false, status, error: REFUSALS.includes(data?.error) ? data.error : 'confirm_required', data };
    if (status === 413) return { ok: false, status, error: 'too_large', data };
    if (status === 422) return { ok: false, status, error: 'validation', data };
    if (status === 429) return { ok: false, status, error: 'throttled', data };
    return { ok: false, status, error: 'failed', data };
}

// filename= only (quoted or bare); the extended filename* form is ignored on purpose.
function filenameFrom(disposition) {
    if (typeof disposition !== 'string') return undefined;
    const match = /(?:^|;)\s*filename=(?:"([^"]*)"|([^;]*))/i.exec(disposition);
    const name = (match?.[1] ?? match?.[2] ?? '').trim();
    return name === '' ? undefined : name;
}

export async function securityDownload(url) {
    if (!sameOriginPath(url)) return { ok: false, status: 0, error: 'invalid_url' };

    let response;
    try {
        response = await fetch(url, { method: 'GET', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    } catch {
        return { ok: false, status: 0, error: 'network' };
    }
    if (response.ok) {
        try {
            const blob = await response.blob();
            return { ok: true, status: response.status, blob, filename: filenameFrom(response.headers?.get?.('Content-Disposition')) };
        } catch {
            return { ok: false, status: response.status, error: 'network' };
        }
    }
    const data = await response.json().catch(() => ({}));
    return failure(response.status, data);
}

export function securityUpload(url, formData, { onProgress, signal } = {}) {
    if (!sameOriginPath(url)) return Promise.resolve({ ok: false, status: 0, error: 'invalid_url' });
    if (signal?.aborted) return Promise.resolve({ ok: false, status: 0, error: 'aborted' });

    return new Promise((resolve) => {
        let settled = false;
        const finish = (result) => {
            if (settled) return;
            settled = true;
            signal?.removeEventListener?.('abort', onAbort);
            resolve(result);
        };
        const xhr = new XMLHttpRequest();
        const onAbort = () => {
            finish({ ok: false, status: 0, error: 'aborted' });
            try {
                xhr.abort();
            } catch {
                // already finished
            }
        };

        xhr.open('POST', url);
        xhr.withCredentials = true;
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-XSRF-TOKEN', xsrfToken());
        if (xhr.upload && typeof onProgress === 'function') {
            xhr.upload.onprogress = (event) => {
                if (event.lengthComputable && event.total > 0) onProgress(Math.min(1, event.loaded / event.total));
            };
        }
        xhr.onload = () => {
            let data = {};
            try {
                data = JSON.parse(xhr.responseText || '{}') ?? {};
            } catch {
                data = {};
            }
            const status = xhr.status;
            finish(status >= 200 && status < 300 ? { ok: true, status, data } : failure(status, data));
        };
        xhr.onerror = () => finish({ ok: false, status: 0, error: 'network' });
        xhr.ontimeout = () => finish({ ok: false, status: 0, error: 'network' });
        xhr.onabort = () => finish({ ok: false, status: 0, error: 'aborted' });
        signal?.addEventListener?.('abort', onAbort, { once: true });

        try {
            xhr.send(formData);
        } catch {
            finish({ ok: false, status: 0, error: 'network' });
        }
    });
}
