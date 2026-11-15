import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';

let mockProps = {};
let mockUrl = '/wishlist/occasions';
const calls = { get: vi.fn() };
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: mockProps, get url() { return mockUrl; } }),
        router: { get: (...a) => calls.get(...a), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import Occasions from '../../../resources/js/Pages/Wishlist/Occasions.vue';
import OccasionList from '../../../resources/js/Wishlist/OccasionList.vue';
import MemberDatesTable from '../../../resources/js/Wishlist/MemberDatesTable.vue';

const UPCOMING = [
    { key: 'a', type: 'birthday', owner: { id: 1, name: 'Adam' }, title: null, date: '2027-03-01', days_away: 0, occasion_id: null },
    { key: 'b', type: 'nameday', owner: { id: 3, name: 'Zora' }, title: null, date: '2027-03-02', days_away: 1, occasion_id: 5 },
    { key: 'c', type: 'christmas', owner: null, title: null, date: '2027-12-24', days_away: 298, occasion_id: null },
    { key: 'd', type: 'custom', owner: null, title: 'Wedding <b>day</b>', date: '2027-06-12', days_away: 103, occasion_id: 9 },
];
const MEMBERS = [
    { id: 2, name: 'Mira', is_viewer: true, birth: { month: 3, day: 15 }, birth_date: '1980-03-15', nameday: null, can_edit_dates: true },
    { id: 1, name: 'Adam', is_viewer: false, birth: { month: 5, day: 5 }, birth_date: null, nameday: { calendar: 'sk', name: 'Adam', month: 12, day: 24 }, can_edit_dates: false },
    { id: 3, name: 'Zora', is_viewer: false, birth: null, birth_date: null, nameday: null, can_edit_dates: false },
];
const CUSTOM = [
    { id: 9, title: 'Wedding <b>day</b>', month: 6, day: 12, year: null, owner: null, can_edit: true },
    { id: 10, title: 'Trip', month: 8, day: 1, year: 2027, owner: { id: 1, name: 'Adam' }, can_edit: false },
];
const base = { upcoming: UPCOMING, members: MEMBERS, custom: CUSTOM, can_add: true, today: '2027-03-01' };

let current;
const plugin = (locale, messages) => createAppI18n(locale, messages ?? loadMessages(locale));
function mountPage(props = {}, { locale = 'en', messages } = {}) {
    window.innerWidth = 1280;
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    mockProps = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'edit', finance: 'none', health: 'none' } },
        app: { env: 'local', styleGuide: false },
    });
    current = mount(Occasions, { props: { ...base, ...props }, attachTo: document.body, global: { plugins: [plugin(locale, messages)] } });
    return current;
}
beforeEach(() => { calls.get.mockReset(); mockUrl = '/wishlist/occasions'; });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; delete document.documentElement.dataset.theme; });

describe('Occasions upcoming list', () => {
    it('renders type, title, date, days-away tag and the list link per row', () => {
        const w = mountPage();
        const rows = w.findAll('[data-testid="upcoming-list"] > li');
        expect(rows).toHaveLength(4);
        expect(rows[0].text()).toContain("Adam's birthday");
        expect(rows[0].text()).toContain('Today');
        expect(rows[0].find('a').attributes('href')).toBe('/wishlist?member=1');
        expect(rows[0].text()).toContain('Open Adam\'s list');
        expect(rows[1].text()).toContain("Zora's name day");
        expect(rows[1].text()).toContain('Tomorrow');
        expect(rows[2].text()).toContain('Christmas');
        expect(rows[2].text()).toContain('in 298 days');
        expect(rows[2].find('a').attributes('href')).toBe('/wishlist');
        expect(rows[2].text()).toContain('Open wishlists');
        expect(rows[0].find('time').attributes('datetime')).toBe('2027-03-01');
        expect(rows[0].find('[role="img"]').attributes('aria-label')).toBe('Birthday');
        expect(rows[3].find('[role="img"]').attributes('aria-label')).toBe('Custom occasion');
    });

    it('renders titles as text, never as HTML', () => {
        const w = mountPage();
        expect(w.find('[data-testid="upcoming-list"]').html()).not.toContain('<b>day</b>');
        expect(w.text()).toContain('Wedding <b>day</b>');
    });

    it('uses the warn tag within three days and neutral beyond', () => {
        const w = mountPage();
        const tags = w.findAll('[data-testid="upcoming-list"] .ui-tag');
        expect(tags[0].classes().join(' ')).toContain('warn');
        expect(tags[1].classes().join(' ')).toContain('warn');
        expect(tags[2].classes().join(' ')).not.toContain('warn');
    });

    it('shows the empty state with an Add action only when the viewer can add', async () => {
        const w = mount(OccasionList, { props: { upcoming: [], canAdd: true }, attachTo: document.body, global: { plugins: [plugin('en')] } });
        expect(w.text()).toContain('No occasions yet');
        const add = w.findAll('button').find((b) => b.text() === 'Add occasion');
        await add.trigger('click');
        expect(w.emitted('add')).toHaveLength(1);
        await w.setProps({ canAdd: false });
        expect(w.findAll('button')).toHaveLength(0);
        w.unmount();
    });
});

describe('Dates by member', () => {
    it('shows day and month only, Not set muted, and Edit only where allowed', () => {
        const w = mount(MemberDatesTable, { props: { members: MEMBERS }, attachTo: document.body, global: { plugins: [plugin('en')] } });
        const text = w.text();
        expect(text).toContain('15 March');
        expect(text).toContain('5 May');
        expect(text).not.toContain('1980');
        expect(text).toContain('Not set');
        const buttons = w.findAll('button');
        expect(buttons).toHaveLength(1);
        expect(buttons[0].text()).toBe('Edit dates');
        w.unmount();
    });

    it('prompts Add birth date for an editable member without one', () => {
        const w = mount(MemberDatesTable, { props: { members: [{ ...MEMBERS[0], birth: null, birth_date: null }] }, attachTo: document.body, global: { plugins: [plugin('en')] } });
        expect(w.find('button').text()).toBe('Add birth date');
        w.unmount();
    });
});

describe('Occasions page', () => {
    it('lists custom occasions with Edit only for editable rows and opens the dates panel', async () => {
        const w = mountPage();
        const rows = w.findAll('[data-testid="custom-list"] > li');
        expect(rows).toHaveLength(2);
        expect(rows[0].find('button').text()).toBe('Edit occasion');
        expect(rows[1].find('button').exists()).toBe(false);

        await w.find('[data-testid="dates-table"] button').trigger('click');
        await flushPromises();
        expect(document.body.querySelector('[data-testid="occasion-panel"]')).not.toBeNull();
        expect(calls.get).toHaveBeenCalledWith('/wishlist/occasions', { panel: 'dates', member: 2 }, expect.anything());
    });

    it('opens the custom panel from a deep link and ignores one the viewer may not use', async () => {
        mockUrl = '/wishlist/occasions?panel=custom&occasion=9';
        const w = mountPage();
        await flushPromises();
        expect(document.body.querySelector('h2.ui-panel__title').textContent).toBe('Edit occasion');
        w.unmount();
        document.body.innerHTML = '';

        mockUrl = '/wishlist/occasions?panel=custom&occasion=10';
        mountPage();
        await flushPromises();
        expect(document.body.querySelector('[data-testid="occasion-panel"]')).toBeNull();
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`has no axe violations (${locale}, ${dark ? 'dark' : 'light'})`, async () => {
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                mountPage({}, { locale });
                await flushPromises();
                await expectNoAxeViolations(document.body);
            });
        }
    }

    it('renders pseudo-long copy without axe violations', async () => {
        mountPage({}, { locale: 'sk', messages: pseudoLong(loadMessages('sk')) });
        await flushPromises();
        await expectNoAxeViolations(document.body);
    });
});
