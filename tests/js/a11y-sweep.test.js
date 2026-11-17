import { describe, it, vi, afterEach } from 'vitest';
import { flushPromises } from '@vue/test-utils';
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
                auth: { user: { id: 1, name: 'Mira', isAdmin: true }, access: { todo: 'edit', wishlist: 'edit', finance: 'edit', health: 'edit', shopping: 'edit', recipes: 'edit' } },
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
import RecipesIndex from '../../resources/js/Pages/Recipes/Index.vue';
import RecipesShow from '../../resources/js/Pages/Recipes/Show.vue';
import RecipesForm from '../../resources/js/Pages/Recipes/Form.vue';
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
    'Recipes/Index': RecipesIndex, 'Recipes/Index (empty)': RecipesIndex, 'Recipes/Index (draft)': RecipesIndex,
    'Recipes/Show (author)': RecipesShow, 'Recipes/Show (non-author)': RecipesShow,
    'Recipes/Form (create)': RecipesForm, 'Recipes/Form (edit)': RecipesForm, 'Recipes/Form (review)': RecipesForm,
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
const recUnits = [{ id: 1, key: 'pcs', names: null }, { id: 2, key: 'g', names: null }, { id: 9, key: 'tsp', names: null }];
const recCard = (id, extra = {}) => ({
    id, title: `Recipe ${id}`, mainPhoto: null, prepMinutes: 10, cookMinutes: 20, servings: 4, visibility: 'shared', categories: ['Soup', 'Vegetarian'],
    author: { id: 1, name: 'Mira', isMe: true }, favourite: false, ...extra,
});
const recIndexBase = {
    hasMore: false, page: 1, filters: {}, canCreate: true,
    filterOptions: { categories: [{ name: 'Soup', key: 'soup' }], authors: [{ id: 1, name: 'Mira' }, { id: 5, name: 'Anna' }] },
};
pageProps['Recipes/Index'] = {
    ...recIndexBase,
    recipes: [recCard(1, { favourite: true }), recCard(2, { visibility: 'private', author: { id: 5, name: 'Anna', isMe: false }, categories: [], prepMinutes: null, cookMinutes: null, servings: null }), recCard(3)],
};
pageProps['Recipes/Index (empty)'] = { ...recIndexBase, recipes: [] };
pageProps['Recipes/Index (draft)'] = {
    ...recIndexBase, recipes: [recCard(1)],
    import: { available: true, acknowledged: true, providers: ['codex'] },
    drafts: [
        { id: '33333333-3333-4333-8333-333333333333', status: 'ready', host: 'recepty.example', reason: null },
        { id: '44444444-4444-4444-8444-444444444444', status: 'failed', host: 'blog.example', reason: 'no_recipe' },
    ],
};
const recDetail = {
    id: 12, title: 'Tomato soup', description: 'Warm and simple', servings: 4, prepMinutes: 10, cookMinutes: 30, visibility: 'shared',
    sourceUrl: 'https://www.example.test/soup', sourceHost: 'www.example.test', createdVia: 'manual',
    author: { id: 1, name: 'Mira', isMe: true }, categories: ['Soup'],
    ingredients: [
        { id: 101, name: 'Flour', quantity: '200', unitId: 2, note: 'sifted' },
        { id: 102, name: 'Eggs', quantity: '2', unitId: 1, note: null },
        { id: 103, name: 'Salt', quantity: null, unitId: null, note: 'to taste' },
    ],
    steps: [{ id: 201, body: 'Boil water' }, { id: 202, body: 'Serve' }],
    photos: [{ id: 31, position: 1, isMain: true, version: 1, thumbUrl: '/recipes/12/photos/31/thumb?v=1', fullUrl: '/recipes/12/photos/31/full?v=1' }],
};
const recState = { favourite: true, note: 'Less salt', servings: null, tickedIngredientIds: [101], doneStepIds: [201] };
pageProps['Recipes/Show (author)'] = { recipe: recDetail, state: recState, units: recUnits, abilities: { edit: true, delete: true, changeVisibility: true, addToShopping: true } };
pageProps['Recipes/Show (non-author)'] = {
    recipe: { ...recDetail, author: { id: 3, name: 'Anna', isMe: false } }, state: { ...recState, favourite: false, note: null },
    units: recUnits, abilities: { edit: false, delete: false, changeVisibility: false, addToShopping: true },
};
const recLimits = {
    titleMax: 120, descriptionMax: 1000, ingredientNameMax: 120, ingredientNoteMax: 80, stepMax: 1000, ingredientsMax: 80, stepsMax: 60,
    categoriesMax: 3, categoryMax: 30, servingsMin: 1, servingsMax: 99, minutesMax: 1440, quantityMax: 9999, sourceUrlMax: 2000, photosMax: 10, photoMaxBytes: 5242880,
};
const recFormBase = {
    recipe: null, defaults: { servings: 4, visibility: 'private' }, units: recUnits, categorySuggestions: ['Polievka'],
    starterCategories: ['breakfast', 'soup', 'dessert'], limits: recLimits, isShared: false, canAddToShopping: true,
};
pageProps['Recipes/Form (create)'] = recFormBase;
pageProps['Recipes/Form (edit)'] = { ...recFormBase, recipe: recDetail, isShared: true };
pageProps['Recipes/Form (review)'] = {
    ...recFormBase,
    prefill: {
        title: 'Palacinky', description: null, servings: null, prep_minutes: 10, cook_minutes: null, categories: ['Dessert'],
        ingredients: [{ name: 'flour', quantity: '250', unit_id: 2, note: null, original: '250 g flour' }, { name: 'eggs', quantity: '2', unit_id: 1, note: null, original: '2 eggs' }],
        steps: [{ body: 'Mix everything.' }], aiFields: ['title', 'ingredients', 'steps'],
        sourceUrl: 'https://recepty.example/palacinky', sourceHost: 'recepty.example', textCut: false, importId: '33333333-3333-4333-8333-333333333333',
    },
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

// Phone minimum width for the Recipes pages (seven-item bottom bar).
for (const locale of ['en', 'sk', 'pl'])
    for (const theme of ['light', 'dark'])
        for (const name of ['Recipes/Index', 'Recipes/Show (author)', 'Recipes/Form (create)']) cases.push([name, locale, theme, 360]);

describe('accessibility sweep', () => {
    it.each(cases)('%s in %s, %s theme, %ipx has no axe violations', async (name, locale, theme, width) => {
        mockLocale = locale;
        document.documentElement.dataset.theme = theme;
        window.innerWidth = width;
        wrapper = mountUi(pages[name], { locale, props: pageProps[name] ?? {} });
        await expectNoAxeViolations(wrapper.element);
    });

    it.each([['en', 'light'], ['sk', 'dark'], ['pl', 'light']])('Recipes/Show in cooking mode in %s, %s theme, 390px has no axe violations', async (locale, theme) => {
        mockLocale = locale;
        document.documentElement.dataset.theme = theme;
        window.innerWidth = 400;
        wrapper = mountUi(RecipesShow, { locale, props: pageProps['Recipes/Show (author)'] });
        await flushPromises();
        await wrapper.find('[data-testid="cooking-toggle-bar"]').trigger('click');
        await flushPromises();
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
