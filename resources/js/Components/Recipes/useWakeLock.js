import { onScopeDispose, ref, watch } from 'vue';

/**
 * Screen Wake Lock as a progressive enhancement (cooking mode). While `enabled` is true a `screen` lock is held where the browser
 * has one. The browser drops the lock when the tab is hidden, so it is released then and asked for again when the tab is visible.
 * No support, a refusal (low battery, no permission policy) and every error are silent: nothing is shown and nothing breaks.
 *
 * Returns { supported, active }. `supported` is false without navigator.wakeLock (the hint text is only shown when true).
 */
export function useWakeLock(enabled) {
    const supported = typeof navigator !== 'undefined' && 'wakeLock' in navigator && Boolean(navigator.wakeLock);
    const active = ref(false);
    let sentinel = null;
    let requesting = false;

    async function release() {
        const held = sentinel;
        sentinel = null;
        active.value = false;
        if (!held) return;
        try {
            await held.release();
        } catch {
            // already released by the browser
        }
    }

    async function acquire() {
        if (!supported || !enabled.value || sentinel || requesting) return;
        if (typeof document !== 'undefined' && document.visibilityState === 'hidden') return;
        requesting = true;
        try {
            const lock = await navigator.wakeLock.request('screen');
            if (!enabled.value) {
                // Switched off while the request was running.
                await lock.release().catch(() => {});

                return;
            }
            sentinel = lock;
            active.value = true;
            lock.addEventListener?.('release', () => {
                if (sentinel === lock) {
                    sentinel = null;
                    active.value = false;
                }
            });
        } catch {
            // refused: carry on without it
        } finally {
            requesting = false;
        }
    }

    const onVisibility = () => {
        if (document.visibilityState === 'hidden') release();
        else acquire();
    };

    if (supported) {
        watch(enabled, (on) => { if (on) acquire(); else release(); }, { immediate: true });
        document.addEventListener('visibilitychange', onVisibility);
    }
    onScopeDispose(() => {
        if (supported) document.removeEventListener('visibilitychange', onVisibility);
        release();
    });

    return { supported, active };
}
