// Single client for the JSON security endpoints (security dialogs, import wizard). Same-origin only, CSRF header
// from the XSRF-TOKEN cookie. Resolves, never rejects:
//   { ok: true, status, data }
//   { ok: false, status, error: 'confirm_required' | 'reauth_required' | 'second_factor_required' | 'throttle'
//                              | 'invalid' | 'network' | 'failed', data, minutes?, errors? }

function xsrfToken() {
    const match = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : '';
}

const REFUSALS = ['confirm_required', 'reauth_required', 'second_factor_required'];

// Minutes to wait, rounded up with a minimum of 1.
function waitMinutes(response, data) {
    const seconds = Number(response.headers?.get?.('Retry-After') ?? data?.retry_after ?? data?.seconds);
    if (Number.isFinite(seconds) && seconds > 0) return Math.max(1, Math.ceil(seconds / 60));
    const minutes = Number(data?.minutes);
    return Number.isFinite(minutes) && minutes > 0 ? Math.max(1, Math.ceil(minutes)) : 1;
}

export async function securityFetch(url, { method = 'GET', body } = {}) {
    // Same-origin relative paths only (T-06-11): never send cookies or the CSRF token to another origin.
    if (typeof url !== 'string' || !url.startsWith('/') || url.startsWith('//')) {
        return { ok: false, status: 0, error: 'failed', data: {} };
    }
    const verb = method.toUpperCase();
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const init = { method: verb, credentials: 'same-origin', headers };
    if (verb !== 'GET') {
        headers['Content-Type'] = 'application/json';
        headers['X-XSRF-TOKEN'] = xsrfToken();
        if (body !== undefined) init.body = JSON.stringify(body);
    }

    let response;
    try {
        response = await fetch(url, init);
    } catch {
        return { ok: false, status: 0, error: 'network', data: {} };
    }
    const data = await response.json().catch(() => ({}));
    const status = response.status;
    if (response.ok) return { ok: true, status, data };
    if (status === 423) {
        const error = REFUSALS.includes(data?.error) ? data.error : 'confirm_required';
        return { ok: false, status, error, data };
    }
    if (status === 429) return { ok: false, status, error: 'throttle', minutes: waitMinutes(response, data), data };
    if (status === 422) return { ok: false, status, error: 'invalid', errors: data?.errors ?? {}, data };
    return { ok: false, status, error: 'failed', data };
}
