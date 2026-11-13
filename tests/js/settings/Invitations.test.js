import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

let mockProps = {};
const hoisted = vi.hoisted(() => ({
    routerMock: { get: vi.fn(), post: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() },
    state: { formPost: vi.fn(), lastForm: null },
}));
const routerMock = hoisted.routerMock;
const state = hoisted.state;
vi.mock('@inertiajs/vue3', async () => {
    const { h, reactive } = await import('vue');
    return {
        usePage: () => ({ props: mockProps }),
        router: hoisted.routerMock,
        useForm: (data) => {
            const initial = { ...data };
            state.lastForm = reactive({
                ...data, errors: {}, processing: false, put: vi.fn(), post: (...a) => state.formPost(...a),
                reset(...keys) { (keys.length ? keys : Object.keys(initial)).forEach((k) => { this[k] = initial[k]; }); },
            });
            return state.lastForm;
        },
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import Members from '../../../resources/js/Pages/Settings/Members.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const SECTIONS = [{ key: 'members', href: '/settings/members', labelKey: 'settings.section.members', icon: 'users' }];
const PRESETS = {
    parent: { todo: 'edit', wishlist: 'edit', finance: 'edit', health: 'none' },
    child: { todo: 'edit', wishlist: 'edit', finance: 'none', health: 'none' },
    grandparent: { todo: 'edit', wishlist: 'edit', finance: 'none', health: 'none' },
    guest: { todo: 'view', wishlist: 'none', finance: 'none', health: 'none' },
};
const MEMBERS = [{ id: 1, name: 'Anna', email: 'anna@example.test', isAdmin: true, isSelf: true, access: { todo: 'none', wishlist: 'none', finance: 'none', health: 'none' } }];
const future = new Date(Date.now() + 5 * 864e5).toISOString();
const INVITATIONS = [
    { id: 11, email: 'eva@example.test', preset: 'child', locale: 'sk', state: 'valid', expiresAt: future, mailFailed: true },
    { id: 12, email: 'old@example.test', preset: 'guest', locale: 'en', state: 'expired', expiresAt: future, mailFailed: false },
    { id: 13, email: 'gone@example.test', preset: 'parent', locale: 'pl', state: 'revoked', expiresAt: future, mailFailed: false },
];

let current;
function mountPage({ locale = 'en', invitations = INVITATIONS } = {}) {
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 1, name: 'Anna', email: 'anna@example.test', isAdmin: true }, access: {} },
        app: { env: 'local', styleGuide: false },
    };
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(Members, {
        props: { sections: SECTIONS, members: MEMBERS, presets: PRESETS, openMember: null, invitations },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, loadMessages(locale))] },
    });
    return current;
}
const dialog = () => document.body.querySelector('[role=dialog]');
const btn = (root, text) => [...root.querySelectorAll('button')].find((b) => b.textContent.trim() === text);
const aria = (label) => current.find(`button[aria-label="${label}"]`);
const tick = async () => { await nextTick(); await nextTick(); };

beforeEach(() => { routerMock.post.mockReset(); routerMock.delete.mockReset(); state.formPost = vi.fn(); useToast().toasts.splice(0); });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; });

describe('Invitations card', () => {
    it('lists invitations with preset, language and status tags and the mail failure warning', () => {
        mountPage();
        const text = current.text();
        expect(text).toContain('Invitations');
        expect(text).toContain('eva@example.test');
        expect(text).toContain('Child');
        expect(text).toContain('Language: Slovenčina');
        expect(text).toMatch(/Pending, expires/);
        expect(text).toContain('Email not sent');
        expect(text).toContain('Expired');
        expect(text).toContain('Revoked');
    });

    it('puts the email in the aria-label of each action and hides actions on revoked rows', () => {
        mountPage();
        for (const label of ['Resend invite to eva@example.test', 'Copy invite link for eva@example.test', 'Revoke invite for eva@example.test']) {
            expect(aria(label).exists()).toBe(true);
        }
        expect(aria('Resend invite to old@example.test').exists()).toBe(true);
        expect(current.find('button[aria-label$="gone@example.test"]').exists()).toBe(false);
    });

    it('shows the empty state with an invite action', () => {
        mountPage({ invitations: [] });
        expect(current.text()).toContain('No pending invitations');
        expect(current.text()).toContain('Invite a family member to get started.');
    });
});

describe('Invite dialog', () => {
    it('opens with email, starting access with its hint, language defaulting to the admin locale, and the help text', async () => {
        mountPage();
        await current.find('.members__header button').trigger('click');
        await tick();
        const d = dialog();
        expect(d.textContent).toContain('Invite a family member');
        expect(d.querySelector('input[type=email]')).not.toBeNull();
        expect(d.querySelectorAll('select')).toHaveLength(2);
        expect(d.querySelectorAll('select')[1].value).toBe('en');
        expect(d.querySelectorAll('select')[0].options).toHaveLength(4);
        expect(d.textContent).toContain('The invite works for 7 days and can be used once.');
        expect(d.textContent).toContain('Starting access');
        expect(d.textContent).toContain('To-do and Wishlist: edit. Finances and Health: none.');
        expect(btn(d, 'Send invite')).toBeTruthy();
        expect(document.activeElement).toBe(d.querySelector('input[type=email]'));
    });

    it('posts the form and switches to the result view with the link from flash data', async () => {
        mountPage();
        await current.find('.members__header button').trigger('click');
        await tick();
        const d = dialog();
        const input = d.querySelector('input[type=email]');
        input.value = 'new@example.test';
        input.dispatchEvent(new Event('input'));
        await tick();
        d.querySelector('form').dispatchEvent(new Event('submit', { cancelable: true }));
        expect(state.formPost).toHaveBeenCalledWith('/settings/invitations', expect.any(Object));
        expect(state.lastForm.email).toBe('new@example.test');

        mockProps.flash.data = { invite: { email: 'new@example.test', link: 'https://x.test/invite/abc', mailQueued: true, rotated: false } };
        state.formPost.mock.calls[0][1].onSuccess();
        await tick();
        const r = dialog();
        expect(r.textContent).toContain('Invite sent to new@example.test.');
        expect(r.querySelector('input[readonly]').value).toBe('https://x.test/invite/abc');
        expect(btn(r, 'Copy link')).toBeTruthy();
        expect(btn(r, 'Done')).toBeTruthy();
        expect(btn(r, 'Invite another')).toBeTruthy();

        Object.assign(navigator, { clipboard: { writeText: vi.fn().mockResolvedValue() } });
        btn(r, 'Copy link').click();
        await tick();
        expect(navigator.clipboard.writeText).toHaveBeenCalledWith('https://x.test/invite/abc');
        expect(useToast().toasts.map((x) => x.message)).toContain('Invite link copied');

        btn(r, 'Invite another').click();
        await tick();
        expect(dialog().querySelector('input[type=email]')).not.toBeNull();
    });

    it('shows a field error translated from the server key', async () => {
        mountPage();
        await current.find('.members__header button').trigger('click');
        await tick();
        state.lastForm.errors = { email: 'invite.dialog.error.exists' };
        await tick();
        expect(dialog().textContent).toContain('This email already belongs to a member');
    });

    it('shows the warning instead of the success text when the mail was not queued', async () => {
        mountPage();
        await current.find('.members__header button').trigger('click');
        await tick();
        mockProps.flash.data = { invite: { email: 'a@example.test', link: 'https://x.test/invite/z', mailQueued: false, rotated: false } };
        state.formPost.mockImplementation((u, o) => o.onSuccess());
        dialog().querySelector('form').dispatchEvent(new Event('submit', { cancelable: true }));
        await tick();
        expect(dialog().textContent).toContain('We could not send the email');
    });
});

it('defaults the language to the admin locale (pl)', async () => {
    mountPage({ locale: 'pl' });
    await current.find('.members__header button').trigger('click');
    await tick();
    expect(dialog().querySelectorAll('select')[1].value).toBe('pl');
});

describe('Row actions', () => {
    it('Copy link posts, then opens the result view with the rotated note; the link is dropped on close', async () => {
        mountPage();
        await aria('Copy invite link for eva@example.test').trigger('click');
        expect(routerMock.post).toHaveBeenCalledWith('/settings/invitations/11/link', {}, expect.any(Object));
        mockProps.flash.data = { invite: { email: 'eva@example.test', link: 'https://x.test/invite/rot', mailQueued: false, rotated: true } };
        routerMock.post.mock.calls[0][2].onSuccess();
        await tick();
        const r = dialog();
        expect(r.textContent).toContain('A new link was created. The previous link no longer works.');
        expect(r.querySelector('input[readonly]').value).toBe('https://x.test/invite/rot');
        btn(r, 'Done').click();
        await tick();
        expect(dialog()).toBeNull();
        expect(document.body.innerHTML).not.toContain('invite/rot');
    });

    it('Resend posts and shows the resent toast', async () => {
        mountPage();
        await aria('Resend invite to eva@example.test').trigger('click');
        expect(routerMock.post).toHaveBeenCalledWith('/settings/invitations/11/resend', {}, expect.any(Object));
        mockProps.flash.data = { invite: { email: 'eva@example.test', link: 'https://x.test/invite/r2', mailQueued: true, rotated: true } };
        routerMock.post.mock.calls[0][2].onSuccess();
        await tick();
        expect(useToast().toasts.map((x) => x.message)).toContain('Invite sent again to eva@example.test.');
        expect(dialog().textContent).toContain('A new link was created');
    });

    it('Revoke asks for confirmation, defaults focus to Keep invite and deletes on confirm', async () => {
        mountPage();
        await aria('Revoke invite for eva@example.test').trigger('click');
        await tick();
        const d = dialog();
        expect(d.textContent).toContain('Revoke the invite for eva@example.test? The link will stop working.');
        expect(document.activeElement.textContent.trim()).toBe('Keep invite');
        expect(routerMock.delete).not.toHaveBeenCalled();
        btn(d, 'Revoke invite').click();
        await tick();
        expect(routerMock.delete).toHaveBeenCalledWith('/settings/invitations/11', expect.any(Object));
        routerMock.delete.mock.calls[0][1].onSuccess();
        expect(useToast().toasts.map((x) => x.message)).toContain('Invite revoked');
    });

    it('Keep invite closes without deleting', async () => {
        mountPage();
        await aria('Revoke invite for eva@example.test').trigger('click');
        await tick();
        btn(dialog(), 'Keep invite').click();
        await tick();
        expect(routerMock.delete).not.toHaveBeenCalled();
        expect(dialog()).toBeNull();
    });
});

describe('Accessibility', () => {
    it.each(['en', 'sk', 'pl'])('has no axe violations in %s, light and dark: page, invite dialog, result view, revoke confirm', async (locale) => {
        for (const theme of ['light', 'dark']) {
            document.documentElement.dataset.theme = theme;
            mountPage({ locale });
            await expectNoAxeViolations(document.body);

            await current.find('.members__header button').trigger('click');
            await tick();
            await expectNoAxeViolations(document.body);

            mockProps.flash.data = { invite: { email: 'a@example.test', link: 'https://x.test/invite/z', mailQueued: true, rotated: true } };
            state.formPost.mockImplementation((u, o) => o.onSuccess());
            dialog().querySelector('form').dispatchEvent(new Event('submit', { cancelable: true }));
            await tick();
            await expectNoAxeViolations(document.body);
            current.unmount();
            document.body.innerHTML = '';

            mountPage({ locale });
            await current.find('button[aria-label$="eva@example.test"]:last-of-type').trigger('click');
            await tick();
            await expectNoAxeViolations(document.body);
            current.unmount();
            current = null;
            document.body.innerHTML = '';
        }
        delete document.documentElement.dataset.theme;
    });
});
