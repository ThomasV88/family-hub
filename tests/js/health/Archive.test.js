import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick, reactive } from 'vue';
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
const PERSON = { id: ID, name: 'Ada Novak', birthDate: null, ageYears: 11, sex: 'female', bloodType: null, hasAccount: false, aiConsent: false, canEdit: true, notes: null, contacts: [] };
const ITEMS = [
    { type: 'visit', id: 'v-1', label: 'Dr Heart', date: '2024-02-01', archivedOn: '2026-11-10' },
    { type: 'document', id: 'd-1', label: 'ECG <b>scan</b>', date: null, archivedOn: '2026-11-09' },
    { type: 'vaccination', id: 'x-1', label: 'MMR', date: '2020-01-10', archivedOn: '2026-11-08' },
];
const PEOPLE = [{ id: '22222222-2222-4222-8222-222222222222', name: 'Aunt Mary', initials: 'AM' }];
const base = (over = {}) => ({
    people: [{ id: ID, name: 'Ada Novak', initials: 'AN' }], person: PERSON, canCreate: true, panel: null,
    visits: [], folders: [], timeline: [], timelineFilters: [], view: 'folders', filter: 'all', specialties: [], ...over,
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
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; vi.clearAllMocks(); });
const $ = (sel) => document.querySelector(sel);
const tick = async () => { await nextTick(); await nextTick(); };

describe('archive section', () => {
    it('opens from the person menu through the server panel query', async () => {
        mountPage();
        $('[data-testid="header-menu"] button').click();
        await tick();
        [...document.querySelectorAll('[role="menuitem"]')].find((i) => i.textContent.trim() === 'Archived items').click();
        await tick();
        expect(router.get).toHaveBeenCalledWith(expect.any(String), expect.objectContaining({ panel: 'archive' }), expect.any(Object));
    });

    it('lists archived records grouped by type and posts the restore for one record', async () => {
        mountPage({ panel: { kind: 'archive', id: null }, archive: { items: ITEMS, people: [] } });
        await tick();
        const panel = $('[data-testid="archive-panel"]');
        expect([...panel.querySelectorAll('h3')].map((h3) => h3.textContent.trim())).toEqual(['Visits', 'Vaccinations', 'Documents']);
        expect(panel.textContent).toContain('ECG <b>scan</b>');
        expect(panel.querySelector('b')).toBeNull();
        panel.querySelectorAll('[data-testid="archive-restore"]')[1].click();
        expect(router.post).toHaveBeenCalledWith(`/health/people/${ID}/archive/vaccination/x-1/restore`, {}, expect.any(Object));
    });

    it('says so when nothing is archived', async () => {
        mountPage({ panel: { kind: 'archive', id: null }, archive: { items: [], people: [] } });
        await tick();
        expect($('[data-testid="archive-empty"]').textContent).toContain('Nothing is archived');
    });

    it('lists archived people under the switcher with a restore action, also with no active person', async () => {
        mountPage({ archive: { items: [], people: PEOPLE } });
        expect($('[data-testid="archived-people"]').textContent).toContain('Aunt Mary');
        $('[data-testid="archived-person-restore"]').click();
        expect(router.post).toHaveBeenCalledWith(`/health/people/${PEOPLE[0].id}/restore`, {}, expect.any(Object));
        current.unmount();
        document.body.innerHTML = '';
        mountPage({ person: null, people: [], archive: { items: [], people: PEOPLE } });
        expect($('[data-testid="archived-people"]')).not.toBeNull();
    });

    it('draws nothing when the server sent no archived people', () => {
        mountPage({ archive: { items: [], people: [] } });
        expect($('[data-testid="archived-people"]')).toBeNull();
    });
});

describe('delete confirmation copy', () => {
    const openPersonDelete = async () => {
        $('[data-testid="header-menu"] button').click();
        await tick();
        [...document.querySelectorAll('[role="menuitem"]')].at(-1).click();
        await tick();
    };

    it('says permanent when the record is inside the delete window (or the server sent no hint)', async () => {
        mountPage({ person: { ...PERSON, permanentDelete: true } });
        await openPersonDelete();
        expect(document.body.textContent).toContain('This cannot be undone.');
        expect(document.body.textContent).not.toContain('will be archived');
    });

    it('says archive after the window and still sends the same delete request', async () => {
        mountPage({ person: { ...PERSON, permanentDelete: false } });
        await openPersonDelete();
        expect(document.body.textContent).toContain('will be archived');
        expect(document.body.textContent).not.toContain('This cannot be undone.');
        [...document.querySelectorAll('button')].filter((b) => b.textContent.trim() === 'Archive person').at(-1).click();
        expect(router.delete).toHaveBeenCalledWith(`/health/people/${ID}`);
    });

    it('uses the archive copy for a visit after the window', async () => {
        const visit = { id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', visitedOn: '2026-02-01', doctor: 'Dr Heart', specialtyKey: 'cardiology', specialtyOther: null, diagnosis: null, notes: null, documentCount: 0, permanentDelete: false };
        mountPage({ visits: [visit], panel: { kind: 'visit', id: visit.id } });
        await tick();
        $('[data-testid="visit-delete"]').click();
        await tick();
        expect(document.body.textContent).toContain('this visit is past that time');
        expect($('[data-testid="visit-delete"]').textContent.trim()).toBe('Archive visit');
    });
});
