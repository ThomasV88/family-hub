import { getCurrentScope, onScopeDispose, ref, toValue, watch } from 'vue';
import { securityFetch } from '../../Security/securityFetch.js';
import { handleHealthRefusal } from '../healthRefusal.js';
import { isBusy, isPaused } from './batchState.js';

// Polls the JSON status route of one batch. Memory only: nothing is written to any browser storage and the answer is
// no-store on the server. 5 s while any document waits or is being read, 60 s while the only active documents are
// paused for AI quota, nothing when no document is active. Paused while the tab is hidden, resumed on visibility.
export const FAST = 5000;
export const SLOW = 60000;

export function useBatchStatus(personId, batchId, initial) {
    const seed = () => toValue(initial) ?? {};
    const batch = ref(seed().batch ?? null);
    const items = ref(seed().items ?? []);
    const failedPolls = ref(0);

    let timer = null;
    let inflight = false;
    let disposed = false;

    const anyBusy = () => items.value.some(isBusy);
    const anyPaused = () => items.value.some(isPaused);
    const clear = () => { if (timer !== null) { clearTimeout(timer); timer = null; } };

    function schedule() {
        clear();
        if (disposed || (typeof document !== 'undefined' && document.hidden)) return;
        if (!anyBusy() && !anyPaused()) return;
        timer = setTimeout(poll, anyBusy() ? FAST : SLOW);
    }

    async function poll() {
        timer = null;
        if (inflight || disposed) return;
        inflight = true;
        const result = await securityFetch(`/health/people/${toValue(personId)}/upload-batches/${toValue(batchId)}/status`, { cache: 'no-store' });
        inflight = false;
        if (disposed) return;
        if (result.ok) {
            failedPolls.value = 0;
            if (Array.isArray(result.data?.items)) items.value = result.data.items;
            if (result.data?.batch) batch.value = result.data.batch;
        } else if (result.status === 423 && handleHealthRefusal(result.error)) {
            return;
        } else {
            failedPolls.value += 1;
        }
        schedule();
    }

    // Immediate check (after an upload, a Continue or "Try again").
    async function refresh() {
        clear();
        await poll();
        if (timer === null) schedule();
    }

    function onVisibility() {
        if (document.hidden) { clear(); return; }
        if (anyBusy() || anyPaused()) { clear(); poll(); }
    }

    document.addEventListener('visibilitychange', onVisibility);
    // A server answer to a page reload (Inertia props) always wins over what was polled.
    watch(() => toValue(initial), (next) => {
        batch.value = next?.batch ?? null;
        items.value = next?.items ?? [];
        failedPolls.value = 0;
        schedule();
    });
    watch(() => [anyBusy(), anyPaused()].join(), () => schedule(), { flush: 'sync' });
    schedule();

    function dispose() {
        disposed = true;
        clear();
        document.removeEventListener('visibilitychange', onVisibility);
    }
    if (getCurrentScope()) onScopeDispose(dispose);

    return { batch, items, failedPolls, refresh, dispose };
}
