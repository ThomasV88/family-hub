import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';

const put = vi.fn();
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: { locale: 'en', flash: {}, auth: { user: { id: 1, name: 'Anna', email: 'a@example.test', isAdmin: false }, access: {} }, app: { env: 'local' } } }),
        router: { put: (...a) => put(...a), post: vi.fn(), visit: vi.fn(), on: vi.fn() },
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});
const push = vi.hoisted(() => ({
    isPushSupported: vi.fn(() => true), isIosNotInstalled: vi.fn(() => false), currentSubscription: vi.fn(async () => null),
    enable: vi.fn(async () => ({ ok: true })), disable: vi.fn(async () => ({ ok: true })), sendTest: vi.fn(async () => ({ ok: true })), resync: vi.fn(async () => {}),
}));
vi.mock('../../../resources/js/push.js', () => push);

import Notifications from '../../../resources/js/Pages/Settings/Notifications.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
const SECTIONS = [{ key: 'notifications', href: '/settings/notifications', labelKey: 'notifications.nav', icon: 'bell' }];
let current;
function mountPage({ locale = 'en', props = {} } = {}) {
    current = mount(Notifications, {
        props: { sections: SECTIONS, email: 'a@example.test', preferences: { mail: true, push: false }, vapidPublicKey: 'KEY', ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, load(locale))] },
    });
    return current;
}
beforeEach(() => {
    window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
    window.Notification = { permission: 'default', requestPermission: vi.fn() };
    globalThis.Notification = window.Notification;
    push.isPushSupported.mockReturnValue(true);
    push.isIosNotInstalled.mockReturnValue(false);
    push.currentSubscription.mockResolvedValue(null);
});
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; vi.clearAllMocks(); });

describe('Notifications page', () => {
    it('renders heading, intro, switches and the email hint', async () => {
        mountPage();
        await flushPromises();
        expect(current.find('h1').text()).toBe('Notifications');
        expect(current.text()).toContain('Sent to a@example.test.');
        expect(current.find('[data-testid=switch-mail]').element.checked).toBe(true);
        expect(current.find('[data-testid=switch-push]').element.checked).toBe(false);
    });

    it('saves a switch immediately and reverts with an alert on failure', async () => {
        mountPage();
        await current.find('[data-testid=switch-push]').setValue(true);
        expect(put).toHaveBeenCalledWith('/settings/notifications/push', { enabled: true }, expect.objectContaining({ preserveScroll: true }));
        put.mock.calls[0][2].onError({});
        await flushPromises();
        expect(current.find('[data-testid=switch-push]').element.checked).toBe(false);
        expect(current.text()).toContain('We could not save this setting.');
    });

    it('warns when both channels are off', async () => {
        mountPage({ props: { preferences: { mail: false, push: false } } });
        expect(current.find('[data-testid=both-off]').text()).toContain('You will not get task reminders');
    });

    it('never asks for permission on mount, and resyncs only when granted', async () => {
        mountPage();
        await flushPromises();
        expect(window.Notification.requestPermission).not.toHaveBeenCalled();
        expect(push.resync).not.toHaveBeenCalled();
        current.unmount();
        window.Notification.permission = 'granted';
        mountPage();
        await flushPromises();
        expect(push.resync).toHaveBeenCalledWith('KEY');
    });

    it('enables push from the button and sets the preference on', async () => {
        mountPage();
        await flushPromises();
        expect(current.text()).toContain('Push is off for this device.');
        push.currentSubscription.mockResolvedValue({});
        await current.find('[data-testid=push-enable]').trigger('click');
        await flushPromises();
        expect(push.enable).toHaveBeenCalledWith('KEY');
        expect(current.text()).toContain('Push is on for this device.');
        expect(put).toHaveBeenCalledWith('/settings/notifications/push', { enabled: true }, expect.anything());
    });

    it('shows enable failure text', async () => {
        push.enable.mockResolvedValueOnce({ ok: false, reason: 'server' });
        mountPage();
        await flushPromises();
        await current.find('[data-testid=push-enable]').trigger('click');
        await flushPromises();
        expect(current.find('[data-testid=push-failure]').text()).toContain('We could not turn on push');
    });

    it('offers test and turn off when enabled', async () => {
        push.currentSubscription.mockResolvedValue({});
        mountPage();
        await flushPromises();
        await current.find('[data-testid=push-test]').trigger('click');
        await flushPromises();
        expect(push.sendTest).toHaveBeenCalled();
        expect(useToast().toasts.some((x) => x.message === 'Test notification sent.')).toBe(true);
        push.sendTest.mockResolvedValueOnce({ ok: false });
        await current.find('[data-testid=push-test]').trigger('click');
        await flushPromises();
        expect(current.text()).toContain('We could not send the test.');
        await current.find('[data-testid=push-disable]').trigger('click');
        await flushPromises();
        expect(push.disable).toHaveBeenCalled();
        expect(current.text()).toContain('Push is off for this device.');
    });

    it('shows the iOS install hint without a button', async () => {
        push.isIosNotInstalled.mockReturnValue(true);
        mountPage();
        await flushPromises();
        expect(current.find('[data-testid=state-ios]').text()).toContain('Install the app to get push on iPhone and iPad');
        expect(current.find('[data-testid=push-enable]').exists()).toBe(false);
    });

    it('shows unsupported, unavailable and denied states without a button', async () => {
        push.isPushSupported.mockReturnValue(false);
        mountPage();
        expect(current.find('[data-testid=state-unsupported]').exists()).toBe(true);
        expect(current.find('[data-testid=push-enable]').exists()).toBe(false);
        current.unmount();
        push.isPushSupported.mockReturnValue(true);
        mountPage({ props: { vapidPublicKey: null } });
        expect(current.find('[data-testid=state-unavailable]').exists()).toBe(true);
        current.unmount();
        window.Notification.permission = 'denied';
        mountPage();
        expect(current.find('[data-testid=state-denied]').text()).toContain('blocked for this site');
        expect(current.find('[data-testid=push-enable]').exists()).toBe(false);
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations in %s, light and dark, in each device state', async (locale) => {
        const states = [
            () => {},
            () => push.currentSubscription.mockResolvedValue({}),
            () => push.isIosNotInstalled.mockReturnValue(true),
            () => { window.Notification.permission = 'denied'; },
            () => push.isPushSupported.mockReturnValue(false),
        ];
        for (const theme of ['light', 'dark']) {
            document.documentElement.dataset.theme = theme;
            for (const setup of states) {
                window.Notification.permission = 'default';
                push.isPushSupported.mockReturnValue(true);
                push.isIosNotInstalled.mockReturnValue(false);
                push.currentSubscription.mockResolvedValue(null);
                setup();
                mountPage({ locale, props: { preferences: { mail: false, push: false } } });
                await flushPromises();
                await expectNoAxeViolations(document.body);
                current.unmount();
                current = null;
            }
        }
        delete document.documentElement.dataset.theme;
    });
});
