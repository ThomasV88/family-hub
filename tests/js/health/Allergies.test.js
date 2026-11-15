import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick, reactive } from 'vue';
import { readFileSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

let mockProps = {};
const { router } = vi.hoisted(() => ({
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() },
}));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps, get url() { return '/health'; } }),
    router,
    Head: { props: ['title'], setup: () => () => null },
    Link: { props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) },
}));

import Person from '../../../resources/js/Pages/Health/Person.vue';

const ID = '11111111-1111-4111-8111-111111111111';
const PERSON = { id: ID, name: 'Ada Novak', birthDate: null, ageYears: 11, sex: 'female', bloodType: null, hasAccount: false, aiConsent: false, canEdit: true, notes: null };
const V1 = { id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', visitedOn: '2026-02-01', doctor: 'Dr Heart', specialtyKey: 'cardiology', specialtyOther: null, diagnosis: 'Murmur, <b>benign</b>', notes: 'Long notes', documentCount: 0 };
const V2 = { id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2', visitedOn: '2025-05-01', doctor: '', specialtyKey: 'cardiology', specialtyOther: null, diagnosis: null, notes: null, documentCount: 0 };
const V3 = { id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa3', visitedOn: '2026-03-01', doctor: 'Dr Sleep', specialtyKey: 'other', specialtyOther: 'Sleep clinic', diagnosis: null, notes: null, documentCount: 0 };
const A1 = { id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb1', substance: 'Wasp <b>venom</b>', reaction: 'Swelling', severity: 'severe' };
const A2 = { id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2', substance: 'Latex', reaction: null, severity: 'moderate' };
const A3 = { id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb3', substance: 'Pollen', reaction: 'Sneezing', severity: 'mild' };
const SPECIALTIES = Object.keys(JSON.parse(readFileSync('lang/en.json', 'utf8')).health.specialty);
const FOLDERS = [
    { key: 'other', otherLabel: 'Sleep clinic', count: 1, latestOn: '2026-03-01', visitIds: [V3.id] },
    { key: 'cardiology', otherLabel: null, count: 2, latestOn: '2026-02-01', visitIds: [V1.id, V2.id] },
];
const TIMELINE = [V3, V1, V2].map((v) => ({ type: 'visit', id: v.id, date: v.visitedOn }));
const base = (over = {}) => ({
    people: [{ id: ID, name: 'Ada Novak', initials: 'AN' }], person: PERSON, canCreate: true, panel: null,
    visits: [V3, V1, V2], folders: FOLDERS, timeline: TIMELINE, timelineFilters: ['other', 'cardiology'], view: 'folders', filter: 'all', specialties: SPECIALTIES, allergies: [A1, A2, A3],
    ...over,
});

let current;
function mountPage(props = {}, { locale = 'en' } = {}) {
    window.innerWidth = 1280;
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    mockProps = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: 'none', health: 'edit' } },
        app: { env: 'local', styleGuide: false },
    });
    current = mount(Person, { props: base(props), attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return current;
}
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); vi.clearAllMocks(); });

const button = (text) => [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === text);
const type = (el, value) => { el.value = value; el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input')); };


describe('allergies card', () => {
    it('lists rows in server order with a severity tag that has text and an icon', () => {
        mountPage();
        const card = document.querySelector('[data-testid="allergy-card"]');
        expect(card.querySelector('h2').textContent).toBe('Allergies');
        const rows = [...card.querySelectorAll('[data-testid="allergy-row"]')];
        expect(rows.map((r) => r.querySelector('.allergies__substance').textContent)).toEqual([A1.substance, 'Latex', 'Pollen']);
        const tags = rows.map((r) => r.querySelector('[data-severity]'));
        expect(tags.map((t) => t.textContent.trim())).toEqual(['Severe', 'Moderate', 'Mild']);
        expect(tags.map((t) => t.dataset.severity)).toEqual(['severe', 'moderate', 'mild']);
        for (const tag of tags) expect(tag.querySelector('svg')).not.toBeNull();
        expect(card.querySelector('b')).toBeNull();
    });

    it('shows the empty state and Add allergy only to editors', () => {
        mountPage({ allergies: [] });
        expect(document.querySelector('[data-testid="allergy-empty"]').textContent).toContain('No allergies recorded');
        expect(document.querySelector('[data-testid="allergy-add"]')).not.toBeNull();
        current.unmount();
        mountPage({ allergies: [], person: { ...PERSON, canEdit: false } });
        expect(document.querySelector('[data-testid="allergy-add"]')).toBeNull();
    });

    it('opens the panel through the router', async () => {
        mountPage();
        document.querySelector('[data-testid="allergy-add"]').click();
        expect(router.get).toHaveBeenLastCalledWith(expect.any(String), { panel: 'new-allergy' }, expect.any(Object));
        document.querySelector('[data-testid="allergy-row"]').click();
        expect(router.get).toHaveBeenLastCalledWith(expect.any(String), { panel: 'allergy', id: A1.id }, expect.any(Object));
    });
});

describe('allergy panel', () => {
    it('has no default severity, validates and posts', async () => {
        mountPage({ panel: { kind: 'new-allergy', id: null } });
        await nextTick();
        const form = document.querySelector('[data-testid="allergy-form"]');
        const select = form.querySelector('select');
        expect(select.value).toBe('');
        document.querySelector('[data-testid="allergy-save"]').click();
        await nextTick();
        expect(router.post).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('Substance is missing');
        expect(document.body.textContent).toContain('Severity is missing');

        type(form.querySelector('input'), 'Peanuts');
        type(select, 'severe');
        await nextTick();
        document.querySelector('[data-testid="allergy-save"]').click();
        expect(router.post).toHaveBeenCalledWith(`/health/people/${ID}/allergies`, { substance: 'Peanuts', reaction: null, severity: 'severe' }, expect.any(Object));
    });

    it('edits with PATCH, confirms deletion with Keep and asks before discarding', async () => {
        mountPage({ panel: { kind: 'allergy', id: A1.id } });
        await nextTick();
        document.querySelector('[data-testid="allergy-save"]').click();
        expect(router.patch).toHaveBeenCalledWith(`/health/people/${ID}/allergies/${A1.id}`, { substance: A1.substance, reaction: 'Swelling', severity: 'severe' }, expect.any(Object));

        document.querySelector('[data-testid="allergy-delete"]').click();
        await nextTick();
        expect(button('Keep allergy')).toBeDefined();
        [...document.querySelectorAll('button')].filter((b) => b.textContent.trim() === 'Delete allergy').at(-1).click();
        expect(router.delete).toHaveBeenCalledWith(`/health/people/${ID}/allergies/${A1.id}`, expect.any(Object));
    });

    it('asks to discard a dirty form', async () => {
        mountPage({ panel: { kind: 'new-allergy', id: null } });
        await nextTick();
        type(document.querySelector('[data-testid="allergy-form"] input'), 'x');
        await nextTick();
        [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Close' && !b.getAttribute('aria-label')).click();
        await nextTick();
        expect(document.body.textContent).toContain('Discard changes?');
    });

    it('is text only for a view-only member', async () => {
        mountPage({ person: { ...PERSON, canEdit: false }, canCreate: false, panel: { kind: 'allergy', id: A1.id } });
        await nextTick();
        expect(document.querySelector('[data-testid="allergy-form"]')).toBeNull();
        expect(document.querySelector('[data-testid="allergy-readonly"]').textContent).toContain('Severe');
        expect(document.querySelector('[data-testid="allergy-save"]')).toBeNull();
        expect(document.querySelector('[data-testid="allergy-delete"]')).toBeNull();
    });

    it('keeps the allergy components free of v-html, remember and storage', () => {
        for (const file of ['AllergyList', 'AllergyPanel']) {
            expect(readFileSync(`resources/js/Health/${file}.vue`, 'utf8'), file).not.toMatch(/remember|v-html|localStorage|sessionStorage/);
        }
    });
});

const states = {
    'card filled': () => ({}),
    'card empty': () => ({ allergies: [] }),
    'card view-only': () => ({ person: { ...PERSON, canEdit: false } }),
    'panel create': () => ({ panel: { kind: 'new-allergy', id: null } }),
    'panel edit': () => ({ panel: { kind: 'allergy', id: A2.id } }),
    'panel read-only': () => ({ person: { ...PERSON, canEdit: false }, panel: { kind: 'allergy', id: A1.id } }),
};
describe('accessibility', () => {
    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            for (const [name, make] of Object.entries(states)) {
                it(`passes axe: ${name} (${locale}${dark ? ', dark' : ''})`, async () => {
                    document.documentElement.classList.toggle('dark', dark);
                    mountPage(make(), { locale });
                    await nextTick();
                    await expectNoAxeViolations(document.body);
                });
            }
        }
    }

    it('passes axe with the delete confirm open', async () => {
        mountPage({ panel: { kind: 'allergy', id: A1.id } });
        await nextTick();
        document.querySelector('[data-testid="allergy-delete"]').click();
        await nextTick();
        await expectNoAxeViolations(document.body);
    });
});
