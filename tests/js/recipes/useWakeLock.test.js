import { describe, it, expect, vi, afterEach } from 'vitest';
import { effectScope, ref, nextTick } from 'vue';
import { useWakeLock } from '../../../resources/js/Components/Recipes/useWakeLock.js';

function fakeLock() {
    const listeners = {};
    return { release: vi.fn().mockResolvedValue(undefined), addEventListener: (n, f) => { listeners[n] = f; }, listeners };
}
function setWakeLock(value) {
    Object.defineProperty(navigator, 'wakeLock', { value, configurable: true });
}
function setVisibility(state) {
    Object.defineProperty(document, 'visibilityState', { value: state, configurable: true });
    document.dispatchEvent(new Event('visibilitychange'));
}
const flush = async () => { await nextTick(); await Promise.resolve(); await Promise.resolve(); };

afterEach(() => {
    delete navigator.wakeLock;
    Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true });
});

describe('useWakeLock', () => {
    it('requests a screen lock when enabled and releases it when disabled', async () => {
        const lock = fakeLock();
        const request = vi.fn().mockResolvedValue(lock);
        setWakeLock({ request });
        const enabled = ref(false);
        const scope = effectScope();
        const wake = scope.run(() => useWakeLock(enabled));
        expect(wake.supported).toBe(true);
        expect(request).not.toHaveBeenCalled();
        enabled.value = true;
        await flush();
        expect(request).toHaveBeenCalledWith('screen');
        expect(wake.active.value).toBe(true);
        enabled.value = false;
        await flush();
        expect(lock.release).toHaveBeenCalled();
        expect(wake.active.value).toBe(false);
        scope.stop();
    });

    it('releases when the tab becomes hidden and asks again when it is visible', async () => {
        const first = fakeLock();
        const second = fakeLock();
        const request = vi.fn().mockResolvedValueOnce(first).mockResolvedValueOnce(second);
        setWakeLock({ request });
        const scope = effectScope();
        scope.run(() => useWakeLock(ref(true)));
        await flush();
        expect(request).toHaveBeenCalledTimes(1);
        setVisibility('hidden');
        await flush();
        expect(first.release).toHaveBeenCalled();
        setVisibility('visible');
        await flush();
        expect(request).toHaveBeenCalledTimes(2);
        scope.stop();
        await flush();
        expect(second.release).toHaveBeenCalled();
    });

    it('is a silent no-op without navigator.wakeLock', async () => {
        const enabled = ref(true);
        const scope = effectScope();
        const wake = scope.run(() => useWakeLock(enabled));
        expect(wake.supported).toBe(false);
        enabled.value = false;
        enabled.value = true;
        await flush();
        expect(wake.active.value).toBe(false);
        scope.stop();
    });

    it('swallows a rejected request', async () => {
        setWakeLock({ request: vi.fn().mockRejectedValue(new DOMException('no', 'NotAllowedError')) });
        const scope = effectScope();
        const wake = scope.run(() => useWakeLock(ref(true)));
        await flush();
        expect(wake.supported).toBe(true);
        expect(wake.active.value).toBe(false);
        scope.stop();
    });

    it('does not keep a lock that arrives after it was switched off', async () => {
        const lock = fakeLock();
        let resolve;
        setWakeLock({ request: vi.fn(() => new Promise((r) => { resolve = r; })) });
        const enabled = ref(true);
        const scope = effectScope();
        const wake = scope.run(() => useWakeLock(enabled));
        await flush();
        enabled.value = false;
        await flush();
        resolve(lock);
        await flush();
        expect(lock.release).toHaveBeenCalled();
        expect(wake.active.value).toBe(false);
        scope.stop();
    });
});
