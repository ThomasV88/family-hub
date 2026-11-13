import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

let mockProps = {};
let mockUrl = '/todo';
const calls = { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() };
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: mockProps, get url() { return mockUrl; } }),
        router: {
            get: (...a) => calls.get(...a),
            post: (...a) => calls.post(...a),
            patch: (...a) => calls.patch(...a),
            delete: (...a) => calls.delete(...a),
            visit: vi.fn(),
            on: vi.fn(),
        },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import Index from '../../../resources/js/Pages/Todo/Index.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const ME = 2;
const MEMBERS = [{ id: 1, name: 'Zora' }, { id: 2, name: 'Mira' }, { id: 3, name: 'Adam' }];
const ALL = { edit: true, status: true, delete: true, visibility: true };
const task = (id, extra = {}) => ({
    id, title: `Task ${id}`, notes: null, status: 'todo', visibility: 'family', creatorId: ME, assigneeId: ME,
    dueDate: null, dueTime: null, isRecurring: false, completedAt: null, memberIds: [], abilities: ALL, ...extra,
});
const TASKS = [
    task(1, { notes: 'Some notes', dueDate: '2026-11-20', dueTime: '18:30' }),
    task(2, { assigneeId: 3, creatorId: 3, title: 'Adam chore', abilities: { edit: false, status: true, delete: false, visibility: false } }),
    task(3, { assigneeId: 3, creatorId: 1, title: 'Locked', abilities: { edit: false, status: false, delete: false, visibility: false } }),
];

let current;
function mountBoard({ url = '/todo', locale = 'en', props = {} } = {}) {
    mockUrl = url;
    window.innerWidth = 1024;
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: ME, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'edit', wishlist: 'none', finance: 'none', health: 'none' } },
        app: { env: 'local', styleGuide: false },
    };
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(Index, {
        props: { timezone: 'Europe/Bratislava', today: '2026-11-13', canEdit: true, members: MEMBERS, tasks: TASKS, selectedTaskMissing: false, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, loadMessages(locale))] },
    });
    return current;
}
beforeEach(() => { mockUrl = '/todo'; Object.values(calls).forEach((f) => f.mockReset()); useToast().toasts.splice(0); });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; });

const panel = () => document.body.querySelector('[data-testid="task-panel"]');
const byText = (text) => [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === text);
const field = (label) => [...document.body.querySelectorAll('label')].find((l) => l.textContent.replace('*', '').trim() === label);
const input = (label) => document.getElementById(field(label).getAttribute('for'));
async function type(el, value) {
    el.value = value;
    el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
    await flushPromises();
}

describe('Task panel', () => {
    it('opens by setting ?task= when a card is clicked', async () => {
        mountBoard();
        await current.findAll('[data-testid="task-card"] button.task-card__title')[0].trigger('click');
        expect(calls.get).toHaveBeenCalledWith('/todo', { task: 1 }, expect.objectContaining({ preserveState: true, preserveScroll: true, replace: true }));
        expect(panel()).toBeNull();
    });

    it('create mode: New task heading, status To do, assignee me, save disabled until a title is typed', async () => {
        mountBoard({ url: '/todo?task=new' });
        await flushPromises();
        expect(document.body.querySelector('h2.ui-panel__title').textContent).toBe('New task');
        expect(input('Assigned to').value).toBe('2');
        expect([...document.querySelectorAll('[role="radiogroup"] button')].map((b) => b.getAttribute('aria-pressed'))).toEqual(['true', 'false', 'false']);
        expect(byText('Save task').disabled).toBe(true);
        await type(input('Title'), 'Water plants');
        expect(byText('Save task').disabled).toBe(false);
    });

    it('create mode posts the full payload and closes on success', async () => {
        calls.post.mockImplementation((url, data, opts) => { opts.onStart?.(); opts.onSuccess?.(); opts.onFinish?.(); });
        mountBoard({ url: '/todo?task=new&assignee=3' });
        await flushPromises();
        expect(input('Assigned to').value).toBe('3');
        await type(input('Title'), '  Buy bread ');
        await type(input('Due date'), '2026-12-01');
        const time = document.querySelector('input[role="switch"]');
        time.checked = true;
        time.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();
        await type(input('Time'), '09:15');
        await type(input('Notes'), 'wholegrain');
        byText('Save task').click();
        await flushPromises();
        expect(calls.post).toHaveBeenCalledWith('/todo/tasks', {
            title: 'Buy bread', status: 'todo', assignee_id: 3, due_date: '2026-12-01', due_time: '09:15', notes: 'wholegrain',
        }, expect.any(Object));
        expect(calls.get).toHaveBeenCalledWith('/todo', {}, expect.any(Object));
    });

    it('clearing the date clears the time', async () => {
        calls.patch.mockImplementation((url, data, opts) => opts.onSuccess?.());
        mountBoard({ url: '/todo?task=1' });
        await flushPromises();
        expect(input('Time').value).toBe('18:30');
        await type(input('Due date'), '');
        expect(field('Time')).toBeUndefined();
        byText('Save task').click();
        await flushPromises();
        expect(calls.patch).toHaveBeenCalledWith('/todo/tasks/1', expect.objectContaining({ due_date: null, due_time: null }), expect.any(Object));
    });

    it('edit mode shows the task, saves by PATCH and stays open', async () => {
        calls.patch.mockImplementation((url, data, opts) => { opts.onSuccess?.(); });
        mountBoard({ url: '/todo?task=1' });
        await flushPromises();
        expect(document.body.querySelector('h2.ui-panel__title').textContent).toBe('Task details');
        expect(input('Title').value).toBe('Task 1');
        expect(input('Notes').value).toBe('Some notes');
        expect(byText('Save task').disabled).toBe(true);
        await type(input('Title'), 'Renamed');
        byText('Save task').click();
        await flushPromises();
        expect(calls.patch).toHaveBeenCalledWith('/todo/tasks/1', expect.objectContaining({ title: 'Renamed', due_date: '2026-11-20', due_time: '18:30' }), expect.any(Object));
        expect(calls.get).not.toHaveBeenCalled();
        expect(byText('Save task').disabled).toBe(true);
    });

    it('renders server validation errors inline and focuses the first invalid field', async () => {
        calls.patch.mockImplementation((url, data, opts) => opts.onError?.({ title: 'todo.error.title_long', due_date: 'todo.error.date_invalid' }));
        mountBoard({ url: '/todo?task=1' });
        await flushPromises();
        await type(input('Title'), 'x');
        byText('Save task').click();
        await flushPromises();
        expect(panel().textContent).toContain('Use at most 200 characters.');
        expect(panel().textContent).toContain('Enter a valid date.');
        expect(document.activeElement).toBe(input('Title'));
    });

    it('saves on Enter in the title field', async () => {
        calls.patch.mockImplementation((url, data, opts) => opts.onSuccess?.());
        mountBoard({ url: '/todo?task=1' });
        await flushPromises();
        await type(input('Title'), 'Entered');
        input('Title').dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        await flushPromises();
        expect(calls.patch).toHaveBeenCalledWith('/todo/tasks/1', expect.objectContaining({ title: 'Entered' }), expect.any(Object));
    });

    it('shows a danger alert and keeps input on a server failure', async () => {
        calls.patch.mockImplementation((url, data, opts) => opts.onHttpException?.());
        mountBoard({ url: '/todo?task=1' });
        await flushPromises();
        await type(input('Title'), 'Kept');
        byText('Save task').click();
        await flushPromises();
        const alert = panel().querySelector('[role="alert"]');
        expect(alert.textContent).toContain('We could not save the task. Nothing was changed. Try again.');
        expect(input('Title').value).toBe('Kept');
    });

    it('asks before discarding unsaved changes and keeps editing by default', async () => {
        mountBoard({ url: '/todo?task=1' });
        await flushPromises();
        await type(input('Title'), 'Dirty');
        document.querySelector('button[aria-label="Close"]').click();
        await flushPromises();
        expect(document.body.textContent).toContain('Discard changes?');
        expect(calls.get).not.toHaveBeenCalled();
        byText('Keep editing').click();
        await flushPromises();
        expect(calls.get).not.toHaveBeenCalled();
        document.querySelector('button[aria-label="Close"]').click();
        await flushPromises();
        byText('Discard').click();
        await flushPromises();
        expect(calls.get).toHaveBeenCalledWith('/todo', {}, expect.any(Object));
    });

    it('closes without a dialog when nothing changed, and Cancel reverts and closes', async () => {
        mountBoard({ url: '/todo?task=1' });
        await flushPromises();
        document.querySelector('button[aria-label="Close"]').click();
        await flushPromises();
        expect(document.body.textContent).not.toContain('Discard changes?');
        expect(calls.get).toHaveBeenCalledTimes(1);
    });

    it('Cancel reverts and closes without a dialog', async () => {
        mountBoard({ url: '/todo?task=1' });
        await flushPromises();
        await type(input('Title'), 'Dirty');
        byText('Cancel changes').click();
        await flushPromises();
        expect(document.body.textContent).not.toContain('Discard changes?');
        expect(calls.get).toHaveBeenCalledTimes(1);
    });

    it('deletes after confirmation with the safe button focused first', async () => {
        calls.delete.mockImplementation((url, opts) => opts.onSuccess?.());
        mountBoard({ url: '/todo?task=1' });
        await flushPromises();
        byText('Delete task').click();
        await flushPromises();
        expect(document.body.textContent).toContain('Delete "Task 1"? This cannot be undone.');
        expect(calls.delete).not.toHaveBeenCalled();
        expect(document.activeElement.textContent.trim()).toBe('Keep task');
        [...document.querySelectorAll('[role="dialog"] button')].find((b) => b.textContent.trim() === 'Delete task').click();
        await flushPromises();
        expect(calls.delete).toHaveBeenCalledWith('/todo/tasks/1', expect.any(Object));
        expect(calls.get).toHaveBeenCalledWith('/todo', {}, expect.any(Object));
    });

    it('hides Delete without the delete ability', async () => {
        mountBoard({ url: '/todo?task=1', props: { tasks: [task(1, { abilities: { edit: true, status: true, delete: false, visibility: false } })] } });
        await flushPromises();
        expect(byText('Delete task')).toBeUndefined();
        expect(byText('Save task')).toBeDefined();
    });

    it('is read-only without the edit ability: Close only, status chips follow the status ability', async () => {
        calls.patch.mockImplementation((url, data, opts) => opts.onSuccess?.());
        mountBoard({ url: '/todo?task=2' });
        await flushPromises();
        expect(input('Title').readOnly).toBe(true);
        expect(input('Assigned to').disabled).toBe(true);
        expect(byText('Save task')).toBeUndefined();
        expect(byText('Close')).toBeDefined();
        const chips = [...document.querySelectorAll('[role="radiogroup"] button')];
        expect(chips.every((c) => !c.disabled)).toBe(true);
        chips[2].click();
        await flushPromises();
        expect(calls.patch).toHaveBeenCalledWith('/todo/tasks/2/status', { status: 'done' }, expect.any(Object));
    });

    it('disables status chips when there is no status ability either', async () => {
        mountBoard({ url: '/todo?task=3' });
        await flushPromises();
        expect([...document.querySelectorAll('[role="radiogroup"] button')].every((c) => c.disabled)).toBe(true);
    });

    it('shows the access-denied state for a missing or invisible task', async () => {
        mountBoard({ url: '/todo?task=999', props: { selectedTaskMissing: true } });
        await flushPromises();
        expect(panel().textContent).toContain('You do not have access to this page');
        expect(panel().querySelector('form')).toBeNull();
    });

    it('opens create for a person via Add task for {name}', async () => {
        mountBoard();
        const add = [...current.findAll('button')].find((b) => b.text() === 'Add task for Adam');
        await add.trigger('click');
        expect(calls.get).toHaveBeenCalledWith('/todo', { task: 'new', assignee: 3 }, expect.any(Object));
    });

    it('hides Add task for {name} and the create panel for view-only members', async () => {
        mountBoard({ url: '/todo?task=new', props: { canEdit: false } });
        await flushPromises();
        expect([...current.findAll('button')].some((b) => b.text().startsWith('Add task for'))).toBe(false);
        expect(panel()).toBeNull();
    });

    it('registers the shell add handler so the topbar Add opens the create panel', async () => {
        mountBoard();
        const add = [...document.querySelectorAll('button, a')].find((b) => b.textContent.trim() === 'Add task');
        add.click();
        await flushPromises();
        expect(calls.get).toHaveBeenCalledWith('/todo', { task: 'new' }, expect.any(Object));
    });
});

describe('Card checkbox', () => {
    const box = (n) => current.findAll('[data-testid="task-card"] input[type="checkbox"]')[n];

    it('toggles done optimistically via PATCH status', async () => {
        calls.patch.mockImplementation(() => {});
        mountBoard();
        expect(box(0).attributes('aria-label')).toBe('Mark done: Task 1');
        await box(0).setValue(true);
        expect(calls.patch).toHaveBeenCalledWith('/todo/tasks/1/status', { status: 'done' }, expect.objectContaining({ preserveScroll: true }));
        expect(box(0).element.checked).toBe(true);
        expect(box(0).attributes('aria-label')).toBe('Mark not done: Task 1');
    });

    it('reverts and toasts on failure', async () => {
        calls.patch.mockImplementation((url, data, opts) => { setTimeout(() => opts.onHttpException?.(), 0); });
        mountBoard();
        await box(0).setValue(true);
        await new Promise((r) => setTimeout(r, 10));
        await flushPromises();
        expect(box(0).element.checked).toBe(false);
        expect(useToast().toasts.map((t) => t.message)).toContain('We could not update the task. Try again.');
    });

    it('is disabled without the status ability', () => {
        mountBoard();
        expect(box(2).attributes('disabled')).toBeDefined();
        expect(box(2).attributes('aria-disabled')).toBe('true');
    });
});

describe('Task panel accessibility', () => {
    const cases = {
        create: { url: '/todo?task=new' },
        edit: { url: '/todo?task=1' },
        readonly: { url: '/todo?task=3' },
        missing: { url: '/todo?task=99', props: { selectedTaskMissing: true } },
    };
    for (const [name, opts] of Object.entries(cases)) {
        for (const locale of ['en', 'sk', 'pl']) {
            for (const theme of ['light', 'dark']) {
                it(`${name} has no axe violations (${locale}, ${theme})`, async () => {
                    document.documentElement.dataset.theme = theme;
                    mountBoard({ ...opts, locale });
                    await flushPromises();
                    await expectNoAxeViolations(document.body);
                    delete document.documentElement.dataset.theme;
                });
            }
        }
    }
});
