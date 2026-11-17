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
        useForm: (data) => reactive({ ...data, processing: false, errors: {}, put: vi.fn(), post: vi.fn(), reset: vi.fn(), clearErrors: vi.fn() }),
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import Members from '../../../resources/js/Pages/Settings/Members.vue';
import ChildCreateDialog from '../../../resources/js/Components/Profile/ChildCreateDialog.vue';

const SECTIONS = [
    { key: 'members', href: '/settings/members', labelKey: 'settings.section.members', icon: 'users' },
    { key: 'audit', href: '/settings/audit', labelKey: 'settings.section.audit', icon: 'scroll-text' },
    { key: 'appearance', href: '/settings/appearance', labelKey: 'settings.section.appearance', icon: 'palette' },
];
const PRESETS = {
    parent: { todo: 'edit', wishlist: 'edit', finance: 'edit', health: 'none', shopping: 'edit', recipes: 'edit' },
    child: { todo: 'edit', wishlist: 'edit', finance: 'none', health: 'none', shopping: 'edit', recipes: 'edit' },
    grandparent: { todo: 'edit', wishlist: 'edit', finance: 'none', health: 'none', shopping: 'edit', recipes: 'edit' },
    guest: { todo: 'view', wishlist: 'none', finance: 'none', health: 'none', shopping: 'view', recipes: 'view' },
};
const MEMBERS = [
    { id: 1, name: 'Anna', email: 'anna@example.test', isAdmin: true, isSelf: true, access: { todo: 'none', wishlist: 'none', finance: 'none', health: 'none', shopping: 'none', recipes: 'none' } },
    { id: 2, name: 'Bob', email: 'bob@example.test', isAdmin: false, isSelf: false, access: { todo: 'edit', wishlist: 'view', finance: 'none', health: 'none', shopping: 'edit', recipes: 'edit' } },
];

const CHILD = { id: 3, name: 'Ema', email: null, isAdmin: false, isSelf: false, isChild: true, signInDisabled: true, photoVersion: null, managerNames: ['Anna', 'Bob'], wishlistManagerIds: [1, 2], access: { todo: 'edit', wishlist: 'edit', finance: 'none', health: 'none', shopping: 'edit', recipes: 'edit' } };

let current;
function mountPage({ locale = 'en', messages, ...props } = {}) {
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 1, name: 'Anna', email: 'anna@example.test', isAdmin: true }, access: { todo: 'none', wishlist: 'none', finance: 'none', health: 'none', shopping: 'none', recipes: 'none' } },
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
        expect(panel.querySelectorAll('select')).toHaveLength(7);
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
        expect(table.findAll('th').map((h) => h.text())).toEqual(['Members', 'To-do', 'Wishlist', 'Finances', 'Health', 'Shopping', 'Recipes', 'Settings']);
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

describe('Children on the Members page', () => {
    const members = [...MEMBERS, CHILD];
    const tabs = () => [...document.body.querySelectorAll('.ui-panel [role=tab]')].map((t) => t.textContent.trim());

    it('shows Invite member and Add child in the header and opens the add-child dialog', async () => {
        mountPage({ members, eligibleParents: [{ id: 1, name: 'Anna' }, { id: 2, name: 'Bob' }] });
        const buttons = current.findAll('.members__header button').map((b) => b.text());
        expect(buttons).toEqual(['Invite member', 'Add child']);
        await current.findAll('.members__header button')[1].trigger('click');
        expect(document.body.querySelector('[role=dialog]').textContent).toContain('Add a child');
    });

    it('shows a child row with the Child and No sign-in tags and Managed by instead of an e-mail', () => {
        mountPage({ members });
        const row = current.findAll('li').find((li) => li.text().includes('Ema'));
        expect(row.text()).toContain('Managed by Anna, Bob');
        expect(row.text()).toContain('Child');
        expect(row.text()).toContain('No sign-in');
        expect(row.text()).not.toContain('Active');
        expect(row.text()).not.toContain('@');
        expect(manage('Ema').exists()).toBe(true);
        const adult = current.findAll('li').find((li) => li.text().includes('Bob'));
        expect(adult.text()).toContain('bob@example.test');
        expect(adult.text()).not.toContain('No sign-in');
    });

    it('opens a child on its Profile tab with only Profile, Permissions and Wishlist tabs and the fixed sign-in switch', async () => {
        mountPage({ members, eligibleParents: [{ id: 2, name: 'Bob' }] });
        await manage('Ema').trigger('click');
        const panel = document.body.querySelector('[role=dialog], aside.ui-panel');
        expect(tabs()).toEqual(['Profile', 'Permissions', 'Wishlist']);
        expect(panel.querySelector('[role=tab][aria-selected=true]').textContent.trim()).toBe('Profile');
        expect(panel.textContent).toContain('No e-mail, cannot sign in.');
        expect(panel.textContent).toContain('Direct sign-in disabled');
        const sw = panel.querySelector('input[role=switch]');
        expect(sw.disabled).toBe(true);
        expect(sw.checked).toBe(true);
        expect(panel.querySelector('input[autocomplete="name"], input[value="Ema"]')).not.toBeNull();
    });

    it('offers only eligible parents as managers of a child', async () => {
        mountPage({ members, eligibleParents: [{ id: 2, name: 'Bob' }] });
        await manage('Ema').trigger('click');
        await [...document.body.querySelectorAll('[role=tab]')].find((t) => t.textContent.trim() === 'Wishlist').click();
        await Promise.resolve();
        const labels = [...document.body.querySelectorAll('[role=tabpanel]:not([hidden]) label')].map((l) => l.textContent.trim());
        expect(labels).toContain('Bob');
        expect(labels).not.toContain('Anna');
    });

    it('keeps the adult panel unchanged: e-mail, Permissions, Wishlist, Sessions and Security tabs', async () => {
        mountPage({ members });
        await manage('Bob').trigger('click');
        expect(tabs()).toEqual(['Permissions', 'Wishlist', 'Sessions', 'Security']);
    });

    it('opens the new child, sends its photo to the photo endpoint and shows a failed upload in the panel', async () => {
        const { router } = await import('@inertiajs/vue3');
        router.post.mockClear();
        mountPage({ members, eligibleParents: [{ id: 2, name: 'Bob' }] });
        const file = new File(['x'], 'ema.jpg', { type: 'image/jpeg' });
        current.findComponent(ChildCreateDialog).vm.$emit('created', { id: 3, photo: file });
        await new Promise((r) => setTimeout(r));
        const panel = document.body.querySelector('.ui-panel');
        expect(panel.querySelector('[role=tab][aria-selected=true]').textContent.trim()).toBe('Profile');
        const [url, data, options] = router.post.mock.calls[0];
        expect(url).toBe('/people/3/photo');
        expect(data.photo).toBe(file);
        expect(options.forceFormData).toBe(true);
        expect(document.body.querySelector('[data-testid=photo-failed]')).toBeNull();
        options.onError({ photo: 'profile.photo.err_size' });
        await new Promise((r) => setTimeout(r));
        expect(document.body.querySelector('[data-testid=photo-failed]').textContent).toContain('The child was added, but the photo was not saved.');
        expect(panel.textContent).toContain('The picture is larger than 5 MB.');
    });

    it('shows the Make a normal member card on a child, posts the e-mail and hides it from adults', async () => {
        mountPage({ members });
        await manage('Ema').trigger('click');
        const panel = document.body.querySelector('.ui-panel');
        expect(panel.textContent).toContain('Make a normal member');
        expect(panel.textContent).toContain('Send invitation');
        expect(panel.querySelector('[data-testid=convert-pending]')).toBeNull();
        const form = [...panel.querySelectorAll('form')].find((f) => f.textContent.includes('Send invitation'));
        expect(form.querySelector('input[type=email]')).not.toBeNull();
        await form.dispatchEvent(new Event('submit'));
        await new Promise((r) => setTimeout(r));
        current.unmount();
        current = null;
        document.body.innerHTML = '';

        mountPage({ members });
        await manage('Bob').trigger('click');
        expect(document.body.querySelector('.ui-panel').textContent).not.toContain('Make a normal member');
    });

    it('marks a child with an open invitation as Invited and shows the pending address', async () => {
        const invited = [...MEMBERS, { ...CHILD, invitation: { id: 9, email: 'ema@example.test', expiresAt: '2026-12-01T10:00:00Z' } }];
        mountPage({ members: invited });
        const row = current.findAll('li').find((li) => li.text().includes('Ema'));
        expect(row.text()).toContain('Invited');
        await row.find('button').trigger('click');
        const panel = document.body.querySelector('.ui-panel');
        expect(panel.querySelector('[data-testid=convert-pending]').textContent).toContain('Invitation sent to ema@example.test');
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations for the convert card with a pending invitation in %s, light and dark', async (locale) => {
        for (const theme of ['light', 'dark']) {
            document.documentElement.dataset.theme = theme;
            const invited = { ...CHILD, name: `Ema ${'Wŵ'.repeat(20)}`, invitation: { id: 9, email: `${'long'.repeat(12)}@example.test`, expiresAt: '2026-12-01T10:00:00Z' } };
            mountPage({ locale, members: [...MEMBERS, invited], eligibleParents: [{ id: 2, name: 'Bob' }] });
            await current.findAll('li').find((li) => li.text().includes('Ema')).find('button').trigger('click');
            await expectNoAxeViolations(document.body);
            current.unmount();
            current = null;
            document.body.innerHTML = '';
        }
        delete document.documentElement.dataset.theme;
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations for the child row and panel in %s, light and dark', async (locale) => {
        for (const theme of ['light', 'dark']) {
            document.documentElement.dataset.theme = theme;
            mountPage({ locale, members: [...MEMBERS, { ...CHILD, name: `Ema ${'Wŵ'.repeat(20)}` }], eligibleParents: [{ id: 2, name: 'Bob' }] });
            await expectNoAxeViolations(document.body);
            await current.findAll('li').find((li) => li.text().includes('Ema')).find('button').trigger('click');
            await expectNoAxeViolations(document.body);
            current.unmount();
            current = null;
            document.body.innerHTML = '';
        }
        delete document.documentElement.dataset.theme;
    });
});
