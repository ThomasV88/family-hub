import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';

let mockProps = {};
const get = vi.fn();
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: mockProps }),
        router: { get: (...a) => get(...a), post: vi.fn(), visit: vi.fn(), on: vi.fn() },
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import Audit from '../../../resources/js/Pages/Settings/Audit.vue';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
const SECTIONS = [
    { key: 'audit', href: '/settings/audit', labelKey: 'settings.section.audit', icon: 'scroll-text' },
    { key: 'appearance', href: '/settings/appearance', labelKey: 'settings.section.appearance', icon: 'palette' },
];
const ENTRIES = [
    { id: 3, occurredAt: '2026-05-12T10:00:00+00:00', actorLabel: 'Anna', event: 'permission_changed', group: 'permission', module: 'finance', targetLabel: 'Bob', meta: { from: 'none', to: 'view' }, ip: '203.0.113.7' },
    { id: 2, occurredAt: '2026-05-12T09:00:00+00:00', actorLabel: null, event: 'login_failed', group: 'login_failed', module: null, targetLabel: null, meta: {}, ip: '203.0.113.8' },
    { id: 1, occurredAt: '2026-05-12T08:00:00+00:00', actorLabel: 'Bob', event: 'health_read', group: 'health', module: 'health', targetLabel: null, meta: {}, ip: null },
];
const GROUPS = ['login', 'login_failed', 'invite', 'permission', 'session_revoked', 'health', 'finance', 'account'];

let current;
function mountPage({ locale = 'en', ...props } = {}) {
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 1, name: 'Anna', email: 'a@example.test', isAdmin: true }, access: {} },
        app: { env: 'local', styleGuide: false },
    };
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(Audit, {
        props: {
            sections: SECTIONS, entries: ENTRIES, pagination: { page: 1, lastPage: 1, total: 3 },
            members: [{ id: 1, name: 'Anna' }, { id: 2, name: 'Bob' }], groups: GROUPS,
            filters: { member: null, event: null, from: null, to: null }, error: null, ...props,
        },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, load(locale))] },
    });
    return current;
}
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; get.mockClear(); });

describe('Audit page', () => {
    it('shows heading, intro, hidden caption, scoped headers and composed details', () => {
        mountPage();
        expect(current.find('h1').text()).toBe('Audit log');
        expect(current.text()).toContain('Content of records is never logged');
        const caption = current.find('caption');
        expect(caption.text()).toBe('Audit log entries');
        expect(caption.classes()).toContain('visually-hidden');
        const heads = current.findAll('th');
        expect(heads.map((h) => h.text())).toEqual(['When', 'Member', 'Event', 'Details', 'IP address']);
        expect(heads.every((h) => h.attributes('scope') === 'col')).toBe(true);
        expect(current.text()).toContain('Changed Finances for Bob: None to View');
        expect(current.text()).toContain('Read Health data');
        expect(current.findAll('time')[0].attributes('datetime')).toBe(ENTRIES[0].occurredAt);
        expect(current.find('.audit-table__ip').text()).toBe('203.0.113.7');
    });

    it('offers no row actions, checkboxes, delete or export controls', () => {
        mountPage();
        const table = current.find('table');
        expect(table.findAll('button, input, a')).toHaveLength(0);
        expect(current.findAll('input[type=checkbox]')).toHaveLength(0);
    });

    it('applies filters through the URL query and clears them', async () => {
        mountPage();
        await current.findAll('select')[0].setValue('2');
        await current.findAll('select')[1].setValue('finance');
        await current.findAll('input[type=date]')[0].setValue('2026-05-01');
        await current.find('form').trigger('submit');
        expect(get).toHaveBeenCalledWith('/settings/audit', { member: '2', event: 'finance', from: '2026-05-01' }, expect.any(Object));
        const clear = current.findAll('button').find((b) => b.text() === 'Clear filters');
        await clear.trigger('click');
        expect(get).toHaveBeenLastCalledWith('/settings/audit', {}, expect.any(Object));
    });

    it('echoes the filters from the server', () => {
        mountPage({ filters: { member: 2, event: 'health', from: '2026-05-01', to: '2026-05-31' } });
        expect(current.findAll('select')[0].element.value).toBe('2');
        expect(current.findAll('select')[1].element.value).toBe('health');
        expect(current.findAll('input[type=date]')[1].element.value).toBe('2026-05-31');
    });

    it('paginates with Previous and Next and moves focus to the caption', async () => {
        mountPage({ pagination: { page: 2, lastPage: 3, total: 60 } });
        expect(current.text()).toContain('Page 2 of 3');
        const next = current.findAll('button').find((b) => b.text() === 'Next');
        await next.trigger('click');
        expect(get).toHaveBeenCalledWith('/settings/audit', { page: 3 }, expect.objectContaining({ onFinish: expect.any(Function) }));
        get.mock.calls[0][2].onFinish();
        await flushPromises();
        expect(document.activeElement.tagName).toBe('CAPTION');
    });

    it('disables Previous on the first page', () => {
        mountPage({ pagination: { page: 1, lastPage: 2, total: 30 } });
        expect(current.findAll('button').find((b) => b.text() === 'Previous').element.disabled).toBe(true);
    });

    it('shows the empty state with a clear action', () => {
        mountPage({ entries: [], pagination: { page: 1, lastPage: 1, total: 0 } });
        expect(current.text()).toContain('No entries match');
        expect(current.find('table').exists()).toBe(false);
    });

    it('shows the range error', () => {
        mountPage({ error: 'audit.error.range' });
        expect(current.find('[role=alert]').text()).toContain('start date must be on or before');
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations in %s, light and dark', async (locale) => {
        for (const theme of ['light', 'dark']) {
            document.documentElement.dataset.theme = theme;
            for (const props of [{}, { entries: [] }, { error: 'audit.error.range', pagination: { page: 1, lastPage: 2, total: 30 } }]) {
                mountPage({ locale, ...props });
                await expectNoAxeViolations(document.body);
                current.unmount();
                current = null;
            }
        }
        delete document.documentElement.dataset.theme;
    });
});
