import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { enable, disable, sendTest, resync, isPushSupported, isIosNotInstalled, urlBase64ToUint8Array } from '../../resources/js/push.js';

const KEY = 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM';
let subscription;
let pushManager;
let requestPermission;

function setUa(ua, extra = {}) {
    Object.defineProperty(navigator, 'userAgent', { value: ua, configurable: true });
    Object.defineProperty(navigator, 'maxTouchPoints', { value: extra.touch ?? 0, configurable: true });
    Object.defineProperty(navigator, 'standalone', { value: extra.standalone, configurable: true });
    window.matchMedia = () => ({ matches: Boolean(extra.displayStandalone) });
}

beforeEach(() => {
    subscription = {
        endpoint: 'https://fcm.googleapis.com/x',
        toJSON: () => ({ endpoint: 'https://fcm.googleapis.com/x', keys: { p256dh: 'a', auth: 'b' } }),
        unsubscribe: vi.fn().mockResolvedValue(true),
        options: { applicationServerKey: urlBase64ToUint8Array(KEY).buffer },
    };
    pushManager = { subscribe: vi.fn().mockResolvedValue(subscription), getSubscription: vi.fn().mockResolvedValue(subscription) };
    Object.defineProperty(navigator, 'serviceWorker', { value: { ready: Promise.resolve({ pushManager }) }, configurable: true });
    window.PushManager = function PushManager() {};
    requestPermission = vi.fn().mockResolvedValue('granted');
    window.Notification = { requestPermission, permission: 'default' };
    globalThis.Notification = window.Notification;
    document.cookie = 'XSRF-TOKEN=tok%3D1';
    globalThis.fetch = vi.fn().mockResolvedValue({ ok: true });
});
afterEach(() => { vi.restoreAllMocks(); });

describe('push.js', () => {
    it('detects support', () => {
        expect(isPushSupported()).toBe(true);
        delete window.PushManager;
        expect(isPushSupported()).toBe(false);
    });

    it('detects iPhone and iPadOS outside the installed app', () => {
        setUa('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', { standalone: false });
        expect(isIosNotInstalled()).toBe(true);
        setUa('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', { touch: 5, standalone: false });
        expect(isIosNotInstalled()).toBe(true);
        setUa('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', { standalone: true });
        expect(isIosNotInstalled()).toBe(false);
        setUa('Mozilla/5.0 (iPhone)', { displayStandalone: true });
        expect(isIosNotInstalled()).toBe(false);
        setUa('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', { touch: 0 });
        expect(isIosNotInstalled()).toBe(false);
        setUa('Mozilla/5.0 (X11; Linux x86_64)');
        expect(isIosNotInstalled()).toBe(false);
    });

    it('enable asks permission, subscribes and posts with the XSRF header', async () => {
        const result = await enable(KEY);
        expect(result).toEqual({ ok: true });
        expect(requestPermission).toHaveBeenCalledTimes(1);
        expect(pushManager.subscribe).toHaveBeenCalledWith(expect.objectContaining({ userVisibleOnly: true }));
        const [url, init] = fetch.mock.calls[0];
        expect(url).toBe('/settings/notifications/push');
        expect(init.method).toBe('POST');
        expect(init.headers['X-XSRF-TOKEN']).toBe('tok=1');
        expect(JSON.parse(init.body).endpoint).toBe('https://fcm.googleapis.com/x');
    });

    it('enable stops when permission is denied and rolls back when the server refuses', async () => {
        requestPermission.mockResolvedValue('denied');
        expect(await enable(KEY)).toEqual({ ok: false, reason: 'denied' });
        expect(pushManager.subscribe).not.toHaveBeenCalled();
        requestPermission.mockResolvedValue('granted');
        fetch.mockResolvedValue({ ok: false });
        expect(await enable(KEY)).toEqual({ ok: false, reason: 'server' });
        expect(subscription.unsubscribe).toHaveBeenCalled();
    });

    it('disable unsubscribes and deletes by endpoint', async () => {
        await disable();
        expect(subscription.unsubscribe).toHaveBeenCalled();
        const [, init] = fetch.mock.calls[0];
        expect(init.method).toBe('DELETE');
        expect(JSON.parse(init.body)).toEqual({ endpoint: 'https://fcm.googleapis.com/x' });
    });

    it('sendTest posts to the test route', async () => {
        expect(await sendTest()).toEqual({ ok: true });
        expect(fetch.mock.calls[0][0]).toBe('/settings/notifications/push/test');
    });

    it('resync re-posts a matching subscription and drops one made with another key', async () => {
        await resync(KEY);
        expect(fetch.mock.calls[0][1].method).toBe('POST');
        expect(subscription.unsubscribe).not.toHaveBeenCalled();
        fetch.mockClear();
        subscription.options.applicationServerKey = new Uint8Array([1, 2, 3]).buffer;
        await resync(KEY);
        expect(subscription.unsubscribe).toHaveBeenCalled();
        expect(fetch.mock.calls[0][1].method).toBe('DELETE');
        expect(requestPermission).not.toHaveBeenCalled();
    });
});
