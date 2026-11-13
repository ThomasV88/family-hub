import { router } from '@inertiajs/vue3';

// Query keys whose default value is omitted from the URL.
const DEFAULTS = { account: 'all', type: 'all', category: 'all', source: 'all' };

function clean(query) {
    const out = {};
    for (const [key, value] of Object.entries(query)) {
        if (value === null || value === undefined || value === '' || value === DEFAULTS[key]) continue;
        out[key] = value;
    }
    return out;
}

export function currentQuery() {
    if (typeof window === 'undefined') return {};
    return Object.fromEntries(new URLSearchParams(window.location.search));
}

// Link target for another finance page that keeps the selected account and month.
export function financeUrl(path, { account, month, ...extra } = {}) {
    const query = clean({ account, month, ...extra });
    const qs = new URLSearchParams(query).toString();
    return qs ? `${path}?${qs}` : path;
}

// One Inertia visit for chip, month, filter and panel changes. `params` overrides the current URL's query;
// `page` and `tx` are dropped unless given, so changing the view never leaves a stale panel or page behind.
export function visitFinance(params = {}, { only, reset, path, ...options } = {}) {
    const base = { ...currentQuery() };
    delete base.page;
    delete base.tx;
    const query = clean({ ...base, ...params });
    router.get(path ?? window.location.pathname, query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        ...(only ? { only } : {}),
        ...(reset ? { reset } : {}),
        ...options,
    });
}
