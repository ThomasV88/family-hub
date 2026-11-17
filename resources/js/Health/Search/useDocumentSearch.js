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
        state.value = next;
    }

    function startCooldown() {
        cooling.value = true;
        if (coolTimer !== null) clearTimeout(coolTimer);
        coolTimer = setTimeout(() => {
            coolTimer = null;
            cooling.value = false;
            if (deferred) {
                deferred = false;
                if (trimmed.value.length >= MIN_LENGTH) run(false);
            }
        }, COOLDOWN_MS);
    }

    function failureKind(result) {
        if (result.status === 419) return 'session';
        if (result.status === 403 || result.status === 404) return 'forbidden';
        return 'generic';
    }

    async function run(withFocus) {
        stopTimer();
        const q = trimmed.value;
        if (q.length < MIN_LENGTH) return;
        if (cooling.value) {
            deferred = true;
            return;
        }
        const person = scopePersonId();
        const body = { q, ...(person ? { person } : {}) };
        // The same search is already on its way (Enter or the button pressed again): no second request.
        if (controller !== null && inflightKey === JSON.stringify(body)) {
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
            page.value = 1;
            state.value = items.value.length > 0 ? 'results' : 'empty';
            if (pendingFocus) focusTick.value += 1;
            return;
        }
        if (result.error === 'aborted') return;
        items.value = [];
        hasMore.value = false;
        if (result.status === 423 && handleHealthRefusal(result.error)) {
            reset('idle');
            query.value = '';
            return;
        }
        if (result.status === 429) {
            state.value = 'throttled';
            startCooldown();
            return;
        }
        errorKind.value = result.ok ? 'generic' : failureKind(result);
        state.value = 'error';
    }

    async function loadMore() {
        if (loadingMore.value || state.value !== 'results' || !hasMore.value || lastBody === null || refreshing.value) return;
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
        if (result.status === 423 && handleHealthRefusal(result.error)) {
            reset('idle');
            query.value = '';
            return;
        }
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

    function rescope() {
        if (trimmed.value.length >= MIN_LENGTH && active.value) run(false);
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

    return { query, state, items, hasMore, page, errorKind, refreshing, loadingMore, moreError, focusTick, loaded, cooling, active, setQuery, submit, rescope, retry, loadMore, clear };
}
