import { describe, it, expect, vi, beforeEach } from 'vitest';

const { router } = vi.hoisted(() => ({ router: { visit: vi.fn() } }));
vi.mock('@inertiajs/vue3', () => ({ router }));

import { handleHealthRefusal } from '../../../resources/js/Health/healthRefusal.js';

beforeEach(() => vi.clearAllMocks());

describe('handleHealthRefusal', () => {
    it.each(['reauth_required', 'confirm_required'])('lifts the guard, then visits the confirm page for %s', (code) => {
        const order = [];
        router.visit.mockImplementation(() => order.push('visit'));
        const onBeforeLeave = vi.fn(() => order.push('before'));

        expect(handleHealthRefusal(code, { onBeforeLeave })).toBe(true);

        expect(order).toEqual(['before', 'visit']);
        expect(router.visit).toHaveBeenCalledWith('/confirm-access');
    });

    it('sends a missing second factor to the security settings', () => {
        const onBeforeLeave = vi.fn();
        expect(handleHealthRefusal('second_factor_required', { onBeforeLeave })).toBe(true);
        expect(onBeforeLeave).toHaveBeenCalledOnce();
        expect(router.visit).toHaveBeenCalledWith('/settings/security');
    });

    it('works without a callback', () => {
        expect(handleHealthRefusal('reauth_required')).toBe(true);
        expect(router.visit).toHaveBeenCalledWith('/confirm-access');
    });

    it.each(['too_large', 'validation', 'network', 'failed', undefined, null, '', 'toString', '__proto__'])('ignores %s without visiting', (code) => {
        const onBeforeLeave = vi.fn();
        expect(handleHealthRefusal(code, { onBeforeLeave })).toBe(false);
        expect(onBeforeLeave).not.toHaveBeenCalled();
        expect(router.visit).not.toHaveBeenCalled();
    });
});
