import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

const calls = { post: vi.fn(), patch: vi.fn() };
vi.mock('@inertiajs/vue3', () => ({
    router: { post: (...a) => calls.post(...a), patch: (...a) => calls.patch(...a), delete: vi.fn(), get: vi.fn() },
}));

import TaskDetailPanel from '../../../resources/js/Todo/TaskDetailPanel.vue';

const ME = 2;
const MEMBERS = [{ id: 1, name: 'Zora' }, { id: 2, name: 'Mira' }, { id: 3, name: 'Adam' }];
const task = (extra = {}) => ({
    id: 7, title: 'T', notes: null, status: 'todo', visibility: 'family', creatorId: ME, assigneeId: ME, dueDate: null, dueTime: null,
    isRecurring: false, completedAt: null, memberIds: [], abilities: { edit: true, status: true, delete: true, visibility: true }, ...extra,
});

let current;
function mountPanel({ mode = 'edit', t = task(), locale = 'en' } = {}) {
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(TaskDetailPanel, {
        props: { open: true, mode, task: mode === 'create' ? null : t, members: MEMBERS, me: ME, timezone: 'Europe/Bratislava' },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, loadMessages(locale))] },
    });
    return current;
}
beforeEach(() => Object.values(calls).forEach((f) => f.mockReset()));
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; });

const radios = () => [...document.body.querySelectorAll('input[type="radio"][name$="-visibility"]')];
const pick = async (value) => {
    const r = radios().find((x) => x.value === value);
    r.checked = true;
    r.dispatchEvent(new Event('change', { bubbles: true }));
    await flushPromises();
};
const assignee = () => [...document.body.querySelectorAll('label')].find((l) => l.textContent.trim() === 'Assigned to');
const assigneeSelect = () => document.getElementById(assignee().getAttribute('for'));
const optionValues = () => [...assigneeSelect().querySelectorAll('option')].map((o) => o.value);
const checkboxes = () => [...document.body.querySelectorAll('fieldset fieldset input[type="checkbox"]')];
const check = async (i, on = true) => {
    const c = checkboxes()[i];
    c.checked = on;
    c.dispatchEvent(new Event('change', { bubbles: true }));
    await flushPromises();
};
const save = () => [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Save task');

describe('VisibilityField in the panel', () => {
    it('shows three radio cards with hints, Family by default on create', async () => {
        mountPanel({ mode: 'create' });
        await flushPromises();
        expect(radios().map((r) => [r.value, r.checked])).toEqual([['personal', false], ['family', true], ['selected', false]]);
        expect(document.body.textContent).toContain('Only you can see it. Admins cannot see it either.');
        expect(document.body.textContent).toContain('Everyone with access to tasks can see it.');
        expect(document.body.textContent).toContain('You and the people you choose can see it.');
    });

    it('Selected reveals members except the creator and limits the assignee options', async () => {
        mountPanel({ mode: 'create' });
        await flushPromises();
        await pick('selected');
        expect(checkboxes()).toHaveLength(2);
        expect(document.body.textContent).toContain('Choose members');
        expect(optionValues()).toEqual(['2', '']);
        await check(1);
        expect(optionValues()).toEqual(['2', '3', '']);
    });

    it('an assignee that drops out of the chosen members resets to the creator', async () => {
        mountPanel({ mode: 'edit', t: task({ visibility: 'selected', memberIds: [ME, 3], assigneeId: 3 }) });
        await flushPromises();
        expect(assigneeSelect().value).toBe('3');
        await check(1, false);
        expect(assigneeSelect().value).toBe('2');
    });

    it('Personal disables the assignee select, sets the current user and shows the hint', async () => {
        mountPanel({ mode: 'create' });
        await flushPromises();
        await pick('personal');
        expect(assigneeSelect().disabled).toBe(true);
        expect(assigneeSelect().value).toBe('2');
        expect(document.body.textContent).toContain('Personal tasks stay with you.');
    });

    it('sends visibility and member_ids when the creator saves', async () => {
        mountPanel({ mode: 'edit' });
        await flushPromises();
        await pick('selected');
        await check(0);
        save().click();
        await flushPromises();
        expect(calls.patch).toHaveBeenCalledWith('/todo/tasks/7', expect.objectContaining({ visibility: 'selected', member_ids: [1] }), expect.any(Object));
    });

    it('shows selected_none inline from the server', async () => {
        calls.post.mockImplementation((url, data, opts) => opts.onError?.({ member_ids: 'todo.error.selected_none' }));
        mountPanel({ mode: 'create' });
        await flushPromises();
        const title = document.body.querySelector('input[type="text"], input:not([type])');
        title.value = 'x';
        title.dispatchEvent(new Event('input', { bubbles: true }));
        await pick('selected');
        save().click();
        await flushPromises();
        expect(document.body.textContent).toContain('Choose at least one member');
    });

    it('is read-only for a non-creator editor and omits visibility from the payload', async () => {
        calls.patch.mockImplementation((url, data, opts) => opts.onSuccess?.());
        mountPanel({ mode: 'edit', t: task({ creatorId: 1, visibility: 'selected', memberIds: [1, 2], abilities: { edit: true, status: true, delete: false, visibility: false } }) });
        await flushPromises();
        expect(radios()).toHaveLength(0);
        expect(document.querySelector('[data-testid="visibility-readonly"]').textContent).toContain('Selected members');
        const title = document.body.querySelector('input[type="text"], input:not([type])');
        title.value = 'Renamed';
        title.dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();
        save().click();
        await flushPromises();
        const sent = calls.patch.mock.calls[0][1];
        expect(sent).not.toHaveProperty('visibility');
        expect(sent).not.toHaveProperty('member_ids');
    });

    for (const visibility of ['personal', 'family', 'selected']) {
        for (const locale of ['en', 'sk', 'pl']) {
            for (const theme of ['light', 'dark']) {
                it(`${visibility} has no axe violations (${locale}, ${theme})`, async () => {
                    document.documentElement.dataset.theme = theme;
                    mountPanel({ mode: 'edit', locale, t: task({ visibility, memberIds: visibility === 'selected' ? [ME, 3] : [] }) });
                    await flushPromises();
                    await expectNoAxeViolations(document.body);
                    delete document.documentElement.dataset.theme;
                });
            }
        }
    }
});
