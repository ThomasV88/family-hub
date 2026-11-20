import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { MEMBERS } from './fixtures.js';
import { giftsMemberList, sharedMemberList } from './groupFixtures.js';

let mockProps = {};
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: mockProps, url: '/wishlist' }),
        router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import Index from '../../../resources/js/Pages/Wishlist/Index.vue';
import GroupPanel from '../../../resources/js/Wishlist/GroupPanel.vue';

const LONG_NAME = 'Veľmi dlhý názov spoločného zoznamu prianí celej rodiny';
const MANY = Array.from({ length: 12 }, (_, i) => ({ id: 20 + i, name: `Člen s dlhým menom číslo ${i + 1}` }));
const GROUPS = [{ id: 7, name: LONG_NAME, mode: 'gifts', is_member: true }, { id: 8, name: 'Druhý zoznam', mode: 'shared', is_member: true }];
const CANDIDATES = [{ id: 2, name: 'Mira' }, ...MANY];

let current;
function mountIndex(list, locale, width = 320) {
    window.innerWidth = width;
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ groups: [] }) }));
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'], list,
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { wishlist: 'edit' } },
        app: { env: 'local', styleGuide: false },
    };
    current = mount(Index, {
        props: { members: MEMBERS, selected: null, list, groups: GROUPS, group_candidates: CANDIDATES },
        attachTo: document.body, global: { plugins: [createAppI18n(locale, pseudoLong(loadMessages(locale)))] },
    });
    return current;
}
const longGroup = { name: LONG_NAME, members: MANY };
const noEllipsis = (el) => [...el.querySelectorAll('[class*="truncate"], [class*="ellipsis"], [class*="line-clamp"]')].map((n) => n.textContent.trim()).filter(Boolean);

afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; delete globalThis.fetch; });

describe('group wishlist screens with pseudo-long text (+40 percent, 320 px)', () => {
    for (const locale of ['sk', 'pl']) {
        it(`chips and gifts header keep the long name and every member name whole (${locale})`, async () => {
            const w = mountIndex(giftsMemberList({}, longGroup), locale);
            await flushPromises();
            expect(w.text()).toContain(LONG_NAME);
            const header = w.find('[data-testid="group-header"]');
            for (const m of MANY) expect(header.text()).toContain(m.name);
            expect(noEllipsis(w.element)).toEqual([]);
        });

        it(`shared view and the received_by line keep text whole (${locale})`, async () => {
            const w = mountIndex(sharedMemberList({}, longGroup), locale);
            await flushPromises();
            expect(w.find('[data-testid="group-header"]').text()).toContain(LONG_NAME);
            expect(w.find('[data-testid="received-by"]').exists()).toBe(true);
            expect(noEllipsis(w.element)).toEqual([]);
        });

        it(`the panel keeps the mode hints, the member checkbox list and the confirmations whole (${locale})`, async () => {
            window.innerWidth = 320;
            const messages = pseudoLong(loadMessages(locale));
            const w = mount(GroupPanel, {
                props: { open: true, mode: 'edit', group: { id: 7, name: LONG_NAME, mode: 'gifts', is_member: true, can_manage: true, members: MANY }, candidates: CANDIDATES, viewerId: 2 },
                attachTo: document.body, global: { plugins: [createAppI18n(locale, messages)] },
            });
            current = w;
            await flushPromises();
            const g = messages.wishlist.group;
            const text = () => document.body.textContent;
            expect(document.body.querySelectorAll('input[type="radio"]')).toHaveLength(2);
            for (const m of MANY) expect(text()).toContain(m.name);
            expect(noEllipsis(document.body)).toEqual([]);
            for (const b of document.body.querySelectorAll('button')) expect(b.className).not.toMatch(/whitespace-nowrap/);

            document.body.querySelectorAll('input[type="radio"]')[1].click();
            await flushPromises();
            [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === g.save).click();
            await flushPromises();
            expect(text()).toContain(g.mode_change_title);
            expect(text()).toContain(g.mode_change_body);
            expect(text()).toContain(g.mode_change_confirm);
            expect(noEllipsis(document.body)).toEqual([]);
        });
    }

    it('keeps the chips and the panel CSS free of nowrap and truncation', () => {
        for (const f of ['GroupChips', 'GroupHeader', 'GroupPanel', 'GiftListView', 'SharedListView']) {
            const src = readFileSync(`resources/js/Wishlist/${f}.vue`, 'utf8');
            expect(src, f).not.toMatch(/text-overflow:\s*ellipsis|line-clamp|truncate/);
        }
    });

    it('uses no v-html anywhere in the Wishlist components', () => {
        for (const f of ['GroupChips', 'GroupHeader', 'GroupPanel', 'GiftListView', 'SharedListView', 'OwnListView', 'GiftWishPanel', 'OwnWishPanel', 'ReservationBox']) {
            expect(readFileSync(`resources/js/Wishlist/${f}.vue`, 'utf8'), f).not.toContain('v-html');
        }
    });
});
