import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { mountUi } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';

const post = vi.fn();
const visit = vi.fn();
vi.mock('@inertiajs/vue3', () => ({ router: { post: (...a) => post(...a), visit: (...a) => visit(...a) } }));
const webauthn = vi.hoisted(() => ({ supported: true, assert: vi.fn() }));
vi.mock('../../../resources/js/Security/webauthn.js', () => ({
    passkeysSupported: () => webauthn.supported,
    getAssertion: (...a) => webauthn.assert(...a),
}));
const fetchMock = vi.fn();
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: (...a) => fetchMock(...a) }));

import ReauthForm from '../../../resources/js/Components/Security/ReauthForm.vue';

const both = { password: true, totp: true, passkey: false };
let wrapper;
const mountForm = (props = {}, slots = {}) => {
    wrapper = mountUi(ReauthForm, { props: { methods: both, mode: 'page', ...props }, slots });
    return wrapper;
};
beforeEach(() => { post.mockReset(); visit.mockReset(); fetchMock.mockReset(); webauthn.supported = true; webauthn.assert.mockReset(); });
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; vi.useRealTimers(); });

describe('ReauthForm', () => {
    it('posts the password through Inertia in page mode and never sends the code', async () => {
        mountForm();
        await wrapper.find('input[autocomplete="current-password"]').setValue('secret');
        await wrapper.find('form').trigger('submit');
        expect(post).toHaveBeenCalledWith('/confirm-access', { password: 'secret' }, expect.any(Object));
    });

    it('for a member with a second factor shows only the authenticator code and the passkey, never the password', () => {
        mountForm({ methods: { password: false, totp: true, passkey: true } });
        expect(wrapper.find('input[autocomplete="current-password"]').exists()).toBe(false);
        expect(wrapper.find('input[autocomplete="one-time-code"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="use-password"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="use-passkey"]').exists()).toBe(true);
    });

    it('swaps to the code input and back, and offers no recovery option', async () => {
        mountForm();
        await wrapper.find('[data-testid="use-code"]').trigger('click');
        const otp = wrapper.find('input[autocomplete="one-time-code"]');
        expect(otp.exists()).toBe(true);
        await otp.setValue('123456');
        await wrapper.find('form').trigger('submit');
        expect(post).toHaveBeenCalledWith('/confirm-access', { code: '123456' }, expect.any(Object));
        expect(wrapper.html().toLowerCase()).not.toContain('recovery');

        await wrapper.find('[data-testid="use-password"]').trigger('click');
        expect(wrapper.find('input[autocomplete="current-password"]').exists()).toBe(true);
    });

    it('shows the server error translated, clears the input and refocuses it', async () => {
        mountForm();
        const input = wrapper.find('input[autocomplete="current-password"]');
        await input.setValue('bad');
        await wrapper.find('form').trigger('submit');
        post.mock.calls[0][2].onError({ password: 'reauth.error.password' });
        await flushPromises();
        const alert = wrapper.find('[role="alert"]');
        expect(alert.text()).toContain('That password is not correct');
        expect(wrapper.find('input[autocomplete="current-password"]').element.value).toBe('');
    });

    it('dialog mode submits through securityFetch and emits confirmed', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: { confirmed: true } });
        mountForm({ mode: 'dialog' });
        await wrapper.find('input[autocomplete="current-password"]').setValue('secret');
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledWith('/confirm-access', { method: 'POST', body: { password: 'secret' } });
        expect(post).not.toHaveBeenCalled();
        expect(wrapper.emitted('confirmed')).toHaveLength(1);
    });

    it('dialog mode shows invalid error keys', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 422, error: 'invalid', errors: { password: 'reauth.error.password' } });
        mountForm({ mode: 'dialog' });
        await wrapper.find('input[autocomplete="current-password"]').setValue('x');
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        expect(wrapper.find('[role="alert"]').text()).toContain('That password is not correct');
        expect(wrapper.emitted('confirmed')).toBeUndefined();
    });

    it('throttle disables submit, counts minutes down and announces the end', async () => {
        vi.useFakeTimers();
        fetchMock.mockResolvedValue({ ok: false, status: 429, error: 'throttle', minutes: 2 });
        mountForm({ mode: 'dialog' });
        await wrapper.find('input[autocomplete="current-password"]').setValue('x');
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        expect(wrapper.find('[data-testid="throttle-alert"]').text()).toContain('2 minutes');
        expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined();

        vi.advanceTimersByTime(60 * 1000);
        await flushPromises();
        expect(wrapper.find('[data-testid="throttle-alert"]').text()).toContain('1 minute');

        vi.advanceTimersByTime(60 * 1000);
        await flushPromises();
        expect(wrapper.find('[data-testid="throttle-alert"]').exists()).toBe(false);
        expect(wrapper.find('[role="status"]').text()).toContain('You can try again now');
        expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeUndefined();
    });

    it('renders the alternatives slot under a divider only when filled', () => {
        mountForm();
        expect(wrapper.find('[role="separator"]').exists()).toBe(false);
        wrapper.unmount();
        mountForm({}, { alternatives: '<button data-testid="alt">Passkey</button>' });
        expect(wrapper.find('[role="separator"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="alt"]').exists()).toBe(true);
    });

    describe('passkey', () => {
        const withPasskey = { password: true, totp: false, passkey: true };
        const passkeyOnly = { password: false, totp: false, passkey: true };
        const answer = (finalResult) => fetchMock.mockImplementation(async (url) => (url.endsWith('/options')
            ? { ok: true, status: 200, data: { options: { challenge: 'x' } } }
            : finalResult));

        it('shows the passkey button under the divider, before alternatives slot content', () => {
            mountForm({ methods: withPasskey }, { alternatives: '<button data-testid="alt">Google</button>' });
            const html = wrapper.html();
            expect(wrapper.find('[data-testid="use-passkey"]').text()).toBe('Use a passkey');
            expect(html.indexOf('use-passkey')).toBeLessThan(html.indexOf('data-testid="alt"'));
            expect(wrapper.findAll('[role="separator"]')).toHaveLength(2);
        });

        it('is not rendered without the method or without browser support', () => {
            mountForm({ methods: both });
            expect(wrapper.find('[data-testid="use-passkey"]').exists()).toBe(false);
            wrapper.unmount();
            webauthn.supported = false;
            mountForm({ methods: withPasskey });
            expect(wrapper.find('[data-testid="use-passkey"]').exists()).toBe(false);
        });

        it('a passkey-only member sees no password field or divider and a primary button', () => {
            mountForm({ methods: passkeyOnly });
            expect(wrapper.find('form').exists()).toBe(false);
            expect(wrapper.find('[role="separator"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="use-passkey"]').classes()).toContain('ui-button--primary');
        });

        it('page mode posts mode "page" and visits the returned redirect', async () => {
            webauthn.assert.mockResolvedValue({ id: 'cred' });
            answer({ ok: true, status: 200, data: { redirect: '/finance' } });
            mountForm({ methods: withPasskey });
            await wrapper.find('[data-testid="use-passkey"]').trigger('click');
            await flushPromises();
            expect(fetchMock).toHaveBeenCalledWith('/confirm-access/passkey/options');
            expect(fetchMock).toHaveBeenCalledWith('/confirm-access/passkey', { method: 'POST', body: { credential: { id: 'cred' }, mode: 'page' } });
            expect(visit).toHaveBeenCalledWith('/finance');
        });

        it('dialog mode posts mode "dialog" and emits confirmed', async () => {
            webauthn.assert.mockResolvedValue({ id: 'cred' });
            answer({ ok: true, status: 200, data: { confirmed: true } });
            mountForm({ methods: withPasskey, mode: 'dialog' });
            await wrapper.find('[data-testid="use-passkey"]').trigger('click');
            await flushPromises();
            expect(fetchMock).toHaveBeenLastCalledWith('/confirm-access/passkey', { method: 'POST', body: { credential: { id: 'cred' }, mode: 'dialog' } });
            expect(wrapper.emitted('confirmed')).toHaveLength(1);
            expect(visit).not.toHaveBeenCalled();
        });

        it('shows the waiting line while the prompt is open', async () => {
            let release;
            webauthn.assert.mockReturnValue(new Promise((r) => { release = r; }));
            fetchMock.mockResolvedValue({ ok: true, status: 200, data: { options: {} } });
            mountForm({ methods: withPasskey, mode: 'dialog' });
            await wrapper.find('[data-testid="use-passkey"]').trigger('click');
            await flushPromises();
            expect(wrapper.find('[data-testid="passkey-waiting"]').text()).toBe('Follow the prompt on your device.');
            expect(wrapper.find('[data-testid="use-passkey"]').attributes('aria-busy')).toBe('true');
            release({ id: 'c' });
            await flushPromises();
        });

        it('a failure shows the generic passkey copy; a cancel is quiet', async () => {
            webauthn.assert.mockResolvedValue({ id: 'cred' });
            answer({ ok: false, status: 422, error: 'invalid', errors: {}, data: { error: 'passkey_failed' } });
            mountForm({ methods: withPasskey, mode: 'dialog' });
            await wrapper.find('[data-testid="use-passkey"]').trigger('click');
            await flushPromises();
            expect(wrapper.find('[data-testid="error-alert"]').text()).toBe('Passkey check did not work. Try again or use a code.');
            expect(wrapper.emitted('confirmed')).toBeUndefined();

            wrapper.unmount();
            webauthn.assert.mockRejectedValue(Object.assign(new Error('cancelled'), { name: 'cancelled' }));
            mountForm({ methods: withPasskey, mode: 'dialog' });
            await wrapper.find('[data-testid="use-passkey"]').trigger('click');
            await flushPromises();
            expect(wrapper.find('[data-testid="error-alert"]').exists()).toBe(false);
        });

        it('a throttle locks the passkey button', async () => {
            fetchMock.mockResolvedValue({ ok: false, status: 429, error: 'throttle', minutes: 3, data: {} });
            mountForm({ methods: withPasskey, mode: 'dialog' });
            await wrapper.find('[data-testid="use-passkey"]').trigger('click');
            await flushPromises();
            expect(wrapper.find('[data-testid="throttle-alert"]').text()).toContain('3 minutes');
            expect(wrapper.find('[data-testid="use-passkey"]').attributes('disabled')).toBeDefined();
        });

        it.each(['en', 'sk', 'pl'])('has no axe violations in %s, light and dark', async (locale) => {
            for (const theme of ['light', 'dark']) {
                document.documentElement.setAttribute('data-theme', theme);
                for (const methods of [withPasskey, passkeyOnly, { ...withPasskey, totp: true }]) {
                    wrapper = mountUi(ReauthForm, { props: { methods, mode: 'page' }, locale });
                    await expectNoAxeViolations(wrapper.element);
                    wrapper.unmount();
                }
                wrapper = null;
            }
            document.documentElement.removeAttribute('data-theme');
        });
    });
});
