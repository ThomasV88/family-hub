import { router } from '@inertiajs/vue3';

// One way to turn a failed Shopping request into a message and into a page that matches the server again.
// 422 never reaches here (it is a validation error, handled by each form). 419 is handled globally (back to the sign-in page).

/** Lang key for a failed response. `fallback` is used for anything that is not a known refusal (5xx, unknown). */
export function failureKey(response, fallback = 'shopping.error.save_failed') {
    switch (response?.status) {
        case 403: return 'shopping.error.forbidden';
        case 404:
        case 409: return 'shopping.error.list_changed';
        case 429: return 'shopping.error.too_many_requests';
        default: return fallback;
    }
}

/**
 * After 403, 404 or 409 the page shows a state the server no longer has (list unlocked, finished, deleted, no longer allowed).
 * Reload it; if the list itself is gone, leave for the overview.
 */
export function reconcile(response) {
    if (![403, 404, 409].includes(response?.status)) return;
    router.reload({
        preserveScroll: true,
        onHttpException: (next) => {
            if (next?.status === 404) router.visit('/shopping', { replace: true });

            return false;
        },
    });
}

/**
 * onHttpException and onNetworkError for a visit. `report(key, response)` shows the message in the component's own place
 * (an alert in a dialog, a toast). Returning false stops Inertia from opening its raw error window.
 */
export function httpHandlers(report, fallback = 'shopping.error.save_failed') {
    return {
        onHttpException: (response) => {
            report(failureKey(response, fallback), response);
            reconcile(response);

            return false;
        },
        onNetworkError: () => report(fallback, null),
    };
}
