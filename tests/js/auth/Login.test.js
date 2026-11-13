import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import { readFileSync } from 'node:fs';
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
                post: (...args) => formPost(...args),
                reset: vi.fn(),
            });
            return form;
        },
        router: { post: vi.fn(), visit: vi.fn(), on: vi.fn() },
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
let current;
function mountLogin({ locale = 'en', props = {}, flash = {}, errors = {} } = {}) {
    mockProps = { locale, flash: { status: null, toast: null, data: null, ...flash }, auth: { user: null }, availableLocales: ['sk', 'pl', 'en'] };
    pendingErrors = errors;
    current = mount(Login, {
        props,
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, load(locale))] },
    });
    return current;
}
afterEach(() => { current?.unmount(); current = null; formPost.mockClear(); document.body.innerHTML = ''; vi.useRealTimers(); });

import Login from '../../../resources/js/Pages/Auth/Login.vue';

describe('Login page', () => {
    it('renders inside AuthLayout with one h1 and the expected controls', () => {
        mountLogin();
        const w = current;
        expect(w.findAll('h1')).toHaveLength(1);
        expect(w.find('h1').text()).toBe('Sign in');
        expect(w.text()).toContain('Family Hub');
        const email = w.find('input[type=email]');
        expect(email.attributes('autocomplete')).toBe('username');
        expect(email.attributes('autofocus')).toBeDefined();
        const password = w.find('input[autocomplete=current-password]');
        expect(password.attributes('type')).toBe('password');
        expect(w.find('input[type=checkbox]').element.checked).toBe(false);
        expect(w.text()).toContain('Stay signed in for 30 days');
        expect(w.find('a[href="/forgot-password"]').text()).toBe('Forgot password?');
        expect(w.text()).toContain('Family Hub is by invitation only');
        expect(w.find('[data-testid=language-switcher]').exists()).toBe(true);
        expect(w.find('[data-testid=theme-switch]').exists()).toBe(true);
        expect(w.text()).not.toContain('Sign in with Google');
    });

    it('shows and toggles the password with aria-pressed', async () => {
        mountLogin();
        const toggle = current.find('[data-testid=toggle-password]');
        expect(toggle.attributes('aria-pressed')).toBe('false');
        expect(toggle.attributes('aria-label')).toBe('Show password');
        await toggle.trigger('click');
        expect(toggle.attributes('aria-pressed')).toBe('true');
        expect(toggle.attributes('aria-label')).toBe('Hide password');
        expect(current.find('input[autocomplete=current-password]').attributes('type')).toBe('text');
    });

    it('renders the Google button and divider only when googleEnabled', () => {
        mountLogin({ props: { googleEnabled: true } });
        expect(current.text()).toContain('Sign in with Google');
        expect(current.find('[role=separator]').text()).toBe('or');
    });

    it('validates empty email and password client-side and does not post', async () => {
        mountLogin();
        await current.find('form').trigger('submit');
        expect(current.text()).toContain('Enter your email.');
        expect(current.text()).toContain('Enter your password.');
        expect(formPost).not.toHaveBeenCalled();
    });

    it('posts email, password and remember to /login', async () => {
        mountLogin();
        await current.find('input[type=email]').setValue('anna@example.test');
        await current.find('input[autocomplete=current-password]').setValue('secret-secret');
        await current.find('input[type=checkbox]').setValue(true);
        await current.find('form').trigger('submit');
        expect(formPost).toHaveBeenCalledTimes(1);
        expect(formPost.mock.calls[0][0]).toBe('/login');
        expect(form.email).toBe('anna@example.test');
        expect(form.password).toBe('secret-secret');
        expect(form.remember).toBe(true);
    });

    it('shows the button loading while the form is processing', async () => {
        mountLogin();
        form.processing = true;
        await flushPromises();
        const submit = current.find('button[type=submit]');
        expect(submit.attributes('aria-busy')).toBe('true');
        expect(submit.attributes('disabled')).toBeDefined();
    });

    it('shows one generic danger alert for auth.error.failed', () => {
        mountLogin({ errors: { email: 'auth.error.failed' } });
        const alert = current.find('[data-testid=failed-alert]');
        expect(alert.attributes('role')).toBe('alert');
        expect(alert.text()).toBe('Email or password is not correct. Check them and try again.');
        expect(current.findAll('[role=alert]')).toHaveLength(1);
    });

    it('shows the throttle wait in minutes, disables submit, and re-enables after the wait', async () => {
        vi.useFakeTimers();
        mountLogin({ errors: { throttle: '3' } });
        await flushPromises();
        const alert = current.find('[data-testid=throttle-alert]');
        expect(alert.text()).toBe('Too many attempts. Try again in 3 minutes.');
        expect(alert.attributes('role')).toBe('alert');
        expect(current.find('button[type=submit]').attributes('disabled')).toBeDefined();
        await vi.advanceTimersByTimeAsync(3 * 60 * 1000);
        expect(current.find('button[type=submit]').attributes('disabled')).toBeUndefined();
        expect(current.find('[data-testid=throttle-alert]').exists()).toBe(false);
    });

    it('uses the singular for one minute', async () => {
        mountLogin({ errors: { throttle: '1' } });
        await flushPromises();
        expect(current.find('[data-testid=throttle-alert]').text()).toBe('Too many attempts. Try again in 1 minute.');
    });

    it.each([
        ['signed_out', 'info', 'You have been signed out.'],
        ['session_ended', 'info', 'You were signed out. Sign in again to continue.'],
        ['password_reset', 'success', 'Your password was changed. Sign in with the new one.'],
    ])('shows the %s status alert', (status, variant, text) => {
        mountLogin({ flash: { status } });
        const alert = current.find('[data-testid=status-alert]');
        expect(alert.text()).toBe(text);
        expect(alert.classes()).toContain(`ui-alert--${variant}`);
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations in %s, light and dark', async (locale) => {
        for (const theme of ['light', 'dark']) {
            document.documentElement.dataset.theme = theme;
            mountLogin({ locale, props: { googleEnabled: true }, errors: { email: 'auth.error.failed' }, flash: { status: 'signed_out' } });
            await expectNoAxeViolations(document.body);
            current.unmount();
            current = null;
        }
        delete document.documentElement.dataset.theme;
    });
});
