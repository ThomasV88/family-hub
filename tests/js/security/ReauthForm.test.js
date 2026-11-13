import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { mountUi } from '../helpers/mount.js';

const post = vi.fn();
vi.mock('@inertiajs/vue3', () => ({ router: { post: (...a) => post(...a) } }));
const fetchMock = vi.fn();
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: (...a) => fetchMock(...a) }));

import ReauthForm from '../../../resources/js/Components/Security/ReauthForm.vue';

const both = { password: true, totp: true, passkey: false, google: false };
let wrapper;
const mountForm = (props = {}, slots = {}) => {
    wrapper = mountUi(ReauthForm, { props: { methods: both, mode: 'page', ...props }, slots });
    return wrapper;
};
beforeEach(() => { post.mockReset(); fetchMock.mockReset(); });
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; vi.useRealTimers(); });

describe('ReauthForm', () => {
    it('posts the password through Inertia in page mode and never sends the code', async () => {
        mountForm();
        await wrapper.find('input[autocomplete="current-password"]').setValue('secret');
        await wrapper.find('form').trigger('submit');
        expect(post).toHaveBeenCalledWith('/confirm-access', { password: 'secret' }, expect.any(Object));
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
});
