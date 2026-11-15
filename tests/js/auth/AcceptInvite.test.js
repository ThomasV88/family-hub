import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
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

import AcceptInvite from '../../../resources/js/Pages/Auth/AcceptInvite.vue';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
let current;
function mountPage({ locale = 'en', props = {}, flash = {}, errors = {} } = {}) {
    mockProps = { locale, flash: { status: null, toast: null, data: null, ...flash }, auth: { user: null }, availableLocales: ['sk', 'pl', 'en'] };
    pendingErrors = errors;
    current = mount(AcceptInvite, {
        props: { state: 'valid', email: 'eva@example.test', token: 'tok123', googleEnabled: false, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, load(locale))] },
    });
    return current;
}
afterEach(() => { current?.unmount(); current = null; formPost.mockClear(); document.body.innerHTML = ''; });

describe('AcceptInvite page', () => {
    it('renders the valid state with the disabled email and the account fields', () => {
        mountPage();
        const w = current;
        expect(w.findAll('h1')).toHaveLength(1);
        expect(w.find('h1').text()).toBe('Join your family on Family Hub');
        expect(w.text()).toContain('You were invited as eva@example.test.');
        expect(w.find('input[type=email]').attributes('disabled')).toBeDefined();
        expect(w.find('input[type=email]').element.value).toBe('eva@example.test');
        expect(w.find('input[autocomplete=name]').exists()).toBe(true);
        expect(w.findAll('input[autocomplete=new-password]')).toHaveLength(2);
        expect(w.text()).toContain('At least 12 characters.');
        expect(w.text()).toContain('Create account');
        expect(w.text()).not.toContain('Continue with Google');
    });

    it('validates client-side and does not post', async () => {
        mountPage();
        await current.find('form').trigger('submit');
        expect(current.text()).toContain('Enter your name.');
        expect(formPost).not.toHaveBeenCalled();
        await current.find('input[autocomplete=name]').setValue('Eva');
        await current.find('form').trigger('submit');
        expect(current.text()).toContain('Use at least 12 characters.');
        const [password, confirm] = current.findAll('input[autocomplete=new-password]');
        await password.setValue('a long enough passphrase');
        await confirm.setValue('something else entirely');
        await current.find('form').trigger('submit');
        expect(current.text()).toContain('The passwords do not match.');
        expect(formPost).not.toHaveBeenCalled();
    });

    it('posts the form to the invite url', async () => {
        mountPage();
        await current.find('input[autocomplete=name]').setValue('Eva');
        const [password, confirm] = current.findAll('input[autocomplete=new-password]');
        await password.setValue('a long enough passphrase');
        await confirm.setValue('a long enough passphrase');
        await current.find('form').trigger('submit');
        expect(formPost).toHaveBeenCalledTimes(1);
        expect(formPost.mock.calls[0][0]).toBe('/invite/tok123');
    });

    it('shows the Google button with the invite token only when googleEnabled', () => {
        mountPage({ props: { googleEnabled: true } });
        const link = current.find('a[href="/auth/google/redirect?invite=tok123"]');
        expect(link.text()).toContain('Continue with Google');
        expect(current.text()).toContain('Use the Google account for eva@example.test.');
    });

    it('shows the mismatch alert after a wrong Google account', () => {
        mountPage({ props: { googleEnabled: true }, flash: { status: 'google_mismatch' } });
        expect(current.find('[data-testid=mismatch-alert]').text()).toContain('That Google account uses a different email.');
    });

    it.each([
        ['expired', 'It expired after 7 days.'],
        ['used', 'It has already been used.'],
        ['revoked', 'It is no longer valid.'],
    ])('shows the invalid state for %s', (state, copy) => {
        mountPage({ props: { state, email: null, token: null } });
        expect(current.find('h1').text()).toBe('This invite cannot be used');
        expect(current.text()).toContain(copy);
        expect(current.find('a[href="/login"]').text()).toBe('Go to sign in');
        expect(current.find('form').exists()).toBe(false);
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations in %s, light and dark', async (locale) => {
        for (const theme of ['light', 'dark']) {
            document.documentElement.dataset.theme = theme;
            for (const props of [{ googleEnabled: true }, { state: 'expired', email: null, token: null }]) {
                mountPage({ locale, props, flash: { status: 'google_mismatch' }, errors: { name: 'invite.accept.error.name_required' } });
                await expectNoAxeViolations(document.body);
                current.unmount();
                current = null;
            }
        }
        delete document.documentElement.dataset.theme;
    });
});
