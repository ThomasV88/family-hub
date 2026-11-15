// One mapping of the idle gate's 423 for every Health screen that talks JSON (uploads, downloads, exports), the same
// as the finance import wizard. Callers pass `replay: true` to securityFetch / securityUpload / securityDownload, so a
// stale gate normally opens "Confirm it's you" over the page and the call is replayed once (staleGate.js). What reaches
// here is the rest: 'cancelled' (the member closed the dialog: stay put, nothing was sent), a refusal that came back
// after the replay, or a missing second factor. Those leave for the confirm page (or the security page when a second
// factor is missing); `onBeforeLeave` lets the page lift its unsaved-changes guard first.
import { router } from '@inertiajs/vue3';

const TARGETS = {
    reauth_required: '/confirm-access',
    confirm_required: '/confirm-access',
    second_factor_required: '/settings/security',
};

// Returns true when the code was a gate refusal and the visit was started, false otherwise.
export function handleHealthRefusal(code, { onBeforeLeave } = {}) {
    if (code === 'cancelled') return true;
    const target = Object.hasOwn(TARGETS, code) ? TARGETS[code] : null;
    if (target === null) return false;
    if (typeof onBeforeLeave === 'function') onBeforeLeave();
    router.visit(target);
    return true;
}
