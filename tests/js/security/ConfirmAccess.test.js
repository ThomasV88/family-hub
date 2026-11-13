import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mountUi } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';

const post = vi.fn();
const pageProps = { flash: {} };
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        router: { post: (...a) => post(...a) },
        usePage: () => ({ props: pageProps }),
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});
vi.mock('../../../resources/js/Layouts/AppShell.vue', async () => {
    const { h } = await import('vue');
    return { default: { render() { return h('main', {}, this.$slots.default?.()); } } };
});

import ConfirmAccess from '../../../resources/js/Pages/Auth/ConfirmAccess.vue';

const pw = { password: true, totp: false, passkey: false, google: false };
let wrapper;
const mountPage = (props = {}, locale = 'en') => {
    wrapper = mountUi(ConfirmAccess, { props: { methods: pw, minutes: 15, interrupted: false, ...props }, locale });
    return wrapper;
};
beforeEach(() => { post.mockReset(); pageProps.flash = {}; });
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; document.documentElement.removeAttribute('data-theme'); });

describe('ConfirmAccess page', () => {
    it('shows one h1, the idle body with minutes and the back link', () => {
        mountPage();
        expect(wrapper.findAll('h1')).toHaveLength(1);
        expect(wrapper.find('h1').text()).toBe("Confirm it's you");
        expect(wrapper.text()).toContain('after 15 minutes without activity');
        expect(wrapper.find('a[href="/"]').text()).toBe('Back to overview');
        expect(wrapper.find('[data-testid="interrupted-alert"]').exists()).toBe(false);
    });

    it('shows the interrupted notice politely', () => {
        mountPage({ interrupted: true });
        const alert = wrapper.find('[data-testid="interrupted-alert"]');
        expect(alert.text()).toContain('not saved');
        expect(alert.attributes('role')).toBe('status');
    });

    it('offers the code link only with an authenticator and never a recovery option', async () => {
        mountPage();
        expect(wrapper.find('[data-testid="use-code"]').exists()).toBe(false);
        wrapper.unmount();
        mountPage({ methods: { ...pw, totp: true } });
        expect(wrapper.find('[data-testid="use-code"]').text()).toBe('Use authenticator code');
        expect(wrapper.html().toLowerCase()).not.toContain('recovery');
    });

    it('without a password shows the passkey-only body and the code form', () => {
        mountPage({ methods: { password: false, totp: true, passkey: false, google: false } });
        expect(wrapper.text()).toContain('Use your passkey or authenticator code to confirm.');
        expect(wrapper.find('input[autocomplete="current-password"]').exists()).toBe(false);
        expect(wrapper.find('input[autocomplete="one-time-code"]').exists()).toBe(true);
    });

    it('with no method offers a single Sign in again button', async () => {
        mountPage({ methods: { password: false, totp: false, passkey: false, google: false } });
        expect(wrapper.text()).toContain('no password or second step');
        expect(wrapper.find('form').exists()).toBe(false);
        await wrapper.find('[data-testid="sign-in-again"]').trigger('click');
        expect(post).toHaveBeenCalledWith('/confirm-access/sign-in-again', {}, expect.any(Object));
    });

    it('adds the Google link next to Sign in again for an eligible member', () => {
        mountPage({ methods: { password: false, totp: false, passkey: false, google: true } });
        expect(wrapper.find('[data-testid="sign-in-again"]').exists()).toBe(true);
        const link = wrapper.find('a[href="/confirm-access/google"]');
        expect(link.exists()).toBe(true);
        expect(link.text()).toBe('Sign in with Google again');
        expect(wrapper.text()).toContain('no password or second step');
    });

    it('shows neither the Google link nor its text with the flag off or with other methods', () => {
        mountPage({ methods: { password: false, totp: false, passkey: false, google: false } });
        expect(wrapper.findAll('button')).toHaveLength(1);
        expect(wrapper.find('a[href="/confirm-access/google"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Sign in with Google again');
        for (const methods of [pw, { ...pw, password: false, totp: true }, { ...pw, password: false, passkey: true }]) {
            wrapper.unmount();
            mountPage({ methods });
            expect(wrapper.find('a[href="/confirm-access/google"]').exists()).toBe(false);
            expect(wrapper.text()).not.toContain('Sign in with Google again');
        }
    });

    it('shows a danger alert after a failed Google confirmation', () => {
        pageProps.flash = { status: 'google_reauth_failed' };
        mountPage();
        const alert = wrapper.find('[data-testid="google-failed-alert"]');
        expect(alert.attributes('role')).toBe('alert');
        expect(alert.text()).toContain("could not confirm it's you");
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations in %s, light and dark', async (locale) => {
        for (const theme of ['light', 'dark']) {
            document.documentElement.setAttribute('data-theme', theme);
            mountPage({ methods: { ...pw, totp: true }, interrupted: true }, locale);
            await expectNoAxeViolations(wrapper.element);
            wrapper.unmount();
            pageProps.flash = { status: 'google_reauth_failed' };
            mountPage({ methods: { password: false, totp: false, passkey: false, google: true } }, locale);
            await expectNoAxeViolations(wrapper.element);
            wrapper.unmount();
            pageProps.flash = {};
            wrapper = null;
        }
    });
});
