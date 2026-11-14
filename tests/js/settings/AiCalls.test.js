import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { createAppI18n } from '../../../resources/js/i18n.js';

let mockProps = {};
const get = vi.fn();
const post = vi.fn();
const reload = vi.fn();
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: mockProps }),
        router: { get: (...a) => get(...a), post: (...a) => post(...a), reload: (...a) => reload(...a), visit: vi.fn(), on: vi.fn() },
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import AiCalls from '../../../resources/js/Pages/Settings/AiCalls.vue';
import SettingsLayout from '../../../resources/js/Layouts/SettingsLayout.vue';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
const src = (p) => readFileSync(`${process.cwd()}/${p}`, 'utf8');
const SECTIONS = [
    { key: 'ai_calls', href: '/settings/ai-calls', labelKey: 'settings.section.ai_calls', icon: 'bot' },
    { key: 'appearance', href: '/settings/appearance', labelKey: 'settings.section.appearance', icon: 'palette' },
];
const PERSON = '11111111-1111-4111-8111-111111111111';
const ROWS = [
    { time: '2026-11-14T10:00:00+00:00', startedBy: 'Anna', person: 'Eva', agent: 'codex', model: 'gpt-5-codex', status: 'succeeded', durationMs: 12000 },
    { time: '2026-11-14T09:00:00+00:00', startedBy: 'Bob', person: null, agent: 'codex', model: 'default', status: 'failed', durationMs: 65000 },
    { time: '2026-11-14T08:00:00+00:00', startedBy: 'Bob', person: 'Eva', agent: 'codex', model: null, status: 'running', durationMs: null },
    { time: '2026-11-14T07:00:00+00:00', startedBy: 'Anna', person: 'Eva', agent: 'codex', model: null, status: 'queued', durationMs: null },
    { time: '2026-11-14T06:00:00+00:00', startedBy: 'Anna', person: 'Eva', agent: 'codex', model: 'default', status: 'cancelled', durationMs: 3000 },
];
const NO_FILTERS = { person: null, member: null, status: null };
const OPTIONS = { people: [{ id: PERSON, name: 'Eva' }], members: [{ id: 1, name: 'Anna' }, { id: 2, name: 'Bob' }] };
const unit = (n, u) => new Intl.NumberFormat('en-GB', { style: 'unit', unit: u, unitDisplay: 'short', maximumFractionDigits: 0 }).format(n);
const LOGIN = (state, checkedAt = '2026-11-14T10:30:00+00:00') => ({ agent: 'codex', state, checkedAt });

let current;
function mountPage({ locale = 'en', messages, ...props } = {}) {
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 1, name: 'Anna', email: 'a@example.test', isAdmin: true }, access: {} },
        app: { env: 'local', styleGuide: false },
    };
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(AiCalls, {
        props: {
            sections: SECTIONS, login: LOGIN('logged_in'),
            calls: { rows: ROWS, page: 1, pages: 1, total: 5 },
            filters: NO_FILTERS, options: OPTIONS, ...props,
        },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, messages ?? load(locale))] },
    });
    return current;
}
beforeEach(() => { post.mockImplementation((url, data, opts) => opts?.onSuccess?.()); });
afterEach(() => {
    current?.unmount(); current = null; document.body.innerHTML = '';
    get.mockReset(); post.mockReset(); reload.mockReset(); vi.useRealTimers();
});

describe('AI calls page: login card', () => {
    const CASES = [
        ['logged_in', 'Logged in', null],
        ['expired', 'Login expired', 'cannot be done from this page'],
        ['not_responding', 'AI service not responding', 'did not answer'],
        ['unknown', 'Not checked yet', 'has not been checked yet'],
    ];
    for (const [state, tag, help] of CASES) {
        it(`renders the ${state} state`, () => {
            mountPage({ login: LOGIN(state, state === 'unknown' ? null : undefined) });
            expect(current.find('h1').text()).toBe('AI calls');
            expect(current.find('h2').text()).toBe('Codex login');
            expect(current.text()).toContain(tag);
            if (help) expect(current.text()).toContain(help);
            expect(current.text()).toContain('AI documents are read by OpenAI Codex.');
            if (state === 'unknown') expect(current.text()).not.toContain('Last checked');
            else expect(current.find('time').exists()).toBe(true);
        });
    }

    it('the expired alert is not role=alert on load and no credential input exists', () => {
        mountPage({ login: LOGIN('expired') });
        expect(current.find('[role="alert"]').exists()).toBe(false);
        expect(current.find('input[type="password"]').exists()).toBe(false);
        expect(current.find('textarea').exists()).toBe(false);
        expect(current.findAll('form')).toHaveLength(0);
        expect(current.findAll('input[type="text"], input:not([type])')).toHaveLength(0);
    });

    it('Check again posts once, goes busy, reloads only login every 3 s and gives up after 30 s', async () => {
        vi.useFakeTimers();
        mountPage({ login: LOGIN('unknown', null) });
        const button = () => current.findAll('button').find((b) => b.text() === 'Check again');
        await button().trigger('click');
        await button().trigger('click');
        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/settings/ai-calls/check');
        expect(button().attributes('aria-busy')).toBe('true');
        expect(button().attributes('disabled')).toBeDefined();

        vi.advanceTimersByTime(3000);
        expect(reload).toHaveBeenCalledTimes(1);
        expect(reload.mock.calls[0][0]).toMatchObject({ only: ['login'] });
        vi.advanceTimersByTime(27000);
        expect(reload).toHaveBeenCalledTimes(10);
        await flushPromises();
        expect(current.text()).toContain('No answer yet');
        expect(button().attributes('disabled')).toBeUndefined();
        expect(button().attributes('aria-busy')).toBeUndefined();
    });

    it('stops polling as soon as a newer check result arrives', async () => {
        vi.useFakeTimers();
        mountPage({ login: LOGIN('unknown', null) });
        const button = () => current.findAll('button').find((b) => b.text() === 'Check again');
        await button().trigger('click');
        vi.advanceTimersByTime(3000);
        await current.setProps({ login: LOGIN('logged_in') });
        vi.advanceTimersByTime(9000);
        expect(reload).toHaveBeenCalledTimes(1);
        expect(button().attributes('disabled')).toBeUndefined();
        expect(current.text()).not.toContain('No answer yet');
    });
});

describe('AI calls page: table', () => {
    it('renders seven columns with Intl time and duration, dashes, removed person and default model', () => {
        mountPage();
        expect(current.findAll('th').map((h) => h.text())).toEqual(['Time', 'Started by', 'Person', 'Agent', 'Model', 'Status', 'Duration']);
        const rows = current.findAll('tbody tr');
        expect(rows).toHaveLength(5);
        const cells = (i) => rows[i].findAll('td').map((c) => c.text());
        expect(cells(0)[0]).toContain('14 Nov 2026');
        expect(cells(0)[0]).toContain('11:00');
        expect(cells(0).slice(1)).toEqual(['Anna', 'Eva', 'OpenAI Codex', 'gpt-5-codex', 'Succeeded', unit(12, 'second')]);
        expect(cells(1)[2]).toBe('Removed person');
        expect(cells(1)[4]).toBe('Default');
        expect(cells(1)[6]).toBe(`${unit(1, 'minute')} ${unit(5, 'second')}`);
        expect(cells(2).slice(4)).toEqual(['-', 'Running', '-']);
        expect(cells(3).slice(4)).toEqual(['-', 'Waiting', '-']);
        expect(cells(4)[5]).toBe('Cancelled');
        expect(current.find('tbody time').attributes('datetime')).toBe(ROWS[0].time);
        expect(current.text()).toContain('Calls older than 12 months are removed automatically.');
    });

    it('rows are not interactive: no anchors or buttons inside the table', () => {
        mountPage();
        const table = current.find('table');
        expect(table.findAll('a, button, [role="button"], [tabindex]')).toHaveLength(0);
        expect(table.findAll('tr').every((r) => !r.element.onclick)).toBe(true);
        expect(current.find('tbody').html()).not.toMatch(/href=/);
    });

    it('status tags carry an icon and text', () => {
        mountPage();
        const tags = current.findAll('tbody .ui-tag');
        expect(tags).toHaveLength(5);
        expect(tags.every((t) => t.find('svg').exists() && t.text().length > 0)).toBe(true);
    });
});

describe('AI calls page: filters', () => {
    const select = (label) => current.findAll('label').find((l) => l.text() === label);

    it('has a labelled group and three labelled selects, and no Clear filters by default', () => {
        mountPage();
        expect(current.find('[role="group"]').attributes('aria-label')).toBe('Filter AI calls');
        for (const label of ['Person', 'Member', 'Status']) {
            const l = select(label);
            expect(l).toBeTruthy();
            expect(current.find(`#${l.attributes('for')}`).element.tagName).toBe('SELECT');
        }
        expect(current.text()).not.toContain('Clear filters');
    });

    it('each filter change issues one partial reload with the right query', async () => {
        mountPage();
        const el = (label) => current.find(`#${select(label).attributes('for')}`);
        await el('Person').setValue(PERSON);
        expect(get).toHaveBeenCalledTimes(1);
        expect(get.mock.calls[0][0]).toBe('/settings/ai-calls');
        expect(get.mock.calls[0][1]).toEqual({ person: PERSON });
        expect(get.mock.calls[0][2]).toMatchObject({ only: ['calls', 'filters'], preserveState: true, preserveScroll: true });

        await el('Member').setValue('2');
        expect(get).toHaveBeenCalledTimes(2);
        expect(get.mock.calls[1][1]).toEqual({ person: PERSON, member: '2' });

        await el('Status').setValue('failed');
        expect(get).toHaveBeenCalledTimes(3);
        expect(get.mock.calls[2][1]).toEqual({ person: PERSON, member: '2', status: 'failed' });
    });

    it('marks the table busy and dimmed during a reload, then clears it', async () => {
        mountPage();
        get.mockImplementation(() => {});
        await current.find(`#${select('Status').attributes('for')}`).setValue('failed');
        expect(current.find('.ai-table').attributes('aria-busy')).toBe('true');
        get.mock.calls[0][2].onFinish();
        await flushPromises();
        expect(current.find('.ai-table').attributes('aria-busy')).toBeUndefined();
    });

    it('shows Clear filters only when a filter is set, and clears with an empty query', async () => {
        mountPage({ filters: { person: null, member: null, status: 'failed' } });
        const clear = current.findAll('button').find((b) => b.text() === 'Clear filters');
        expect(clear).toBeTruthy();
        await clear.trigger('click');
        expect(get.mock.calls[0][1]).toEqual({});
    });
});

describe('AI calls page: states and pagination', () => {
    it('empty', () => {
        mountPage({ calls: { rows: [], page: 1, pages: 1, total: 0 } });
        expect(current.text()).toContain('No AI calls yet');
        expect(current.text()).toContain('When a member runs AI on a document, the call is listed here.');
        expect(current.find('table').exists()).toBe(false);
    });

    it('filtered empty offers Clear filters', async () => {
        mountPage({ calls: { rows: [], page: 1, pages: 1, total: 0 }, filters: { person: null, member: null, status: 'failed' } });
        expect(current.text()).toContain('No calls match these filters.');
        const clear = current.findAll('button').filter((b) => b.text() === 'Clear filters');
        expect(clear.length).toBeGreaterThan(0);
        await clear[clear.length - 1].trigger('click');
        expect(get.mock.calls[0][1]).toEqual({});
    });

    it('load error shows the error state and retries', async () => {
        mountPage();
        get.mockImplementation((u, q, o) => o.onError?.({}));
        await current.find(`#${current.findAll('label').find((l) => l.text() === 'Status').attributes('for')}`).setValue('failed');
        await flushPromises();
        expect(current.text()).toContain('Something went wrong');
        const retry = current.findAll('button').find((b) => b.text() === 'Try loading again');
        expect(retry).toBeTruthy();
        get.mockReset();
        await retry.trigger('click');
        expect(get).toHaveBeenCalledTimes(1);
    });

    it('loading renders a skeleton card and no table', () => {
        mountPage({ calls: null });
        expect(current.find('[aria-busy="true"]').exists()).toBe(true);
        expect(current.findAll('.ui-skeleton').length).toBeGreaterThanOrEqual(6);
        expect(current.find('table').exists()).toBe(false);
    });

    it('pagination: status text, disabled ends, requests with the current filters', async () => {
        mountPage({ calls: { rows: ROWS, page: 2, pages: 3, total: 60 }, filters: { person: null, member: null, status: 'failed' } });
        const status = current.find('[role="status"].ai-table__page');
        expect(status.text()).toBe('Page 2 of 3');
        expect(current.find('.ai-table__pager').attributes('aria-label')).toBe('AI calls, 3 pages');
        await current.findAll('button').find((b) => b.text() === 'Next').trigger('click');
        expect(get.mock.calls[0][1]).toEqual({ status: 'failed', page: 3 });
        await current.findAll('button').find((b) => b.text() === 'Previous').trigger('click');
        expect(get.mock.calls[1][1]).toEqual({ status: 'failed' });
    });

    it('hides the pager for a single page and disables the ends', async () => {
        mountPage();
        expect(current.find('.ai-table__pager').exists()).toBe(false);
        current.unmount();
        mountPage({ calls: { rows: ROWS, page: 1, pages: 2, total: 30 } });
        expect(current.findAll('button').find((b) => b.text() === 'Previous').attributes('disabled')).toBeDefined();
    });
});

describe('plural forms of settings.ai_calls.pages', () => {
    const EXPECT = {
        en: { 0: 'pages', 1: 'page', 2: 'pages', 4: 'pages', 5: 'pages', 12: 'pages', 22: 'pages', 25: 'pages' },
        sk: { 0: 'strán', 1: 'strana', 2: 'strany', 4: 'strany', 5: 'strán', 12: 'strán', 22: 'strán', 25: 'strán' },
        pl: { 0: 'stron', 1: 'strona', 2: 'strony', 4: 'strony', 5: 'stron', 12: 'stron', 22: 'strony', 25: 'stron' },
    };
    for (const locale of ['en', 'sk', 'pl']) {
        it(`${locale} renders the right form for 0, 1, 2, 4, 5, 12, 22, 25`, () => {
            mountPage({ locale, calls: { rows: ROWS, page: 1, pages: 2, total: 30 } });
            const t = createAppI18n(locale, load(locale)).global.t;
            for (const [n, word] of Object.entries(EXPECT[locale])) {
                const text = t('settings.ai_calls.pages', { n: Number(n) }, Number(n));
                expect(text, `${locale} ${n}`).toBe(`${n} ${word}`);
            }
        });
    }
});

describe('settings nav', () => {
    it('shows the AI calls entry with an icon for admins', () => {
        mountPage();
        const link = current.findAll('a').find((a) => a.attributes('href') === '/settings/ai-calls');
        expect(link.text()).toBe('AI calls');
        expect(link.find('svg').exists()).toBe(true);
        expect(link.attributes('aria-current')).toBe('page');
        expect(SettingsLayout).toBeTruthy();
    });
});

describe('accessibility and layout', () => {
    const STATES = {
        logged_in: {},
        expired: { login: LOGIN('expired') },
        not_responding: { login: LOGIN('not_responding') },
        unknown: { login: LOGIN('unknown', null) },
        'multi-page': { calls: { rows: ROWS, page: 2, pages: 3, total: 60 } },
        empty: { calls: { rows: [], page: 1, pages: 1, total: 0 } },
        'filtered-empty': { calls: { rows: [], page: 1, pages: 1, total: 0 }, filters: { person: null, member: null, status: 'failed' } },
        loading: { calls: null },
    };
    for (const locale of ['en', 'sk', 'pl']) {
        for (const theme of ['light', 'dark']) {
            it(`axe clean in ${locale} ${theme} for every state`, async () => {
                document.documentElement.dataset.theme = theme;
                for (const [name, props] of Object.entries(STATES)) {
                    mountPage({ locale, ...props });
                    await expectNoAxeViolations(current.element).catch((e) => { throw new Error(`${name}: ${e.message}`); });
                    current.unmount();
                    document.body.innerHTML = '';
                }
                delete document.documentElement.dataset.theme;
            });
        }
    }

    it('pseudo-long text renders in full at a 320 px stacked layout without truncation hooks', () => {
        document.body.style.width = '320px';
        mountPage({ messages: pseudoLong(load('sk')), locale: 'sk', login: LOGIN('expired'), calls: { rows: ROWS, page: 1, pages: 2, total: 30 } });
        const long = pseudoLong(load('sk')).settings.ai_calls.col_started_by;
        expect(current.html()).toContain(long);
        expect(current.html()).toContain(pseudoLong(load('sk')).settings.ai_login.expired_help);
        const css = ['AiCallsTable', 'AiLoginHealthCard', 'AiCallsFilters'].map((n) => src(`resources/js/Settings/AiCalls/${n}.vue`)).join('\n');
        expect(css).not.toMatch(/text-overflow|line-clamp|ellipsis/);
        expect(src('resources/js/Settings/AiCalls/AiCallsTable.vue')).toMatch(/white-space: normal/);
        document.body.style.width = '';
    });
});

describe('forced colors and reduced motion', () => {
    it('tags keep a currentColor border when forced colors are active', () => {
        for (const file of ['AiCallsTable', 'AiLoginHealthCard']) {
            const text = src(`resources/js/Settings/AiCalls/${file}.vue`);
            expect(text).toMatch(/@media \(forced-colors: active\)[\s\S]*\.ui-tag[\s\S]*border: 1px solid currentColor/);
        }
    });

    it('no transition or animation is declared by the AI calls components', () => {
        for (const file of ['AiCallsTable', 'AiLoginHealthCard', 'AiCallsFilters']) {
            expect(src(`resources/js/Settings/AiCalls/${file}.vue`)).not.toMatch(/transition|animation|@keyframes/);
        }
        expect(src('resources/js/Pages/Settings/AiCalls.vue')).not.toMatch(/transition|animation|@keyframes/);
        mountPage();
        expect(current.html()).not.toMatch(/transition/);
    });
});
