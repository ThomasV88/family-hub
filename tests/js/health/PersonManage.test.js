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

const CONTACTS = [
    { name: 'Mum <b>x</b>', relation: 'Mother', phone: '+421 900 111 222' },
    { name: 'Dad', relation: null, phone: '(02) 555-1234' },
    { name: 'Gran', relation: 'Grandmother', phone: '555 000' },
];
const FULL = { ...PERSON, bloodType: 'A+', notes: 'Note', birthDate: '2015-04-02', contacts: CONTACTS };
const base = (over = {}) => ({
    people: [{ id: ID, name: 'Ada Novak', initials: 'AN' }], person: { ...PERSON, contacts: [] }, canCreate: true, panel: null,
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
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); vi.clearAllMocks(); });

const button = (text) => [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === text);
const type = (el, value) => { el.value = value; el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input')); };
const rows = () => [...document.querySelectorAll('[data-testid="contact-row"]')];

describe('header actions', () => {
    it('offers editors Export records and a More actions menu with Edit and Delete', async () => {
        mountPage();
        const exportLink = document.querySelector('[data-testid="header-export"]');
        expect(exportLink.textContent.trim()).toBe('Export records');
        expect(exportLink.getAttribute('href')).toBe(`/health/people/${ID}/export`);
        const trigger = [...document.querySelectorAll('[data-testid="header-menu"] button')][0];
        expect(trigger.textContent.trim()).toBe('More actions for this person');
        trigger.click();
        await nextTick();
        expect([...document.querySelectorAll('[role="menuitem"]')].map((i) => i.textContent.trim())).toEqual(['Edit person', 'Delete person']);
    });

    it('shows view-only members the line and no actions', () => {
        mountPage({ person: { ...PERSON, canEdit: false, contacts: [] } });
        expect(document.querySelector('[data-testid="person-header"]').textContent).toContain('You can view this record but not change it.');
        expect(document.querySelector('[data-testid="header-export"]')).toBeNull();
        expect(document.querySelector('[data-testid="header-menu"]')).toBeNull();
    });

    it('opens the edit panel from the menu', async () => {
        mountPage();
        [...document.querySelectorAll('[data-testid="header-menu"] button')][0].click();
        await nextTick();
        [...document.querySelectorAll('[role="menuitem"]')][0].click();
        expect(router.get).toHaveBeenCalledWith(expect.any(String), { panel: 'edit-person' }, expect.any(Object));
    });

    it('confirms before deleting, with the safe button focused first', async () => {
        mountPage();
        [...document.querySelectorAll('[data-testid="header-menu"] button')][0].click();
        await nextTick();
        [...document.querySelectorAll('[role="menuitem"]')][1].click();
        await nextTick();
        await nextTick();
        expect(document.body.textContent).toContain('This cannot be undone.');
        expect(document.activeElement.textContent.trim()).toBe('Keep person');
        expect(router.delete).not.toHaveBeenCalled();
        [...document.querySelectorAll('button')].filter((b) => b.textContent.trim() === 'Delete person').at(-1).click();
        expect(router.delete).toHaveBeenCalledWith(`/health/people/${ID}`);
    });
});

describe('person panel', () => {
    it('prefills edit mode and patches with the contacts', async () => {
        mountPage({ person: FULL, panel: { kind: 'edit-person', id: null } });
        await nextTick();
        expect(document.querySelector('[data-testid="person-form"] input').value).toBe('Ada Novak');
        expect(rows()).toHaveLength(3);
        expect(document.querySelector('[data-testid="contact-add"]')).toBeNull();
        expect(rows()[0].querySelector('input[type="tel"]').getAttribute('autocomplete')).toBe('off');
        expect(document.body.textContent).not.toMatch(/Linked account/i);

        type(document.querySelector('[data-testid="person-form"] input'), 'Ada N');
        await nextTick();
        button('Save person').click();
        const [url, data] = router.patch.mock.calls[0];
        expect(url).toBe(`/health/people/${ID}`);
        expect(data.name).toBe('Ada N');
        expect(data.contacts).toEqual([
            { name: 'Mum <b>x</b>', relation: 'Mother', phone: '+421 900 111 222' },
            { name: 'Dad', relation: null, phone: '(02) 555-1234' },
            { name: 'Gran', relation: 'Grandmother', phone: '555 000' },
        ]);
    });

    it('adds up to 3 contacts in create mode and removes rows', async () => {
        mountPage({ panel: { kind: 'new-person', id: null } });
        await nextTick();
        for (let i = 0; i < 3; i++) {
            document.querySelector('[data-testid="contact-add"]').click();
            await nextTick();
        }
        expect(rows()).toHaveLength(3);
        expect(document.querySelector('[data-testid="contact-add"]')).toBeNull();
        rows()[1].querySelector('[data-testid="contact-remove"]').click();
        await nextTick();
        expect(rows()).toHaveLength(2);
        expect(document.querySelector('[data-testid="contact-add"]')).not.toBeNull();
    });

    it('asks for a contact name and phone before sending', async () => {
        mountPage({ panel: { kind: 'new-person', id: null } });
        await nextTick();
        type(document.querySelector('[data-testid="person-form"] input'), 'Kid');
        document.querySelector('[data-testid="contact-add"]').click();
        await nextTick();
        button('Save person').click();
        await nextTick();
        expect(router.post).not.toHaveBeenCalled();
        expect(document.querySelector('[data-testid="person-contacts"]').textContent).toContain('Name is missing');
    });

    it('asks to discard a dirty edit', async () => {
        mountPage({ person: FULL, panel: { kind: 'edit-person', id: null } });
        await nextTick();
        type(document.querySelector('[data-testid="person-form"] input'), 'x');
        await nextTick();
        [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Close' && !b.getAttribute('aria-label')).click();
        await nextTick();
        expect(document.body.textContent).toContain('Discard changes?');
    });

    it('keeps the panel and header free of v-html and the linked-account field', () => {
        for (const file of ['PersonPanel.vue', 'PersonHeader.vue']) {
            const text = readFileSync(`resources/js/Health/${file}`, 'utf8');
            expect(text).not.toContain('v-html');
        }
        expect(readFileSync('resources/js/Health/PersonPanel.vue', 'utf8')).not.toMatch(/linked|user_id/);
    });
});

const states = {
    'header editor': () => ({}),
    'header view-only': () => ({ person: { ...PERSON, canEdit: false, contacts: [] } }),
    'panel edit 0 contacts': () => ({ panel: { kind: 'edit-person', id: null } }),
    'panel edit 3 contacts': () => ({ person: FULL, panel: { kind: 'edit-person', id: null } }),
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
            it(`passes axe: menu open and delete confirm (${locale}${dark ? ', dark' : ''})`, async () => {
                document.documentElement.classList.toggle('dark', dark);
                mountPage({}, { locale });
                [...document.querySelectorAll('[data-testid="header-menu"] button')][0].click();
                await nextTick();
                await expectNoAxeViolations(document.body);
                [...document.querySelectorAll('[role="menuitem"]')][1].click();
                await nextTick();
                await expectNoAxeViolations(document.body);
            });
        }
    }
});
