import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { securityFetch } from '../../Security/securityFetch.js';

// Counts-only polling of the bulk panel (UI-SPEC Screen 3, PROVISIONAL 11): every 15 s while the tab is visible,
// paused when hidden, stopped when no run is open. Three consecutive errors stop the loop until "Try again".
// Nothing is stored in the browser; the panel lives in memory only.
export const BULK_POLL_MS = 15000;
export const BULK_URL = '/settings/ai-agents/bulk';
const MAX_FAILURES = 3;

const visible = () => typeof document === 'undefined' || document.visibilityState !== 'hidden';

function transitionOf(prev, next) {
    const before = prev?.active ?? null;
    const after = next?.active ?? null;
    if (before && !after) {
        return { kind: next?.history?.[0]?.status === 'completed' ? 'finished' : 'ended', summary: next?.history?.[0] ?? null };
    }
    if (before && after && before.id === after.id) {
        if (after.state === 'paused' && before.state !== 'paused') return { kind: 'paused' };
        if (before.state === 'paused' && after.state !== 'paused') return { kind: 'resumed' };
        if (after.state === 'waiting_quota' && before.state !== 'waiting_quota') return { kind: 'waiting', availableAt: after.availableAt };
    }
    return null;
}

export function useBulkStatus({ initial = null, onTransition = () => {}, interval = BULK_POLL_MS } = {}) {
    const panel = ref(initial);
    const failures = ref(0);
    const pollFailed = computed(() => failures.value >= MAX_FAILURES);
    const refreshing = ref(false);
    let timer = null;
    let alive = true;

    const clear = () => {
        if (timer !== null) clearTimeout(timer);
        timer = null;
    };

    function schedule() {
        clear();
        if (!alive || !panel.value?.active || pollFailed.value || !visible()) return;
        timer = setTimeout(refresh, interval);
    }

    // `quiet` is for the admin's own actions (they announce themselves).
    function adopt(next, { quiet = false } = {}) {
        if (!next || typeof next !== 'object') return;
        const change = quiet ? null : transitionOf(panel.value, next);
        panel.value = { active: next.active ?? null, history: Array.isArray(next.history) ? next.history : (panel.value?.history ?? []) };
        failures.value = 0;
        if (change) onTransition(change);
        schedule();
    }

    async function refresh() {
        clear();
        if (!alive) return false;
        refreshing.value = true;
        const result = await securityFetch(BULK_URL);
        refreshing.value = false;
        if (!alive) return false;
        if (result.ok && result.data && 'active' in result.data) {
            adopt(result.data);
            return true;
        }
        failures.value += 1;
        schedule();
        return false;
    }

    function retry() {
        failures.value = 0;
        return refresh();
    }

    function onVisibility() {
        if (!visible()) {
            clear();
            return;
        }
        if (panel.value?.active && !pollFailed.value) refresh();
    }

    onMounted(() => {
        document.addEventListener('visibilitychange', onVisibility);
        schedule();
    });
    onBeforeUnmount(() => {
        alive = false;
        clear();
        document.removeEventListener('visibilitychange', onVisibility);
    });

    return { panel, pollFailed, refreshing, adopt, refresh, retry };
}
