import { computed, onBeforeUnmount, ref } from 'vue';
import { securityFetch } from '../../Security/securityFetch.js';
import { handleHealthRefusal } from '../healthRefusal.js';

// State machine of the Health document search: idle | too_short | loading | results | empty | error | throttled.
// The query lives in this component's memory only. It is sent as a POST body and nowhere else: not in the address, not in navigation state, no
// history entry, no browser storage, no cache. Only the newest request may change what is shown: every run bumps a
// counter and aborts the one before, and a late answer of an older run is dropped. Nothing here reads titles or snippets.
export const MIN_LENGTH = 2;
export const DEBOUNCE_MS = 300;
export const COOLDOWN_MS = 5000;

export function useDocumentSearch({ scopePersonId = () => null } = {}) {
    const query = ref('');
    const state = ref('idle');
    const items = ref([]);
    const hasMore = ref(false);
    const page = ref(1);
    // 'generic' | 'session' | 'forbidden' for the error state; the same kinds (plus 'throttled') for a failed Load more.
    const errorKind = ref('generic');
    const refreshing = ref(false);
    const loadingMore = ref(false);
    const moreError = ref(null);
    // Bumped when a search finished that was started with Enter or the button: the view moves focus to the heading.
    const focusTick = ref(0);
    // Set when Load more appended rows: the first new row gets focus and "{n} more documents loaded" is announced.
    const loaded = ref({ tick: 0, count: 0, firstIndex: 0 });
    const cooling = ref(false);
    // A search is held back by the pause after "too many requests": the view shows it is waiting, not dead.
    const waitingRetry = ref(false);
    // Words of the last query the search did not use (one-letter words, words with no Latin letter or digit, words past the eighth).
    const dropped = ref(0);

    let timer = null;
    let coolTimer = null;
    let seq = 0;
    let controller = null;
    let moreController = null;
    let lastBody = null;
    let deferred = false;
    let inflightKey = null;
    let pendingFocus = false;

    const trimmed = computed(() => query.value.trim());
    const active = computed(() => state.value !== 'idle' && state.value !== 'too_short');

    const stopTimer = () => { if (timer !== null) { clearTimeout(timer); timer = null; } };
    function abortAll() {
        seq += 1;
        controller?.abort();
        moreController?.abort();
        inflightKey = null;
        controller = null;
        moreController = null;
        loadingMore.value = false;
        refreshing.value = false;
    }

    function reset(next) {
        abortAll();
        stopTimer();
        items.value = [];
        hasMore.value = false;
        page.value = 1;
        moreError.value = null;
        lastBody = null;
        deferred = false;
        waitingRetry.value = false;
        dropped.value = 0;
        state.value = next;
    }

    // The pause follows the server's Retry-After when it sent one (at least a second, at most two minutes), else 5 seconds.
    function startCooldown(seconds = null) {
        cooling.value = true;
        if (coolTimer !== null) clearTimeout(coolTimer);
        const wait = Number.isFinite(seconds) && seconds > 0 ? Math.min(120000, Math.max(1000, Math.ceil(seconds * 1000))) : COOLDOWN_MS;
        coolTimer = setTimeout(() => {
            coolTimer = null;
            cooling.value = false;
            waitingRetry.value = false;
            if (deferred) {
                deferred = false;
                if (trimmed.value.length >= MIN_LENGTH) run(false);
            }
        }, wait);
    }

    function failureKind(result) {
        if (result.status === 419) return 'session';
        if (result.status === 403 || result.status === 404) return 'forbidden';
        return 'generic';
    }

    async function run(withFocus, force = false) {
        stopTimer();
        const q = trimmed.value;
        if (q.length < MIN_LENGTH) return;
        if (cooling.value) {
            deferred = true;
            waitingRetry.value = true;
            return;
        }
        const person = scopePersonId();
        const body = { q, ...(person ? { person } : {}) };
        // The same search is already on its way (Enter or the button pressed again): no second request.
        if (!force && controller !== null && inflightKey === JSON.stringify(body)) {
            if (withFocus) pendingFocus = true;
            return;
        }
        abortAll();
        const mine = seq;
        controller = new AbortController();
        const signal = controller.signal;
        inflightKey = JSON.stringify(body);
        lastBody = body;
        pendingFocus = withFocus;
        moreError.value = null;
        if (state.value === 'results') refreshing.value = true;
        else state.value = 'loading';

        const result = await securityFetch('/health/search', { method: 'POST', body: { ...lastBody, page: 1 }, replay: true, signal });
        if (mine !== seq) return;
        controller = null;
        inflightKey = null;
        refreshing.value = false;
        if (result.ok && Array.isArray(result.data?.items)) {
            items.value = result.data.items;
            hasMore.value = Boolean(result.data.hasMore);
            dropped.value = Number.isInteger(result.data.dropped) && result.data.dropped > 0 ? result.data.dropped : 0;
            page.value = 1;
            state.value = items.value.length > 0 ? 'results' : 'empty';
            if (pendingFocus) focusTick.value += 1;
            return;
        }
        if (result.error === 'aborted') return;
        if (result.status === 423 && result.error === 'cancelled') {
            // "Confirm it's you" was closed: nothing was sent, the member stays where they are with the query and the results.
            state.value = items.value.length > 0 ? 'results' : 'idle';
            return;
        }
        items.value = [];
        hasMore.value = false;
        if (result.status === 423) {
            if (handleHealthRefusal(result.error)) {
                reset('idle');
                query.value = '';
                return;
            }
        }
        if (result.status === 429) {
            state.value = 'throttled';
            startCooldown(result.seconds);
            return;
        }
        errorKind.value = result.ok ? 'generic' : failureKind(result);
        state.value = 'error';
    }

    async function loadMore() {
        if (loadingMore.value || state.value !== 'results' || !hasMore.value || lastBody === null || refreshing.value) return;
        if (cooling.value) {
            // Inside the pause after "too many requests": say so instead of sending another one.
            moreError.value = 'throttled';
            return;
        }
        const mine = seq;
        loadingMore.value = true;
        moreError.value = null;
        moreController = new AbortController();
        const signal = moreController.signal;
        const next = page.value + 1;
        const result = await securityFetch('/health/search', { method: 'POST', body: { ...lastBody, page: next }, replay: true, signal });
        if (mine !== seq) return;
        moreController = null;
        loadingMore.value = false;
        if (result.ok && Array.isArray(result.data?.items)) {
            const known = new Set(items.value.map((item) => item.documentId));
            const fresh = result.data.items.filter((item) => !known.has(item.documentId));
            const firstIndex = items.value.length;
            items.value = [...items.value, ...fresh];
            hasMore.value = Boolean(result.data.hasMore);
            page.value = next;
            if (fresh.length > 0) loaded.value = { tick: loaded.value.tick + 1, count: fresh.length, firstIndex };
            return;
        }
        if (result.error === 'aborted') return;
        // Closed "Confirm it's you": the rows already shown stay, nothing else changes.
        if (result.status === 423 && result.error === 'cancelled') return;
        if (result.status === 423 && handleHealthRefusal(result.error)) {
            reset('idle');
            query.value = '';
            return;
        }
        if (result.status === 429) startCooldown(result.seconds);
        moreError.value = result.status === 429 ? 'throttled' : failureKind(result);
    }

    // Typing: under two letters nothing is sent; from two letters the request follows after a pause.
    function setQuery(value) {
        query.value = typeof value === 'string' ? value : '';
        const length = trimmed.value.length;
        if (length < MIN_LENGTH) {
            reset(length === 1 ? 'too_short' : 'idle');
            return;
        }
        stopTimer();
        timer = setTimeout(() => { timer = null; run(false); }, DEBOUNCE_MS);
    }

    // Enter or the button: no waiting.
    function submit() {
        const length = trimmed.value.length;
        if (length < MIN_LENGTH) {
            reset(length === 1 ? 'too_short' : 'idle');
            return;
        }
        return run(true);
    }

    // Runs the same query again (another scope, or the text behind a result was deleted). force: even when the same
    // search is already on its way, because that answer may still carry text that is gone now.
    function rescope(force = false) {
        if (trimmed.value.length < MIN_LENGTH || !active.value) return;
        if (force) {
            // No snippet of the old answer stays on screen while the new one is on its way.
            abortAll();
            items.value = [];
            hasMore.value = false;
            moreError.value = null;
            state.value = 'loading';
        }
        run(false, force);
    }

    function retry() {
        return run(true);
    }

    function clear() {
        query.value = '';
        reset('idle');
    }

    onBeforeUnmount(() => {
        stopTimer();
        if (coolTimer !== null) clearTimeout(coolTimer);
        abortAll();
    });

    return { query, state, items, hasMore, page, errorKind, refreshing, loadingMore, moreError, focusTick, loaded, cooling, waitingRetry, dropped, active, setQuery, submit, rescope, retry, loadMore, clear };
}
