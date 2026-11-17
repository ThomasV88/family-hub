import { computed, getCurrentScope, onScopeDispose, ref, toValue, watch } from 'vue';
import { securityFetch } from '../../Security/securityFetch.js';

// Polls the status of one recipe import (GET /recipes/imports/{id}). Memory only. One request at a time: the next poll is
// scheduled only after the previous answer arrived, so requests never pile up. 2 s between polls, paused while the tab is
// hidden (one immediate check when it shows again), stopped on a terminal status, on an HTTP answer that cannot change
// (403, 404, 419), after 5 failed polls in a row and after 3 minutes of waiting (timedOut). Stops with its scope (unmount).
export const POLL_INTERVAL = 2000;
export const GIVE_UP_AFTER = 3 * 60 * 1000;
const MAX_FAILED = 5;

export const TERMINAL = ['ready', 'failed', 'cancelled', 'used'];

export function useImportStatus(idSource, { interval = POLL_INTERVAL, giveUpAfter = GIVE_UP_AFTER } = {}) {
    const state = ref(null); // { id, status, reason, host, textCut }
    const timedOut = ref(false);
    // null, or the HTTP status (403, 404, 419) or 'network' that ended the polling.
    const error = ref(null);

    let timer = null;
    let startedAt = null;
    let inflight = false;
    let failedPolls = 0;
    let activeId = null;
    let generation = 0;

    const status = computed(() => state.value?.status ?? null);
    const terminal = computed(() => TERMINAL.includes(status.value) || timedOut.value || error.value !== null);
    const clear = () => { if (timer !== null) { clearTimeout(timer); timer = null; } };
    const hidden = () => typeof document !== 'undefined' && document.hidden;

    function schedule() {
        clear();
        if (activeId === null || terminal.value || hidden()) return;
        if (Date.now() - startedAt >= giveUpAfter) { timedOut.value = true; return; }
        timer = setTimeout(poll, interval);
    }

    async function poll() {
        timer = null;
        if (activeId === null || inflight || terminal.value) return;
        const mine = generation;
        const id = activeId;
        inflight = true;
        const result = await securityFetch(`/recipes/imports/${encodeURIComponent(id)}`);
        inflight = false;
        if (mine !== generation) return;
        if (result.ok) {
            failedPolls = 0;
            const d = result.data ?? {};
            state.value = { id, status: String(d.status ?? ''), reason: d.reason ?? null, host: d.host ?? '', textCut: Boolean(d.textCut) };
        } else if ([403, 404, 419].includes(result.status)) {
            error.value = result.status;
            return;
        } else {
            failedPolls += 1;
            if (failedPolls >= MAX_FAILED) { error.value = result.error === 'network' ? 'network' : result.status || 'network'; return; }
        }
        schedule();
    }

    function start(id) {
        stop();
        if (!id) return;
        generation += 1;
        activeId = id;
        startedAt = Date.now();
        state.value = { id, status: 'fetching', reason: null, host: '', textCut: false };
        timedOut.value = false;
        error.value = null;
        failedPolls = 0;
        schedule();
    }

    function stop() {
        generation += 1;
        clear();
        activeId = null;
        inflight = false;
    }

    function onVisibility() {
        if (activeId === null || terminal.value) return;
        if (hidden()) { clear(); return; }
        clear();
        poll();
    }

    if (typeof document !== 'undefined') document.addEventListener('visibilitychange', onVisibility);
    watch(() => toValue(idSource), (id) => start(id ?? null), { immediate: true });

    function dispose() {
        stop();
        if (typeof document !== 'undefined') document.removeEventListener('visibilitychange', onVisibility);
    }
    if (getCurrentScope()) onScopeDispose(dispose);

    return { state, status, terminal, timedOut, error, start, stop, dispose };
}
