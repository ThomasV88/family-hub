import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { mountUi } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';

const router = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router }));
const fetchMock = vi.fn();
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: (...a) => fetchMock(...a) }));
const webauthn = vi.hoisted(() => ({ supported: true, create: vi.fn() }));
vi.mock('../../../resources/js/Security/webauthn.js', () => ({
    passkeysSupported: () => webauthn.supported,
    createCredential: (...a) => webauthn.create(...a),
}));

import PasskeysCard from '../../../resources/js/Components/Security/PasskeysCard.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const methods = { password: true, totp: false, passkey: true, google: false };
const none = { password: false, totp: false, passkey: false, google: false };
const ok = (data = {}, status = 200) => ({ ok: true, status, data });
const stale = { ok: false, status: 423, error: 'confirm_required', data: {} };
const phone = { id: 'p1', name: 'iPhone', authenticator: null, createdAt: '2026-11-01T10:00:00Z', lastUsedAt: null };
const laptop = { id: 'p2', name: '<b>Work</b> laptop', authenticator: null, createdAt: '2026-10-01T10:00:00Z', lastUsedAt: '2026-11-10T10:00:00Z' };
const OPTIONS = { challenge: 'abc' };
const CRED = { id: 'cred', type: 'public-key' };

let wrapper;
const q = (sel) => document.body.querySelector(sel);
const click = async (sel) => { q(sel).click(); await flushPromises(); };
const mountCard = async (props = {}, locale = 'en') => {
    wrapper = mountUi(PasskeysCard, { props: { passkeys: [], methods, confirmFresh: true, ...props }, locale });
    await flushPromises();
};
async function confirmWithPassword() {
    const input = q('input[autocomplete="current-password"]');
    input.value = 'pw';
    input.dispatchEvent(new Event('input'));
    q('.reauth-form form').dispatchEvent(new Event('submit'));
    await flushPromises();
}
const setName = async (value) => {
    const input = q('[role="dialog"] input');
    input.value = value;
    input.dispatchEvent(new Event('input'));
    await flushPromises();
};

beforeEach(() => {
    fetchMock.mockReset();
    router.post.mockReset();
    webauthn.supported = true;
    webauthn.create.mockReset();
    webauthn.create.mockResolvedValue(CRED);
    useToast().toasts.splice(0);
});
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; document.documentElement.removeAttribute('data-theme'); vi.restoreAllMocks(); });

function happy() {
    fetchMock.mockImplementation(async (url, opts = {}) => {
        if (url === '/settings/security/passkeys/options') return ok({ options: OPTIONS });
        if (url === '/settings/security/passkeys' && opts.method === 'POST') return ok({ passkey: { ...phone, id: 'new', name: opts.body.name } }, 201);
        if (url === '/confirm-access') return ok({ confirmed: true });
        return { ok: false, status: 500, error: 'failed', data: {} };
    });
}

describe('PasskeysCard list', () => {
    it('shows the empty state with a primary add button', async () => {
        await mountCard();
        expect(q('[data-testid="passkeys-card"] h2').textContent).toBe('Passkeys');
        expect(document.body.textContent).toContain('No passkeys yet');
        expect(q('[data-testid="add-passkey"]').className).toContain('ui-button--primary');
    });

    it('lists passkeys as escaped text without a title attribute and makes add secondary', async () => {
        await mountCard({ passkeys: [phone, laptop] });
        const rows = document.body.querySelectorAll('[data-testid="passkey-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[1].textContent).toContain('<b>Work</b> laptop');
        expect(rows[1].querySelector('b')).toBeNull();
        expect(rows[1].querySelector('[title]')).toBeNull();
        expect(rows[0].textContent).toContain('Never used');
        expect(rows[1].textContent).toContain('Last used');
        expect(rows[0].querySelector('[data-testid="rename"]').getAttribute('aria-label')).toBe('Rename passkey iPhone');
        expect(rows[0].querySelector('[data-testid="remove"]').getAttribute('aria-label')).toBe('Remove passkey iPhone');
        expect(q('[data-testid="add-passkey"]').className).toContain('ui-button--secondary');
    });

    it('hides the add button on unsupported devices and explains why', async () => {
        webauthn.supported = false;
        await mountCard();
        expect(q('button[data-testid="add-passkey"]')).toBeNull();
        expect(document.body.textContent).toContain('does not support passkeys');
    });

    it('nudges passkey-only members towards a second method', async () => {
        await mountCard({ passkeys: [phone], totp: false });
        expect(q('[data-testid="passkey-nudge"]')).not.toBeNull();
    });
    it('no nudge when an authenticator exists', async () => {
        await mountCard({ passkeys: [phone], totp: true });
        expect(q('[data-testid="passkey-nudge"]')).toBeNull();
    });
});

describe('PasskeysCard add flow', () => {
    it('adds with one prompt, names, toasts and updates the list', async () => {
        happy();
        await mountCard();
        await click('[data-testid="add-passkey"]');
        expect(q('[data-testid="passkey-confirm"]')).toBeNull();
        expect(webauthn.create).toHaveBeenCalledWith(OPTIONS);
        expect(q('[role="dialog"] h2').textContent).toBe('Name this passkey');
        await setName('Kitchen iPad');
        await click('[data-testid="save-name"]');
        expect(fetchMock).toHaveBeenLastCalledWith('/settings/security/passkeys', { method: 'POST', body: { name: 'Kitchen iPad', credential: CRED } });
        expect(useToast().toasts.map((x) => x.message)).toContain('Passkey added');
        expect(wrapper.emitted('update:passkeys').at(-1)[0].map((p) => p.name)).toEqual(['Kitchen iPad']);
        expect(router.post).not.toHaveBeenCalled();
    });

    it('prefills a device guess and "Skip naming" posts it', async () => {
        happy();
        vi.spyOn(navigator, 'userAgent', 'get').mockReturnValue('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)');
        await mountCard();
        await click('[data-testid="add-passkey"]');
        expect(q('[role="dialog"] input').value).toBe('iPhone');
        await click('[data-testid="skip-naming"]');
        expect(fetchMock.mock.calls.at(-1)[1].body.name).toBe('iPhone');
    });

    it('shows a waiting status line with aria-busy while the prompt is open', async () => {
        happy();
        let release;
        webauthn.create.mockReturnValue(new Promise((r) => { release = r; }));
        await mountCard();
        await click('[data-testid="add-passkey"]');
        expect(q('[data-testid="passkey-waiting"]').getAttribute('role')).toBe('status');
        expect(q('[data-testid="add-passkey"]').getAttribute('aria-busy')).toBe('true');
        release(CRED);
        await flushPromises();
        expect(q('[data-testid="passkey-waiting"]')).toBeNull();
    });

    it('a cancelled prompt is a calm message and nothing is saved', async () => {
        happy();
        webauthn.create.mockRejectedValue(Object.assign(new Error('cancelled'), { name: 'cancelled' }));
        await mountCard();
        await click('[data-testid="add-passkey"]');
        expect(q('[data-testid="passkey-alert"]').textContent).toContain('Passkey setup was cancelled');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(q('[role="dialog"]')).toBeNull();
    });

    it('any other failure shows the failed copy', async () => {
        happy();
        webauthn.create.mockRejectedValue(new Error('boom'));
        await mountCard();
        await click('[data-testid="add-passkey"]');
        expect(q('[data-testid="passkey-alert"]').textContent).toContain('We could not create the passkey');
    });

    it('a 422 on save shows the failed copy and closes the name dialog', async () => {
        happy();
        fetchMock.mockImplementation(async (url, opts = {}) => {
            if (url.endsWith('/options')) return ok({ options: OPTIONS });
            return { ok: false, status: 422, error: 'invalid', errors: {}, data: { error: 'passkey_failed' } };
        });
        await mountCard();
        await click('[data-testid="add-passkey"]');
        await click('[data-testid="save-name"]');
        expect(q('[data-testid="passkey-alert"]').textContent).toContain('We could not create the passkey');
        expect(q('[role="dialog"]')).toBeNull();
    });

    it('a name error stays on the field and keeps the credential for a retry', async () => {
        let posts = 0;
        fetchMock.mockImplementation(async (url, opts = {}) => {
            if (url.endsWith('/options')) return ok({ options: OPTIONS });
            posts += 1;
            if (posts === 1) return { ok: false, status: 422, error: 'invalid', errors: { name: ['passkey.error.name'] }, data: {} };
            return ok({ passkey: { ...phone, name: opts.body.name } }, 201);
        });
        await mountCard();
        await click('[data-testid="add-passkey"]');
        await setName('');
        await click('[data-testid="save-name"]');
        expect(q('[role="dialog"]').textContent).toContain('Enter a name of up to 60 characters.');
        await setName('Ok');
        await click('[data-testid="save-name"]');
        expect(webauthn.create).toHaveBeenCalledTimes(1);
        expect(q('[role="dialog"]')).toBeNull();
    });
});

describe('PasskeysCard confirmation', () => {
    it('stale window: the confirm dialog opens BEFORE options are fetched, then the flow runs', async () => {
        happy();
        await mountCard({ confirmFresh: false });
        await click('[data-testid="add-passkey"]');
        expect(q('[role="dialog"] h2').textContent).toBe("Confirm it's you");
        expect(document.body.textContent).toContain("Confirm it's you before changing two-step verification.");
        expect(fetchMock).not.toHaveBeenCalled();
        expect(document.activeElement.closest('.reauth-form')).not.toBeNull();
        await confirmWithPassword();
        expect(fetchMock).toHaveBeenCalledWith('/settings/security/passkeys/options');
        expect(webauthn.create).toHaveBeenCalledTimes(1);
        expect(q('[role="dialog"] h2').textContent).toBe('Name this passkey');
    });

    it('a window confirmed once is not asked again', async () => {
        happy();
        await mountCard({ confirmFresh: false });
        await click('[data-testid="add-passkey"]');
        await confirmWithPassword();
        await click('[data-testid="skip-naming"]');
        await click('[data-testid="add-passkey"]');
        expect(q('[data-testid="passkey-confirm"]')).toBeNull();
        expect(webauthn.create).toHaveBeenCalledTimes(2);
    });

    it('options answering 423 opens the same dialog and restarts after confirming', async () => {
        let first = true;
        fetchMock.mockImplementation(async (url) => {
            if (url.endsWith('/options')) { if (first) { first = false; return stale; } return ok({ options: OPTIONS }); }
            if (url === '/confirm-access') return ok({ confirmed: true });
            return ok({ passkey: phone }, 201);
        });
        await mountCard();
        await click('[data-testid="add-passkey"]');
        expect(q('[data-testid="passkey-confirm"]')).not.toBeNull();
        expect(webauthn.create).not.toHaveBeenCalled();
        await confirmWithPassword();
        expect(webauthn.create).toHaveBeenCalledTimes(1);
    });

    it('store answering 423 drops the credential and restarts from fresh options and a new device prompt', async () => {
        let stores = 0;
        fetchMock.mockImplementation(async (url, opts = {}) => {
            if (url.endsWith('/options')) return ok({ options: OPTIONS });
            if (url === '/confirm-access') return ok({ confirmed: true });
            stores += 1;
            return stores === 1 ? stale : ok({ passkey: { ...phone, name: opts.body.name } }, 201);
        });
        webauthn.create.mockResolvedValueOnce({ id: 'first' }).mockResolvedValueOnce({ id: 'second' });
        await mountCard();
        await click('[data-testid="add-passkey"]');
        await click('[data-testid="save-name"]');
        expect(q('[data-testid="passkey-confirm"]')).not.toBeNull();
        expect(q('input[autocomplete="one-time-code"]')).toBeNull();
        await confirmWithPassword();
        expect(webauthn.create).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls.filter(([u]) => u.endsWith('/options'))).toHaveLength(2);
        await click('[data-testid="save-name"]');
        const posts = fetchMock.mock.calls.filter(([u, o]) => u === '/settings/security/passkeys' && o?.method === 'POST');
        expect(posts[0][1].body.credential.id).toBe('first');
        expect(posts[1][1].body.credential.id).toBe('second');
    });

    it('no usable method shows the no-method copy and never navigates to /confirm-access', async () => {
        happy();
        await mountCard({ confirmFresh: false, methods: none });
        await click('[data-testid="add-passkey"]');
        expect(q('[data-testid="reauth-no-method"]').textContent).toContain('This account has no password or second step');
        expect(q('[data-testid="sign-in-again"]')).not.toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('guidance: after the first passkey is saved the page continues to the requested page', async () => {
        happy();
        await mountCard({ guidance: true, confirmFresh: false });
        await click('[data-testid="add-passkey"]');
        await confirmWithPassword();
        await click('[data-testid="skip-naming"]');
        expect(router.post).toHaveBeenCalledWith('/settings/security/continue');
    });
});

describe('PasskeysCard rename and remove', () => {
    it('renames in place without re-authentication', async () => {
        fetchMock.mockResolvedValue(ok({ passkey: { ...phone, name: 'New name' } }));
        await mountCard({ passkeys: [phone] });
        await click('[data-testid="rename"]');
        expect(q('[role="dialog"] h2').textContent).toBe('Rename passkey');
        expect(q('[role="dialog"] input').value).toBe('iPhone');
        await setName('New name');
        await click('[data-testid="save-name"]');
        expect(fetchMock).toHaveBeenCalledWith('/settings/security/passkeys/p1', { method: 'PATCH', body: { name: 'New name' } });
        expect(useToast().toasts.map((x) => x.message)).toContain('Passkey renamed');
        expect(wrapper.emitted('update:passkeys').at(-1)[0][0].name).toBe('New name');
    });

    it('a rename name error shows on the field', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 422, error: 'invalid', errors: { name: ['passkey.error.name'] }, data: {} });
        await mountCard({ passkeys: [phone] });
        await click('[data-testid="rename"]');
        await click('[data-testid="save-name"]');
        expect(q('[role="dialog"]').textContent).toContain('Enter a name of up to 60 characters.');
    });

    it('removes a passkey and drops it from the list', async () => {
        fetchMock.mockResolvedValue(ok({}, 204));
        await mountCard({ passkeys: [phone, laptop] });
        await click('[data-testid="remove"]');
        await click('[data-testid="confirm-remove"]');
        expect(fetchMock).toHaveBeenCalledWith('/settings/security/passkeys/p1', { method: 'DELETE' });
        expect(wrapper.emitted('update:passkeys').at(-1)[0].map((p) => p.id)).toEqual(['p2']);
        expect(useToast().toasts.map((x) => x.message)).toContain('Passkey removed');
    });

    it('warns about the last factor when the requirement is on and no authenticator exists', async () => {
        await mountCard({ passkeys: [phone], required: true });
        await click('[data-testid="remove"]');
        expect(q('[data-testid="last-factor"]')).not.toBeNull();
    });
});

describe('PasskeysCard accessibility', () => {
    for (const locale of ['en', 'sk', 'pl']) {
        for (const theme of ['light', 'dark']) {
            it(`has no axe violations: empty and list, ${locale} ${theme}`, async () => {
                document.documentElement.setAttribute('data-theme', theme);
                await mountCard({}, locale);
                await expectNoAxeViolations(document.body);
                wrapper.unmount();
                await mountCard({ passkeys: [phone, laptop] }, locale);
                await expectNoAxeViolations(document.body);
            });
        }
    }

    it('has no axe violations on the unsupported state', async () => {
        webauthn.supported = false;
        await mountCard();
        await expectNoAxeViolations(document.body);
    });
});
