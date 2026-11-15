// Central handling of a stale confirmation ("Confirm it's you"). When a write is refused by the gate (423
// confirm_required / reauth_required) the page stays exactly as it is: a dialog opens over it, the member proves
// identity there, and the original request is REPLAYED once from memory. Nothing is stored anywhere (no
// localStorage, sessionStorage or cookie); the payload lives in the closure of the refused call until it is replayed
// or discarded.
//
// Two entry points feed the same queue:
//   - runWithGate(attempt)        for the JSON/XHR helpers (securityFetch, securityUpload, securityDownload, importApi)
//   - installStaleGate(router)    wraps router.visit so every Inertia write (router.post/put/patch/delete, useForm,
//                                 <Form>, <Link method>) is covered without touching the pages
// Safety rules, all enforced here:
//   - only a request the gate itself refused is replayed (never a failure for another reason)
//   - at most once per refused request; a second refusal after the replay is reported, never looped
//   - only for the same signed-in member (identity compared before and after) and within REPLAY_WINDOW_MS
//   - only same-origin relative URLs
//   - GET navigations are not touched: they keep redirecting to /confirm-access
import { reactive } from 'vue';

export const REFUSALS = ['confirm_required', 'reauth_required'];
export const REPLAY_WINDOW_MS = 5 * 60 * 1000;

// Reactive state read by StaleGateHost.vue.
export const gate = reactive({ hosted: false, open: false, methods: null, count: 0 });

const NOT_WRITES = ['get', 'query'];
let queue = [];
let session = null; // { entries: [...] } while the dialog is open
let identity = () => null;
let notify = () => {};
let now = () => Date.now();

export function setIdentity(getter) {
    identity = typeof getter === 'function' ? getter : () => null;
}
export function setNotifier(fn) {
    notify = typeof fn === 'function' ? fn : () => {};
}
export function setClock(fn) {
    now = typeof fn === 'function' ? fn : () => Date.now();
}
export function setHosted(value) {
    gate.hosted = !!value;
}

// Test helper: forget everything.
export function resetStaleGate() {
    queue = [];
    session = null;
    gate.open = false;
    gate.methods = null;
    gate.count = 0;
    gate.hosted = false;
    identity = () => null;
    notify = () => {};
    now = () => Date.now();
}

export function isSameOriginPath(url) {
    if (typeof url !== 'string') return false;
    if (url.startsWith('/')) return !url.startsWith('//') && !url.includes('\\');
    try {
        return new URL(url, window.location.href).origin === window.location.origin;
    } catch {
        return false;
    }
}

function usableMethods(methods) {
    return methods && typeof methods === 'object' ? methods : { password: false, totp: false, passkey: false };
}

// Ask the member to confirm; resolves once with the outcome of THIS entry:
//   { status: 'replayed', result }  the request was replayed (result is whatever replay() returned)
//   { status: 'cancelled' }         the dialog was closed or confirmation failed to open
//   { status: 'expired' }           too old, or another member is signed in now: nothing was sent
//   { status: 'unavailable' }       no dialog host is mounted (tests, bare pages): the caller keeps its old behaviour
export function requestConfirmation({ methods, replay }) {
    if (!gate.hosted || typeof replay !== 'function') return Promise.resolve({ status: 'unavailable' });

    return new Promise((resolve) => {
        const entry = {
            replay,
            resolve,
            at: now(),
            user: identity(),
            trigger: typeof document !== 'undefined' ? document.activeElement : null,
        };
        if (session) {
            // A second refused request while the dialog is open waits for the same confirmation.
            session.entries.push(entry);
            gate.count = session.entries.length;
            return;
        }
        session = { entries: [entry] };
        gate.methods = usableMethods(methods);
        gate.count = 1;
        gate.open = true;
    });
}

function fresh(entry) {
    return now() - entry.at <= REPLAY_WINDOW_MS && entry.user !== null && entry.user === identity();
}

function close() {
    const done = session;
    session = null;
    gate.open = false;
    gate.methods = null;
    gate.count = 0;
    return done;
}

// The dialog confirmed: replay every waiting request once, in order.
export async function confirmed() {
    const done = close();
    if (!done) return;
    for (const entry of done.entries) {
        if (!fresh(entry)) {
            entry.resolve({ status: 'expired' });
            continue;
        }
        try {
            entry.resolve({ status: 'replayed', result: await entry.replay() });
        } catch (error) {
            entry.resolve({ status: 'replayed', result: { ok: false, status: 0, error: 'failed', thrown: error } });
        }
    }
}

// Cancel, Escape or the close button: nothing is sent, the form stays as it was.
export function cancel() {
    const done = close();
    if (!done) return;
    done.entries.forEach((entry) => entry.resolve({ status: 'cancelled' }));
    const first = done.entries[0]?.trigger;
    if (first && typeof first.focus === 'function' && document.contains(first)) first.focus();
}

export function isRefusal(result) {
    return !!result && result.ok === false && REFUSALS.includes(result.error);
}

// Wraps a function that performs one request and resolves { ok, error, ... } (never rejects). If the gate refused it,
// confirm in the dialog and run the very same function once more.
export async function runWithGate(attempt) {
    const first = await attempt();
    if (!isRefusal(first)) return first;

    const outcome = await requestConfirmation({ methods: first.methods ?? first.data?.methods, replay: attempt });
    if (outcome.status === 'replayed') return outcome.result;
    if (outcome.status === 'cancelled') return { ...first, error: 'cancelled', cancelled: true };
    return first;
}

// --- Inertia writes ------------------------------------------------------------------------------------------

function snapshot(data) {
    if (data === undefined || data === null || typeof data !== 'object') return data;
    try {
        return structuredClone(data);
    } catch {
        return data; // a value that cannot be cloned is replayed by reference, still only from memory
    }
}

function hrefOf(href) {
    if (typeof href === 'string') return href;
    if (href instanceof URL) return href.href;
    if (href && typeof href.url === 'string') return href.url;
    return null;
}

function isGateRefusal(response) {
    const body = response?.data;
    return response?.status === 423 && body && typeof body === 'object' && REFUSALS.includes(body.error);
}

function guardedVisit(original, href, options, replayed) {
    const url = hrefOf(href);
    const method = String(options?.method ?? href?.method ?? 'get').toLowerCase();
    if (NOT_WRITES.includes(method) || url === null || !isSameOriginPath(url)) return original(href, options);

    const payload = snapshot(options?.data);
    const wrapped = {
        ...options,
        onHttpException: (response) => {
            if (!isGateRefusal(response)) return options?.onHttpException?.(response);
            if (replayed) {
                // Refused again right after a successful confirmation: say so, never loop, never reopen.
                notify('replay_failed');
                return false;
            }
            requestConfirmation({
                methods: response.data.methods,
                // onBefore is not repeated: the member already answered it for this submit.
                replay: () => guardedVisit(original, href, { ...options, onBefore: undefined, data: payload }, true),
            }).then((outcome) => {
                if (outcome.status === 'expired') notify('expired');
            });
            return false;
        },
    };
    return original(href, wrapped);
}

// Idempotent. Call once at startup with Inertia's router.
export function installStaleGate(router) {
    if (!router || router.__staleGate) return;
    const original = router.visit.bind(router);
    router.visit = (href, options = {}) => guardedVisit(original, href, options, false);
    router.__staleGate = true;
}
