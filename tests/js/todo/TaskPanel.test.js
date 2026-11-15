import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
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
const REC = { kind: 'weekly', interval: 1, weekdays: ['MO', 'WE'] };
const TASKS = [
    task(1, { notes: 'Some notes', dueDate: '2026-11-20', dueTime: '18:30' }),
    task(2, { assigneeId: 3, creatorId: 3, title: 'Adam chore', abilities: { edit: false, status: true, delete: false, visibility: false } }),
    task(3, { assigneeId: 3, creatorId: 1, title: 'Locked', abilities: { edit: false, status: false, delete: false, visibility: false } }),
];

let current;
function mountBoard({ url = '/todo', locale = 'en', props = {} } = {}) {
    mockUrl = url;
    window.innerWidth = 1024;
    mockProps = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: ME, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'edit', wishlist: 'none', finance: 'none', health: 'none' } },
        app: { env: 'local', styleGuide: false },
    });
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

const RECS = [
    task(4, { title: 'Water plants', dueDate: '2026-11-16', isRecurring: true, recurrence: REC, recurrenceMode: 'completion', remindBefore: 1440 }),
    task(5, { title: 'Pay rent', dueDate: '2026-11-30', isRecurring: true, recurrence: { kind: 'monthly_day', interval: 1, monthDay: 30 }, recurrenceMode: 'schedule', remindBefore: 0 }),
    task(6, { title: 'Old rent', dueDate: '2026-10-30', status: 'done', completedAt: '2026-10-30T10:00:00+01:00', isRecurring: true, recurrence: { kind: 'monthly_day', interval: 1, monthDay: 30 }, recurrenceMode: 'schedule', abilities: { edit: true, status: false, delete: true, visibility: true } }),
];
function mountRec(opts = {}) {
    return mountBoard({ ...opts, props: { tasks: [...TASKS, ...RECS], ...(opts.props ?? {}) } });
}

describe('Repeat, reminder, skip and history in the panel', () => {
    it('create payload stays free of repeat and reminder keys by default', async () => {
        calls.post.mockImplementation((url, data, opts) => { opts.onSuccess?.(); });
        mountRec({ url: '/todo?task=new' });
        await flushPromises();
        await type(input('Title'), 'Plain');
        await type(input('Due date'), '2026-12-01');
        byText('Save task').click();
        await flushPromises();
        const data = calls.post.mock.calls[0][1];
        expect(Object.keys(data)).not.toContain('recurrence');
        expect(Object.keys(data)).not.toContain('remind_before_minutes');
    });

    it('disables Repeat and Remind me without a due date', async () => {
        mountRec({ url: '/todo?task=new' });
        await flushPromises();
        expect(input('Repeat').disabled).toBe(true);
        expect(input('Remind me').disabled).toBe(true);
        expect(document.body.textContent).toContain('Set a due date to make this task repeat.');
        expect(document.body.textContent).toContain('Set a due date to get a reminder.');
    });

    it('posts a structured weekly pattern and never an RRULE', async () => {
        calls.post.mockImplementation((url, data, opts) => { opts.onSuccess?.(); });
        mountRec({ url: '/todo?task=new' });
        await flushPromises();
        await type(input('Title'), 'Gym');
        await type(input('Due date'), '2026-12-01');
        await type(input('Repeat'), 'weekly');
        const chips = [...document.body.querySelectorAll('[data-testid="repeat-fields"] button[aria-pressed]')];
        expect(chips).toHaveLength(7);
        chips[0].click();
        await flushPromises();
        chips[2].click();
        await flushPromises();
        await type(input('Remind me'), 'before_1d');
        byText('Save task').click();
        await flushPromises();
        const data = calls.post.mock.calls[0][1];
        expect(data).toMatchObject({ recurrence: { kind: 'weekly', interval: 1, weekdays: ['MO', 'WE'] }, recurrence_mode: 'schedule', remind_before_minutes: 1440 });
        expect(JSON.stringify(data)).not.toContain('FREQ=');
    });

    it('shows an inline weekdays error and does not post when none is chosen', async () => {
        mountRec({ url: '/todo?task=new' });
        await flushPromises();
        await type(input('Title'), 'Gym');
        await type(input('Due date'), '2026-12-01');
        await type(input('Repeat'), 'weekly');
        byText('Save task').click();
        await flushPromises();
        expect(calls.post).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('Choose at least one weekday.');
    });

    it('validates the custom interval and sends the unit', async () => {
        calls.post.mockImplementation((url, data, opts) => { opts.onSuccess?.(); });
        mountRec({ url: '/todo?task=new' });
        await flushPromises();
        await type(input('Title'), 'Filter');
        await type(input('Due date'), '2027-01-31');
        await type(input('Repeat'), 'custom');
        await type(input('Interval'), '0');
        byText('Save task').click();
        await flushPromises();
        expect(calls.post).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('Enter a whole number from 1 to 99.');
        await type(input('Interval'), '3');
        await type(input('Unit'), 'month');
        expect(document.body.querySelector('[data-testid="repeat-summary"]').textContent).toBe('Repeats every 3 months');
        byText('Save task').click();
        await flushPromises();
        expect(calls.post.mock.calls[0][1]).toMatchObject({ recurrence: { kind: 'custom', interval: 3, unit: 'month', monthDay: 31 } });
    });

    it('shows the live summary for monthly day and last day', async () => {
        mountRec({ url: '/todo?task=new' });
        await flushPromises();
        await type(input('Due date'), '2027-01-31');
        await type(input('Repeat'), 'monthly');
        const summary = () => document.body.querySelector('[data-testid="repeat-summary"]').textContent;
        expect(summary()).toBe('Repeats every month on day 31');
        await type(input('Day of month'), 'last');
        expect(summary()).toBe('Repeats on the last day of every month');
    });

    it('loads an existing recurring task and sends recurrence null when the repeat is removed', async () => {
        calls.patch.mockImplementation((url, data, opts) => { opts.onSuccess?.(); });
        mountRec({ url: '/todo?task=4' });
        await flushPromises();
        expect(input('Repeat').value).toBe('weekly');
        expect(input('Remind me').value).toBe('before_1d');
        expect(document.body.querySelector('[data-testid="repeat-summary"]').textContent).toBe('Repeats on Monday and Wednesday');
        expect(document.body.querySelector('input[type="radio"][value="completion"]').checked).toBe(true);
        await type(input('Repeat'), 'none');
        byText('Save task').click();
        await flushPromises();
        expect(calls.patch.mock.calls[0][1].recurrence).toBeNull();
    });

    it('keeps a stored month day (clamped rule) in the summary', async () => {
        mountRec({ url: '/todo?task=5' });
        await flushPromises();
        expect(document.body.querySelector('[data-testid="repeat-summary"]').textContent).toBe('Repeats every month on day 30');
    });

    it('resets repeat and reminder when the due date is cleared', async () => {
        calls.patch.mockImplementation((url, data, opts) => { opts.onSuccess?.(); });
        mountRec({ url: '/todo?task=4' });
        await flushPromises();
        await type(input('Due date'), '');
        expect(input('Repeat').disabled).toBe(true);
        expect(input('Remind me').value).toBe('at_due');
        byText('Save task').click();
        await flushPromises();
        const data = calls.patch.mock.calls[0][1];
        expect(data.recurrence).toBeNull();
        expect(data).not.toHaveProperty('remind_before_minutes');
    });

    it('maps server repeat errors onto the fields', async () => {
        calls.patch.mockImplementation((url, data, opts) => { opts.onError?.({ 'recurrence.interval': 'todo.error.interval' }); });
        mountRec({ url: '/todo?task=4' });
        await flushPromises();
        await type(input('Repeat'), 'custom');
        await type(input('Interval'), '5');
        byText('Save task').click();
        await flushPromises();
        expect(document.body.textContent).toContain('Enter a whole number from 1 to 99.');
    });

    it('offers Skip this occurrence on a recurring task and posts to /skip', async () => {
        calls.post.mockImplementation((url, data, opts) => { opts.onSuccess?.(); });
        mountRec({ url: '/todo?task=4' });
        await flushPromises();
        byText('Skip this occurrence').click();
        await flushPromises();
        expect(calls.post).toHaveBeenCalledWith('/todo/tasks/4/skip', {}, expect.any(Object));
    });

    it('has no Skip button on plain tasks or completed history rows', async () => {
        mountRec({ url: '/todo?task=1' });
        await flushPromises();
        expect(byText('Skip this occurrence')).toBeUndefined();
        current.unmount();
        mountRec({ url: '/todo?task=6' });
        await flushPromises();
        expect(byText('Skip this occurrence')).toBeUndefined();
    });

    it('lists the history in a collapsible section', async () => {
        mountRec({ url: '/todo?task=4', props: { history: [{ kind: 'skipped', at: '2026-11-09T10:00:00+01:00' }, { kind: 'completed', at: '2026-11-02T10:00:00+01:00' }] } });
        await flushPromises();
        const toggle = byText('Completed history');
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        toggle.click();
        await flushPromises();
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        const items = [...document.body.querySelectorAll('#task-history-list li')].map((li) => li.textContent);
        expect(items).toEqual(['Skipped 9 Nov', 'Completed 2 Nov']);
    });

    it('shows the empty history copy and the recurring delete text', async () => {
        mountRec({ url: '/todo?task=4', props: { history: [] } });
        await flushPromises();
        byText('Completed history').click();
        await flushPromises();
        expect(document.body.querySelector('#task-history-list').textContent).toContain('No completions yet.');
        byText('Delete task').click();
        await flushPromises();
        expect(document.body.textContent).toContain('and all its future repeats');
    });

    it('toasts the next due date from the flash after completing a recurring task', async () => {
        mountRec();
        mockProps.flash = { status: null, toast: null, data: { nextDue: '2026-11-16 18:30', toast: 'todo.toast.done_next' } };
        await flushPromises();
        expect(useToast().toasts.map((x) => x.message)).toContain('Done. Next one is due 16 Nov 18:30.');
    });

    it('renders completed recurring history cards with a disabled checkbox', async () => {
        mountRec();
        const showDone = current.findAll('button').find((b) => b.text().startsWith('Show done'));
        await showDone.trigger('click');
        const cards = current.findAll('[data-testid="task-card"]');
        const old = cards.find((c) => c.text().includes('Old rent'));
        expect(old.find('input[type="checkbox"]').attributes('disabled')).toBeDefined();
    });
});

describe('Repeat and reminder accessibility', () => {
    for (const task of [4, 5]) {
        for (const locale of ['en', 'sk', 'pl']) {
            for (const theme of ['light', 'dark']) {
                it(`recurring task ${task} panel has no axe violations (${locale}, ${theme})`, async () => {
                    document.documentElement.dataset.theme = theme;
                    mountRec({ url: `/todo?task=${task}`, locale, props: { history: [{ kind: 'completed', at: '2026-11-02T10:00:00+01:00' }] } });
                    await flushPromises();
                    await expectNoAxeViolations(document.body);
                    delete document.documentElement.dataset.theme;
                });
            }
        }
    }

    for (const kind of ['custom', 'monthly', 'daily']) {
        it(`create panel with ${kind} repeat has no axe violations`, async () => {
            mountRec({ url: '/todo?task=new' });
            await flushPromises();
            await type(input('Due date'), '2026-12-01');
            await type(input('Repeat'), kind);
            await expectNoAxeViolations(document.body);
        });
    }
});
