import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

const del = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    router: { delete: (...a) => del(...a) },
    useForm: (data) => ({ ...data }),
}));

import SessionsTab from '../../../resources/js/Components/Settings/SessionsTab.vue';
import MemberPanel from '../../../resources/js/Components/Settings/MemberPanel.vue';

const MEMBER = { id: 7, name: 'Bob', email: 'bob@example.test', isAdmin: false, isSelf: false, access: { todo: 'edit', wishlist: 'edit', finance: 'none', health: 'none' } };
const NOW = new Date().toISOString();
const ROWS = [
    { id: 'a'.repeat(40), browser: 'Safari', os: 'iOS', mobile: true, ip: '203.0.113.5', lastActivity: NOW, isCurrent: false, staysSignedIn: true },
    { id: 'b'.repeat(40), browser: null, os: null, mobile: false, ip: '203.0.113.9', lastActivity: new Date(Date.now() - 3 * 3600e3).toISOString(), isCurrent: true, staysSignedIn: false },
];

let current;
function mockFetch(rows, ok = true) {
    globalThis.fetch = vi.fn().mockResolvedValue({ ok, json: async () => ({ sessions: rows }) });
}
async function mountTab(locale = 'en') {
    current = mount(SessionsTab, {
        props: { member: MEMBER },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, loadMessages(locale))] },
    });
    await flushPromises();
    return current;
}
beforeEach(() => mockFetch(ROWS));
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; del.mockReset(); });

const bodyButton = (text) => [...document.body.querySelectorAll('button')].filter((b) => b.textContent.trim() === text).at(-1);

describe('SessionsTab', () => {
    it('fetches JSON and renders one row per session with tags and aria labels', async () => {
        await mountTab();
        expect(fetch).toHaveBeenCalledWith('/settings/members/7/sessions', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const text = current.text();
        expect(text).toContain('Safari on iOS');
        expect(text).toContain('Unknown browser on unknown system');
        expect(text).toContain('203.0.113.5');
        expect(text).toContain('This device');
        expect(text).toContain('Stays signed in');
        const labels = current.findAll('button').map((b) => b.attributes('aria-label'));
        expect(labels).toContain('Revoke session: Safari on iOS');
    });

    it('shows skeletons while loading and an error state with retry on failure', async () => {
        mockFetch([], false);
        await mountTab();
        expect(current.find('[role=alert]').exists()).toBe(true);
        mockFetch(ROWS);
        await current.findAll('button').find((b) => b.text() === 'Try again').trigger('click');
        await flushPromises();
        expect(current.text()).toContain('Safari on iOS');
    });

    it('confirms before revoking one session, then sends DELETE and reloads', async () => {
        await mountTab();
        await current.find('button[aria-label="Revoke session: Safari on iOS"]').trigger('click');
        expect(document.body.textContent).toContain('Sign Bob out on Safari on iOS?');
        expect(del).not.toHaveBeenCalled();
        bodyButton('Revoke').click();
        await flushPromises();
        expect(del).toHaveBeenCalledTimes(1);
        expect(del.mock.calls[0][0]).toBe(`/settings/members/7/sessions/${'a'.repeat(40)}`);
        del.mock.calls[0][1].onFinish();
        await flushPromises();
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it('revokes all after confirmation', async () => {
        await mountTab();
        await current.findAll('button').find((b) => b.text() === 'Revoke all sessions').trigger('click');
        expect(document.body.textContent).toContain('Sign Bob out everywhere?');
        [...document.body.querySelectorAll('button')].filter((b) => b.textContent.trim() === 'Revoke all sessions').at(-1).click();
        await flushPromises();
        expect(del.mock.calls[0][0]).toBe('/settings/members/7/sessions');
    });

    it('hides Revoke all when the only session is the current one', async () => {
        mockFetch([ROWS[1]]);
        await mountTab();
        expect(current.findAll('button').some((b) => b.text() === 'Revoke all sessions')).toBe(false);
    });

    it('shows the empty state', async () => {
        mockFetch([]);
        await mountTab();
        expect(current.text()).toContain('No active sessions');
    });

    it.each(['en', 'sk', 'pl'])('panel with Sessions tab has no axe violations in %s, light and dark', async (locale) => {
        for (const theme of ['light', 'dark']) {
            document.documentElement.dataset.theme = theme;
            current = mount(MemberPanel, {
                props: { member: MEMBER, presets: {}, initialTab: 'sessions' },
                attachTo: document.body,
                global: { plugins: [createAppI18n(locale, loadMessages(locale))], provide: { 'fh-panel-mode': 'overlay' } },
            });
            await flushPromises();
            expect(document.body.textContent).toContain('203.0.113.5');
            await expectNoAxeViolations(document.body);
            current.unmount();
            current = null;
        }
        delete document.documentElement.dataset.theme;
    });
});
