import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';

let mockProps = {};
vi.mock('@inertiajs/vue3', async () => {
    const { h, reactive } = await import('vue');
    return {
        usePage: () => ({ props: mockProps }),
        router: { get: vi.fn(), post: vi.fn(), visit: vi.fn(), on: vi.fn() },
        useForm: (data) => reactive({ ...data, processing: false, put: vi.fn() }),
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import Members from '../../../resources/js/Pages/Settings/Members.vue';

const SECTIONS = [
    { key: 'members', href: '/settings/members', labelKey: 'settings.section.members', icon: 'users' },
    { key: 'audit', href: '/settings/audit', labelKey: 'settings.section.audit', icon: 'scroll-text' },
    { key: 'appearance', href: '/settings/appearance', labelKey: 'settings.section.appearance', icon: 'palette' },
];
const PRESETS = {
    parent: { todo: 'edit', wishlist: 'edit', finance: 'edit', health: 'none' },
    child: { todo: 'edit', wishlist: 'edit', finance: 'none', health: 'none' },
    grandparent: { todo: 'edit', wishlist: 'edit', finance: 'none', health: 'none' },
    guest: { todo: 'view', wishlist: 'none', finance: 'none', health: 'none' },
};
const MEMBERS = [
    { id: 1, name: 'Anna', email: 'anna@example.test', isAdmin: true, isSelf: true, access: { todo: 'none', wishlist: 'none', finance: 'none', health: 'none' } },
    { id: 2, name: 'Bob', email: 'bob@example.test', isAdmin: false, isSelf: false, access: { todo: 'edit', wishlist: 'view', finance: 'none', health: 'none' } },
];

let current;
function mountPage({ locale = 'en', messages, ...props } = {}) {
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 1, name: 'Anna', email: 'anna@example.test', isAdmin: true }, access: { todo: 'none', wishlist: 'none', finance: 'none', health: 'none' } },
        app: { env: 'local', styleGuide: false },
    };
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(Members, {
        props: { sections: SECTIONS, members: MEMBERS, presets: PRESETS, openMember: null, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] },
    });
    return current;
}
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; });

const manage = (name) => current.find(`button[aria-label="Manage ${name}"]`);

describe('Members page', () => {
    it('shows the heading, the member list with role, you and status tags and a Manage button each', () => {
        mountPage();
        expect(current.find('h1').text()).toBe('Members and access');
        expect(current.text()).toContain('anna@example.test');
        expect(current.text()).toContain('Admin');
        expect(current.text()).toContain('Member');
        expect(current.text()).toContain('You');
        expect(current.text().match(/Active/g)).toHaveLength(2);
        expect(manage('Anna').text()).toBe('Manage');
        expect(manage('Bob').exists()).toBe(true);
        expect(current.text()).toContain('Invite member');
    });

    it('opens the member panel on the Permissions tab from Manage', async () => {
        mountPage();
        await manage('Bob').trigger('click');
        const panel = document.body.querySelector('[role=dialog], aside.ui-panel');
        expect(panel.textContent).toContain('Bob');
        expect(panel.textContent).toContain('bob@example.test');
        expect(panel.querySelector('[role=tab][aria-selected=true]').textContent.trim()).toBe('Permissions');
        expect(panel.querySelectorAll('select')).toHaveLength(5);
    });

    it('opens the panel straight from the openMember prop', () => {
        mountPage({ openMember: 2 });
        expect(document.body.textContent).toContain('Save permissions');
    });

    it('shows the read-only matrix with text and icon cells and opens a row on click', async () => {
        mountPage();
        await current.findAll('[role=tab]').find((t) => t.text() === 'Permission matrix').trigger('click');
        const table = current.find('table');
        expect(table.find('caption').text()).toBe('Permission matrix');
        expect(table.findAll('th').map((h) => h.text())).toEqual(['Members', 'To-do', 'Wishlist', 'Finances', 'Health', 'Settings']);
        expect(table.findAll('select, input')).toHaveLength(0);
        const bobRow = table.findAll('tbody tr')[1];
        expect(bobRow.text()).toContain('Edit');
        expect(bobRow.text()).toContain('View');
        expect(bobRow.text()).toContain('None');
        expect(bobRow.text()).toContain('No access');
        expect(table.findAll('tbody tr')[0].text()).toContain('Admin');
        expect(current.text()).toContain('None: cannot see the module.');
        expect(table.html()).not.toContain('ui-tag--danger');
        await bobRow.find('button').trigger('click');
        expect(document.body.textContent).toContain('Save permissions');
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations in %s, light and dark, with pseudo-long names', async (locale) => {
        for (const theme of ['light', 'dark']) {
            document.documentElement.dataset.theme = theme;
            const members = MEMBERS.map((m) => ({ ...m, name: `${m.name} ${'Wŵ'.repeat(20)}` }));
            mountPage({ locale, members });
            await expectNoAxeViolations(document.body);
            await current.findAll('[role=tab]')[1].trigger('click');
            await expectNoAxeViolations(document.body);
            await current.find('tbody button').trigger('click');
            await expectNoAxeViolations(document.body);
            current.unmount();
            current = null;
            document.body.innerHTML = '';
        }
        delete document.documentElement.dataset.theme;
    });

    it('holds with pseudo-long strings', async () => {
        mountPage({ messages: pseudoLong(loadMessages('en')) });
        await expectNoAxeViolations(document.body);
    });
});
