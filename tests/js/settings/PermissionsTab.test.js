import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';

const put = vi.fn();
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        useForm: (data) => {
            const form = reactive({ ...data, processing: false, put: (...a) => put(form, ...a) });
            return form;
        },
    };
});

import PermissionsTab from '../../../resources/js/Components/Settings/PermissionsTab.vue';
import MemberPanel from '../../../resources/js/Components/Settings/MemberPanel.vue';

const PRESETS = {
    parent: { todo: 'edit', wishlist: 'edit', finance: 'edit', health: 'none' },
    child: { todo: 'edit', wishlist: 'edit', finance: 'none', health: 'none' },
    grandparent: { todo: 'edit', wishlist: 'edit', finance: 'none', health: 'none' },
    guest: { todo: 'view', wishlist: 'none', finance: 'none', health: 'none' },
};
const MEMBER = { id: 7, name: 'Bob', email: 'bob@example.test', isAdmin: false, isSelf: false, access: { todo: 'edit', wishlist: 'edit', finance: 'none', health: 'none' } };

let current;
function mountTab({ locale = 'en', member = MEMBER, messages } = {}) {
    current = mount(PermissionsTab, {
        props: { member, presets: PRESETS },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] },
    });
    return current;
}
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; put.mockReset(); });

const button = (text) => current.findAll('button').find((b) => b.text() === text);
const selects = () => current.findAll('select').slice(1); // first is the preset select

describe('PermissionsTab', () => {
    it('shows four module selects with member-specific aria labels and a static settings row', () => {
        mountTab();
        expect(selects()).toHaveLength(4);
        expect(selects()[2].attributes('aria-label')).toBe('Bob: Finances');
        expect(selects().map((s) => s.element.value)).toEqual(['edit', 'edit', 'none', 'none']);
        expect(current.text()).toContain('Admins only');
        expect(current.text().match(/Restricted by default/g)).toHaveLength(2);
    });

    it('shows the admin notes on an admin viewing themselves', () => {
        mountTab({ member: { ...MEMBER, isAdmin: true, isSelf: true } });
        expect(current.text()).toContain('Always allowed');
        expect(current.text()).toContain('Admins do not get Health or Finances automatically.');
        expect(current.text()).toContain('You are the admin and always keep access to settings.');
    });

    it('disables Save permissions until something changes and Cancel reverts', async () => {
        mountTab();
        expect(button('Save permissions').element.disabled).toBe(true);
        await selects()[1].setValue('view');
        expect(button('Save permissions').element.disabled).toBe(false);
        expect(current.emitted('dirty').at(-1)).toEqual([true]);
        await button('Cancel').trigger('click');
        expect(selects()[1].element.value).toBe('edit');
        expect(button('Save permissions').element.disabled).toBe(true);
    });

    it('applies a preset into the selects without saving, then lets the admin adjust', async () => {
        mountTab();
        await current.findAll('select')[0].setValue('guest');
        expect(current.text()).toContain('To-do: view only. Everything else: none.');
        await button('Apply preset').trigger('click');
        expect(selects().map((s) => s.element.value)).toEqual(['view', 'none', 'none', 'none']);
        expect(put).not.toHaveBeenCalled();
        expect(current.text()).toContain('Preset applied. Adjust anything, then save.');
        await selects()[1].setValue('edit');
        expect(selects()[1].element.value).toBe('edit');
    });

    it('saves the four values with one PUT', async () => {
        mountTab();
        await selects()[2].setValue('view');
        await current.find('form').trigger('submit');
        expect(put).toHaveBeenCalledTimes(1);
        const [form, url] = put.mock.calls[0];
        expect(url).toBe('/settings/members/7/permissions');
        expect({ todo: form.todo, wishlist: form.wishlist, finance: form.finance, health: form.health }).toEqual({ todo: 'edit', wishlist: 'edit', finance: 'view', health: 'none' });
    });

    it('shows a danger alert on server error and keeps the selections', async () => {
        mountTab();
        await selects()[2].setValue('view');
        await current.find('form').trigger('submit');
        put.mock.calls[0][2].onError();
        await current.vm.$nextTick();
        expect(current.find('[role=alert]').text()).toContain('We could not save the permissions');
        expect(selects()[2].element.value).toBe('view');
    });

    it('never colours none as danger', () => {
        mountTab();
        expect(current.html()).not.toContain('ui-tag--danger');
        expect(current.html()).not.toContain('ui-alert--danger');
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations in %s, light and dark', async (locale) => {
        for (const theme of ['light', 'dark']) {
            document.documentElement.dataset.theme = theme;
            mountTab({ locale });
            await selects()[0].setValue('view');
            await button(loadMessages(locale).permissions.apply_label).trigger('click');
            await expectNoAxeViolations(document.body);
            current.unmount();
            current = null;
        }
        delete document.documentElement.dataset.theme;
    });

    it('holds with pseudo-long strings', async () => {
        mountTab({ messages: pseudoLong(loadMessages('en')) });
        await expectNoAxeViolations(document.body);
    });
});

describe('MemberPanel discard guard', () => {
    function mountPanel() {
        current = mount(MemberPanel, {
            props: { member: MEMBER, presets: PRESETS },
            attachTo: document.body,
            global: { plugins: [createAppI18n('en', loadMessages('en'))], provide: { 'fh-panel-mode': 'overlay' } },
        });
        return current;
    }
    const inBody = (text) => [...document.body.querySelectorAll('button')].find((b) => (b.getAttribute('aria-label') ?? b.textContent).trim() === text);

    it('closes straight away when nothing changed', async () => {
        mountPanel();
        inBody('Close').click();
        await current.vm.$nextTick();
        expect(current.emitted('close')).toHaveLength(1);
    });

    it('asks before discarding unsaved changes, with Keep editing as the safe choice', async () => {
        mountPanel();
        const select = document.body.querySelectorAll('select')[2];
        select.value = 'view';
        select.dispatchEvent(new Event('change'));
        await current.vm.$nextTick();
        inBody('Close').click();
        await current.vm.$nextTick();
        expect(current.emitted('close')).toBeUndefined();
        expect(document.body.textContent).toContain('Your permission changes for Bob are not saved.');
        inBody('Keep editing').click();
        await current.vm.$nextTick();
        expect(current.emitted('close')).toBeUndefined();
        inBody('Close').click();
        await current.vm.$nextTick();
        inBody('Discard').click();
        await current.vm.$nextTick();
        expect(current.emitted('close')).toHaveLength(1);
    });
});
