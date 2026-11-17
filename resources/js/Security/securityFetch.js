// Single client for the JSON security endpoints (security dialogs, import wizard). Same-origin only, CSRF header
// from the XSRF-TOKEN cookie. Resolves, never rejects:
//   { ok: true, status, data }
//   { ok: false, status, error: 'confirm_required' | 'reauth_required' | 'second_factor_required' | 'throttle'
//                              | 'invalid' | 'network' | 'failed' | 'cancelled' | 'aborted', data, methods?, minutes?, errors? }
// `signal` (an AbortSignal) lets a caller drop a request it no longer wants; that resolves as 'aborted', not 'network'.
// `replay: true` (writes the member just submitted) opens "Confirm it's you" over the page when the gate refuses, and
// sends the same request once more after a successful confirmation (see staleGate.js). 'cancelled' = the member
// closed the dialog, nothing was sent. Without a mounted dialog host the refusal is returned as before.

function xsrfToken() {
    const match = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : '';
}

import { runWithGate } from './staleGate.js';

const REFUSALS = ['confirm_required', 'reauth_required', 'second_factor_required'];

// Minutes to wait, rounded up with a minimum of 1.
function waitMinutes(response, data) {
    const seconds = Number(response.headers?.get?.('Retry-After') ?? data?.retry_after ?? data?.seconds);
    if (Number.isFinite(seconds) && seconds > 0) return Math.max(1, Math.ceil(seconds / 60));
    const minutes = Number(data?.minutes);
    return Number.isFinite(minutes) && minutes > 0 ? Math.max(1, Math.ceil(minutes)) : 1;
}

export function securityFetch(url, options = {}) {
    const { replay = false } = options;
    const attempt = () => send(url, options);

    return replay ? runWithGate(attempt) : attempt();
}

async function send(url, { method = 'GET', body, signal } = {}) {
    // Same-origin relative paths only (T-06-11): never send cookies or the CSRF token to another origin.
    if (typeof url !== 'string' || !url.startsWith('/') || url.startsWith('//')) {
        return { ok: false, status: 0, error: 'failed', data: {} };
    }
    const verb = method.toUpperCase();
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const init = { method: verb, credentials: 'same-origin', headers };
    if (signal) init.signal = signal;
    if (verb !== 'GET') {
        headers['Content-Type'] = 'application/json';
        headers['X-XSRF-TOKEN'] = xsrfToken();
        if (body !== undefined) init.body = JSON.stringify(body);
    }

    let response;
    try {
        response = await fetch(url, init);
    } catch (caught) {
        if (signal?.aborted || caught?.name === 'AbortError') return { ok: false, status: 0, error: 'aborted', data: {} };
        return { ok: false, status: 0, error: 'network', data: {} };
    }
    const data = await response.json().catch(() => ({}));
    const status = response.status;
    if (response.ok) return { ok: true, status, data };
    if (status === 423) {
        const error = REFUSALS.includes(data?.error) ? data.error : 'confirm_required';
        return { ok: false, status, error, data, methods: data?.methods };
    }
    if (status === 429) return { ok: false, status, error: 'throttle', minutes: waitMinutes(response, data), data };
    if (status === 422) return { ok: false, status, error: 'invalid', errors: data?.errors ?? {}, data };
    return { ok: false, status, error: 'failed', data };
}
