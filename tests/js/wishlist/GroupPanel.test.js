import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';

const calls = { post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() };
vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn(), post: (...a) => calls.post(...a), put: (...a) => calls.put(...a), patch: (...a) => calls.patch(...a), delete: (...a) => calls.delete(...a), visit: vi.fn(), on: vi.fn() },
}));

import { help } from '../../../resources/js/Help/helpStore.js';
import GroupPanel from '../../../resources/js/Wishlist/GroupPanel.vue';

const CANDIDATES = [{ id: 2, name: 'Mira' }, { id: 1, name: 'Adam' }, { id: 3, name: 'Zora' }];
const GROUP = { id: 9, name: 'Grandma 80', mode: 'gifts', is_member: true, can_manage: true, members: [{ id: 1, name: 'Adam' }, { id: 2, name: 'Mira' }] };

let current;
function mountPanel(props = {}, { locale = 'en', messages } = {}) {
    current = mount(GroupPanel, {
        props: { open: true, mode: 'create', group: null, candidates: CANDIDATES, viewerId: 2, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] },
    });
    return current;
}
beforeEach(() => {
    Object.values(calls).forEach((f) => f.mockReset());
    help.topics['wishlist.group_mode'] = { title: 'Group list mode', text: 'Two modes.' };
    help.topics['wishlist.group_members'] = { title: 'Group list members', text: 'Who is in.' };
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
});
afterEach(() => {
    vi.useRealTimers();
    current?.unmount();
    current = null;
    document.body.innerHTML = '';
    delete document.documentElement.dataset.theme;
});

const btn = (text) => [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === text);
const field = (label) => [...document.body.querySelectorAll('label')].find((l) => l.textContent.replace('*', '').trim() === label);
const input = (label) => document.getElementById(field(label).getAttribute('for'));
const checkbox = (label) => field(label).querySelector('input');
async function type(el, value) {
    el.value = value;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}
const heading = () => document.body.querySelector('h2.ui-panel__title').textContent;
const radios = () => [...document.body.querySelectorAll('input[type="radio"]')];

describe('create', () => {
    it('shows both modes with hints, two help tips and the viewer as a disabled checked member', async () => {
        mountPanel();
        await vi.advanceTimersByTimeAsync(60);
        expect(heading()).toBe('New group list');
        expect(radios().map((r) => r.checked)).toEqual([true, false]);
        expect(document.body.textContent).toContain('Others reserve gifts for this list.');
        expect(document.body.textContent).toContain('Only the group sees this list.');
        expect([...document.body.querySelectorAll('[data-help-topic]')].map((e) => e.dataset.helpTopic)).toEqual(['wishlist.group_mode', 'wishlist.group_members']);
        const me = checkbox('Mira (You)');
        expect(me.checked).toBe(true);
        expect(me.disabled).toBe(true);
        expect(checkbox('Adam').checked).toBe(false);
    });

    it('requires a name before posting', async () => {
        mountPanel();
        btn('Create list').click();
        await flushPromises();
        expect(document.body.textContent).toContain('Enter a name for the list.');
        expect(calls.post).not.toHaveBeenCalled();
    });

    it('posts name, mode and the other members only', async () => {
        mountPanel();
        await type(input('Name'), '  Cottage  ');
        radios()[1].click();
        checkbox('Zora').click();
        await flushPromises();
        btn('Create list').click();
        await flushPromises();
        expect(calls.post).toHaveBeenCalledWith('/wishlist/groups', { name: 'Cottage', mode: 'shared', member_ids: [3] }, expect.any(Object));
    });

    it('shows a server error key as translated text', async () => {
        mountPanel();
        await type(input('Name'), 'Cottage');
        btn('Create list').click();
        await flushPromises();
        calls.post.mock.calls[0][2].onError({ member_ids: 'wishlist.group.error.member_not_eligible' });
        await flushPromises();
        expect(document.body.textContent).toContain('Only family members with Wishlist edit access can be on a list.');
    });
});

describe('edit', () => {
    it('is prefilled and patches only name and mode, without a members call when members are unchanged', async () => {
        mountPanel({ mode: 'edit', group: GROUP });
        expect(heading()).toBe('Group list');
        expect(input('Name').value).toBe('Grandma 80');
        await type(input('Name'), 'Grandma 81');
        btn('Save list').click();
        await flushPromises();
        expect(calls.patch).toHaveBeenCalledWith('/wishlist/groups/9', { name: 'Grandma 81', mode: 'gifts' }, expect.any(Object));
        calls.patch.mock.calls[0][2].onSuccess();
        expect(calls.put).not.toHaveBeenCalled();
    });

    it('puts the full member set including the viewer when members changed', async () => {
        mountPanel({ mode: 'edit', group: GROUP });
        checkbox('Zora').click();
        await flushPromises();
        btn('Save list').click();
        await flushPromises();
        expect(calls.patch).not.toHaveBeenCalled();
        expect(calls.put).toHaveBeenCalledWith('/wishlist/groups/9/members', { member_ids: [1, 2, 3] }, expect.any(Object));
    });

    it('asks before changing the mode and sends confirm_mode_change only after confirming', async () => {
        mountPanel({ mode: 'edit', group: GROUP });
        radios()[1].click();
        await flushPromises();
        btn('Save list').click();
        await flushPromises();
        expect(calls.patch).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('All reservations on this list are cancelled.');
        btn('Change mode').click();
        await flushPromises();
        expect(calls.patch).toHaveBeenCalledWith('/wishlist/groups/9', { name: 'Grandma 80', mode: 'shared', confirm_mode_change: true }, expect.any(Object));
    });

    it('Keep as is closes the dialog without a request and restores the mode', async () => {
        mountPanel({ mode: 'edit', group: GROUP });
        radios()[1].click();
        await flushPromises();
        btn('Save list').click();
        await flushPromises();
        btn('Keep as is').click();
        await flushPromises();
        expect(calls.patch).not.toHaveBeenCalled();
        expect(radios()[0].checked).toBe(true);
    });

    it('leaves after confirmation', async () => {
        mountPanel({ mode: 'edit', group: GROUP });
        btn('Leave this list').click();
        await flushPromises();
        expect(calls.delete).not.toHaveBeenCalled();
        btn('Leave list').click();
        await flushPromises();
        expect(calls.delete).toHaveBeenCalledWith('/wishlist/groups/9/membership', expect.any(Object));
    });

    it('deletes after a confirmation that names the list', async () => {
        mountPanel({ mode: 'edit', group: GROUP });
        btn('Delete list').click();
        await flushPromises();
        expect(document.body.textContent).toContain('Grandma 80 and all its wishes will be deleted.');
        [...document.body.querySelectorAll('button')].filter((b) => b.textContent.trim() === 'Delete list').pop().click();
        await flushPromises();
        expect(calls.delete).toHaveBeenCalledWith('/wishlist/groups/9', expect.any(Object));
    });

    it('is read-only with only Leave for a member who cannot manage', async () => {
        mountPanel({ mode: 'edit', group: { ...GROUP, can_manage: false } });
        expect(input('Name').disabled).toBe(true);
        expect(btn('Save list')).toBeUndefined();
        expect(btn('Delete list')).toBeUndefined();
        expect(btn('Leave this list')).toBeTruthy();
    });
});

describe('accessibility', () => {
    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`has no axe violations (${locale}, ${dark ? 'dark' : 'light'})`, async () => {
                vi.useRealTimers();
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                mountPanel({}, { locale });
                await flushPromises();
                await expectNoAxeViolations(document.body);
                current.unmount();
                document.body.innerHTML = '';
                mountPanel({ mode: 'edit', group: GROUP }, { locale });
                await flushPromises();
                await expectNoAxeViolations(document.body);
            });
        }
    }

    it('renders pseudo-long copy without axe violations', async () => {
        vi.useRealTimers();
        mountPanel({ mode: 'edit', group: GROUP }, { locale: 'sk', messages: pseudoLong(loadMessages('sk')) });
        await flushPromises();
        await expectNoAxeViolations(document.body);
    });
});
