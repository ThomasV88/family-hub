// One mapping of the idle gate's 423 for every Health screen that talks JSON (uploads, downloads, exports), the same
// as the finance import wizard: the member leaves for the confirm page (or the security page when a second factor is
// missing). `onBeforeLeave` lets the page lift its unsaved-changes guard first. Nothing is replayed after the visit.
import { router } from '@inertiajs/vue3';

const TARGETS = {
    reauth_required: '/confirm-access',
    confirm_required: '/confirm-access',
    second_factor_required: '/settings/security',
};

// Returns true when the code was a gate refusal and the visit was started, false otherwise.
export function handleHealthRefusal(code, { onBeforeLeave } = {}) {
    const target = Object.hasOwn(TARGETS, code) ? TARGETS[code] : null;
    if (target === null) return false;
    if (typeof onBeforeLeave === 'function') onBeforeLeave();
    router.visit(target);
    return true;
}
