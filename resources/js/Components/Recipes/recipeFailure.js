// One way to turn a failed Recipes request into a message key, shared by the form and the photo field (same idea as
// Shopping/failure.js, with the Recipes texts). 422 is a validation error and handled by each caller; 419 (expired session)
// is handled globally by the app for Inertia visits and named here for plain fetch calls.

/** Lang key for a failed response status. `fallback` covers 5xx, dropped connections and anything unknown. */
export function failureKey(status, fallback = 'recipes.error.save_failed') {
    switch (status) {
        case 403: return 'recipes.error.forbidden';
        case 404: return 'recipes.error.not_found';
        case 419: return 'recipes.error.session_expired';
        case 409: return 'recipes.error.changed';
        case 429: return 'recipes.error.too_many_requests';
        default: return fallback;
    }
}

/** The recipe is gone or no longer visible: the page cannot show it any more. */
export const isGone = (status) => status === 404;

/** First message of a Laravel error value (string or list). */
export const firstMessage = (value) => (Array.isArray(value) ? String(value[0] ?? '') : String(value ?? ''));
