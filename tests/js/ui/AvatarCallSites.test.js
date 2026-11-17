import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

let mockAvatars;
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: mockAvatars === undefined ? {} : { avatars: mockAvatars }, url: '/x' }),
        router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import PersonChips from '../../../resources/js/Wishlist/PersonChips.vue';
import GiftListView from '../../../resources/js/Wishlist/GiftListView.vue';
import ContributorList from '../../../resources/js/Wishlist/ContributorList.vue';
import TaskColumn from '../../../resources/js/Todo/TaskColumn.vue';
import VisibilityField from '../../../resources/js/Todo/VisibilityField.vue';
import HealthAccessCard from '../../../resources/js/Components/Settings/HealthAccessCard.vue';
import PersonHeader from '../../../resources/js/Health/PersonHeader.vue';
import { giverListWithReservations } from '../wishlist/givingFixtures.js';

let current;
const m = (component, props, slots = {}) => {
    window.matchMedia ??= (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ groups: [] }) }));
    current = mount(component, { props, slots, attachTo: document.body, global: { plugins: [createAppI18n('en', loadMessages('en'))] } });
    return current;
};
afterEach(() => { current?.unmount(); current = null; mockAvatars = undefined; document.body.innerHTML = ''; });

const cases = [
    ['PersonChips', 7, () => m(PersonChips, { members: [{ id: 7, name: 'Zora', is_viewer: false }] })],
    ['GiftListView', 1, () => m(GiftListView, { list: giverListWithReservations() })],
    ['ContributorList', 5, () => m(ContributorList, { reservation: { contributors: [{ id: 5, name: 'Petra', amount: null, is_organizer: false }], people: 1, pledged: null }, price: null })],
    ['TaskColumn', 9, () => m(TaskColumn, { name: 'Mira', personId: 9, today: '2026-01-01', timezone: 'Europe/Bratislava' })],
    ['VisibilityField', 3, () => m(VisibilityField, { modelValue: 'selected', memberIds: [], members: [{ id: 3, name: 'Adam' }], me: 2 })],
    ['HealthAccessCard', 4, () => m(HealthAccessCard, { people: [{ id: 'p1', name: 'Eva', hasAccount: true, linkedUserId: 4, members: {} }] })],
    ['PersonHeader', 6, () => m(PersonHeader, { person: { id: 'p2', name: 'Ola', photoUserId: 6, hasAccount: true, canEdit: false, aiConsent: false, ageYears: null, sex: null, bloodType: null } })],
];

describe('avatar call sites', () => {
    it.each(cases)('%s shows the photo of the member in the avatars map', (name, id, build) => {
        mockAvatars = { [id]: 2 };
        const w = build();
        const img = w.find('.ui-avatar img');
        expect(img.exists()).toBe(true);
        expect(img.attributes('src')).toBe(`/people/${id}/photo/thumb?v=2`);
    });

    it.each(cases)('%s keeps the initial without a photo', (name, id, build) => {
        mockAvatars = {};
        const w = build();
        expect(w.find('.ui-avatar img').exists()).toBe(false);
        expect(w.find('.ui-avatar').text()).not.toBe('');
    });

    it('HealthAccessCard and PersonHeader show only the initial for a person without an account', () => {
        mockAvatars = { 4: 2 };
        let w = m(HealthAccessCard, { people: [{ id: 'p1', name: 'Eva', hasAccount: false, linkedUserId: null, members: {} }] });
        expect(w.find('.ui-avatar img').exists()).toBe(false);
        w.unmount();
        w = m(PersonHeader, { person: { id: 'p2', name: 'Ola', photoUserId: null, hasAccount: false, canEdit: false, aiConsent: false, ageYears: null, sex: null, bloodType: null } });
        expect(w.find('.ui-avatar img').exists()).toBe(false);
    });
});

function vueFiles(dir) {
    return readdirSync(dir).flatMap((f) => {
        const p = join(dir, f);
        return statSync(p).isDirectory() ? vueFiles(p) : p.endsWith('.vue') ? [p] : [];
    });
}

describe('source scan', () => {
    // StyleGuide demo avatars are deliberately photo-less; MemberRow is wired by plan 10-05 (entry removed in 10-08).
    const ALLOW = ['StyleGuide/Sections/40-Display.vue', 'Components/Settings/MemberRow.vue'];

    it('every <UiAvatar> binds user-id or src', () => {
        const root = join(process.cwd(), 'resources/js');
        const unbound = [];
        for (const file of vueFiles(root)) {
            const rel = relative(root, file);
            if (ALLOW.includes(rel)) continue;
            const source = readFileSync(file, 'utf8');
            for (const tag of source.match(/<UiAvatar\b[^>]*>/g) ?? []) {
                if (!/(:user-id|:userId|:src)=/.test(tag)) unbound.push(`${rel}: ${tag}`);
            }
        }
        expect(unbound).toEqual([]);
    });

    it('PersonSwitcher keeps its initials chip (decision: no photo next to the health person list)', () => {
        expect(readFileSync('resources/js/Health/PersonSwitcher.vue', 'utf8')).not.toContain('UiAvatar');
    });
});
