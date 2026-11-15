import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';

const put = vi.fn();
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        useForm: (data) => {
            const form = reactive({ ...data, processing: false, put: (...a) => put(JSON.parse(JSON.stringify(form)), ...a) });
            return form;
        },
    };
});

import WishlistManagersTab from '../../../resources/js/Components/Settings/WishlistManagersTab.vue';
import MemberPanel from '../../../resources/js/Components/Settings/MemberPanel.vue';

const MEMBERS = [
    { id: 1, name: 'Anna', email: 'a@example.test', wishlistManagerIds: [], access: {} },
    { id: 2, name: 'Bob', email: 'b@example.test', wishlistManagerIds: [1], access: {} },
    { id: 3, name: 'Cyril', email: 'c@example.test', wishlistManagerIds: [], access: {} },
];

let current;
function mountTab({ member = MEMBERS[1], members = MEMBERS, locale = 'en', messages } = {}) {
    current = mount(WishlistManagersTab, { props: { member, members }, attachTo: document.body, global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] } });
    return current;
}
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; put.mockReset(); });

describe('WishlistManagersTab', () => {
    it('lists the other members, excludes the member itself and pre-checks the saved managers', () => {
        mountTab();
        const boxes = current.findAll('input[type="checkbox"]');
        expect(boxes).toHaveLength(2);
        expect(current.findAll('label').map((l) => l.text())).not.toContain('Bob');
        expect(current.findAll('label').map((l) => l.text())).toEqual(['Anna', 'Cyril']);
        expect(boxes.map((b) => b.element.checked)).toEqual([true, false]);
        expect(current.text()).toContain("Chosen members can add and edit wishes on Bob's list.");
    });

    it('saves with a PUT of manager_ids, enabled only when changed', async () => {
        mountTab();
        const save = current.findAll('button').find((b) => b.text() === 'Save managers');
        expect(save.element.disabled).toBe(true);
        await current.findAll('input[type="checkbox"]')[1].setValue(true);
        expect(save.element.disabled).toBe(false);
        await current.find('form').trigger('submit');
        expect(put).toHaveBeenCalledTimes(1);
        const [form, url] = put.mock.calls[0];
        expect(url).toBe('/settings/members/2/wishlist-managers');
        expect([...form.manager_ids].sort()).toEqual([1, 3]);
    });

    it('sends an empty list when every manager is unchecked', async () => {
        mountTab();
        await current.findAll('input[type="checkbox"]')[0].setValue(false);
        await current.find('form').trigger('submit');
        expect(put.mock.calls[0][0].manager_ids).toEqual([]);
    });

    it('shows the none text when there are no other members', () => {
        mountTab({ members: [MEMBERS[1]] });
        expect(current.text()).toContain('There are no other members.');
        expect(current.findAll('input')).toHaveLength(0);
    });

    it('shows the danger alert when saving fails', async () => {
        put.mockImplementation((form, url, opts) => opts.onError({}));
        mountTab();
        await current.findAll('input[type="checkbox"]')[1].setValue(true);
        await current.find('form').trigger('submit');
        await flushPromises();
        expect(current.find('[role="alert"]').text()).toContain('We could not save the managers. Nothing was changed. Try again.');
    });

    it('is reachable as the Wishlist tab of the member panel', async () => {
        current = mount(MemberPanel, { props: { member: MEMBERS[1], members: MEMBERS, presets: {} }, attachTo: document.body, global: { plugins: [createAppI18n('en', loadMessages('en'))] } });
        const tab = [...document.body.querySelectorAll('[role="tab"]')].find((t) => t.textContent.trim() === 'Wishlist');
        expect(tab).toBeTruthy();
        tab.click();
        await flushPromises();
        expect(document.body.textContent).toContain('Wishlist managers');
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`has no axe violations (${locale}, ${dark ? 'dark' : 'light'})`, async () => {
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                mountTab({ locale });
                await expectNoAxeViolations(current.element);
                delete document.documentElement.dataset.theme;
            });
        }
    }

    it('has no axe violations with pseudo-long copy', async () => {
        mountTab({ locale: 'sk', messages: pseudoLong(loadMessages('sk')) });
        await expectNoAxeViolations(current.element);
    });
});
