import { describe, it, vi, afterEach } from 'vitest';
import { mountUi, loadMessages } from './helpers/mount.js';
import { expectNoAxeViolations } from './helpers/axe.js';
import { pseudoLong } from './helpers/pseudoLong.js';
import { mount } from '@vue/test-utils';
import { createAppI18n } from '../../resources/js/i18n.js';

let mockLocale = 'en';
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({
            props: {
                locale: mockLocale,
                app: { name: 'Family Hub', env: 'local' },
                auth: { user: { id: 1, name: 'Mira' }, access: { todo: 'edit', wishlist: 'edit', finance: 'edit', health: 'edit' } },
                heartbeat: { last_recorded_at: '2026-11-13T10:00:00+00:00', last_source: 'schedule', count_24h: 3, expected_24h: 1440 },
            },
        }),
        router: { post: vi.fn(), get: vi.fn(), visit: vi.fn(), on: vi.fn(), reload: vi.fn() },
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import StyleGuide from '../../resources/js/Pages/StyleGuide.vue';
import Home from '../../resources/js/Pages/Home.vue';
import TodoIndex from '../../resources/js/Pages/Todo/Index.vue';
import AiCalls from '../../resources/js/Pages/Settings/AiCalls.vue';
import AppShell from '../../resources/js/Layouts/AppShell.vue';

let wrapper;
const originalWidth = window.innerWidth;
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    delete document.documentElement.dataset.theme;
    window.innerWidth = originalWidth;
});

const pages = { StyleGuide, Home, 'Todo/Index': TodoIndex, 'Settings/AiCalls': AiCalls };
const ME = 1;
const todoTask = (id, extra = {}) => ({
    id, title: `Task ${id}`, notes: null, status: 'todo', visibility: 'family', creatorId: ME, assigneeId: ME,
    dueDate: null, dueTime: null, isRecurring: false, completedAt: null, memberIds: [], ...extra,
});
const pageProps = {
    'Todo/Index': {
        timezone: 'Europe/Bratislava', today: '2026-11-13', canEdit: true,
        members: [{ id: 1, name: 'Mira' }, { id: 2, name: 'Adam' }],
        tasks: [
            todoTask(1, { dueDate: '2026-11-10' }),
            todoTask(2, { visibility: 'personal', isRecurring: true, dueDate: '2026-11-13', dueTime: '18:00' }),
            todoTask(3, { assigneeId: 2, visibility: 'selected', memberIds: [1, 2], status: 'in_progress' }),
            todoTask(4, { status: 'done', completedAt: '2026-11-12T10:00:00Z' }),
            todoTask(5, { assigneeId: null }),
        ],
    },
};
pageProps['Settings/AiCalls'] = {
    sections: [
        { key: 'ai_calls', href: '/settings/ai-calls', labelKey: 'settings.section.ai_calls', icon: 'bot' },
        { key: 'appearance', href: '/settings/appearance', labelKey: 'settings.section.appearance', icon: 'palette' },
    ],
    login: { agent: 'codex', state: 'expired', checkedAt: '2026-11-14T10:30:00+00:00' },
    calls: {
        page: 1, pages: 2, total: 30,
        rows: [
            { time: '2026-11-14T10:00:00+00:00', startedBy: 'Mira', person: 'Eva', agent: 'codex', model: 'default', status: 'succeeded', durationMs: 12000 },
            { time: '2026-11-14T09:00:00+00:00', startedBy: 'Adam', person: null, agent: 'codex', model: null, status: 'failed', durationMs: 65000 },
            { time: '2026-11-14T08:00:00+00:00', startedBy: 'Adam', person: 'Eva', agent: 'codex', model: null, status: 'running', durationMs: null },
        ],
    },
    filters: { person: null, member: null, status: null },
    options: { people: [{ id: '11111111-1111-4111-8111-111111111111', name: 'Eva' }], members: [{ id: 1, name: 'Mira' }, { id: 2, name: 'Adam' }] },
};
const cases = [];
for (const locale of ['en', 'sk', 'pl'])
    for (const theme of ['light', 'dark'])
        for (const width of [390, 1280])
            for (const name of Object.keys(pages)) cases.push([name, locale, theme, width]);

describe('accessibility sweep', () => {
    it.each(cases)('%s in %s, %s theme, %ipx has no axe violations', async (name, locale, theme, width) => {
        mockLocale = locale;
        document.documentElement.dataset.theme = theme;
        window.innerWidth = width;
        wrapper = mountUi(pages[name], { locale, props: pageProps[name] ?? {} });
        await expectNoAxeViolations(wrapper.element);
    });

    it.each([390, 1280])('AppShell with pseudo-long English text at %ipx has no axe violations', async (width) => {
        mockLocale = 'en';
        window.innerWidth = width;
        wrapper = mount(AppShell, {
            attachTo: document.body,
            slots: { default: '<h1>Title</h1>' },
            global: { plugins: [createAppI18n('en', pseudoLong(loadMessages('en')))] },
        });
        await expectNoAxeViolations(wrapper.element);
    });
});
