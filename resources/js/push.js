// Web Push helpers for the Notifications settings page. The permission prompt is only ever opened from enable(),
// which the page calls from a button click.

const ENDPOINT = '/settings/notifications/push';

export function isPushSupported() {
    return typeof window !== 'undefined'
        && 'serviceWorker' in navigator
        && 'PushManager' in window
        && 'Notification' in window;
}

/** iPhone/iPad in a normal Safari tab: push only works once the app is on the Home Screen. */
export function isIosNotInstalled() {
    if (typeof navigator === 'undefined') return false;
    const ua = navigator.userAgent || '';
    const iphoneOrIpad = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
    if (!iphoneOrIpad) return false;
    const standalone = navigator.standalone === true
        || (typeof window.matchMedia === 'function' && window.matchMedia('(display-mode: standalone)').matches);

    return !standalone;
}

export function urlBase64ToUint8Array(base64) {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i += 1) out[i] = raw.charCodeAt(i);

    return out;
}

function xsrfToken() {
    const match = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : '';
}

function send(method, body) {
    return fetch(ENDPOINT + (method === 'POST' && body === undefined ? '/test' : ''), {
        method,
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
}

function sameKey(subscription, vapidPublicKey) {
    const current = subscription.options?.applicationServerKey;
    if (!current) return true; // cannot tell, keep it
    const wanted = urlBase64ToUint8Array(vapidPublicKey);
    const have = new Uint8Array(current);

    return have.length === wanted.length && have.every((b, i) => b === wanted[i]);
}

export async function currentSubscription() {
    if (!isPushSupported()) return null;
    const registration = await navigator.serviceWorker.ready;

    return registration.pushManager.getSubscription();
}

/** Asks for permission (this is the only place that does) and registers the subscription with the server. */
export async function enable(vapidPublicKey) {
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return { ok: false, reason: permission };
    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
    });
    const response = await send('POST', subscription.toJSON());
    if (!response.ok) {
        await subscription.unsubscribe();

        return { ok: false, reason: 'server' };
    }

    return { ok: true };
}

export async function disable() {
    const subscription = await currentSubscription();
    if (!subscription) return { ok: true };
    const endpoint = subscription.endpoint;
    await subscription.unsubscribe();
    await send('DELETE', { endpoint });

    return { ok: true };
}

export async function sendTest() {
    const response = await send('POST');

    return { ok: response.ok };
}

/** Keeps the server in step with the browser. Never prompts. */
export async function resync(vapidPublicKey) {
    const subscription = await currentSubscription();
    if (!subscription || !vapidPublicKey) return;
    if (!sameKey(subscription, vapidPublicKey)) {
        const endpoint = subscription.endpoint;
        await subscription.unsubscribe();
        await send('DELETE', { endpoint });

        return;
    }
    await send('POST', subscription.toJSON());
}
