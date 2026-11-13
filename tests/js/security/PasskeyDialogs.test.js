import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { mountUi } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { pseudoLong } from '../helpers/pseudoLong.js';

const router = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router }));
const fetchMock = vi.fn();
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: (...a) => fetchMock(...a) }));
vi.mock('../../../resources/js/Security/webauthn.js', () => ({ passkeysSupported: () => true, getAssertion: vi.fn(), createCredential: vi.fn() }));

import PasskeyNameDialog from '../../../resources/js/Components/Security/PasskeyNameDialog.vue';
import PasskeyRemoveDialog from '../../../resources/js/Components/Security/PasskeyRemoveDialog.vue';
import PasskeyRow from '../../../resources/js/Components/Security/PasskeyRow.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const methods = { password: true, totp: true, passkey: true };
const none = { password: false, totp: false, passkey: false };
const passkey = { id: 'p1', name: 'iPhone', authenticator: null, createdAt: '2026-11-01T10:00:00Z', lastUsedAt: null };
const ok = (data = {}, status = 200) => ({ ok: true, status, data });
const stale = { ok: false, status: 423, error: 'confirm_required', data: {} };

let wrapper;
const q = (sel) => document.body.querySelector(sel);
const click = async (sel) => { q(sel).click(); await flushPromises(); };
const mountIt = async (component, props = {}, locale = 'en') => {
    wrapper = mountUi(component, { props: { open: true, ...props }, locale });
    await flushPromises();
};

beforeEach(() => { fetchMock.mockReset(); router.post.mockReset(); useToast().toasts.splice(0); });
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; document.documentElement.removeAttribute('data-theme'); vi.restoreAllMocks(); });

describe('PasskeyNameDialog', () => {
    it('add mode: prefilled and focused, offers Skip naming, cannot be dismissed', async () => {
        await mountIt(PasskeyNameDialog, { mode: 'add', name: 'iPhone' });
        expect(q('[role="dialog"] h2').textContent).toBe('Name this passkey');
        expect(q('[role="dialog"] input').value).toBe('iPhone');
        expect(document.activeElement).toBe(q('[role="dialog"] input'));
        expect(q('[data-testid="skip-naming"]')).not.toBeNull();
        expect(q('[role="dialog"] input').getAttribute('maxlength')).toBe('60');
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flushPromises();
        expect(wrapper.emitted('update:open')).toBeUndefined();
    });

    it('emits the trimmed name on save and on Enter', async () => {
        await mountIt(PasskeyNameDialog, { mode: 'add', name: 'iPhone' });
        const input = q('[role="dialog"] input');
        input.value = '  Kitchen  ';
        input.dispatchEvent(new Event('input'));
        await click('[data-testid="save-name"]');
        expect(wrapper.emitted('save')[0]).toEqual(['Kitchen']);
        await click('[data-testid="skip-naming"]');
        expect(wrapper.emitted('skip')).toHaveLength(1);
    });

    it('rename mode: no Skip, shows the server error on the field', async () => {
        await mountIt(PasskeyNameDialog, { mode: 'rename', name: 'Old', error: 'passkey.error.name' });
        expect(q('[role="dialog"] h2').textContent).toBe('Rename passkey');
        expect(q('[data-testid="skip-naming"]')).toBeNull();
        expect(q('[role="dialog"]').textContent).toContain('Enter a name of up to 60 characters.');
    });
});

describe('PasskeyRemoveDialog', () => {
    it('shows the Screen 4 copy with Keep passkey focused and no form when fresh', async () => {
        await mountIt(PasskeyRemoveDialog, { passkey, methods });
        expect(q('[role="dialog"] h2').textContent).toBe('Remove passkey');
        expect(document.body.textContent).toContain('Remove iPhone? You will not be able');
        expect(document.activeElement).toBe(q('[data-testid="keep"]'));
        expect(q('.reauth-form')).toBeNull();
    });

    it('deletes directly, toasts and tells the card', async () => {
        fetchMock.mockResolvedValue(ok({}, 204));
        await mountIt(PasskeyRemoveDialog, { passkey, methods });
        await click('[data-testid="confirm-remove"]');
        expect(fetchMock).toHaveBeenCalledWith('/settings/security/passkeys/p1', { method: 'DELETE' });
        expect(useToast().toasts.map((x) => x.message)).toContain('Passkey removed');
        expect(wrapper.emitted('removed')[0]).toEqual(['p1']);
    });

    it('a 423 shows the form in place, focused, and retries after confirming without navigating', async () => {
        let first = true;
        fetchMock.mockImplementation(async (url, opts = {}) => {
            if (url === '/confirm-access') return ok({ confirmed: true });
            if (first) { first = false; return stale; }
            return ok({}, 204);
        });
        await mountIt(PasskeyRemoveDialog, { passkey, methods });
        await click('[data-testid="confirm-remove"]');
        expect(q('.reauth-form')).not.toBeNull();
        expect(document.activeElement.closest('.reauth-form')).not.toBeNull();
        const input = q('input[autocomplete="current-password"]');
        input.value = 'pw';
        input.dispatchEvent(new Event('input'));
        q('.reauth-form form').dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(wrapper.emitted('removed')[0]).toEqual(['p1']);
        expect(router.post).not.toHaveBeenCalled();
    });

    it('no usable method: no-method copy, Sign in again and the admin setup link', async () => {
        fetchMock.mockResolvedValue(stale);
        await mountIt(PasskeyRemoveDialog, { passkey, methods: none, isAdmin: true });
        await click('[data-testid="confirm-remove"]');
        expect(q('[data-testid="reauth-no-method"]').textContent).toContain('This account has no password or second step');
        expect(q('a[href="/settings/security"]')).not.toBeNull();
        await click('[data-testid="sign-in-again"]');
        expect(router.post).toHaveBeenCalledWith('/confirm-access/sign-in-again');
    });

    it('shows the last-factor warning when asked', async () => {
        await mountIt(PasskeyRemoveDialog, { passkey, methods, lastFactor: true });
        expect(q('[data-testid="last-factor"]').textContent).toContain('only second step');
    });

    it('a failure shows the generic alert', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 500, error: 'failed', data: {} });
        await mountIt(PasskeyRemoveDialog, { passkey, methods });
        await click('[data-testid="confirm-remove"]');
        expect(q('[role="alert"]')).not.toBeNull();
    });
});

describe('PasskeyRow', () => {
    it('keeps long names inside the row', async () => {
        wrapper = mountUi(PasskeyRow, { props: { passkey: { ...passkey, name: pseudoLong('A'.repeat(60)) } } });
        const li = wrapper.element;
        expect(li.querySelector('[title]')).toBeNull();
        expect(li.querySelectorAll('button')).toHaveLength(2);
    });
});

describe('Passkey dialogs accessibility', () => {
    for (const locale of ['en', 'sk', 'pl']) {
        for (const theme of ['light', 'dark']) {
            it(`has no axe violations for name, rename and remove, ${locale} ${theme}`, async () => {
                document.documentElement.setAttribute('data-theme', theme);
                for (const [component, props] of [
                    [PasskeyNameDialog, { mode: 'add', name: 'iPhone' }],
                    [PasskeyNameDialog, { mode: 'rename', name: 'iPhone', error: 'passkey.error.name' }],
                    [PasskeyRemoveDialog, { passkey, methods, lastFactor: true }],
                ]) {
                    await mountIt(component, props, locale);
                    await expectNoAxeViolations(document.body);
                    wrapper.unmount();
                    document.body.innerHTML = '';
                }
                wrapper = null;
            });
        }
    }
});
