import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';
import { loadMessages } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';

let mockProps = {};
let form;
let pendingErrors = {};
const formPost = vi.fn();
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: mockProps }),
        useForm: (data) => {
            form = reactive({
                ...data,
                errors: { ...pendingErrors },
                processing: false,
                transformed: null,
                transform(fn) { this.transformer = fn; return this; },
                post(...args) { formPost(this.transformer ? this.transformer({ ...this }) : null, ...args); },
                reset() { this.code = ''; this.recovery_code = ''; },
                clearErrors() { this.errors = {}; },
            });
            return form;
        },
        router: { post: vi.fn(), visit: vi.fn(), on: vi.fn() },
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import TwoFactorChallenge from '../../../resources/js/Pages/Auth/TwoFactorChallenge.vue';
import Login from '../../../resources/js/Pages/Auth/Login.vue';

let wrapper;
function mountPage(component, { locale = 'en', props = {}, errors = {}, flash = {}, slots = {} } = {}) {
    mockProps = { locale, flash: { status: null, toast: null, data: null, ...flash }, auth: { user: null }, availableLocales: ['sk', 'pl', 'en'] };
    pendingErrors = errors;
    wrapper = mount(component, {
        props,
        slots,
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, loadMessages(locale))] },
    });
    return wrapper;
}
afterEach(() => { wrapper?.unmount(); wrapper = null; formPost.mockClear(); document.body.innerHTML = ''; vi.useRealTimers(); });

describe('TwoFactorChallenge', () => {
    it('shows the code form with the totp body and posts only the code', async () => {
        mountPage(TwoFactorChallenge, { props: { totp: true, passkey: false } });
        expect(wrapper.findAll('h1')).toHaveLength(1);
        expect(wrapper.find('h1').text()).toBe('Two-step verification');
        expect(wrapper.text()).toContain('Enter the 6-digit code from your authenticator app.');
        expect(wrapper.text()).toContain('6-digit code');
        await wrapper.find('input[autocomplete="one-time-code"]').setValue('123456');
        await wrapper.find('form').trigger('submit');
        expect(formPost).toHaveBeenCalledTimes(1);
        expect(formPost.mock.calls[0][0]).toEqual({ code: '123456' });
        expect(formPost.mock.calls[0][1]).toBe('/two-factor-challenge');
        expect(wrapper.find('button[type=submit]').text()).toBe('Verify code');
    });

    it('switches to the recovery code, moves focus, and posts only the recovery code', async () => {
        mountPage(TwoFactorChallenge, { props: { totp: true, passkey: false } });
        await wrapper.find('[data-testid="use-recovery"]').trigger('click');
        await flushPromises();
        const input = wrapper.find('input[autocomplete="off"]');
        expect(input.exists()).toBe(true);
        expect(wrapper.find('input[autocomplete="one-time-code"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Enter one of your recovery codes. Each code works once.');
        expect(document.activeElement).toBe(input.element);

        await input.setValue('ABCDE-FGHIJ');
        await wrapper.find('form').trigger('submit');
        expect(formPost.mock.calls[0][0]).toEqual({ recovery_code: 'ABCDE-FGHIJ' });

        await wrapper.find('[data-testid="use-totp"]').trigger('click');
        expect(wrapper.find('input[autocomplete="one-time-code"]').exists()).toBe(true);
    });

    it('does not post an empty code', async () => {
        mountPage(TwoFactorChallenge, { props: { totp: true, passkey: false } });
        await wrapper.find('form').trigger('submit');
        expect(formPost).not.toHaveBeenCalled();
    });

    it('shows no code form for a passkey-only member and offers a passkey slot', () => {
        mountPage(TwoFactorChallenge, {
            props: { totp: false, passkey: true },
            slots: { passkey: '<div data-testid="slot-probe">passkey</div>' },
        });
        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.find('[data-testid="passkey-only"]').text()).toBe('Use your passkey to finish signing in.');
        expect(wrapper.find('[data-testid="slot-probe"]').exists()).toBe(true);
    });

    it('shows one generic danger alert for a wrong code, clears and refocuses the input', async () => {
        mountPage(TwoFactorChallenge, { props: { totp: true, passkey: false } });
        await wrapper.find('input[autocomplete="one-time-code"]').setValue('000000');
        await wrapper.find('form').trigger('submit');
        form.errors = { code: 'twofactor.error.code' };
        await formPost.mock.calls[0][2].onError();
        await flushPromises();

        const alerts = wrapper.findAll('[role="alert"]').filter((a) => a.text() !== ''); // the code input keeps an empty live region
        expect(alerts).toHaveLength(1);
        expect(wrapper.find('[data-testid="challenge-alert"]').text()).toBe('That code is not correct. Check it and try again.');
        expect(wrapper.find('input[autocomplete="one-time-code"]').element.value).toBe('');
        expect(document.activeElement).toBe(wrapper.find('input[autocomplete="one-time-code"]').element);
    });

    it('shows the recovery error text for a rejected recovery code', async () => {
        mountPage(TwoFactorChallenge, { props: { totp: true, passkey: false }, errors: { recovery_code: 'twofactor.error.recovery' } });
        expect(wrapper.find('[data-testid="challenge-alert"]').text()).toBe('That recovery code is not correct or was already used.');
    });

    it('announces the lockout, disables submit, counts down by minute and then says it is over', async () => {
        vi.useFakeTimers();
        mountPage(TwoFactorChallenge, { props: { totp: true, passkey: false }, errors: { throttle: '3' } });
        await flushPromises();
        const alert = wrapper.find('[data-testid="throttle-alert"]');
        expect(alert.text()).toBe('Too many attempts. Try again in 3 minutes.');
        expect(alert.attributes('role')).toBe('alert');
        expect(wrapper.find('button[type=submit]').attributes('disabled')).toBeDefined();

        await vi.advanceTimersByTimeAsync(60 * 1000);
        expect(wrapper.find('[data-testid="throttle-alert"]').text()).toBe('Too many attempts. Try again in 2 minutes.');

        await vi.advanceTimersByTimeAsync(2 * 60 * 1000);
        expect(wrapper.find('[data-testid="throttle-alert"]').exists()).toBe(false);
        const over = wrapper.find('[data-testid="throttle-over"]');
        expect(over.text()).toBe('You can try again now.');
        expect(over.attributes('role')).toBe('status');
        expect(wrapper.find('button[type=submit]').attributes('disabled')).toBeUndefined();
    });

    it('links back to sign in and shows the language and theme switches', () => {
        mountPage(TwoFactorChallenge, { props: { totp: true, passkey: false } });
        expect(wrapper.find('[data-testid="back-to-login"]').attributes('href')).toBe('/login');
        expect(wrapper.find('[data-testid="back-to-login"]').text()).toBe('Back to sign in');
        expect(wrapper.find('[data-testid="language-switcher"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="theme-switch"]').exists()).toBe(true);
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations in %s, light and dark', async (locale) => {
        for (const theme of ['light', 'dark']) {
            document.documentElement.setAttribute('data-theme', theme);
            mountPage(TwoFactorChallenge, { locale, props: { totp: true, passkey: true } });
            await expectNoAxeViolations(wrapper.element);
            wrapper.unmount();
            mountPage(TwoFactorChallenge, { locale, props: { totp: false, passkey: true } });
            await expectNoAxeViolations(wrapper.element);
            wrapper.unmount();
            wrapper = null;
        }
        document.documentElement.removeAttribute('data-theme');
    });

    it('has no fixed widths that would overflow a 320px screen', () => {
        mountPage(TwoFactorChallenge, { props: { totp: true, passkey: false } });
        const html = wrapper.html();
        expect(html).not.toMatch(/width:\s*\d{3,}px/);
    });
});

describe('Login status for an expired challenge', () => {
    it('shows the info alert twofactor.error.expired', () => {
        mountPage(Login, { flash: { status: 'challenge_expired' } });
        const alert = wrapper.find('[data-testid="status-alert"]');
        expect(alert.text()).toBe('Your sign-in timed out. Sign in again.');
    });
});
