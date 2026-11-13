import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { mountUi } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';

const router = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn(), clearHistory: vi.fn() }));
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        router,
        usePage: () => ({ props: { auth: { user: { isAdmin: false } } } }),
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});
vi.mock('../../../resources/js/Layouts/SettingsLayout.vue', async () => {
    const { h } = await import('vue');
    return { default: { props: ['title', 'current', 'sections'], render() { return h('main', {}, [h('h1', {}, this.title), this.$slots.default?.()]); } } };
});
const fetchMock = vi.fn();
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: (...a) => fetchMock(...a) }));

import TwoFactorSetupDialog from '../../../resources/js/Components/Security/TwoFactorSetupDialog.vue';
import Security from '../../../resources/js/Pages/Settings/Security.vue';

const methods = { password: true, totp: false, passkey: false };
const base = { sections: [], status: { totp: false, recoveryRemaining: null }, passkeys: [], required: false, guidance: false, continueTo: null, methods, confirmFresh: true, passkeysEnabled: false };
let wrapper;
const mountPage = (props = {}, locale = 'en') => {
    wrapper = mountUi(Security, { props: { ...base, ...props }, locale });
    return wrapper;
};
beforeEach(() => { Object.values(router).forEach((f) => f.mockReset()); fetchMock.mockReset(); });
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; document.documentElement.removeAttribute('data-theme'); });

describe('Security page', () => {
    it('shows the Off status and the setup button, no recovery card', () => {
        mountPage();
        expect(wrapper.find('h1').text()).toBe('security.title');
        expect(wrapper.find('[data-testid="status-tag"]').text()).toBe('Off');
        expect(wrapper.text()).toContain('Two-step verification is off.');
        expect(wrapper.find('[data-testid="setup-totp"]').text()).toBe('Set up authenticator app');
        expect(wrapper.find('[data-testid="recovery-card"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="required-tag"]').exists()).toBe(false);
    });

    it('shows On, the remaining count with plural, and the create-codes button', () => {
        mountPage({ status: { totp: true, recoveryRemaining: 8 } });
        expect(wrapper.find('[data-testid="status-tag"]').text()).toBe('On');
        expect(wrapper.find('[data-testid="remaining"]').text()).toBe('8 recovery codes left');
        expect(wrapper.find('[data-testid="low"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="setup-totp"]').exists()).toBe(false);
    });

    it('warns when 2 or fewer codes remain and when none are left', () => {
        mountPage({ status: { totp: true, recoveryRemaining: 1 } });
        expect(wrapper.find('[data-testid="remaining"]').text()).toBe('1 recovery code left');
        expect(wrapper.find('[data-testid="low"]').text()).toContain('Running low');
        wrapper.unmount();
        mountPage({ status: { totp: true, recoveryRemaining: 0 } });
        expect(wrapper.find('[data-testid="remaining"]').text()).toBe('No recovery codes left');
        expect(wrapper.find('[data-testid="low"]').exists()).toBe(true);
    });

    it('shows the Required tag and note under the requirement', () => {
        mountPage({ required: true });
        expect(wrapper.find('[data-testid="required-tag"]').text()).toBe('Required');
        expect(wrapper.text()).toContain('Your family admin requires');
    });

    it('guidance shows a non-alert warning, the needs tag and focuses the h1', () => {
        mountPage({ guidance: true, required: true, continueTo: '/finance' });
        const alert = wrapper.find('[data-testid="guidance"]');
        expect(alert.text()).toContain('Set up two-step verification to continue');
        expect(alert.attributes('role')).toBeUndefined();
        expect(wrapper.find('[data-testid="status-tag"]').text()).toBe('Needs two-step');
        expect(document.activeElement).toBe(wrapper.find('h1').element);
    });

    it('renders the passkeys card in the region only when passkeys are enabled', () => {
        mountPage({ passkeysEnabled: true });
        expect(wrapper.find('[data-testid="passkeys-region"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="passkeys-region"] [data-testid="passkeys-card"]').exists()).toBe(true);
        wrapper.unmount();
        mountPage({ passkeysEnabled: false });
        expect(wrapper.find('[data-testid="passkeys-region"]').exists()).toBe(false);
    });

    it('opens the setup dialog from the button', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: { svg: '<svg/>', secretKey: 'ABCD' } });
        mountPage();
        await wrapper.find('[data-testid="setup-totp"]').trigger('click');
        await flushPromises();
        expect(document.body.querySelector('[data-testid="setup-dialog"]')).not.toBeNull();
    });

    it('opens the turn-off and new-codes dialogs from the cards', async () => {
        mountPage({ status: { totp: true, recoveryRemaining: 8 } });
        await wrapper.find('[data-testid="disable-totp"]').trigger('click');
        expect(document.body.querySelector('[data-testid="disable-dialog"]')).not.toBeNull();
        document.body.querySelector('[data-testid="keep"]').click();
        await flushPromises();
        await wrapper.find('[data-testid="create-codes"]').trigger('click');
        expect(document.body.querySelector('[data-testid="regen-dialog"]')).not.toBeNull();
    });

    it('after setup finishes: reloads props normally, or posts continue under guidance', async () => {
        mountPage();
        wrapper.findComponent(TwoFactorSetupDialog).vm.$emit('finished');
        expect(router.clearHistory).toHaveBeenCalled();
        expect(router.reload).toHaveBeenCalled();
        expect(router.post).not.toHaveBeenCalled();
        wrapper.unmount();
        router.post.mockReset(); router.reload.mockReset();
        mountPage({ guidance: true, continueTo: '/finance' });
        wrapper.findComponent(TwoFactorSetupDialog).vm.$emit('finished');
        expect(router.post).toHaveBeenCalledWith('/settings/security/continue');
        expect(router.reload).not.toHaveBeenCalled();
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const theme of ['light', 'dark']) {
            it(`has no axe violations (${locale}, ${theme}) off, on and guidance`, async () => {
                document.documentElement.setAttribute('data-theme', theme);
                for (const props of [{}, { status: { totp: true, recoveryRemaining: 2 }, required: true }, { guidance: true, required: true }]) {
                    mountPage(props, locale);
                    await expectNoAxeViolations(document.body);
                    wrapper.unmount();
                }
            });
        }
    }
});
