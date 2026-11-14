import { getCurrentScope, onScopeDispose, ref, toValue, watch } from 'vue';
import { securityFetch } from '../../Security/securityFetch.js';
import { handleHealthRefusal } from '../healthRefusal.js';

// Polls the JSON status route of one person while any AI run is queued or running. Memory only: nothing is written
// to any browser storage. 5 s between polls, 15 s once the wait passed 2 minutes, paused while the tab is hidden,
// stopped when every run is terminal or after 15 minutes (stoppedLongWait).
const FAST = 5000;
const SLOW = 15000;
const SLOW_AFTER = 2 * 60 * 1000;
const GIVE_UP_AFTER = 15 * 60 * 1000;

const isActive = (s) => s?.status === 'queued' || s?.status === 'running';

export function useAiStatus(personId, initialStatuses = {}) {
    const statuses = ref({ ...(toValue(initialStatuses) ?? {}) });
    const available = ref(null);
    const failedPolls = ref(0);
    const stoppedLongWait = ref(false);

    let timer = null;
    let startedAt = null;
    let inflight = false;
    let disposed = false;

    const anyActive = () => Object.values(statuses.value).some(isActive);
    const clear = () => { if (timer !== null) { clearTimeout(timer); timer = null; } };

    function schedule() {
        clear();
        if (disposed || !anyActive() || stoppedLongWait.value) {
            if (!anyActive()) startedAt = null;
            return;
        }
        if (typeof document !== 'undefined' && document.hidden) return;
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
        startedAt = null;
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
            return;
        }
        if (anyActive() && !stoppedLongWait.value) {
            clear();
            poll();
        }
    }

    document.addEventListener('visibilitychange', onVisibility);
    watch(() => toValue(initialStatuses), (next) => {
        statuses.value = { ...(next ?? {}) };
        stoppedLongWait.value = false;
        startedAt = null;
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

    return { statuses, available, failedPolls, stoppedLongWait, refresh, markQueued, dispose };
}
