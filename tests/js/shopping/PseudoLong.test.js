import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { installHelp } from '../helpers/help.js';
import { resetHelp } from '../../../resources/js/Help/helpStore.js';

let mockProps = {};
vi.mock('@inertiajs/vue3', async () => {
    const { h, reactive } = await import('vue');
    return {
        usePage: () => ({ props: mockProps, url: '/shopping' }),
        router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() },
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
        useForm: (data) => reactive({ ...data, processing: false, errors: {}, post: vi.fn(), put: vi.fn(), delete: vi.fn(), transform() { return this; }, clearErrors() {} }),
    };
});

import Index from '../../../resources/js/Pages/Shopping/Index.vue';
import Show from '../../../resources/js/Pages/Shopping/Show.vue';
import SettingsShopping from '../../../resources/js/Pages/Settings/Shopping.vue';

const person = (id, name) => ({ id, name, isChild: false });
const LONG_NAME = 'Veľmi dlhý názov položky na nákupnom zozname';
const item = (id, name, extra = {}) => ({ id, name, quantity: '1.00', unitId: 1, note: null, state: 'pending', catalogItemId: null, imageVersion: null, ...extra });
const LIST = {
    id: 5, name: 'Weekly shop', status: 'open', store: null, storeName: '', creator: person(1, 'Mira'), responsible: person(1, 'Mira'),
    responsibleFallback: null, remindAt: null, reminderPassed: false, reminderSent: false, lockedAt: null, doneAt: null, source: null,
};
const PLAN = { editItems: true, lock: true, unlock: false, mark: false, finish: false, copy: true, mail: true, delete: true, manageCatalog: false };
const SHOP = { editItems: false, lock: false, unlock: true, mark: true, finish: true, copy: true, mail: true, delete: false };

let current;
function mountPage(component, props, locale = 'en', width = 390) {
    window.innerWidth = width;
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 1, name: 'Mira', email: 'm@example.test', isAdmin: true }, access: { shopping: 'edit' } },
        app: { env: 'local', styleGuide: false },
    };
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(component, { props, attachTo: document.body, global: { plugins: [createAppI18n(locale, pseudoLong(loadMessages(locale)))] } });
    return current;
}
const showProps = (list, abilities) => ({
    list, abilities, members: { contributors: [], viewers: [] }, units: [{ id: 1, key: 'pcs', names: null }], stores: [], catalog: [],
    items: [item(1, LONG_NAME), item(2, 'Bread', { state: 'bought' })],
});
const noEllipsis = (el) => {
    const clamped = el.querySelectorAll('[class*="truncate"], [class*="ellipsis"], [class*="line-clamp"]');
    return [...clamped].map((n) => n.textContent.trim()).filter(Boolean);
};

afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; resetHelp(); });

describe('Shopping pages with pseudo-long text (+40 percent)', () => {
    it('Show (open) keeps item names whole and lets the buttons wrap', () => {
        installHelp('en');
        mountPage(Show, showProps({ ...LIST }, PLAN));
        const names = current.findAll('[data-testid="item-row"]').map((r) => r.text());
        expect(names[0]).toContain(LONG_NAME);
        expect(noEllipsis(current.element).filter((t) => t.includes('Veľmi'))).toEqual([]);
        for (const b of current.findAll('button')) expect(b.classes().join(' ')).not.toMatch(/whitespace-nowrap/);
    });

    it('Show (locked) keeps item names whole', () => {
        installHelp('en');
        mountPage(Show, showProps({ ...LIST, status: 'locked', lockedAt: '2026-11-17T10:00:00+01:00' }, SHOP));
        expect(current.findAll('[data-testid="item-row"]')[0].text()).toContain(LONG_NAME);
        expect(noEllipsis(current.element).filter((t) => t.includes('Veľmi'))).toEqual([]);
    });

    it('Index renders long list names whole', () => {
        const lists = [{ id: 1, name: LONG_NAME, status: 'open', store: { id: 4, name: 'Tesco', logoVersion: 2 }, responsible: person(1, 'Mira'), remindAt: null, reminderPassed: false, itemsCount: 0, boughtCount: 0, unavailableCount: 0, abilities: { copy: true } }];
        mountPage(Index, { tab: 'open', lists, archive: { data: [], currentPage: 1, lastPage: 1, perPage: 20, total: 0 }, counts: { open: 1, archive: 0 }, stores: [], responsibleCandidates: [person(1, 'Mira')], canCreate: true });
        expect(current.find('[data-testid="list-row"]').text()).toContain(LONG_NAME);
        expect(noEllipsis(current.element).filter((t) => t.includes('Veľmi'))).toEqual([]);
    });

    it('Index at 360 px keeps a locked row with progress readable', () => {
        const lists = [{ id: 2, name: LONG_NAME, status: 'locked', itemsCount: 6, boughtCount: 2, store: { id: 5, name: 'Zelený košík', logoVersion: null }, responsible: person(3, 'Leon Novák'), remindAt: null, reminderPassed: false, unavailableCount: 0, abilities: { copy: true } }];
        mountPage(Index, { tab: 'open', lists, archive: { data: [], currentPage: 1, lastPage: 1, perPage: 20, total: 0 }, counts: { open: 1, archive: 0 }, stores: [], responsibleCandidates: [person(1, 'Mira')], canCreate: true }, 'sk', 360);
        const row = current.find('[data-testid="list-row"]');
        expect(row.classes()).toContain('shop-list-row');
        expect(row.text()).toContain(LONG_NAME);
        const meta = row.find('.shop-list-row__meta').text();
        expect(meta).toContain('Zelený košík');
        expect(meta).toContain('Leon');
        expect(row.find('[role="progressbar"], progress, .ui-progress').exists()).toBe(true);
        const trailing = row.find('.ui-row__trailing');
        expect(trailing.exists()).toBe(true);
        expect(trailing.find('button').exists()).toBe(true);
        expect(trailing.text().length).toBeGreaterThan(0);
        expect(noEllipsis(row.element)).toEqual([]);
    });

    it('ListRow CSS stacks the trailing cluster under the text at narrow widths', () => {
        const css = readFileSync('resources/js/Shopping/ListRow.vue', 'utf8');
        const media = css.match(/@media \(max-width: 480px\) \{([\s\S]*)\}\s*<\/style>/)?.[1] ?? '';
        expect(media).toMatch(/\.shop-list-row \{[^}]*flex-wrap: wrap/);
        expect(media).toContain('flex-basis: 100%');
        const title = css.match(/\.shop-list-row :deep\(\.ui-row__title\) \{([^}]*)\}/)?.[1] ?? '';
        expect(title).toContain('white-space: normal');
        expect(title).toContain('overflow-wrap: break-word');
        expect(title).not.toContain('anywhere');
    });

    it('Settings/Shopping renders without clipped names', () => {
        installHelp('en');
        mountPage(SettingsShopping, {
            sections: [{ key: 'shopping', href: '/settings/shopping', labelKey: 'settings.section.shopping', icon: 'shopping-cart' }],
            catalog: [{ id: 1, name: LONG_NAME, defaultUnitId: 1, imageVersion: null, usedOn: 2 }],
            stores: [{ id: 1, name: LONG_NAME, logoVersion: null, usedOn: 1 }],
            units: [{ id: 1, key: 'pcs', names: null, builtIn: true, usedOn: 0 }], canManageUnits: false,
        });
        expect(current.find('[data-testid="catalog-row"]').text()).toContain(LONG_NAME);
        expect(noEllipsis(current.element).filter((t) => t.includes('Veľmi'))).toEqual([]);
    });
});
