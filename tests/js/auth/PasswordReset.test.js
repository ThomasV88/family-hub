import { describe, it, expect, vi, afterEach } from 'vitest';
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
            form = reactive({ ...data, errors: { ...pendingErrors }, processing: false, post: (...a) => formPost(...a), reset: vi.fn() });
            return form;
        },
        router: { post: vi.fn(), visit: vi.fn(), on: vi.fn() },
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import ForgotPassword from '../../../resources/js/Pages/Auth/ForgotPassword.vue';
import ResetPassword from '../../../resources/js/Pages/Auth/ResetPassword.vue';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
let current;
function mountPage(component, { locale = 'en', props = {}, flash = {}, errors = {} } = {}) {
    mockProps = { locale, flash: { status: null, toast: null, data: null, ...flash }, auth: { user: null }, availableLocales: ['sk', 'pl', 'en'] };
    pendingErrors = errors;
    current = mount(component, { props, attachTo: document.body, global: { plugins: [createAppI18n(locale, load(locale))] } });
    return current;
}
afterEach(() => { current?.unmount(); current = null; formPost.mockClear(); document.body.innerHTML = ''; vi.useRealTimers(); });

describe('ForgotPassword', () => {
    it('renders the title, body, email field and back link', () => {
        mountPage(ForgotPassword);
        expect(current.findAll('h1')).toHaveLength(1);
        expect(current.find('h1').text()).toBe('Reset your password');
        expect(current.text()).toContain('Enter your email and we will send you a link.');
        expect(current.find('input[type=email]').attributes('autocomplete')).toBe('email');
        expect(current.find('button[type=submit]').text()).toBe('Send reset link');
        expect(current.find('a[href="/login"]').text()).toBe('Back to sign in');
    });

    it('validates an empty email and posts a valid one to /forgot-password', async () => {
        mountPage(ForgotPassword);
        await current.find('form').trigger('submit');
        expect(current.text()).toContain('Enter your email.');
        expect(formPost).not.toHaveBeenCalled();
        await current.find('input[type=email]').setValue('anna@example.test');
        await current.find('form').trigger('submit');
        expect(formPost).toHaveBeenCalledWith('/forgot-password');
    });

    it('shows the generic success alert and moves focus to it', async () => {
        mountPage(ForgotPassword, { flash: { status: 'reset_link_sent' } });
        await flushPromises();
        const alert = current.find('[data-testid=sent-alert]');
        expect(alert.attributes('role')).toBe('status');
        expect(alert.text()).toContain('If an account exists for that email');
        expect(document.activeElement).toBe(alert.element);
    });

    it('shows the plural throttle alert and disables submit', async () => {
        mountPage(ForgotPassword, { errors: { throttle: '2' } });
        await flushPromises();
        expect(current.find('[data-testid=throttle-alert]').text()).toBe('Too many attempts. Try again in 2 minutes.');
        expect(current.find('button[type=submit]').attributes('disabled')).toBeDefined();
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations in %s, light and dark', async (locale) => {
        for (const theme of ['light', 'dark']) {
            document.documentElement.dataset.theme = theme;
            mountPage(ForgotPassword, { locale, flash: { status: 'reset_link_sent' } });
            await expectNoAxeViolations(document.body);
            current.unmount();
            current = null;
        }
        delete document.documentElement.dataset.theme;
    });
});

describe('ResetPassword', () => {
    const valid = { token: 'tok', email: 'anna@example.test', valid: true };

    it('renders two new-password fields and the hints', () => {
        mountPage(ResetPassword, { props: valid });
        expect(current.find('h1').text()).toBe('Choose a new password');
        const fields = current.findAll('input[autocomplete=new-password]');
        expect(fields).toHaveLength(2);
        expect(current.text()).toContain('At least 12 characters. A long sentence works well.');
        expect(current.text()).toContain('You will be signed out on other devices.');
        expect(current.find('button[type=submit]').text()).toBe('Set new password');
    });

    it('rejects a short password and a mismatch client-side', async () => {
        mountPage(ResetPassword, { props: valid });
        const [pw, confirm] = current.findAll('input[autocomplete=new-password]');
        await pw.setValue('short');
        await current.find('form').trigger('submit');
        expect(current.text()).toContain('Use at least 12 characters.');
        await pw.setValue('a long new passphrase');
        await confirm.setValue('something else entirely');
        await current.find('form').trigger('submit');
        expect(current.text()).toContain('The passwords do not match.');
        expect(formPost).not.toHaveBeenCalled();
    });

    it('posts token, email and both passwords to /reset-password', async () => {
        mountPage(ResetPassword, { props: valid });
        const [pw, confirm] = current.findAll('input[autocomplete=new-password]');
        await pw.setValue('a long new passphrase');
        await confirm.setValue('a long new passphrase');
        await current.find('form').trigger('submit');
        expect(formPost.mock.calls[0][0]).toBe('/reset-password');
        expect(form.token).toBe('tok');
        expect(form.email).toBe('anna@example.test');
        expect(form.password_confirmation).toBe('a long new passphrase');
    });

    it('translates server error keys', () => {
        mountPage(ResetPassword, { props: valid, errors: { password: 'invite.accept.error.password_mismatch' } });
        expect(current.text()).toContain('The passwords do not match.');
    });

    it('shows the invalid-link state with a link to request a new one', () => {
        mountPage(ResetPassword, { props: { token: 'x', email: '', valid: false } });
        expect(current.find('h1').text()).toBe('This reset link cannot be used');
        expect(current.text()).toContain('It may have expired. Request a new one.');
        expect(current.find('a[href="/forgot-password"]').text()).toBe('Request a new link');
        expect(current.find('input[autocomplete=new-password]').exists()).toBe(false);
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations in %s, light and dark, valid and invalid', async (locale) => {
        for (const theme of ['light', 'dark']) {
            document.documentElement.dataset.theme = theme;
            for (const props of [valid, { token: 'x', email: '', valid: false }]) {
                mountPage(ResetPassword, { locale, props });
                await expectNoAxeViolations(document.body);
                current.unmount();
                current = null;
            }
        }
        delete document.documentElement.dataset.theme;
    });
});
