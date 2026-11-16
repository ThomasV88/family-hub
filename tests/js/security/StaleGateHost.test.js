import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { createAppI18n } from '../../../resources/js/i18n.js';

const page = vi.hoisted(() => ({ props: { auth: { user: { id: 7, isAdmin: false } } } }));
const router = vi.hoisted(() => ({ post: vi.fn(), visit: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router, usePage: () => page }));
const webauthn = vi.hoisted(() => ({ supported: true, assert: vi.fn() }));
vi.mock('../../../resources/js/Security/webauthn.js', () => ({
    passkeysSupported: () => webauthn.supported,
    getAssertion: (...a) => webauthn.assert(...a),
}));

import StaleGateHost from '../../../resources/js/Components/Security/StaleGateHost.vue';
import { gate, requestConfirmation, resetStaleGate } from '../../../resources/js/Security/staleGate.js';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const PW = { password: true, totp: false, passkey: false };
const TOTP = { password: false, totp: true, passkey: false };
const PASSKEY = { password: false, totp: true, passkey: true };
const NONE = { password: false, totp: false, passkey: false };

let wrapper;
let button;
const fetchMock = vi.fn();
const reply = (status, body) => ({ ok: status < 400, status, headers: { get: () => null }, json: async () => body });
const q = (sel) => document.body.querySelector(sel);

function mountHost(locale = 'en', messages = loadMessages(locale)) {
    wrapper = mount(StaleGateHost, { attachTo: document.body, global: { plugins: [createAppI18n(locale, messages)] } });
    return wrapper;
}

// A form control the member just pressed: the dialog must hand focus back to it.
function pressSubmit() {
    button = document.createElement('button');
    button.textContent = 'Save';
    document.body.appendChild(button);
    button.focus();
}

const replayFn = () => vi.fn().mockResolvedValue({ ok: true, status: 200, data: { saved: true } });
async function open(methods, replay = replayFn()) {
    pressSubmit();
    const outcome = requestConfirmation({ methods, replay });
    await flushPromises();
    return { outcome, replay };
}
async function typeInto(sel, value) {
    const el = q(sel);
    el.value = value;
    el.dispatchEvent(new Event('input'));
    await flushPromises();
}
const submit = async () => { q('form').dispatchEvent(new Event('submit', { cancelable: true })); await flushPromises(); };

beforeEach(() => {
    resetStaleGate();
    fetchMock.mockReset();
    router.post.mockReset();
    router.visit.mockReset();
    webauthn.supported = true;
    webauthn.assert.mockReset();
    vi.stubGlobal('fetch', fetchMock);
    useToast().toasts.splice(0);
    page.props.auth.user = { id: 7, isAdmin: false };
});
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    button?.remove();
    document.body.innerHTML = '';
    document.documentElement.removeAttribute('data-theme');
    resetStaleGate();
    vi.unstubAllGlobals();
});

describe('StaleGateHost', () => {
    it('is not shown until a write is refused, then opens "Confirm it\'s you" over the page', async () => {
        mountHost();
        expect(q('[role="dialog"]')).toBeNull();
        await open(PW);
        const dialog = q('[role="dialog"]');
        expect(dialog.textContent).toContain("Confirm it's you");
        expect(dialog.textContent).toContain('Nothing you entered is lost');
        expect(q('input[autocomplete="current-password"]')).not.toBeNull();
        expect(document.activeElement).toBe(q('input[autocomplete="current-password"]'));
    });

    it('confirms with the password, replays once and closes; focus returns to the control that was pressed', async () => {
        mountHost();
        fetchMock.mockResolvedValue(reply(200, { confirmed: true }));
        const { outcome, replay } = await open(PW);
        await typeInto('input[autocomplete="current-password"]', 'secret');
        await submit();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/confirm-access');
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ password: 'secret' });
        expect(await outcome).toMatchObject({ status: 'replayed', result: { data: { saved: true } } });
        expect(replay).toHaveBeenCalledTimes(1);
        await flushPromises();
        expect(q('[role="dialog"]')).toBeNull();
        expect(document.activeElement).toBe(button);
    });

    it('shows a wrong password inline, keeps the dialog open and replays nothing', async () => {
        mountHost();
        fetchMock.mockResolvedValue(reply(422, { errors: { password: 'reauth.error.password' } }));
        const { replay } = await open(PW);
        await typeInto('input[autocomplete="current-password"]', 'nope');
        await submit();

        expect(q('[data-testid="error-alert"]').textContent).toContain('That password is not correct');
        expect(q('[role="dialog"]')).not.toBeNull();
        expect(replay).not.toHaveBeenCalled();
        expect(gate.open).toBe(true);
        // One live region for the problem: the alert; the dialog adds no second one.
        expect(document.body.querySelectorAll('[role="dialog"] [aria-live], [role="dialog"] [role="alert"], [role="dialog"] [role="status"]').length).toBeLessThanOrEqual(1);
    });

    it('cancel sends nothing, closes and returns focus', async () => {
        mountHost();
        const { outcome, replay } = await open(PW);
        q('[data-testid="stale-gate-cancel"]').click();
        expect(await outcome).toEqual({ status: 'cancelled' });
        await flushPromises();
        expect(replay).not.toHaveBeenCalled();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(q('[role="dialog"]')).toBeNull();
        expect(document.activeElement).toBe(button);
    });

    it('Escape cancels like the Cancel button', async () => {
        mountHost();
        const { outcome, replay } = await open(PW);
        q('.ui-dialog__scrim').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(await outcome).toEqual({ status: 'cancelled' });
        expect(replay).not.toHaveBeenCalled();
    });

    it('keeps Tab inside the dialog (focus trap)', async () => {
        mountHost();
        await open(PW);
        const dialog = q('[role="dialog"]');
        const focusable = [...dialog.querySelectorAll('button, input, [href], select, textarea, [tabindex]:not([tabindex="-1"])')];
        focusable.at(-1).focus();
        const event = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
        document.activeElement.dispatchEvent(event);
        expect(dialog.contains(document.activeElement)).toBe(true);
    });

    it('asks a member with a second factor for the authenticator code only, and sends the code', async () => {
        mountHost();
        fetchMock.mockResolvedValue(reply(200, { confirmed: true }));
        const { outcome } = await open(TOTP);
        expect(q('input[autocomplete="current-password"]')).toBeNull();
        expect(q('[data-testid="use-password"]')).toBeNull();
        await typeInto('input[autocomplete="one-time-code"]', '123456');
        await submit();
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ code: '123456' });
        expect((await outcome).status).toBe('replayed');
    });

    it('confirms with a passkey through the existing fake and replays once', async () => {
        mountHost();
        webauthn.assert.mockResolvedValue({ id: 'cred', type: 'public-key' });
        fetchMock.mockImplementation(async (url) => (url === '/confirm-access/passkey/options'
            ? reply(200, { options: { challenge: 'abc' } })
            : reply(200, { confirmed: true })));
        const { outcome, replay } = await open(PASSKEY);
        q('[data-testid="use-passkey"]').click();
        await flushPromises();
        expect(fetchMock.mock.calls.map(([u]) => u)).toEqual(['/confirm-access/passkey/options', '/confirm-access/passkey']);
        expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toMatchObject({ mode: 'dialog' });
        expect((await outcome).status).toBe('replayed');
        expect(replay).toHaveBeenCalledTimes(1);
    });

    it('a cancelled passkey prompt stays quiet and replays nothing', async () => {
        mountHost();
        webauthn.assert.mockRejectedValue(Object.assign(new Error('x'), { name: 'cancelled' }));
        fetchMock.mockResolvedValue(reply(200, { options: { challenge: 'abc' } }));
        const { replay } = await open(PASSKEY);
        q('[data-testid="use-passkey"]').click();
        await flushPromises();
        expect(replay).not.toHaveBeenCalled();
        expect(q('[data-testid="error-alert"]')).toBeNull();
        expect(q('[role="dialog"]')).not.toBeNull();
    });

    it('offers "Sign in again" and replays nothing when the member has no way to confirm', async () => {
        mountHost();
        const { replay } = await open(NONE);
        expect(q('[data-testid="reauth-no-method"]')).not.toBeNull();
        q('[data-testid="sign-in-again"]').click();
        expect(router.post).toHaveBeenCalledWith('/confirm-access/sign-in-again');
        expect(replay).not.toHaveBeenCalled();
    });

    it('does not replay for another member who signed in meanwhile and says so', async () => {
        mountHost();
        fetchMock.mockResolvedValue(reply(200, { confirmed: true }));
        const { outcome, replay } = await open(PW);
        page.props.auth.user = { id: 9, isAdmin: false };
        await typeInto('input[autocomplete="current-password"]', 'secret');
        await submit();
        expect((await outcome).status).toBe('expired');
        expect(replay).not.toHaveBeenCalled();
    });

    it('unmounting while open cancels the waiting request instead of leaving it hanging', async () => {
        mountHost();
        const { outcome } = await open(PW);
        wrapper.unmount();
        wrapper = null;
        expect(await outcome).toEqual({ status: 'cancelled' });
    });

    it('serves two concurrent refusals with one dialog and replays both after one confirmation', async () => {
        mountHost();
        fetchMock.mockResolvedValue(reply(200, { confirmed: true }));
        const first = await open(PW);
        const second = requestConfirmation({ methods: PW, replay: replayFn() });
        await flushPromises();
        expect(document.body.querySelectorAll('[role="dialog"]').length).toBe(1);
        await typeInto('input[autocomplete="current-password"]', 'secret');
        await submit();
        await Promise.all([first.outcome, second]);
        expect(first.replay).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});

describe('StaleGateHost accessibility', () => {
    const scenarios = { 'password': PW, 'code and passkey': PASSKEY, 'no method': NONE };
    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            for (const [name, methods] of Object.entries(scenarios)) {
                it(`${name} passes axe (${locale}${dark ? ', dark' : ''}, 320 px)`, async () => {
                    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
                    document.body.style.width = '320px';
                    mountHost(locale);
                    await open(methods);
                    await expectNoAxeViolations(document.body);
                    document.body.style.width = '';
                });
            }
        }
    }

    it('still passes axe with +40% longer strings (sk)', async () => {
        mountHost('sk', pseudoLong(loadMessages('sk')));
        await open(PASSKEY);
        await expectNoAxeViolations(document.body);
    });

    it('shows an inline error without axe violations', async () => {
        mountHost();
        fetchMock.mockResolvedValue(reply(422, { errors: { password: 'reauth.error.password' } }));
        await open(PW);
        await typeInto('input[autocomplete="current-password"]', 'nope');
        await submit();
        await expectNoAxeViolations(document.body);
    });
});
