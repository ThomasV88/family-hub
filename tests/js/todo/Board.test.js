import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

let mockProps = {};
let mockUrl = '/todo';
const post = vi.fn();
const get = vi.fn();
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: mockProps, get url() { return mockUrl; } }),
        router: { post: (...a) => post(...a), get: (...a) => get(...a), visit: vi.fn(), on: vi.fn() },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import Index from '../../../resources/js/Pages/Todo/Index.vue';

const ME = 2;
const MEMBERS = [
    { id: 1, name: 'Zora' },
    { id: 2, name: 'Mira' },
    { id: 3, name: 'Adam' },
];
const task = (id, extra = {}) => ({
    id, title: `Task ${id}`, notes: null, status: 'todo', visibility: 'family', creatorId: ME, assigneeId: ME,
    dueDate: null, dueTime: null, isRecurring: false, completedAt: null, memberIds: [], ...extra,
});
const TASKS = [
    task(1),
    task(2, { assigneeId: 3, title: 'Adam chore' }),
    task(3, { assigneeId: 1, visibility: 'personal', creatorId: 1, title: 'Zora secret' }),
    task(4, { assigneeId: null, title: 'Nobody task' }),
    task(5, { status: 'done', completedAt: '2026-11-12T10:00:00Z', title: 'Finished' }),
    task(6, { dueDate: '2026-11-10', title: 'Late one' }),
    task(7, { visibility: 'selected', isRecurring: true, title: 'Selected repeat' }),
];

let current;
function mountBoard({ locale = 'en', props = {}, width = 1024 } = {}) {
    window.innerWidth = width;
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: ME, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'edit', wishlist: 'none', finance: 'none', health: 'none' } },
        app: { env: 'local', styleGuide: false },
    };
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(Index, {
        props: { timezone: 'Europe/Bratislava', today: '2026-11-13', canEdit: true, members: MEMBERS, tasks: TASKS, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, loadMessages(locale))] },
    });
    return current;
}
beforeEach(() => { mockUrl = '/todo'; });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; post.mockReset(); get.mockReset(); });

const headings = () => current.findAll('section h2').map((h) => h.text());

describe('Todo board', () => {
    it('renders h1, quick add and columns: you first, others by name, Unassigned last', () => {
        mountBoard();
        expect(current.find('h1').text()).toBe('Tasks');
        expect(current.find('input').attributes('placeholder')).toBe('Add a task and press Enter');
        expect(headings()).toEqual(['Mira', 'Adam', 'Zora', 'Unassigned']);
        expect(current.find('section').text()).toContain('You');
    });

    it('omits Unassigned when empty, and puts tasks of users without a column under Unassigned', () => {
        mountBoard({ props: { tasks: [task(1)] } });
        expect(headings()).toEqual(['Mira', 'Adam', 'Zora']);
        current.unmount();
        mountBoard({ props: { tasks: [task(1, { assigneeId: 99 })] } });
        expect(headings()).toEqual(['Mira', 'Adam', 'Zora', 'Unassigned']);
    });

    it('sorts overdue first and counts open tasks only', () => {
        mountBoard();
        const mine = current.findAll('section')[0];
        const titles = mine.findAll('li button').map((b) => b.text());
        expect(titles.slice(0, 3)).toEqual(['Late one', 'Task 1', 'Selected repeat']);
        expect(mine.text()).toContain('3 open tasks');
        expect(mine.text()).not.toContain('Finished');
    });

    it('hides done tasks behind a toggle', async () => {
        mountBoard();
        const mine = current.findAll('section')[0];
        const toggle = mine.findAll('button').find((b) => b.text() === 'Show done (1)');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        await toggle.trigger('click');
        expect(mine.text()).toContain('Finished');
        expect(mine.findAll('button').find((b) => b.text() === 'Hide done (1)').attributes('aria-expanded')).toBe('true');
    });

    it('marks cards: overdue tag, repeat, personal and selected icons, disabled checkbox', () => {
        mountBoard();
        expect(current.text()).toContain('Overdue, 10 Nov');
        expect(current.find('[aria-label="Repeats"]').exists()).toBe(true);
        expect(current.find('[aria-label="Shared with selected members"]').exists()).toBe(true);
        expect(current.find('[aria-label="Personal task"]').exists()).toBe(true);
        expect(current.find('input[type=checkbox]').attributes('disabled')).toBeDefined();
        expect(current.find('[data-testid=task-card] .task-card__title').attributes('draggable')).toBeUndefined();
    });

    it('filters via chips and keeps state in the URL', async () => {
        mountBoard();
        const chip = (label) => current.findAll('[role=group] button').find((b) => b.text() === label);
        await chip('Mine').trigger('click');
        expect(get).toHaveBeenCalledWith('/todo', { filter: 'mine' }, expect.objectContaining({ preserveState: true, replace: true }));
        expect(headings()).toEqual(['Mira']);
        await chip('Shared').trigger('click');
        expect(current.text()).not.toContain('Zora secret');
        await chip('Personal').trigger('click');
        expect(headings()).toEqual([]);
        expect(current.text()).toContain('No tasks match');
        await current.findAll('button').find((b) => b.text() === 'Show all tasks').trigger('click');
        expect(headings()).toContain('Adam');
        expect(chip('All').attributes('aria-pressed')).toBe('true');
    });

    it('reads the initial filter from the URL', () => {
        mockUrl = '/todo?filter=shared';
        mountBoard();
        expect(current.findAll('[role=group] button').find((b) => b.text() === 'Shared').attributes('aria-pressed')).toBe('true');
    });

    it('shows the empty board state with an Add action that focuses the input', async () => {
        mountBoard({ props: { tasks: [] } });
        expect(current.text()).toContain('No tasks yet');
        await current.findAll('main button').find((b) => b.text() === 'Add task' && b.attributes('type') === 'button').trigger('click');
        expect(document.activeElement).toBe(current.find('input').element);
    });

    it('view-only: no quick add, info alert, no add action', () => {
        mountBoard({ props: { canEdit: false } });
        expect(current.find('[data-testid=todo-quick-add]').exists()).toBe(false);
        expect(current.text()).toContain('You can view tasks but not change them.');
    });

    it('opens a task into the URL query', async () => {
        mountBoard();
        await current.find('[data-testid=task-card] button').trigger('click');
        expect(get).toHaveBeenCalledWith('/todo', { task: expect.any(Number) }, expect.anything());
    });

    it('collapses other columns on one column when there are more than 3 members', async () => {
        const many = [...MEMBERS, { id: 4, name: 'Eva' }];
        mountBoard({ width: 390, props: { members: many } });
        const buttons = current.findAll('section h2 button');
        expect(buttons[0].attributes('aria-expanded')).toBe('true');
        expect(buttons[1].attributes('aria-expanded')).toBe('false');
        await buttons[1].trigger('click');
        expect(current.findAll('section h2 button')[1].attributes('aria-expanded')).toBe('true');
    });

    it('keeps others expanded with 3 members or fewer on one column', () => {
        mountBoard({ width: 390 });
        expect(current.findAll('section h2 button').map((b) => b.attributes('aria-expanded'))).toEqual(['true', 'true', 'true', 'true']);
    });
});

describe('Quick add', () => {
    it('posts the trimmed title on Enter with preserveScroll, then clears and keeps focus', async () => {
        mountBoard();
        const input = current.find('input');
        await input.setValue('  Buy bread ');
        await input.trigger('keydown', { key: 'Enter' });
        expect(post).toHaveBeenCalledWith('/todo/tasks', { title: 'Buy bread' }, expect.objectContaining({ preserveScroll: true }));
        post.mock.calls[0][2].onSuccess();
        await flushPromises();
        expect(input.element.value).toBe('');
        expect(document.activeElement).toBe(input.element);
    });

    it('the Add task button submits too and is busy while processing', async () => {
        mountBoard();
        await current.find('input').setValue('Milk');
        await current.find('form button[type=submit]').trigger('click');
        expect(post).toHaveBeenCalledTimes(1);
        post.mock.calls[0][2].onStart();
        await flushPromises();
        expect(current.find('form button[type=submit]').attributes('aria-busy')).toBe('true');
    });

    it('empty title shows an inline error and sends nothing', async () => {
        mountBoard();
        const input = current.find('input');
        await input.setValue('   ');
        await input.trigger('keydown', { key: 'Enter' });
        expect(post).not.toHaveBeenCalled();
        expect(current.text()).toContain('Enter a task title.');
        expect(input.attributes('aria-invalid')).toBe('true');
    });

    it('ignores Enter during IME composition', async () => {
        mountBoard();
        const input = current.find('input');
        await input.setValue('Hello');
        await input.trigger('keydown', { key: 'Enter', isComposing: true });
        expect(post).not.toHaveBeenCalled();
    });

    it('shows the server error key translated', async () => {
        mountBoard();
        await current.find('input').setValue('x');
        await current.find('input').trigger('keydown', { key: 'Enter' });
        post.mock.calls[0][2].onError({ title: 'todo.error.title_long' });
        await flushPromises();
        expect(current.text()).toContain('Use at most 200 characters.');
    });
});

describe('Todo board accessibility and safety', () => {
    it('renders titles as text only', () => {
        mountBoard({ props: { tasks: [task(1, { title: '<img src=x onerror=alert(1)>' })] } });
        expect(current.find('img').exists()).toBe(false);
        expect(current.text()).toContain('<img src=x');
    });

    const states = {
        populated: {},
        empty: { tasks: [] },
        viewOnly: { canEdit: false },
    };
    it.each(['en', 'sk', 'pl'].flatMap((l) => ['light', 'dark'].flatMap((th) => Object.keys(states).map((s) => [l, th, s]))))(
        'has no axe violations in %s, %s, %s',
        async (locale, theme, state) => {
            document.documentElement.dataset.theme = theme;
            mountBoard({ locale, props: states[state] });
            await expectNoAxeViolations(current.element);
            delete document.documentElement.dataset.theme;
        },
    );

    it('has no axe violations when filtered (empty result)', async () => {
        mockUrl = '/todo?filter=personal';
        mountBoard({ props: { tasks: [task(1)] } });
        await expectNoAxeViolations(current.element);
    });
});
