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
const M1 = { id: 'cccccccc-cccc-4ccc-8ccc-ccccccccccc1', name: 'Ibuprofen <b>forte</b>', dose: '200 mg', schedule: 'Twice a day', startedOn: '2026-01-10', endedOn: null, notes: null, ongoing: true };
const M2 = { id: 'cccccccc-cccc-4ccc-8ccc-ccccccccccc2', name: 'Vitamin D', dose: null, schedule: null, startedOn: null, endedOn: null, notes: null, ongoing: true };
const M3 = { id: 'cccccccc-cccc-4ccc-8ccc-ccccccccccc3', name: 'Amoxicillin', dose: '500 mg', schedule: null, startedOn: '2026-02-01', endedOn: '2026-02-10', notes: null, ongoing: false };
const base = (over = {}) => ({
    people: [{ id: ID, name: 'Ada Novak', initials: 'AN' }], person: PERSON, canCreate: true, panel: null,
    visits: [], folders: [], timeline: [], timelineFilters: [], view: 'folders', filter: 'all', specialties: [], allergies: [], medications: [M1, M2, M3],
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

describe('medications card', () => {
    it('lists ongoing rows with an Ongoing tag and keeps ended ones behind a disclosure', async () => {
        mountPage();
        const card = document.querySelector('[data-testid="medication-card"]');
        expect(card.querySelector('h2').textContent).toBe('Medications');
        const ongoing = [...card.querySelectorAll('[data-testid="medication-ongoing"] [data-testid="medication-row"]')];
        expect(ongoing.map((r) => r.querySelector('.meds__name').textContent)).toEqual([M1.name, 'Vitamin D']);
        expect(ongoing[0].querySelector('.meds__detail').textContent).toBe('200 mg, Twice a day');
        expect(ongoing[1].querySelector('.meds__detail')).toBeNull();
        for (const row of ongoing) {
            expect(row.textContent).toContain('Ongoing');
            expect(row.querySelector('svg')).not.toBeNull();
        }
        expect(card.querySelector('b')).toBeNull();

        const toggle = card.querySelector('[data-testid="medication-toggle"]');
        expect(toggle.textContent.trim()).toBe('Show ended medications');
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        const endedList = card.querySelector('[data-testid="medication-ended"]');
        expect(endedList.style.display).toBe('none');
        toggle.click();
        await nextTick();
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        expect(endedList.style.display).not.toBe('none');
        expect(endedList.textContent).toContain('Amoxicillin');
        expect(endedList.textContent).toMatch(/Ended .*2026/);
    });

    it('shows no disclosure when nothing has ended', () => {
        mountPage({ medications: [M1] });
        expect(document.querySelector('[data-testid="medication-toggle"]')).toBeNull();
    });

    it('shows the empty state and Add medication only to editors', () => {
        mountPage({ medications: [] });
        expect(document.querySelector('[data-testid="medication-empty"]').textContent).toContain('No medications recorded');
        expect(document.querySelector('[data-testid="medication-add"]')).not.toBeNull();
        current.unmount();
        mountPage({ medications: [], person: { ...PERSON, canEdit: false } });
        expect(document.querySelector('[data-testid="medication-add"]')).toBeNull();
    });

    it('opens the panel through the router', () => {
        mountPage();
        document.querySelector('[data-testid="medication-add"]').click();
        expect(router.get).toHaveBeenLastCalledWith(expect.any(String), { panel: 'new-medication' }, expect.any(Object));
        document.querySelector('[data-testid="medication-row"]').click();
        expect(router.get).toHaveBeenLastCalledWith(expect.any(String), { panel: 'medication', id: M1.id }, expect.any(Object));
    });
});

describe('medication panel', () => {
    it('validates the name and posts', async () => {
        mountPage({ panel: { kind: 'new-medication', id: null } });
        await nextTick();
        const form = document.querySelector('[data-testid="medication-form"]');
        document.querySelector('[data-testid="medication-save"]').click();
        await nextTick();
        expect(router.post).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('Name is missing');

        type(form.querySelector('input'), 'Aspirin');
        await nextTick();
        document.querySelector('[data-testid="medication-save"]').click();
        expect(router.post).toHaveBeenCalledWith(`/health/people/${ID}/medications`, { name: 'Aspirin', dose: null, schedule: null, started_on: null, ended_on: null }, expect.any(Object));
    });

    it('shows the end before start message and does not post', async () => {
        mountPage({ panel: { kind: 'new-medication', id: null } });
        await nextTick();
        const inputs = document.querySelectorAll('[data-testid="medication-form"] input');
        type(inputs[0], 'Aspirin');
        type(inputs[3], '2026-05-02');
        type(inputs[4], '2026-05-01');
        await nextTick();
        document.querySelector('[data-testid="medication-save"]').click();
        await nextTick();
        expect(router.post).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('The end date is before the start date');
    });

    it('edits with PATCH, confirms deletion with Keep and asks before discarding', async () => {
        mountPage({ panel: { kind: 'medication', id: M1.id } });
        await nextTick();
        document.querySelector('[data-testid="medication-save"]').click();
        expect(router.patch).toHaveBeenCalledWith(`/health/people/${ID}/medications/${M1.id}`, { name: M1.name, dose: '200 mg', schedule: 'Twice a day', started_on: '2026-01-10', ended_on: null }, expect.any(Object));

        document.querySelector('[data-testid="medication-delete"]').click();
        await nextTick();
        expect(button('Keep medication')).toBeDefined();
        [...document.querySelectorAll('button')].filter((b) => b.textContent.trim() === 'Delete medication').at(-1).click();
        expect(router.delete).toHaveBeenCalledWith(`/health/people/${ID}/medications/${M1.id}`, expect.any(Object));
    });

    it('asks to discard a dirty form', async () => {
        mountPage({ panel: { kind: 'new-medication', id: null } });
        await nextTick();
        type(document.querySelector('[data-testid="medication-form"] input'), 'x');
        await nextTick();
        [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Close' && !b.getAttribute('aria-label')).click();
        await nextTick();
        expect(document.body.textContent).toContain('Discard changes?');
    });

    it('is text only for a view-only member', async () => {
        mountPage({ person: { ...PERSON, canEdit: false }, canCreate: false, panel: { kind: 'medication', id: M1.id } });
        await nextTick();
        expect(document.querySelector('[data-testid="medication-form"]')).toBeNull();
        expect(document.querySelector('[data-testid="medication-readonly"]').textContent).toContain('200 mg');
        expect(document.querySelector('[data-testid="medication-save"]')).toBeNull();
        expect(document.querySelector('[data-testid="medication-delete"]')).toBeNull();
    });

    it('keeps the medication components free of v-html, remember, storage and client-side date maths', () => {
        for (const file of ['MedicationList', 'MedicationPanel']) {
            expect(readFileSync(`resources/js/Health/${file}.vue`, 'utf8'), file).not.toMatch(/remember|v-html|localStorage|sessionStorage|new Date\(\)/);
        }
    });
});

const states = {
    'card ongoing only': () => ({ medications: [M1, M2] }),
    'card filled': () => ({}),
    'card empty': () => ({ medications: [] }),
    'card view-only': () => ({ person: { ...PERSON, canEdit: false } }),
    'panel create': () => ({ panel: { kind: 'new-medication', id: null } }),
    'panel edit': () => ({ panel: { kind: 'medication', id: M3.id } }),
    'panel read-only': () => ({ person: { ...PERSON, canEdit: false }, panel: { kind: 'medication', id: M1.id } }),
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

    it('passes axe with ended medications expanded', async () => {
        mountPage();
        document.querySelector('[data-testid="medication-toggle"]').click();
        await nextTick();
        await expectNoAxeViolations(document.body);
    });

    it('passes axe with the delete confirm open', async () => {
        mountPage({ panel: { kind: 'medication', id: M1.id } });
        await nextTick();
        document.querySelector('[data-testid="medication-delete"]').click();
        await nextTick();
        await expectNoAxeViolations(document.body);
    });
});
