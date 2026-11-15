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
const V1 = { id: 'dddddddd-dddd-4ddd-8ddd-ddddddddddd1', vaccine: 'Tetanus <b>booster</b>', givenOn: '2016-01-10', nextDueOn: '2026-01-01', notes: null, status: 'overdue', dueInDays: -5 };
const V2 = { id: 'dddddddd-dddd-4ddd-8ddd-ddddddddddd2', vaccine: 'MMR', givenOn: '2020-01-10', nextDueOn: '2026-06-20', notes: null, status: 'due_soon', dueInDays: 3 };
const V3 = { id: 'dddddddd-dddd-4ddd-8ddd-ddddddddddd3', vaccine: 'Hepatitis B', givenOn: '2021-01-10', nextDueOn: '2030-01-01', notes: null, status: 'later', dueInDays: 1500 };
const V4 = { id: 'dddddddd-dddd-4ddd-8ddd-ddddddddddd4', vaccine: 'Flu', givenOn: '2025-10-01', nextDueOn: null, notes: null, status: 'none', dueInDays: null };
const VISIT = { id: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeee1', visitedOn: '2025-05-01', doctor: 'Dr. Kova', specialtyKey: 'dentist', specialtyOther: null, diagnosis: null, notes: null, documentCount: 0 };
const base = (over = {}) => ({
    people: [{ id: ID, name: 'Ada Novak', initials: 'AN' }], person: PERSON, canCreate: true, panel: null,
    visits: [], folders: [], timeline: [], timelineFilters: [], view: 'folders', filter: 'all', specialties: [], allergies: [], medications: [],
    vaccinations: [V1, V2, V3, V4], vaccinationAttention: 2,
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
const type = (el, value) => { el.value = value; el.dispatchEvent(new Event('input')); };


describe('vaccinations card', () => {
    it('lists due items first with tags, then past vaccinations', () => {
        mountPage();
        const card = document.querySelector('[data-testid="vaccination-card"]');
        expect(card.querySelector('h2').textContent).toBe('Vaccinations');
        const due = [...card.querySelectorAll('[data-testid="vaccination-due"] [data-testid="vaccination-row"]')];
        expect(due.map((r) => r.querySelector('.vacc__name').textContent)).toEqual([V1.vaccine, 'MMR', 'Hepatitis B']);
        expect(due[0].textContent).toContain('Overdue');
        expect(due[1].textContent).toContain('Due in 3 days');
        expect(due[2].textContent).toMatch(/Due .*2030/);
        expect(due[0].querySelector('svg')).not.toBeNull();
        const past = [...card.querySelectorAll('[data-testid="vaccination-past"] [data-testid="vaccination-row"]')];
        expect(past.map((r) => r.querySelector('.vacc__name').textContent)).toEqual(['Flu']);
        expect(card.querySelector('b')).toBeNull();
    });

    it('uses the singular plural form for one day', () => {
        mountPage({ vaccinations: [{ ...V2, dueInDays: 1 }] });
        expect(document.querySelector('[data-testid="vaccination-tag"]').textContent).toContain('Due in 1 day');
    });

    it('shows the empty state and Add vaccination only to editors', () => {
        mountPage({ vaccinations: [], vaccinationAttention: 0 });
        expect(document.querySelector('[data-testid="vaccination-empty"]').textContent).toContain('No vaccinations recorded');
        expect(document.querySelector('[data-testid="vaccination-add"]')).not.toBeNull();
        current.unmount();
        mountPage({ vaccinations: [], person: { ...PERSON, canEdit: false } });
        expect(document.querySelector('[data-testid="vaccination-add"]')).toBeNull();
    });

    it('opens the panel through the router', () => {
        mountPage();
        document.querySelector('[data-testid="vaccination-add"]').click();
        expect(router.get).toHaveBeenLastCalledWith(expect.any(String), { panel: 'new-vaccination' }, expect.any(Object));
        document.querySelector('[data-testid="vaccination-row"]').click();
        expect(router.get).toHaveBeenLastCalledWith(expect.any(String), { panel: 'vaccination', id: V1.id }, expect.any(Object));
    });
});

describe('vaccination alert', () => {
    it('shows a warning (not role=alert) with the plural count when something needs attention', () => {
        mountPage();
        const alert = document.querySelector('[data-testid="vaccination-alert"]');
        expect(alert.getAttribute('role')).toBeNull();
        expect(alert.textContent).toContain('Vaccination due');
        expect(alert.textContent).toContain('2 vaccinations need attention.');
        current.unmount();
        mountPage({ vaccinationAttention: 1 });
        expect(document.querySelector('[data-testid="vaccination-alert"]').textContent).toContain('1 vaccination needs attention.');
    });

    it('is absent when nothing needs attention', () => {
        mountPage({ vaccinationAttention: 0 });
        expect(document.querySelector('[data-testid="vaccination-alert"]')).toBeNull();
    });
});

describe('vaccination panel', () => {
    it('validates and posts', async () => {
        mountPage({ panel: { kind: 'new-vaccination', id: null } });
        await nextTick();
        const form = document.querySelector('[data-testid="vaccination-form"]');
        document.querySelector('[data-testid="vaccination-save"]').click();
        await nextTick();
        expect(router.post).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('Vaccine is missing');
        expect(document.body.textContent).toContain('Date given is missing');

        const inputs = form.querySelectorAll('input');
        type(inputs[0], 'Polio');
        type(inputs[1], '2026-01-10');
        await nextTick();
        document.querySelector('[data-testid="vaccination-save"]').click();
        expect(router.post).toHaveBeenCalledWith(`/health/people/${ID}/vaccinations`, { vaccine: 'Polio', given_on: '2026-01-10', next_due_on: null }, expect.any(Object));
    });

    it('refuses a next due date before the date given', async () => {
        mountPage({ panel: { kind: 'new-vaccination', id: null } });
        await nextTick();
        const inputs = document.querySelectorAll('[data-testid="vaccination-form"] input');
        type(inputs[0], 'Polio');
        type(inputs[1], '2026-05-02');
        type(inputs[2], '2026-05-01');
        await nextTick();
        document.querySelector('[data-testid="vaccination-save"]').click();
        await nextTick();
        expect(router.post).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('The next due date is before the date given');
    });

    it('edits with PATCH, deletes after confirmation and asks before discarding', async () => {
        mountPage({ panel: { kind: 'vaccination', id: V1.id } });
        await nextTick();
        document.querySelector('[data-testid="vaccination-save"]').click();
        expect(router.patch).toHaveBeenCalledWith(`/health/people/${ID}/vaccinations/${V1.id}`, { vaccine: V1.vaccine, given_on: '2016-01-10', next_due_on: '2026-01-01' }, expect.any(Object));

        document.querySelector('[data-testid="vaccination-delete"]').click();
        await nextTick();
        expect(button('Keep vaccination')).toBeDefined();
        [...document.querySelectorAll('button')].filter((b) => b.textContent.trim() === 'Delete vaccination').at(-1).click();
        expect(router.delete).toHaveBeenCalledWith(`/health/people/${ID}/vaccinations/${V1.id}`, expect.any(Object));
    });

    it('marks as given with today as the default date', async () => {
        mountPage({ panel: { kind: 'vaccination', id: V1.id } });
        await nextTick();
        expect(document.querySelector('[data-testid="vaccination-mark-form"]')).toBeNull();
        document.querySelector('[data-testid="vaccination-mark-open"]').click();
        await nextTick();
        const inputs = document.querySelectorAll('[data-testid="vaccination-mark-form"] input');
        expect(inputs[0].value).toMatch(/^\d{4}-\d{2}-\d{2}$/);
        const given = inputs[0].value;
        type(inputs[1], '2099-01-01');
        await nextTick();
        document.querySelector('[data-testid="vaccination-mark-submit"]').click();
        expect(router.post).toHaveBeenCalledWith(`/health/people/${ID}/vaccinations/${V1.id}/given`, { given_on: given, next_due_on: '2099-01-01' }, expect.any(Object));
    });

    it('offers no mark as given when creating', async () => {
        mountPage({ panel: { kind: 'new-vaccination', id: null } });
        await nextTick();
        expect(document.querySelector('[data-testid="vaccination-mark"]')).toBeNull();
    });

    it('asks to discard a dirty form', async () => {
        mountPage({ panel: { kind: 'new-vaccination', id: null } });
        await nextTick();
        type(document.querySelector('[data-testid="vaccination-form"] input'), 'x');
        await nextTick();
        [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Close' && !b.getAttribute('aria-label')).click();
        await nextTick();
        expect(document.body.textContent).toContain('Discard changes?');
    });

    it('is text only for a view-only member', async () => {
        mountPage({ person: { ...PERSON, canEdit: false }, canCreate: false, panel: { kind: 'vaccination', id: V1.id } });
        await nextTick();
        expect(document.querySelector('[data-testid="vaccination-form"]')).toBeNull();
        expect(document.querySelector('[data-testid="vaccination-readonly"]').textContent).toContain('2016-01-10');
        for (const id of ['save', 'delete', 'mark']) expect(document.querySelector(`[data-testid="vaccination-${id}"]`)).toBeNull();
    });

    it('keeps the vaccination components free of v-html, remember and storage', () => {
        for (const file of ['VaccinationList', 'VaccinationPanel']) {
            expect(readFileSync(`resources/js/Health/${file}.vue`, 'utf8'), file).not.toMatch(/remember|v-html|localStorage|sessionStorage/);
        }
    });
});

describe('timeline', () => {
    const timelineProps = (over = {}) => ({
        view: 'timeline', visits: [VISIT], vaccinations: [V4],
        timeline: [{ type: 'vaccination', id: V4.id, date: V4.givenOn }, { type: 'visit', id: VISIT.id, date: VISIT.visitedOn }],
        timelineFilters: ['dentist', 'vaccinations'], ...over,
    });

    it('renders vaccination entries with the vaccine as title and a Vaccinations chip', () => {
        mountPage(timelineProps());
        const entries = [...document.querySelectorAll('[data-testid="timeline-entry"]')];
        expect(entries).toHaveLength(2);
        expect(entries[0].textContent).toContain('Flu');
        expect(entries[0].querySelector('svg')).not.toBeNull();
        expect(document.querySelector('[data-testid="timeline-filters"]').textContent).toContain('Vaccinations');
        entries[0].click();
        expect(router.get).toHaveBeenLastCalledWith(expect.any(String), expect.objectContaining({ panel: 'vaccination', id: V4.id }), expect.any(Object));
    });

    it('omits the Vaccinations chip when none exist', () => {
        mountPage(timelineProps({ vaccinations: [], timeline: [{ type: 'visit', id: VISIT.id, date: VISIT.visitedOn }], timelineFilters: ['dentist'] }));
        expect(document.querySelector('[data-testid="timeline-filters"]').textContent).not.toContain('Vaccinations');
    });
});

const states = {
    'card mix': () => ({}),
    'card empty': () => ({ vaccinations: [], vaccinationAttention: 0 }),
    'card view-only': () => ({ person: { ...PERSON, canEdit: false } }),
    'panel create': () => ({ panel: { kind: 'new-vaccination', id: null } }),
    'panel edit': () => ({ panel: { kind: 'vaccination', id: V2.id } }),
    'panel read-only': () => ({ person: { ...PERSON, canEdit: false }, panel: { kind: 'vaccination', id: V1.id } }),
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

    it('passes axe with the mark-as-given form open', async () => {
        mountPage({ panel: { kind: 'vaccination', id: V1.id } });
        await nextTick();
        document.querySelector('[data-testid="vaccination-mark-open"]').click();
        await nextTick();
        await expectNoAxeViolations(document.body);
    });

    it('passes axe with the delete confirm open', async () => {
        mountPage({ panel: { kind: 'vaccination', id: V1.id } });
        await nextTick();
        document.querySelector('[data-testid="vaccination-delete"]').click();
        await nextTick();
        await expectNoAxeViolations(document.body);
    });

    it('passes axe on the timeline with a vaccination entry', async () => {
        mountPage({ view: 'timeline', visits: [VISIT], vaccinations: [V4], timeline: [{ type: 'vaccination', id: V4.id, date: V4.givenOn }], timelineFilters: ['vaccinations'] });
        await nextTick();
        await expectNoAxeViolations(document.body);
    });
});
