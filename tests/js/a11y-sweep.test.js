import { describe, it, vi, afterEach } from 'vitest';
import { mountUi, loadMessages } from './helpers/mount.js';
import { expectNoAxeViolations } from './helpers/axe.js';
import { pseudoLong } from './helpers/pseudoLong.js';
import { mount } from '@vue/test-utils';
import { createAppI18n } from '../../resources/js/i18n.js';

let mockLocale = 'en';
vi.mock('@inertiajs/vue3', async () => {
    const { h, reactive } = await import('vue');
    return {
        usePage: () => ({
            props: {
                locale: mockLocale,
                app: { name: 'Family Hub', env: 'local' },
                auth: { user: { id: 1, name: 'Mira', isAdmin: true }, access: { todo: 'edit', wishlist: 'edit', finance: 'edit', health: 'edit', shopping: 'edit' } },
                flash: { status: null, toast: null, data: null },
                availableLocales: ['sk', 'pl', 'en'],
                heartbeat: { last_recorded_at: '2026-11-13T10:00:00+00:00', last_source: 'schedule', count_24h: 3, expected_24h: 1440 },
            },
        }),
        router: { post: vi.fn(), get: vi.fn(), visit: vi.fn(), on: vi.fn(), reload: vi.fn(), delete: vi.fn() },
        useForm: (data) => reactive({ ...data, processing: false, errors: {}, put: vi.fn(), post: vi.fn(), clearErrors: vi.fn() }),
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import StyleGuide from '../../resources/js/Pages/StyleGuide.vue';
import Home from '../../resources/js/Pages/Home.vue';
import TodoIndex from '../../resources/js/Pages/Todo/Index.vue';
import AiCalls from '../../resources/js/Pages/Settings/AiCalls.vue';
import AiAgents from '../../resources/js/Pages/Settings/AiAgents.vue';
import HelpSettings from '../../resources/js/Pages/Settings/Help.vue';
import ProfileEdit from '../../resources/js/Pages/Profile/Edit.vue';
import ShoppingIndex from '../../resources/js/Pages/Shopping/Index.vue';
import ShoppingShow from '../../resources/js/Pages/Shopping/Show.vue';
import SettingsShopping from '../../resources/js/Pages/Settings/Shopping.vue';
import { helpAreas, helpRows } from './helpers/help.js';
import AppShell from '../../resources/js/Layouts/AppShell.vue';

let wrapper;
window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
const originalWidth = window.innerWidth;
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    delete document.documentElement.dataset.theme;
    window.innerWidth = originalWidth;
});

const pages = { StyleGuide, Home, 'Todo/Index': TodoIndex, 'Settings/AiCalls': AiCalls, 'Settings/AiAgents': AiAgents, 'Settings/Help': HelpSettings, 'Profile/Edit': ProfileEdit,
    'Shopping/Index': ShoppingIndex, 'Shopping/Index (empty)': ShoppingIndex,
    'Shopping/Show (open)': ShoppingShow, 'Shopping/Show (locked)': ShoppingShow, 'Shopping/Show (done)': ShoppingShow,
    'Settings/Shopping': SettingsShopping,
};
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
pageProps['Settings/AiAgents'] = {
    sections: [
        { key: 'ai_calls', href: '/settings/ai-calls', labelKey: 'settings.section.ai_calls', icon: 'bot' },
        { key: 'ai_agents', href: '/settings/ai-agents', labelKey: 'settings.section.ai_agents', icon: 'bot' },
    ],
    agents: [
        { key: 'codex', enabled: true, position: 1, model: null, health: { state: 'logged_in', checkedAt: '2026-11-14T10:30:00+00:00' }, cooldownUntil: new Date(Date.now() + 3 * 3600 * 1000).toISOString() },
        { key: 'copilot', enabled: true, position: 2, model: 'gpt-5', health: { state: 'unknown', checkedAt: null }, cooldownUntil: null },
    ],
    providers: ['codex', 'copilot'],
    checkRoute: 'settings.ai_calls.check',
};
pageProps['Settings/Help'] = {
    sections: [
        { key: 'ai_agents', href: '/settings/ai-agents', labelKey: 'settings.section.ai_agents', icon: 'bot' },
        { key: 'help', href: '/settings/help', labelKey: 'settings.section.help', icon: 'circle-help' },
        { key: 'appearance', href: '/settings/appearance', labelKey: 'settings.section.appearance', icon: 'palette' },
    ],
    topics: helpRows('en'),
    orphans: [{ key: 'old.removed.key', texts: { sk: 'a', pl: 'b', en: 'c' }, edited: { sk: false, pl: false, en: false } }],
    areas: helpAreas('en'),
    locales: ['sk', 'pl', 'en'],
    maxLength: 400,
    error: false,
};
pageProps['Profile/Edit'] = {
    sections: [{ key: 'appearance', href: '/settings/appearance', labelKey: 'settings.section.appearance', icon: 'palette' }],
    subject: { id: 1, name: 'Mira', email: 'mira@example.test', photoVersion: 2, isSelf: true, isChild: false },
    nameMax: 60,
};
const shopPerson = (id, name) => ({ id, name, isChild: false });
const shopList = (id, extra = {}) => ({
    id, name: `List ${id}`, status: 'open', store: null, responsible: shopPerson(1, 'Mira'), remindAt: null, reminderPassed: false,
    itemsCount: 0, boughtCount: 0, unavailableCount: 0, abilities: { copy: true }, ...extra,
});
const shopLists = [
    shopList(1, { name: 'Weekly shop', store: { id: 4, name: 'Tesco', logoVersion: 2 }, remindAt: '2026-11-20T18:00:00+01:00' }),
    shopList(2, { name: 'Party', status: 'locked', itemsCount: 8, boughtCount: 3, store: { id: 5, name: 'Lidl', logoVersion: null } }),
    shopList(3, { name: 'Garden', remindAt: '2026-11-10T08:00:00+01:00', reminderPassed: true }),
];
const shopIndexBase = {
    tab: 'open', stores: [{ id: 4, name: 'Tesco', logoVersion: 2 }], responsibleCandidates: [shopPerson(1, 'Mira'), shopPerson(2, 'Adam')], canCreate: true,
};
pageProps['Shopping/Index'] = {
    ...shopIndexBase, lists: shopLists, counts: { open: 3, archive: 1 },
    archive: { data: [{ id: 9, name: 'Old shop', doneAt: '2026-11-01T10:00:00+01:00', storeName: 'Tesco', abilities: { copy: true } }], currentPage: 1, lastPage: 1, perPage: 20, total: 1 },
};
pageProps['Shopping/Index (empty)'] = {
    ...shopIndexBase, lists: [], counts: { open: 0, archive: 0 },
    archive: { data: [], currentPage: 1, lastPage: 1, perPage: 20, total: 0 },
};
const shopItem = (id, extra = {}) => ({ id, name: `Item ${id}`, quantity: '1.00', unitId: 1, note: null, state: 'pending', catalogItemId: null, imageVersion: null, ...extra });
const shopShowBase = {
    items: [shopItem(1, { name: 'Milk' }), shopItem(2, { name: 'Bread', state: 'bought' }), shopItem(3, { name: 'Apples', state: 'unavailable' })],
    members: { contributors: [shopPerson(2, 'Adam')], viewers: [] },
    units: [{ id: 1, key: 'pcs', names: null }], stores: [{ id: 4, name: 'Tesco', logoVersion: 2 }], catalog: [{ id: 1, name: 'Milk', defaultUnitId: 1, imageVersion: null }],
};
const shopListBase = {
    id: 5, name: 'Weekly shop', status: 'open', store: null, storeName: '', creator: shopPerson(1, 'Mira'), responsible: shopPerson(1, 'Mira'),
    responsibleFallback: null, remindAt: null, reminderPassed: false, reminderSent: false, lockedAt: null, doneAt: null, source: null,
};
const shopPlan = { editItems: true, lock: true, unlock: false, mark: false, finish: false, copy: true, mail: true, delete: true, manageCatalog: false };
pageProps['Shopping/Show (open)'] = { ...shopShowBase, list: shopListBase, abilities: shopPlan };
pageProps['Shopping/Show (locked)'] = {
    ...shopShowBase, list: { ...shopListBase, status: 'locked', lockedAt: '2026-11-17T10:00:00+01:00' },
    abilities: { editItems: false, lock: false, unlock: true, mark: true, finish: true, copy: true, mail: true, delete: false },
};
pageProps['Shopping/Show (done)'] = {
    ...shopShowBase, list: { ...shopListBase, status: 'done', doneAt: '2026-11-17T12:00:00+01:00' },
    abilities: { editItems: false, lock: false, unlock: false, mark: false, finish: false, copy: true, mail: true, delete: false },
};
pageProps['Settings/Shopping'] = {
    sections: [
        { key: 'shopping', href: '/settings/shopping', labelKey: 'settings.section.shopping', icon: 'shopping-cart' },
        { key: 'appearance', href: '/settings/appearance', labelKey: 'settings.section.appearance', icon: 'palette' },
    ],
    catalog: [{ id: 1, name: 'Milk', defaultUnitId: 3, imageVersion: 2, usedOn: 2 }, { id: 2, name: 'Apple', defaultUnitId: null, imageVersion: null, usedOn: 0 }],
    stores: [{ id: 1, name: 'Tesco', logoVersion: 1, usedOn: 1 }, { id: 2, name: 'Lidl', logoVersion: null, usedOn: 0 }],
    units: [
        { id: 1, key: 'pcs', names: null, builtIn: true, usedOn: 0 },
        { id: 3, key: 'kg', names: null, builtIn: true, usedOn: 0 },
        { id: 10, key: null, names: { en: 'tin', sk: 'plech', pl: 'puszka' }, builtIn: false, usedOn: 3 },
    ],
    canManageUnits: true,
};
const cases = [];
for (const locale of ['en', 'sk', 'pl'])
    for (const theme of ['light', 'dark'])
        for (const width of [390, 1280])
            for (const name of Object.keys(pages)) cases.push([name, locale, theme, width]);
// Phone minimum width: the Shopping overview (bottom bar with six items, stacked rows).
for (const locale of ['en', 'sk', 'pl'])
    for (const theme of ['light', 'dark'])
        for (const name of ['Shopping/Index', 'Shopping/Index (empty)']) cases.push([name, locale, theme, 360]);

describe('accessibility sweep', () => {
    it.each(cases)('%s in %s, %s theme, %ipx has no axe violations', async (name, locale, theme, width) => {
        mockLocale = locale;
        document.documentElement.dataset.theme = theme;
        window.innerWidth = width;
        wrapper = mountUi(pages[name], { locale, props: pageProps[name] ?? {} });
        await expectNoAxeViolations(wrapper.element);
    });

    it.each([360, 390, 1280])('AppShell with pseudo-long English text at %ipx has no axe violations', async (width) => {
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
