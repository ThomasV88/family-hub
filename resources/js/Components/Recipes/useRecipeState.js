import { computed, reactive } from 'vue';
import { securityFetch } from '../../Security/securityFetch.js';

/**
 * The member's own marks on one recipe (ingredient ticks, done steps, favourite) and every other state write of the detail
 * (notes, servings, reset), sent through ONE first-in-first-out queue with a single request in flight.
 *
 * Same lesson as Shopping's useMarkQueue: requests are never aborted or sent together, so a quick series of taps cannot lose a
 * mark. Every mark is an absolute target state (a retry or a late arrival cannot drift). A mark is shown at once (an override)
 * until its own last request settles; a failure rolls back only that row to the last state the server confirmed and calls
 * `onFail(key, status)`. A second tap on a row whose request is still waiting just replaces the waiting target; a tap on a row
 * whose request is in flight queues one more absolute state behind it. A reset (any non-mark job) is a barrier: a tap made after
 * it is never merged into a mark that waits before it, so it cannot be wiped by the reset.
 *
 * Keys: `i:{ingredientId}`, `s:{stepId}`, `fav`. initial = { tickedIngredientIds, doneStepIds, favourite }.
 * `request` is the JSON sender (securityFetch: { ok, status }); it is injectable for tests.
 */
export function useRecipeState({ recipeId, initial = {}, onFail = () => {}, request = securityFetch }) {
    const base = reactive({});
    const overrides = reactive({});
    const counts = reactive({});
    const queue = [];
    const waiting = [];
    let running = false;
    let pendingResync = null;

    for (const id of initial.tickedIngredientIds ?? []) base[`i:${id}`] = true;
    for (const id of initial.doneStepIds ?? []) base[`s:${id}`] = true;
    base.fav = initial.favourite === true;

    const root = `/recipes/${Number(recipeId)}`;
    function describe(key, target) {
        if (key === 'fav') return { url: `${root}/favourite`, method: 'PUT', body: { favourite: target } };
        const id = Number(key.slice(2));

        return key.startsWith('i:')
            ? { url: `${root}/ingredients/${id}/tick`, method: 'PUT', body: { ticked: target } }
            : { url: `${root}/steps/${id}/done`, method: 'PUT', body: { done: target } };
    }

    /** The state to show: the pending target while the row's request is queued or running, else the confirmed state. */
    const state = (key) => (key in overrides ? overrides[key] : base[key] === true);
    const busy = computed(() => Object.keys(counts).length > 0 || queue.length > 0);

    function release() {
        if (queue.length > 0 || running) return;
        if (pendingResync) {
            const next = pendingResync;
            pendingResync = null;
            seed(next);
        }
        waiting.splice(0).forEach((resolve) => resolve());
    }

    function seed(next) {
        for (const key of Object.keys(base)) delete base[key];
        for (const id of next.tickedIngredientIds ?? []) base[`i:${id}`] = true;
        for (const id of next.doneStepIds ?? []) base[`s:${id}`] = true;
        base.fav = next.favourite === true;
    }

    /**
     * Replaces the confirmed state with fresh server state (after a 403/409 reload). Applied at once when nothing is queued,
     * otherwise as soon as the queue is idle, so a tap in flight is never overwritten by an older snapshot.
     */
    function resync(next) {
        if (queue.length > 0 || running) {
            pendingResync = next;

            return;
        }
        seed(next);
    }

    async function pump() {
        if (running) return;
        running = true;
        while (queue.length > 0) {
            const job = queue.shift();
            let result;
            try {
                result = await request(job.url, { method: job.method, body: job.body });
            } catch {
                result = { ok: false, status: 0 };
            }
            if (job.kind === 'mark') {
                if (result.ok) base[job.key] = job.target;
                counts[job.key] -= 1;
                if (counts[job.key] <= 0) {
                    delete counts[job.key];
                    delete overrides[job.key];
                }
                if (!result.ok) onFail(job.key, result.status ?? 0);
            } else {
                job.resolve(result);
            }
        }
        running = false;
        release();
    }

    /** Show and queue an absolute state for a row (`i:{id}`, `s:{id}`, `fav`). */
    function mark(key, target) {
        overrides[key] = target;
        // Merge only into a waiting mark that sits AFTER the last non-mark job (a reset): searching from the end and
        // stopping at the first send job keeps a tap made after a reset behind that reset.
        let waitingJob = null;
        for (let i = queue.length - 1; i >= 0; i--) {
            if (queue[i].kind !== 'mark') break;
            if (queue[i].key === key) {
                waitingJob = queue[i];
                break;
            }
        }
        if (waitingJob) {
            waitingJob.target = target;
            waitingJob.body = describe(key, target).body;

            return;
        }
        counts[key] = (counts[key] ?? 0) + 1;
        queue.push({ kind: 'mark', key, target, ...describe(key, target) });
        pump();
    }

    /** Any other write in order with the marks. Resolves { ok, status }; never rejects. */
    function send(url, method, body) {
        return new Promise((resolve) => {
            queue.push({ kind: 'send', url, method, body, resolve });
            pump();
        });
    }

    /** Reset my progress: DELETE after every queued mark, then every tick and done step is cleared. */
    async function reset() {
        const result = await send(`${root}/progress`, 'DELETE');
        if (result.ok) {
            for (const key of Object.keys(base)) if (key.startsWith('i:') || key.startsWith('s:')) base[key] = false;
        }

        return result;
    }

    /** Resolves once every queued request has settled. */
    function drained() {
        if (!busy.value && !running) return Promise.resolve();

        return new Promise((resolve) => { waiting.push(resolve); });
    }

    return { state, mark, send, reset, resync, busy, drained, overrides };
}
