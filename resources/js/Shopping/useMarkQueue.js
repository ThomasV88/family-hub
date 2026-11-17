import { computed, reactive } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Marks of one list (Bought, Unavailable, back to pending), sent one at a time in the order they were tapped.
 *
 * Inertia runs a router.put as a synchronous visit, and a new synchronous visit aborts the one in flight. So taps are never sent
 * together: each is queued and sent when the previous one has settled. Every tap is an absolute target state, so the order and
 * a retry cannot drift. The shown state of an item (an override) stays until its own request settles, whatever the other
 * requests do. A failure rolls back only its own item and is reported through `onFail`.
 *
 * url(itemId) -> the state endpoint of an item. onFail(itemId, response|null) is called once per failed item.
 */
export function useMarkQueue({ url, onFail }) {
    const overrides = reactive({});
    const pending = reactive({});
    const queue = [];
    let running = false;
    let waiting = [];

    const busy = computed(() => Object.keys(pending).length > 0);

    function release() {
        const callbacks = waiting;
        waiting = [];
        callbacks.forEach((resolve) => resolve());
    }

    function pump() {
        if (running) return;
        const job = queue.shift();
        if (!job) {
            release();

            return;
        }
        running = true;
        let failed = false;
        const fail = (response = null) => {
            if (failed) return;
            failed = true;
            onFail(job.itemId, response);
        };
        const settle = () => {
            delete overrides[job.itemId];
            delete pending[job.itemId];
            running = false;
            // The next visit starts after Inertia has dropped this finished request from its stream.
            setTimeout(pump, 0);
        };
        try {
            router.put(url(job.itemId), { state: job.target }, {
                preserveScroll: true,
                preserveState: true,
                onError: () => fail(),
                onHttpException: (response) => { fail(response); return false; },
                onNetworkError: () => fail(),
                onCancel: () => fail(),
                onFinish: settle,
            });
        } catch {
            fail();
            settle();
        }
    }

    /** Queue a mark. A tap on an item whose own mark is still queued or running is ignored. Returns true when queued. */
    function mark(itemId, target) {
        if (pending[itemId]) return false;
        pending[itemId] = true;
        overrides[itemId] = target;
        queue.push({ itemId, target });
        pump();

        return true;
    }

    /** Resolves once every queued mark has settled (at once when none is queued). */
    function drained() {
        if (!busy.value) return Promise.resolve();

        return new Promise((resolve) => { waiting.push(resolve); });
    }

    return { overrides, pending, busy, mark, drained };
}
