import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { mountUi } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';

const router = vi.hoisted(() => ({ post: vi.fn(), clearHistory: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router }));
const fetchMock = vi.fn();
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: (...a) => fetchMock(...a) }));

import TwoFactorSetupDialog from '../../../resources/js/Components/Security/TwoFactorSetupDialog.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const methods = { password: true, totp: false, passkey: false };
const SVG = '<svg data-q="1"><rect width="4" height="4"/></svg>';
const KEY = 'JBSWY3DPEHPK3PXP';
const CODES = ['aaaa-1111', 'bbbb-2222', 'cccc-3333', 'dddd-4444', 'eeee-5555', 'ffff-6666', 'gggg-7777', 'hhhh-8888'];
const ok = (data = {}) => ({ ok: true, status: 200, data });
const stale = { ok: false, status: 423, error: 'confirm_required', data: {} };

let wrapper;
let calls;
function route(overrides = {}) {
    fetchMock.mockImplementation(async (url, opts = {}) => {
        const key = `${opts.method ?? 'GET'} ${url}`;
        calls.push(key);
        const o = overrides[key];
        if (o) return typeof o === 'function' ? o() : o;
        if (key === 'POST /user/two-factor-authentication') return ok({ enabled: true });
        if (key === 'GET /user/two-factor-qr-code') return ok({ svg: SVG });
        if (key === 'GET /user/two-factor-secret-key') return ok({ secretKey: KEY });
        if (key === 'POST /user/confirmed-two-factor-authentication') return ok({ recoveryCodes: CODES });
        if (key === 'DELETE /user/two-factor-authentication') return ok({ disabled: true });
        if (key === 'POST /confirm-access') return ok({ confirmed: true });
        return { ok: false, status: 500, error: 'failed', data: {} };
    });
}
const mountDialog = async (props = {}, locale = 'en') => {
    wrapper = mountUi(TwoFactorSetupDialog, { props: { open: true, methods, confirmFresh: true, isAdmin: false, ...props }, locale });
    await flushPromises();
    return wrapper;
};
const q = (sel) => document.body.querySelector(sel);
const click = async (sel) => { q(sel).click(); await flushPromises(); };
async function toStep2() { await click('[data-testid="next-step"]'); }
async function typeCode(value) {
    const input = q('input[autocomplete="one-time-code"]');
    input.value = value;
    input.dispatchEvent(new Event('input'));
    await flushPromises();
}

beforeEach(() => { calls = []; fetchMock.mockReset(); router.post.mockReset(); router.clearHistory.mockReset(); useToast().toasts.splice(0); });
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; document.documentElement.removeAttribute('data-theme'); vi.restoreAllMocks(); });

describe('TwoFactorSetupDialog', () => {
    it('step 1 starts enrolment and shows the QR with an accessible label; the key sits behind a disclosure in blocks of 4', async () => {
        route();
        await mountDialog();
        expect(calls.slice(0, 3)).toEqual(['POST /user/two-factor-authentication', 'GET /user/two-factor-qr-code', 'GET /user/two-factor-secret-key']);
        const qr = q('[data-testid="qr"]');
        expect(qr.getAttribute('role')).toBe('img');
        expect(qr.getAttribute('aria-label')).toBe('QR code for your authenticator app');
        expect(q('[data-testid="key"]')).toBeNull();
        expect(document.body.textContent).not.toContain('JBSW');
        await click('[data-testid="cant-scan"]');
        expect(q('[data-testid="key"] code').textContent).toBe('JBSW Y3DP EHPK 3PXP');
        expect(q('[data-testid="confirm-code"]')).toBeNull();
        expect(q('[data-testid="setup-dialog"] [data-step-heading]').textContent).toBe('Scan the code');
        expect(q('[data-testid="setup-step"]').textContent).toBe('Step 1 of 3');
    });

    it('copy key writes the plain key and toasts', async () => {
        route();
        const writeText = vi.fn().mockResolvedValue();
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        await mountDialog();
        await click('[data-testid="cant-scan"]');
        await click('[data-testid="copy-key"]');
        expect(writeText).toHaveBeenCalledWith(KEY);
        expect(useToast().toasts.map((x) => x.message)).toContain('Key copied');
    });

    it('leaving step 1 removes the QR and key from the DOM; back to scan fetches them again', async () => {
        route();
        await mountDialog();
        await click('[data-testid="cant-scan"]');
        await toStep2();
        expect(q('[data-testid="qr"]')).toBeNull();
        expect(document.body.innerHTML).not.toContain('data-q');
        expect(document.body.textContent).not.toContain('JBSW');
        expect(document.activeElement).toBe(q('input[autocomplete="one-time-code"]'));
        calls.length = 0;
        await click('[data-testid="back-to-scan"]');
        expect(calls).toEqual(['GET /user/two-factor-qr-code', 'GET /user/two-factor-secret-key']);
        expect(q('[data-testid="qr"]')).not.toBeNull();
    });

    it('a wrong code shows the error as an alert and clears the input', async () => {
        route({ 'POST /user/confirmed-two-factor-authentication': { ok: false, status: 422, error: 'invalid', errors: {}, data: {} } });
        await mountDialog();
        await toStep2();
        await typeCode('123456');
        await click('[data-testid="confirm-code"]');
        expect(q('[role="alert"]').textContent).toContain('That code is not correct. Wait for a new code');
        expect(q('input[autocomplete="one-time-code"]').value).toBe('');
    });

    it('a throttle disables the button with the throttle copy', async () => {
        route({ 'POST /user/confirmed-two-factor-authentication': { ok: false, status: 429, error: 'throttle', minutes: 3, data: {} } });
        await mountDialog();
        await toStep2();
        await typeCode('123456');
        await click('[data-testid="confirm-code"]');
        expect(q('[role="alert"]').textContent).toContain('Try again in 3 minutes');
        expect(q('[data-testid="confirm-code"]').disabled).toBe(true);
    });

    it('step 3 lists the 8 codes, copies, downloads a content-free file and gates Finish on the checkbox', async () => {
        route();
        const writeText = vi.fn().mockResolvedValue();
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        let blob;
        URL.createObjectURL = vi.fn((b) => { blob = b; return 'blob:x'; });
        URL.revokeObjectURL = vi.fn();
        const anchorClick = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () { expect(this.download).toBe('family-hub-recovery-codes.txt'); });
        const addSpy = vi.spyOn(window, 'addEventListener');

        await mountDialog();
        await toStep2();
        await typeCode('123456');
        await click('[data-testid="confirm-code"]');

        const list = q('ul[aria-label="Recovery codes"]');
        expect(list.querySelectorAll('li')).toHaveLength(8);
        expect(addSpy.mock.calls.some(([name]) => name === 'beforeunload')).toBe(true);

        await click('[data-testid="copy-codes"]');
        expect(writeText).toHaveBeenCalledWith(CODES.join('\n'));
        await click('[data-testid="download-codes"]');
        expect(anchorClick).toHaveBeenCalled();
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:x');
        const text = await blob.text();
        expect(text).toContain('Family Hub recovery codes');
        expect(text).toContain('aaaa-1111');
        expect(text).not.toMatch(/@/);

        expect(q('[data-testid="finish"]').disabled).toBe(true);
        q('[data-testid="ack"]').click();
        await flushPromises();
        expect(q('[data-testid="finish"]').disabled).toBe(false);
    });

    it('Esc and scrim do not close at step 3 and no close button is shown', async () => {
        route();
        await mountDialog();
        await toStep2();
        await typeCode('123456');
        await click('[data-testid="confirm-code"]');
        q('.ui-dialog__scrim').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        q('.ui-dialog__scrim').click();
        await flushPromises();
        expect(wrapper.emitted('update:open')).toBeUndefined();
        expect(q('[data-testid="recovery-panel"]')).not.toBeNull();
        expect(q('.ui-dialog__header button')).toBeNull();
    });

    it('Finish wipes everything, clears history, toasts and signals the page', async () => {
        route();
        await mountDialog();
        await toStep2();
        await typeCode('123456');
        await click('[data-testid="confirm-code"]');
        q('[data-testid="ack"]').click();
        await flushPromises();
        await click('[data-testid="finish"]');
        expect(router.clearHistory).toHaveBeenCalled();
        expect(useToast().toasts.map((x) => x.message)).toContain('Authenticator app is on');
        expect(wrapper.emitted('finished')).toHaveLength(1);
        expect(wrapper.emitted('update:open').at(-1)).toEqual([false]);
        // Parent closes the dialog: nothing secret is left behind.
        await wrapper.setProps({ open: false });
        await flushPromises();
        const html = document.body.innerHTML;
        for (const secret of [...CODES, KEY, 'data-q']) expect(html).not.toContain(secret);
    });

    it('Cancel at step 1 or 2 deletes the pending secret without a confirm dialog', async () => {
        route();
        await mountDialog();
        await click('[data-testid="cancel-setup"]');
        expect(calls).toContain('DELETE /user/two-factor-authentication');
        expect(wrapper.emitted('update:open').at(-1)).toEqual([false]);
        wrapper.unmount();
        calls.length = 0;
        await mountDialog();
        await toStep2();
        await click('[data-testid="cancel-setup"]');
        expect(calls).toContain('DELETE /user/two-factor-authentication');
        expect(document.body.querySelectorAll('[role="dialog"]')).toHaveLength(1);
    });

    describe('enrolment confirmation (A2)', () => {
        it('a fresh window goes straight to step 1 with no form', async () => {
            route();
            await mountDialog({ confirmFresh: true });
            expect(q('.reauth-form')).toBeNull();
            expect(q('[data-testid="qr"]')).not.toBeNull();
        });

        it('a stale window opens on the confirm step and enables only after confirming', async () => {
            route();
            await mountDialog({ confirmFresh: false });
            expect(q('[role="dialog"]').getAttribute('aria-labelledby')).toBeTruthy();
            expect(document.body.textContent).toContain("Confirm it's you");
            expect(document.body.textContent).toContain('Confirm it\'s you before changing two-step verification.');
            expect(calls).toEqual([]);
            expect(document.activeElement.getAttribute('autocomplete')).toBe('current-password');
            const input = q('input[autocomplete="current-password"]');
            input.value = 'pw';
            input.dispatchEvent(new Event('input'));
            q('.reauth-form form').dispatchEvent(new Event('submit'));
            await flushPromises();
            expect(calls[0]).toBe('POST /confirm-access');
            expect(calls).toContain('POST /user/two-factor-authentication');
            expect(q('[data-testid="qr"]')).not.toBeNull();
            expect(q('input[autocomplete="current-password"]')).toBeNull();
        });

        it('enable answering 423 shows the confirm step and retries enable afterwards', async () => {
            let first = true;
            route({ 'POST /user/two-factor-authentication': () => { if (first) { first = false; return stale; } return ok({ enabled: true }); } });
            await mountDialog({ confirmFresh: true });
            expect(q('.reauth-form')).not.toBeNull();
            const input = q('input[autocomplete="current-password"]');
            input.value = 'pw';
            input.dispatchEvent(new Event('input'));
            q('.reauth-form form').dispatchEvent(new Event('submit'));
            await flushPromises();
            expect(calls.filter((c) => c === 'POST /user/two-factor-authentication')).toHaveLength(2);
            expect(q('[data-testid="qr"]')).not.toBeNull();
        });

        it('confirm answering 423 re-shows the form in place, keeps the typed code and retries it', async () => {
            let first = true;
            route({ 'POST /user/confirmed-two-factor-authentication': () => { if (first) { first = false; return stale; } return ok({ recoveryCodes: CODES }); } });
            await mountDialog();
            await toStep2();
            await typeCode('654321');
            await click('[data-testid="confirm-code"]');
            expect(q('.reauth-form')).not.toBeNull();
            expect(q('input[autocomplete="one-time-code"]').value).toBe('654321');
            const input = q('input[autocomplete="current-password"]');
            input.value = 'pw';
            input.dispatchEvent(new Event('input'));
            q('.reauth-form form').dispatchEvent(new Event('submit'));
            await flushPromises();
            const confirms = fetchMock.mock.calls.filter(([u]) => u === '/user/confirmed-two-factor-authentication');
            expect(confirms).toHaveLength(2);
            expect(confirms[1][1].body).toEqual({ code: '654321' });
            expect(q('[data-testid="recovery-panel"]')).not.toBeNull();
        });

        it('shows the no-method copy and Sign in again when nothing can confirm', async () => {
            route();
            await mountDialog({ confirmFresh: false, methods: { password: false, totp: false, passkey: false }, isAdmin: true });
            expect(q('.reauth-form')).toBeNull();
            expect(q('[data-testid="reauth-no-method"]').textContent).toContain('no password or second step');
            expect(q('a[href="/settings/security"]')).not.toBeNull();
            await click('[data-testid="sign-in-again"]');
            expect(router.post).toHaveBeenCalledWith('/confirm-access/sign-in-again');
        });

        it('never navigates to the confirm page', async () => {
            route();
            await mountDialog({ confirmFresh: false });
            expect(router.post).not.toHaveBeenCalled();
        });
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const theme of ['light', 'dark']) {
            it(`has no axe violations at each step (${locale}, ${theme})`, async () => {
                document.documentElement.setAttribute('data-theme', theme);
                route();
                await mountDialog({}, locale);
                await click('[data-testid="cant-scan"]');
                await expectNoAxeViolations(document.body);
                await toStep2();
                await expectNoAxeViolations(document.body);
                await typeCode('123456');
                await click('[data-testid="confirm-code"]');
                await expectNoAxeViolations(document.body);
                wrapper.unmount();
                document.body.innerHTML = '';
                route();
                await mountDialog({ confirmFresh: false }, locale);
                await expectNoAxeViolations(document.body);
            });
        }
    }
});
