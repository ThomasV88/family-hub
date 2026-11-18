import { computed, onBeforeUnmount, ref } from 'vue';
import { securityFetch } from '../Security/securityFetch.js';

export const POLL_INTERVAL_MS = 1500;
export const POLL_LIMIT = 20;

/**
 * Autofill of the wish form from its first link. Asks the server (POST, then polls GET), fills the title and the
 * notes only while they are still blank (also blank when the answer arrives), and exposes what could not be loaded.
 * `form` is the panel's reactive form ({ title, notes }). Nothing is saved here.
 */
export function useLinkPreview(form) {
    const status = ref('idle'); // idle | loading | done
    const result = ref(null);
    const token = ref('');
    const ran = ref(false);
    let timer = null;
    let run = 0;

    const thumbUrl = computed(() => (token.value && result.value?.image ? `/wishlist/link-preview/${token.value}/image` : ''));
    // Field names (image | title | description) that could not be loaded, each with its reason code when known.
    const missing = computed(() => {
        if (status.value !== 'done') return [];
        const failures = result.value?.failures ?? {};
        return ['image', 'title', 'description'].filter((f) => f in failures).map((field) => ({ field, reason: failures[field] }));
    });

    function stop() {
        run += 1;
        if (timer !== null) clearTimeout(timer);
        timer = null;
    }
    function reset() {
        stop();
        status.value = 'idle';
        result.value = null;
        token.value = '';
        ran.value = false;
    }
    const failAll = (reason) => ({ status: 'done', title: null, description: null, image: false, failures: { image: reason, title: reason, description: reason } });

    function apply(data, blank) {
        if (blank.title && form.value.title.trim() === '' && data.title) form.value.title = data.title;
        if (blank.notes && form.value.notes.trim() === '' && data.description) form.value.notes = data.description;
    }

    async function start(url) {
        stop();
        const mine = run;
        ran.value = true;
        status.value = 'loading';
        result.value = null;
        token.value = '';
        const blank = { title: form.value.title.trim() === '', notes: form.value.notes.trim() === '' };
        const started = await securityFetch('/wishlist/link-preview', { method: 'POST', body: { url: url.trim() } });
        if (mine !== run) return;
        if (!started.ok || !started.data?.token) {
            result.value = failAll('unreachable');
            status.value = 'done';
            return;
        }
        token.value = started.data.token;
        let polls = 0;
        const tick = async () => {
            polls += 1;
            const answer = await securityFetch(`/wishlist/link-preview/${token.value}`, { cache: 'no-store' });
            if (mine !== run) return;
            if (answer.ok && answer.data?.status === 'done') {
                result.value = answer.data;
                status.value = 'done';
                apply(answer.data, blank);
                return;
            }
            if (!answer.ok && answer.status !== 0) {
                result.value = failAll('unreachable');
                status.value = 'done';
                return;
            }
            if (polls >= POLL_LIMIT) {
                result.value = failAll('timeout');
                status.value = 'done';
                return;
            }
            timer = setTimeout(tick, POLL_INTERVAL_MS);
        };
        timer = setTimeout(tick, POLL_INTERVAL_MS);
    }

    onBeforeUnmount(stop);

    return { status, result, ran, thumbUrl, missing, start, reset };
}
