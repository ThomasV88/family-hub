import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { mountUi } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';

const router = vi.hoisted(() => ({ post: vi.fn(), clearHistory: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router }));
const fetchMock = vi.fn();
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: (...a) => fetchMock(...a) }));

import TotpDisableDialog from '../../../resources/js/Components/Security/TotpDisableDialog.vue';
import RegenerateCodesDialog from '../../../resources/js/Components/Security/RegenerateCodesDialog.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const methods = { password: true, totp: true, passkey: false, google: false };
const none = { password: false, totp: false, passkey: false, google: false };
const CODES = ['aaaa-1111', 'bbbb-2222', 'cccc-3333', 'dddd-4444', 'eeee-5555', 'ffff-6666', 'gggg-7777', 'hhhh-8888'];
const ok = (data = {}) => ({ ok: true, status: 200, data });
const stale = { ok: false, status: 423, error: 'confirm_required', data: {} };

let wrapper;
const q = (sel) => document.body.querySelector(sel);
const click = async (sel) => { q(sel).click(); await flushPromises(); };
const mountIt = async (component, props = {}, locale = 'en') => {
    wrapper = mountUi(component, { props: { open: true, methods, isAdmin: false, ...props }, locale });
    await flushPromises();
};
async function confirmWithPassword() {
    const input = q('input[autocomplete="current-password"]');
    input.value = 'pw';
    input.dispatchEvent(new Event('input'));
    q('.reauth-form form').dispatchEvent(new Event('submit'));
    await flushPromises();
}
// Answers by "METHOD url"; a function entry is called each time (so it can answer 423 first, then succeed).
function route(map) {
    fetchMock.mockImplementation(async (url, opts = {}) => {
        const entry = map[`${opts.method ?? 'GET'} ${url}`];
        if (entry) return typeof entry === 'function' ? entry() : entry;
        if (url === '/confirm-access') return ok({ confirmed: true });
        return { ok: false, status: 500, error: 'failed', data: {} };
    });
}
const staleThenOk = (data) => { let first = true; return () => { if (first) { first = false; return stale; } return ok(data); }; };

beforeEach(() => { fetchMock.mockReset(); router.post.mockReset(); router.clearHistory.mockReset(); useToast().toasts.splice(0); });
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; document.documentElement.removeAttribute('data-theme'); vi.restoreAllMocks(); });

describe('TotpDisableDialog', () => {
    it('shows the Screen 5 copy with the safe button focused and no form when fresh', async () => {
        route({ 'DELETE /user/two-factor-authentication': ok({ disabled: true }) });
        await mountIt(TotpDisableDialog);
        expect(q('[role="dialog"] h2').textContent).toBe('Turn off authenticator app');
        expect(document.body.textContent).toContain('Your recovery codes will stop working');
        expect(q('[data-testid="keep"]').textContent).toBe('Keep authenticator app');
        expect(document.activeElement).toBe(q('[data-testid="keep"]'));
        expect(q('.reauth-form')).toBeNull();
        expect(q('[data-testid="last-factor"]')).toBeNull();
    });

    it('shows the last-factor warning when asked', async () => {
        route({});
        await mountIt(TotpDisableDialog, { lastFactor: true });
        expect(q('[data-testid="last-factor"]').textContent).toContain('only second step');
    });

    it('turns off directly when the window is fresh, toasts and tells the page', async () => {
        route({ 'DELETE /user/two-factor-authentication': ok({ disabled: true }) });
        await mountIt(TotpDisableDialog);
        await click('[data-testid="turn-off"]');
        expect(fetchMock).toHaveBeenCalledWith('/user/two-factor-authentication', { method: 'DELETE' });
        expect(useToast().toasts.map((x) => x.message)).toContain('Authenticator app is off');
        expect(wrapper.emitted('done')).toHaveLength(1);
        expect(wrapper.emitted('update:open').at(-1)).toEqual([false]);
    });

    it('a 423 shows the form in the dialog (focused) and retries the DELETE after confirming, never navigating', async () => {
        route({ 'DELETE /user/two-factor-authentication': staleThenOk({ disabled: true }) });
        await mountIt(TotpDisableDialog);
        await click('[data-testid="turn-off"]');
        expect(q('.reauth-form')).not.toBeNull();
        expect(document.body.textContent).toContain("Confirm it's you before changing two-step verification.");
        expect(document.activeElement.getAttribute('autocomplete')).toBe('current-password');
        expect(q('[data-testid="turn-off"]')).toBeNull();
        await confirmWithPassword();
        expect(fetchMock.mock.calls.filter(([u, o]) => u === '/user/two-factor-authentication' && o.method === 'DELETE')).toHaveLength(2);
        expect(wrapper.emitted('done')).toHaveLength(1);
        expect(router.post).not.toHaveBeenCalled();
    });

    it('with no usable method a stale refusal shows no-method, Sign in again and the admin link', async () => {
        route({ 'DELETE /user/two-factor-authentication': stale });
        await mountIt(TotpDisableDialog, { methods: none, isAdmin: true });
        await click('[data-testid="turn-off"]');
        expect(q('.reauth-form')).toBeNull();
        expect(q('[data-testid="reauth-no-method"]').textContent).toContain('no password or second step');
        expect(q('a[href="/settings/security"]').textContent).toBe('Set up an authenticator app first');
        await click('[data-testid="sign-in-again"]');
        expect(router.post).toHaveBeenCalledWith('/confirm-access/sign-in-again');
    });

    it('keeps no password in the DOM after closing', async () => {
        route({ 'DELETE /user/two-factor-authentication': stale });
        await mountIt(TotpDisableDialog);
        await click('[data-testid="turn-off"]');
        const input = q('input[autocomplete="current-password"]');
        input.value = 'hunter2';
        input.dispatchEvent(new Event('input'));
        await wrapper.setProps({ open: false });
        await flushPromises();
        expect(document.body.innerHTML).not.toContain('hunter2');
    });
});

describe('RegenerateCodesDialog', () => {
    it('shows the Screen 6 copy, safe focus, and creates codes directly when fresh', async () => {
        route({ 'POST /user/two-factor-recovery-codes': ok({ recoveryCodes: CODES }) });
        await mountIt(RegenerateCodesDialog);
        expect(q('[role="dialog"] h2').textContent).toBe('Create new recovery codes');
        expect(document.body.textContent).toContain('Your current codes will stop working');
        expect(document.activeElement).toBe(q('[data-testid="keep"]'));
        expect(q('.reauth-form')).toBeNull();
        await click('[data-testid="create"]');
        expect(q('ul[aria-label="Recovery codes"]').querySelectorAll('li')).toHaveLength(8);
        expect(q('[data-testid="finish"]').textContent).toBe('Finish and close');
    });

    it('the codes view is not dismissable by Esc, scrim or a close button', async () => {
        route({ 'POST /user/two-factor-recovery-codes': ok({ recoveryCodes: CODES }) });
        await mountIt(RegenerateCodesDialog);
        await click('[data-testid="create"]');
        q('.ui-dialog__scrim').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        q('.ui-dialog__scrim').click();
        await flushPromises();
        expect(wrapper.emitted('update:open')).toBeUndefined();
        expect(q('.ui-dialog__header button')).toBeNull();
    });

    it('finishing removes the codes from the DOM and refreshes the page', async () => {
        route({ 'POST /user/two-factor-recovery-codes': ok({ recoveryCodes: CODES }) });
        await mountIt(RegenerateCodesDialog);
        await click('[data-testid="create"]');
        q('[data-testid="ack"]').click();
        await flushPromises();
        await click('[data-testid="finish"]');
        expect(wrapper.emitted('done')).toHaveLength(1);
        await wrapper.setProps({ open: false });
        await flushPromises();
        for (const c of CODES) expect(document.body.innerHTML).not.toContain(c);
    });

    it('a 423 shows the form in place and retries once confirmed', async () => {
        route({ 'POST /user/two-factor-recovery-codes': staleThenOk({ recoveryCodes: CODES }) });
        await mountIt(RegenerateCodesDialog);
        await click('[data-testid="create"]');
        expect(q('.reauth-form')).not.toBeNull();
        expect(q('[data-testid="create"]')).toBeNull();
        await confirmWithPassword();
        expect(q('[data-testid="recovery-panel"]')).not.toBeNull();
        expect(router.post).not.toHaveBeenCalled();
    });

    it('with no usable method shows no-method and Sign in again', async () => {
        route({ 'POST /user/two-factor-recovery-codes': stale });
        await mountIt(RegenerateCodesDialog, { methods: none });
        await click('[data-testid="create"]');
        expect(q('[data-testid="reauth-no-method"]')).not.toBeNull();
        expect(q('a[href="/settings/security"]')).toBeNull();
    });
});

describe('dialog accessibility', () => {
    for (const locale of ['en', 'sk', 'pl']) {
        for (const theme of ['light', 'dark']) {
            it(`has no axe violations (${locale}, ${theme}) fresh, stale with form, no-method`, async () => {
                document.documentElement.setAttribute('data-theme', theme);
                for (const [Component, url] of [[TotpDisableDialog, 'DELETE /user/two-factor-authentication'], [RegenerateCodesDialog, 'POST /user/two-factor-recovery-codes']]) {
                    route({ [url]: stale });
                    await mountIt(Component, {}, locale);
                    await expectNoAxeViolations(document.body);
                    await click('[data-testid="turn-off"], [data-testid="create"]');
                    await expectNoAxeViolations(document.body);
                    wrapper.unmount();
                    document.body.innerHTML = '';
                    await mountIt(Component, { methods: none, isAdmin: true }, locale);
                    await click('[data-testid="turn-off"], [data-testid="create"]');
                    await expectNoAxeViolations(document.body);
                    wrapper.unmount();
                    document.body.innerHTML = '';
                }
            });
        }
    }
});
