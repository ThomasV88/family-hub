import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { h } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';
import UiList from '../../../resources/js/Components/ui/UiList.vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';

const { router } = vi.hoisted(() => ({ router: { reload: vi.fn(), post: vi.fn(), delete: vi.fn() } }));
vi.mock('@inertiajs/vue3', () => ({ router, useForm: (d) => ({ ...d }) }));

import SecurityRequirementCard from '../../../resources/js/Components/Settings/SecurityRequirementCard.vue';
import MemberSecurityTab from '../../../resources/js/Components/Settings/MemberSecurityTab.vue';
import MemberRow from '../../../resources/js/Components/Settings/MemberRow.vue';
import MemberPanel from '../../../resources/js/Components/Settings/MemberPanel.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const METHODS = { password: true, totp: false, passkey: false, google: false };
const sec = (over = {}) => ({ totp: false, passkeys: 0, required: false, needs: false, ...over });
const member = (over = {}, security = {}) => ({
    id: 7, name: 'Bob', email: 'bob@example.test', isAdmin: false, isSelf: false,
    access: { todo: 'edit', wishlist: 'edit', finance: 'edit', health: 'none' }, security: sec(security), ...over,
});

let current;
let calls;
function mockFetch(...responses) {
    calls = [];
    globalThis.fetch = vi.fn(async (url, init) => {
        calls.push({ url, method: init?.method, body: init?.body });
        const next = responses.length > 1 ? responses.shift() : responses[0];
        return { ok: next.status < 400, status: next.status, headers: { get: () => null }, json: async () => next.body ?? {} };
    });
}
const ok = (body = {}) => ({ status: 200, body });
const stale = () => ({ status: 423, body: { error: 'confirm_required' } });

function mountIt(component, props, { locale = 'en', messages } = {}) {
    current = mount(component, {
        props,
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] },
    });
    return current;
}
beforeEach(() => {
    window.matchMedia ||= (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {} });
    useToast().toasts.splice(0);
});
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; Object.values(router).forEach((f) => f.mockReset()); });

const card = (over = {}) => ({ securityPolicy: { required: false, pending: 2 }, confirmFresh: true, methods: METHODS, ...over });
const switchEl = () => current.find('input[role=switch]');
const bodyButton = (text) => [...document.body.querySelectorAll('button')].filter((b) => b.textContent.trim() === text).at(-1);

describe('SecurityRequirementCard', () => {
    it('shows the title, label, help and the pending count (plural and zero)', () => {
        mountIt(SecurityRequirementCard, card());
        const text = current.text();
        expect(text).toContain('Two-step verification for sensitive data');
        expect(text).toContain('Require two-step verification for Health and Finances');
        expect(text).toContain('Not set up yet: 2 members');
        current.unmount();
        mountIt(SecurityRequirementCard, card({ securityPolicy: { required: false, pending: 0 } }));
        expect(current.text()).toContain('Everyone with access has set it up.');
    });

    it('saves immediately on a fresh window with a toast and a props reload', async () => {
        mockFetch(ok({ required: true }));
        mountIt(SecurityRequirementCard, card());
        await switchEl().setValue(true);
        await flushPromises();
        expect(calls).toEqual([{ url: '/settings/security/policy', method: 'PUT', body: JSON.stringify({ required: true }) }]);
        expect(useToast().toasts.at(-1).message).toBe('Requirement turned on');
        expect(router.reload).toHaveBeenCalled();
        expect(switchEl().element.checked).toBe(true);
    });

    it('turning it off sends false and shows the off toast', async () => {
        mockFetch(ok({ required: false }));
        mountIt(SecurityRequirementCard, card({ securityPolicy: { required: true, pending: 0 } }));
        expect(switchEl().element.checked).toBe(true);
        await switchEl().setValue(false);
        await flushPromises();
        expect(JSON.parse(calls[0].body)).toEqual({ required: false });
        expect(useToast().toasts.at(-1).message).toBe('Requirement turned off');
    });

    it('on a stale window the switch reverts and the dialog asks to confirm; confirming retries the change', async () => {
        mockFetch(ok({ confirmed: true }), ok({ required: true }));
        mountIt(SecurityRequirementCard, card({ confirmFresh: false }));
        await switchEl().setValue(true);
        await flushPromises();
        expect(calls).toHaveLength(0);
        expect(switchEl().element.checked).toBe(false);
        expect(document.body.textContent).toContain("Confirm it's you");
        expect(document.body.textContent).toContain("Confirm it's you before changing the requirement.");
        expect(router.post).not.toHaveBeenCalled();

        const input = document.body.querySelector('input[type=password]');
        input.value = 'secret';
        input.dispatchEvent(new Event('input'));
        document.body.querySelector('form').dispatchEvent(new Event('submit', { cancelable: true }));
        await flushPromises();
        expect(calls.map((c) => c.url)).toEqual(['/confirm-access', '/settings/security/policy']);
        expect(switchEl().element.checked).toBe(true);
        expect(document.body.textContent).not.toContain("Confirm it's you before");
    });

    it('a 423 answer on a fresh-looking window reverts and opens the dialog', async () => {
        mockFetch(stale());
        mountIt(SecurityRequirementCard, card());
        await switchEl().setValue(true);
        await flushPromises();
        expect(switchEl().element.checked).toBe(false);
        expect(document.body.textContent).toContain("Confirm it's you before changing the requirement.");
    });

    it('closing the dialog leaves the setting unchanged', async () => {
        mockFetch(ok());
        mountIt(SecurityRequirementCard, card({ confirmFresh: false }));
        await switchEl().setValue(true);
        await flushPromises();
        document.body.querySelector('[role=dialog] button[aria-label], .ui-dialog__close')?.click();
        await flushPromises();
        expect(calls).toHaveLength(0);
        expect(switchEl().element.checked).toBe(false);
    });

    it('offers sign-in-again and the setup link when the admin has no method', async () => {
        mountIt(SecurityRequirementCard, card({ confirmFresh: false, methods: { password: false, totp: false, passkey: false, google: false } }));
        await switchEl().setValue(true);
        await flushPromises();
        expect(document.body.textContent).toContain('This account has no password or second step');
        expect(document.body.querySelector('a[href="/settings/security"]').textContent).toContain('Set up an authenticator app first');
        bodyButton('Sign in again').click();
        expect(router.post).toHaveBeenCalledWith('/confirm-access/sign-in-again');
    });
});

describe('MemberRow factor tags', () => {
    const mountRow = (m) => mountIt(MemberRow, { member: m });
    it('shows on for a factor, off for none and needs when required', () => {
        mountRow(member({}, { totp: true }));
        expect(current.text()).toContain('Two-step: on');
        current.unmount();
        mountRow(member({}, { passkeys: 2 }));
        expect(current.text()).toContain('Two-step: on');
        current.unmount();
        mountRow(member());
        expect(current.text()).toContain('Two-step: off');
        current.unmount();
        mountRow(member({}, { required: true, needs: true }));
        expect(current.text()).toContain('Needs two-step');
        expect(current.text()).not.toContain('Two-step: off');
    });
});

describe('MemberPanel', () => {
    it('adds a Security tab after Permissions and Sessions', () => {
        mountIt(MemberPanel, { member: member(), presets: {}, members: [member()], methods: METHODS, confirmFresh: true });
        const tabs = [...document.body.querySelectorAll('[role=tab]')].map((t) => t.textContent.trim());
        expect(tabs.at(-1)).toBe('Security');
        expect(tabs.indexOf('Sessions')).toBeLessThan(tabs.indexOf('Security'));
    });
});

describe('MemberSecurityTab', () => {
    const tabProps = (m, over = {}) => ({ member: m, securityPolicy: { required: false, pending: 0 }, confirmFresh: true, methods: METHODS, ...over });

    it('renders status rows with the passkey plural and none', () => {
        mountIt(MemberSecurityTab, tabProps(member({}, { totp: true, passkeys: 2 })));
        expect(current.text()).toContain('Authenticator app');
        expect(current.text()).toContain('2 passkeys');
        current.unmount();
        mountIt(MemberSecurityTab, tabProps(member()));
        expect(current.find('[data-testid=passkey-count]').text()).toBe('None');
        expect(current.text()).toContain('Not required');
    });

    it('disables the reset with the hint when the member has no factor', () => {
        mountIt(MemberSecurityTab, tabProps(member()));
        expect(current.find('[data-testid=reset-button]').attributes('disabled')).toBeDefined();
        expect(current.text()).toContain('This member has no two-step verification to reset.');
    });

    it('replaces the button on the admin own row with a link', () => {
        mountIt(MemberSecurityTab, tabProps(member({ isSelf: true }, { totp: true })));
        expect(current.find('[data-testid=reset-button]').exists()).toBe(false);
        expect(current.find('a[href="/settings/security"]').text()).toContain('Manage your own two-step verification');
    });

    it('opens the dialog with the plain body, safe button first, and resets on a fresh window', async () => {
        mockFetch(ok({ reset: true }));
        mountIt(MemberSecurityTab, tabProps(member({}, { totp: true })));
        await current.find('[data-testid=reset-button]').trigger('click');
        await flushPromises();
        expect(document.body.textContent).toContain('Remove all two-step verification for Bob?');
        expect(document.body.textContent).not.toContain('before opening Health or Finances');
        expect(document.body.querySelector('input[type=password]')).toBeNull();
        expect(document.activeElement.textContent.trim()).toBe('Keep two-step verification');
        bodyButton('Reset two-step verification').click();
        await flushPromises();
        expect(calls).toEqual([{ url: '/settings/members/7/second-factor', method: 'DELETE', body: undefined }]);
        expect(useToast().toasts.at(-1).message).toBe('Two-step verification reset for Bob');
        expect(router.reload).toHaveBeenCalled();
    });

    it('uses the requirement body when the member is under the requirement', async () => {
        mountIt(MemberSecurityTab, tabProps(member({}, { passkeys: 1, required: true }), { securityPolicy: { required: true, pending: 0 } }));
        await current.find('[data-testid=reset-button]').trigger('click');
        await flushPromises();
        expect(document.body.textContent).toContain('before opening Health or Finances');
    });

    it('shows the confirm form in place on a stale window and retries after confirming', async () => {
        mockFetch(ok({ confirmed: true }), ok({ reset: true }));
        mountIt(MemberSecurityTab, tabProps(member({}, { totp: true }), { confirmFresh: false }));
        await current.find('[data-testid=reset-button]').trigger('click');
        await flushPromises();
        const input = document.body.querySelector('input[type=password]');
        expect(input).not.toBeNull();
        input.value = 'secret';
        input.dispatchEvent(new Event('input'));
        document.body.querySelector('form').dispatchEvent(new Event('submit', { cancelable: true }));
        await flushPromises();
        expect(calls.map((c) => c.url)).toEqual(['/confirm-access', '/settings/members/7/second-factor']);
        expect(router.post).not.toHaveBeenCalled();
    });

    it('re-shows the form after a stale refusal and never navigates to /confirm-access', async () => {
        mockFetch(stale());
        mountIt(MemberSecurityTab, tabProps(member({}, { totp: true })));
        await current.find('[data-testid=reset-button]').trigger('click');
        bodyButton('Reset two-step verification').click();
        await flushPromises();
        expect(document.body.querySelector('input[type=password]')).not.toBeNull();
        expect(router.post).not.toHaveBeenCalled();
    });

    it('shows the no-method copy for an admin without any method', async () => {
        mountIt(MemberSecurityTab, tabProps(member({}, { totp: true }), { confirmFresh: false, methods: { password: false, totp: false, passkey: false, google: false } }));
        await current.find('[data-testid=reset-button]').trigger('click');
        await flushPromises();
        expect(document.body.textContent).toContain('This account has no password or second step');
        expect(document.body.querySelector('a[href="/settings/security"]')).not.toBeNull();
        bodyButton('Sign in again').click();
        expect(router.post).toHaveBeenCalledWith('/confirm-access/sign-in-again');
    });
});

describe('long strings and accessibility', () => {
    it('keeps pseudo-long labels wrapping (no nowrap on buttons and rows)', async () => {
        const messages = pseudoLong(loadMessages('en'));
        mountIt(MemberSecurityTab, tabProps2(), { messages });
        expect(current.text().length).toBeGreaterThan(20);
        await expectNoAxeViolations(document.body);
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations in %s (card, rows, tab and dialogs)', async (locale) => {
        for (const dark of [false, true]) {
            document.documentElement.dataset.theme = dark ? 'dark' : 'light';
            mountIt(SecurityRequirementCard, card({ confirmFresh: false }), { locale });
            await switchEl().setValue(true);
            await flushPromises();
            await expectNoAxeViolations(document.body);
            current.unmount();
            document.body.innerHTML = '';

            mountIt({ render: () => h(UiList, null, () => h(MemberRow, { member: member({}, { required: true, needs: true }) })) }, {}, { locale });
            await expectNoAxeViolations(document.body);
            current.unmount();

            mountIt(MemberSecurityTab, tabProps2({ confirmFresh: false }), { locale });
            await current.find('[data-testid=reset-button]').trigger('click');
            await flushPromises();
            await expectNoAxeViolations(document.body);
            current.unmount();
            document.body.innerHTML = '';
        }
    });
});

function tabProps2(over = {}) {
    return { member: member({}, { totp: true, passkeys: 1, required: true }), securityPolicy: { required: true, pending: 1 }, confirmFresh: true, methods: METHODS, ...over };
}
