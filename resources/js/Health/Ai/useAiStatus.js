import { getCurrentScope, onScopeDispose, ref, toValue, watch } from 'vue';
import { securityFetch } from '../../Security/securityFetch.js';
import { handleHealthRefusal } from '../healthRefusal.js';

// Polls the JSON status route of one person while any AI run is queued or running. Memory only: nothing is written
// to any browser storage. 5 s between polls, 15 s once the wait passed 2 minutes, paused while the tab is hidden,
// stopped when every run is terminal or after 15 minutes (stoppedLongWait). Runs waiting for AI quota (and nothing
// queued or running) are polled every 60 s, only while the tab is visible, and the poller stops after 60 minutes of
// visible waiting (stalled). A run that moves from waiting to queued or running returns to the fast schedule.
const FAST = 5000;
const SLOW = 15000;
const SLOW_AFTER = 2 * 60 * 1000;
const GIVE_UP_AFTER = 15 * 60 * 1000;

const WAIT_POLL = 60000;
const WAIT_GIVE_UP_AFTER = 60 * 60 * 1000;

const isFast = (s) => s?.status === 'queued' || s?.status === 'running';
const isWaiting = (s) => s?.status === 'waiting';
const isActive = (s) => isFast(s) || isWaiting(s);

export function useAiStatus(personId, initialStatuses = {}) {
    const statuses = ref({ ...(toValue(initialStatuses) ?? {}) });
    const available = ref(null);
    const failedPolls = ref(0);
    const stoppedLongWait = ref(false);
    const stalled = ref(false);

    let timer = null;
    let startedAt = null;
    let waitAccum = 0; // visible waiting time already counted
    let waitSince = null; // start of the current visible waiting stretch
    let mode = null; // 'fast' | 'wait' | null
    let inflight = false;
    let disposed = false;

    const anyActive = () => Object.values(statuses.value).some(isActive);
    const anyFast = () => Object.values(statuses.value).some(isFast);
    const resetWait = () => { waitAccum = 0; waitSince = null; };
    const pauseWait = () => {
        if (waitSince !== null) { waitAccum += Date.now() - waitSince; waitSince = null; }
    };
    const clear = () => { if (timer !== null) { clearTimeout(timer); timer = null; } };

    function schedule() {
        clear();
        if (disposed || !anyActive() || stoppedLongWait.value || stalled.value) {
            if (!anyActive()) { startedAt = null; mode = null; resetWait(); }
            return;
        }
        const next = anyFast() ? 'fast' : 'wait';
        if (next !== mode) {
            // Changing schedule starts that schedule's clock again.
            mode = next;
            startedAt = null;
            resetWait();
        }
        if (typeof document !== 'undefined' && document.hidden) { pauseWait(); return; }
        if (mode === 'wait') {
            waitSince ??= Date.now();
            if (waitAccum + (Date.now() - waitSince) >= WAIT_GIVE_UP_AFTER) {
                stalled.value = true;
                return;
            }
            timer = setTimeout(poll, WAIT_POLL);
            return;
        }
        startedAt ??= Date.now();
        const waited = Date.now() - startedAt;
        if (waited >= GIVE_UP_AFTER) {
            stoppedLongWait.value = true;
            return;
        }
        timer = setTimeout(poll, waited >= SLOW_AFTER ? SLOW : FAST);
    }

    async function poll() {
        timer = null;
        const id = toValue(personId);
        if (!id || inflight || disposed) return;
        inflight = true;
        const result = await securityFetch(`/health/people/${id}/ai-status`);
        inflight = false;
        if (disposed) return;
        if (result.ok) {
            failedPolls.value = 0;
            const documents = result.data?.documents;
            if (documents && typeof documents === 'object' && !Array.isArray(documents)) statuses.value = { ...documents };
            else if (Array.isArray(documents)) statuses.value = {};
            if (typeof result.data?.available === 'boolean') available.value = result.data.available;
        } else if (result.status === 423) {
            handleHealthRefusal(result.error);
            clear();
            return;
        } else {
            failedPolls.value += 1;
        }
        schedule();
    }

    // Immediate check (after a run or cancel, or "Try again"); restarts the waiting clock.
    async function refresh() {
        clear();
        failedPolls.value = 0;
        stoppedLongWait.value = false;
        stalled.value = false;
        startedAt = null;
        resetWait();
        await poll();
        if (timer === null) schedule();
    }

    // Shows a run as queued right after the server accepted it, before the first poll answers.
    function markQueued(documentId, extractionId = null) {
        statuses.value = {
            ...statuses.value,
            [documentId]: { status: 'queued', extractionId, readyExtractionId: statuses.value[documentId]?.readyExtractionId ?? null, cancelReason: null, pagesRead: null, pagesTotal: null, pending: statuses.value[documentId]?.pending ?? 0, entryCount: statuses.value[documentId]?.entryCount },
        };
    }

    function onVisibility() {
        if (document.hidden) {
            clear();
            pauseWait();
            return;
        }
        if (anyActive() && !stoppedLongWait.value && !stalled.value) {
            clear();
            poll();
        }
    }

    document.addEventListener('visibilitychange', onVisibility);
    watch(() => toValue(initialStatuses), (next) => {
        statuses.value = { ...(next ?? {}) };
        stoppedLongWait.value = false;
        stalled.value = false;
        startedAt = null;
        resetWait();
        schedule();
    });
    watch(anyActive, () => schedule(), { flush: 'sync' });
    schedule();

    function dispose() {
        disposed = true;
        clear();
        document.removeEventListener('visibilitychange', onVisibility);
    }
    if (getCurrentScope()) onScopeDispose(dispose);

    return { statuses, available, failedPolls, stoppedLongWait, stalled, refresh, markQueued, dispose };
}
