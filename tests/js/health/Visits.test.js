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
const SPECIALTIES = Object.keys(JSON.parse(readFileSync('lang/en.json', 'utf8')).health.specialty);
const FOLDERS = [
    { key: 'other', otherLabel: 'Sleep clinic', count: 1, latestOn: '2026-03-01', visitIds: [V3.id] },
    { key: 'cardiology', otherLabel: null, count: 2, latestOn: '2026-02-01', visitIds: [V1.id, V2.id] },
];
const TIMELINE = [V3, V1, V2].map((v) => ({ type: 'visit', id: v.id, date: v.visitedOn }));
const base = (over = {}) => ({
    people: [{ id: ID, name: 'Ada Novak', initials: 'AN' }], person: PERSON, canCreate: true, panel: null,
    visits: [V3, V1, V2], folders: FOLDERS, timeline: TIMELINE, timelineFilters: ['other', 'cardiology'], view: 'folders', filter: 'all', specialties: SPECIALTIES,
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

describe('specialty folders', () => {
    it('opens the first folder only and counts with pipe plurals', () => {
        mountPage();
        const headers = [...document.querySelectorAll('.folder__header')];
        expect(headers.map((b) => b.getAttribute('aria-expanded'))).toEqual(['true', 'false']);
        expect(headers[0].textContent).toContain('Sleep clinic');
        expect(headers[0].textContent).toContain('1 visit');
        expect(headers[0].textContent).not.toContain('1 visits');
        expect(headers[1].textContent).toContain('Cardiology');
        expect(headers[1].textContent).toContain('2 visits');
        const region = document.getElementById(headers[0].getAttribute('aria-controls'));
        expect(region.getAttribute('role')).toBe('region');
        expect(region.getAttribute('aria-labelledby')).toBe(headers[0].id);
    });

    it('toggles a folder and opens the visit panel through the server', async () => {
        mountPage();
        const second = document.querySelectorAll('.folder__header')[1];
        second.click();
        await nextTick();
        expect(second.getAttribute('aria-expanded')).toBe('true');
        const entry = [...document.querySelectorAll('[data-testid="timeline-entry"]')].find((e) => e.textContent.includes('Dr Heart'));
        entry.click();
        expect(router.get).toHaveBeenCalledWith(expect.any(String), { panel: 'visit', id: V1.id }, expect.objectContaining({ only: ['panel'], preserveState: true }));
    });

    it('prints free text as text, never as markup', () => {
        mountPage();
        document.querySelectorAll('.folder__header')[1].click();
        expect(document.querySelector('b')).toBeNull();
        expect(document.body.textContent).toContain('<b>benign</b>');
    });
});

describe('view switch and timeline', () => {
    it('switches the view through the URL', () => {
        mountPage();
        const radios = document.querySelectorAll('[data-testid="view-switch"] input');
        expect(radios[0].checked).toBe(true);
        radios[1].dispatchEvent(new Event('change'));
        expect(router.get).toHaveBeenCalledWith(expect.any(String), { view: 'timeline' }, expect.objectContaining({ only: ['view', 'filter', 'timeline'] }));
    });

    it('renders an ordered list with time elements, filter chips and a results line', () => {
        mountPage({ view: 'timeline' });
        expect(document.querySelector('ol')).not.toBeNull();
        expect([...document.querySelectorAll('time')].map((t) => t.getAttribute('datetime'))).toEqual(['2026-03-01', '2026-02-01', '2025-05-01']);
        const chips = [...document.querySelectorAll('[data-testid="timeline-filters"] button')];
        expect(chips.map((c) => c.textContent.trim())).toEqual(['All', 'Other', 'Cardiology']);
        expect(chips.map((c) => c.getAttribute('aria-pressed'))).toEqual(['true', 'false', 'false']);
        expect(document.querySelector('[data-testid="timeline-results"]').textContent).toBe('Showing 3 entries');
        // empty doctor falls back to the specialty name
        expect(document.body.textContent).toContain('Cardiology');
    });

    it('reloads only the timeline on a filter change and marks it busy', () => {
        mountPage({ view: 'timeline' });
        [...document.querySelectorAll('[data-testid="timeline-filters"] button')][2].click();
        expect(router.get).toHaveBeenCalledWith(expect.any(String), { view: 'timeline', filter: 'cardiology' }, expect.objectContaining({ only: ['timeline', 'filter'] }));
    });

    it('offers Clear filter when a filter matches nothing', () => {
        mountPage({ view: 'timeline', filter: 'cardiology', timeline: [] });
        expect(document.querySelector('[data-testid="timeline-empty"]').textContent).toContain('Nothing matches this filter.');
        button('Clear filter').click();
        expect(router.get).toHaveBeenCalledWith(expect.any(String), { view: 'timeline' }, expect.any(Object));
    });

    it('has no horizontal scroll containers', () => {
        mountPage({ view: 'timeline' });
        expect(document.querySelector('[class*="scroll"]')).toBeNull();
    });
});

describe('add visit entry points', () => {
    it('offers header, empty-state and shell entries to editors', () => {
        mountPage();
        button('Add visit').click();
        expect(router.get).toHaveBeenCalledWith(expect.any(String), { panel: 'new-visit' }, expect.any(Object));
        current.unmount();
        mountPage({ visits: [], folders: [], timeline: [], timelineFilters: [] });
        expect(document.querySelector('[data-testid="empty-add-visit"]')).not.toBeNull();
        expect(document.body.textContent).toContain('No visits yet');
    });

    it('offers none of them to a view-only member', () => {
        mountPage({ person: { ...PERSON, canEdit: false }, canCreate: false });
        expect(button('Add visit')).toBeUndefined();
        current.unmount();
        mountPage({ person: { ...PERSON, canEdit: false }, canCreate: false, visits: [], folders: [], timeline: [] });
        expect(document.querySelector('[data-testid="empty-add-visit"]')).toBeNull();
        expect(document.querySelector('[aria-label="Add visit"]')).toBeNull();
    });
});

describe('visit panel', () => {
    it('validates, reveals the free-text field only for Other and posts', async () => {
        mountPage({ panel: { kind: 'new-visit', id: null } });
        await nextTick();
        const form = document.querySelector('[data-testid="visit-form"]');
        expect(form.textContent).toContain('If the page locks before you save, long notes are lost. Save often.');
        expect(form.textContent).not.toContain('Specialty name (free text)');

        document.querySelector('[data-testid="visit-save"]').click();
        await nextTick();
        expect(router.post).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('Date is missing');

        const select = form.querySelector('select');
        const labels = [...select.options].map((o) => o.textContent).filter(Boolean);
        expect(labels.at(-1)).toBe('Other');
        expect(labels.slice(0, -1)).toEqual([...labels.slice(0, -1)].sort((a, b) => a.localeCompare(b, 'en')));

        type(select, 'other');
        await nextTick();
        expect(form.textContent).toContain('Specialty name (free text)');
        document.querySelector('[data-testid="visit-save"]').click();
        await nextTick();
        expect(document.body.textContent).toContain('Specialty name is missing');

        type(form.querySelector('input[type="date"]'), '2026-01-10');
        type([...form.querySelectorAll('input')].find((i) => i.maxLength === 80), 'Sleep clinic');
        await nextTick();
        document.querySelector('[data-testid="visit-save"]').click();
        expect(router.post).toHaveBeenCalledWith(`/health/people/${ID}/visits`, expect.objectContaining({ visited_on: '2026-01-10', specialty_key: 'other', specialty_other: 'Sleep clinic', doctor: null }), expect.any(Object));
    });

    it('edits with PATCH, confirms deletion with Keep as the safe choice and asks before discarding', async () => {
        mountPage({ panel: { kind: 'visit', id: V1.id } });
        await nextTick();
        const form = document.querySelector('[data-testid="visit-form"]');
        expect(form.querySelector('input[type="date"]').value).toBe('2026-02-01');
        document.querySelector('[data-testid="visit-save"]').click();
        expect(router.patch).toHaveBeenCalledWith(`/health/people/${ID}/visits/${V1.id}`, expect.objectContaining({ specialty_key: 'cardiology', specialty_other: null }), expect.any(Object));

        document.querySelector('[data-testid="visit-delete"]').click();
        await nextTick();
        expect(document.body.textContent).toContain('Delete this visit?');
        expect(button('Keep visit')).toBeDefined();
        const confirm = [...document.querySelectorAll('button')].filter((b) => b.textContent.trim() === 'Delete visit').at(-1);
        confirm.click();
        expect(router.delete).toHaveBeenCalledWith(`/health/people/${ID}/visits/${V1.id}`, expect.any(Object));
    });

    it('asks to discard a dirty form', async () => {
        mountPage({ panel: { kind: 'new-visit', id: null } });
        await nextTick();
        type(document.querySelector('[data-testid="visit-form"] input[type="date"]'), '2026-01-01');
        await nextTick();
        [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Close' && !b.getAttribute('aria-label')).click();
        await nextTick();
        expect(document.body.textContent).toContain('Discard changes?');
    });

    it('shows text only, with no Save or Delete, to a view-only member', async () => {
        mountPage({ person: { ...PERSON, canEdit: false }, canCreate: false, panel: { kind: 'visit', id: V1.id } });
        await nextTick();
        expect(document.querySelector('[data-testid="visit-form"]')).toBeNull();
        expect(document.querySelector('[data-testid="visit-readonly"]').textContent).toContain('Dr Heart');
        expect(document.querySelector('[data-testid="visit-save"]')).toBeNull();
        expect(document.querySelector('[data-testid="visit-delete"]')).toBeNull();
    });
});

describe('copy and safety', () => {
    it('keeps the visit components free of v-html, remember and storage', () => {
        for (const file of ['ViewSwitch', 'SpecialtyFolder', 'TimelineList', 'VisitPanel']) {
            expect(readFileSync(`resources/js/Health/${file}.vue`, 'utf8'), file).not.toMatch(/remember|v-html|localStorage|sessionStorage/);
        }
        expect(readFileSync('resources/js/Health/SpecialtyFolder.vue', 'utf8')).toMatch(/health\.folder\.visits.*folder\.count\s*\}\s*,\s*folder\.count/);
    });

    it('picks the plural form per locale', () => {
        const forms = (locale) => {
            mountPage({}, { locale });
            const text = [...document.querySelectorAll('.folder__count')].map((n) => n.textContent);
            current.unmount();
            return text;
        };
        expect(forms('en')).toEqual(['1 visit', '2 visits']);
        const sk = forms('sk');
        expect(sk[0]).toMatch(/^1 /);
        expect(sk[1]).toMatch(/^2 /);
        expect(sk[1]).not.toBe(sk[0]);
    });
});

const states = {
    folders: () => ({}),
    'folders collapsed': () => ({ folders: [FOLDERS[1]], visits: [V1, V2] }),
    timeline: () => ({ view: 'timeline' }),
    'timeline filtered': () => ({ view: 'timeline', filter: 'cardiology', timeline: TIMELINE.slice(1) }),
    'filtered empty': () => ({ view: 'timeline', filter: 'cardiology', timeline: [] }),
    'panel create': () => ({ panel: { kind: 'new-visit', id: null } }),
    'panel edit': () => ({ panel: { kind: 'visit', id: V1.id } }),
    'panel read-only': () => ({ person: { ...PERSON, canEdit: false }, panel: { kind: 'visit', id: V1.id } }),
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
        mountPage({ panel: { kind: 'visit', id: V1.id } });
        await nextTick();
        document.querySelector('[data-testid="visit-delete"]').click();
        await nextTick();
        await expectNoAxeViolations(document.body);
    });
});
