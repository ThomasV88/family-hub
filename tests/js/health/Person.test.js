import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick, reactive } from 'vue';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

let mockProps = {};
const { headProps, router } = vi.hoisted(() => ({
    headProps: [],
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() },
}));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps, get url() { return '/health'; } }),
    router,
    Head: { props: ['title'], setup(props) { headProps.push(props); return () => null; } },
    Link: { props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) },
}));

import Person from '../../../resources/js/Pages/Health/Person.vue';

const PEOPLE = [
    { id: '11111111-1111-4111-8111-111111111111', name: 'Ada Novak', initials: 'AN' },
    { id: '22222222-2222-4222-8222-222222222222', name: 'Boris', initials: 'B' },
];
const PERSON = { id: PEOPLE[0].id, name: 'Ada Novak', birthDate: '2015-03-04', ageYears: 11, sex: 'female', bloodType: 'AB-', hasAccount: false, aiConsent: false, canEdit: true, notes: null };
const page = (over = {}) => ({ people: PEOPLE, person: PERSON, canCreate: true, panel: null, ...over });

let current;
function mountPage(props = {}, { locale = 'en' } = {}) {
    window.innerWidth = 1280;
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    mockProps = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: 'none', health: 'edit' } },
        app: { env: 'local', styleGuide: false },
    });
    current = mount(Person, { props: page(props), attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return current;
}
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); vi.clearAllMocks(); headProps.length = 0; });

describe('Health Person page', () => {
    it('renders the same empty state whether no people exist or none are shared', () => {
        mountPage({ person: null, people: [] });
        const a = document.querySelector('[data-testid="health-person"]').innerHTML;
        expect(a).toContain('No people to show');
        expect(a).toContain('When a family member is added to Health and shared with you');
        current.unmount();
        // a child whose own record is off gets exactly the props of an empty family, so the DOM is the same
        mountPage({ person: null, people: [], panel: null });
        expect(document.querySelector('[data-testid="health-person"]').innerHTML).toBe(a);
    });

    it('offers Add person in the empty state only to creators', () => {
        mountPage({ person: null, people: [], canCreate: true });
        expect(document.querySelector('[data-testid="empty-add"]')?.textContent.trim()).toBe('Add person');
        current.unmount();
        mountPage({ person: null, people: [], canCreate: false });
        expect(document.querySelector('[data-testid="empty-add"]')).toBeNull();
        expect(document.body.textContent).toContain('No people to show');
    });

    it('opens the new-person panel through a server round trip', async () => {
        mountPage({ person: null, people: [] });
        document.querySelector('[data-testid="empty-add"]').click();
        expect(router.get).toHaveBeenCalledWith(expect.any(String), { panel: 'new-person' }, expect.objectContaining({ preserveState: true, only: ['panel'] }));
    });

    it('draws a radiogroup with one link chip per person and a roving tabindex', async () => {
        mountPage();
        const group = document.querySelector('[role="radiogroup"]');
        expect(group.getAttribute('aria-label')).toBe('People');
        const chips = [...group.querySelectorAll('[role="radio"]')];
        expect(chips).toHaveLength(2);
        expect(chips.map((c) => c.getAttribute('href'))).toEqual(PEOPLE.map((p) => `/health/people/${p.id}`));
        expect(chips.map((c) => c.getAttribute('aria-checked'))).toEqual(['true', 'false']);
        expect(chips.map((c) => c.getAttribute('tabindex'))).toEqual(['0', '-1']);

        chips[0].focus();
        chips[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
        expect(document.activeElement).toBe(chips[1]);
        chips[1].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
        expect(document.activeElement).toBe(chips[0]);
        expect(group.className).not.toMatch(/scroll/);
    });

    it('shows the header with name, age, sex, blood type and the No account tag', () => {
        mountPage();
        const header = document.querySelector('[data-testid="person-header"]');
        expect(header.querySelector('h2').textContent).toBe('Ada Novak');
        expect(header.textContent).toContain('11 years');
        expect(header.textContent).toContain('Female');
        expect(header.textContent).toContain('AB-');
        expect(header.textContent).toContain('No account');
        current.unmount();
        mountPage({ person: { ...PERSON, hasAccount: true, bloodType: null, ageYears: 1, canEdit: false } });
        const again = document.querySelector('[data-testid="person-header"]');
        expect(again.textContent).not.toContain('No account');
        expect(again.textContent).toContain('1 year');
        expect(again.textContent).not.toContain('Blood type');
        expect(again.textContent).toContain('You can view this record but not change it.');
    });

    it('shows the visits empty state and an empty side column', () => {
        mountPage();
        expect(document.querySelector('[data-testid="person-main"]').textContent).toContain('No visits yet');
        expect(document.querySelector('[data-testid="person-side"]').children).toHaveLength(0);
    });

    it('titles the page Health and never with a person name', () => {
        mountPage();
        expect(headProps.at(-1).title).toBe('Health');
        expect(document.querySelector('h1').textContent).toBe('Health');
    });

    it('posts the create form without submitting on Enter and asks before discarding', async () => {
        mountPage({ panel: { kind: 'new-person', id: null } });
        await nextTick();
        const form = document.querySelector('[data-testid="person-form"]');
        const name = form.querySelector('input');
        name.value = 'Cara';
        name.dispatchEvent(new Event('input'));
        const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
        name.dispatchEvent(enter);
        expect(enter.defaultPrevented).toBe(true);
        expect(router.post).not.toHaveBeenCalled();

        [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Save person').click();
        expect(router.post).toHaveBeenCalledWith('/health/people', expect.objectContaining({ name: 'Cara', sex: 'unspecified', blood_type: null }), expect.any(Object));

        [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Close' && !b.getAttribute('aria-label'))?.click();
        await nextTick();
        expect(document.body.textContent).toContain('Discard changes?');
    });

    it('requires a name before posting and renders server errors inline', async () => {
        mountPage({ panel: { kind: 'new-person', id: null } });
        await nextTick();
        [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Save person').click();
        await nextTick();
        expect(router.post).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('Name is missing');
    });

    it('keeps drafts out of history and storage', () => {
        const walk = (dir) => readdirSync(dir).flatMap((f) => {
            const path = `${dir}/${f}`;
            return statSync(path).isDirectory() ? walk(path) : [path];
        });
        const files = [...walk('resources/js/Pages/Health'), ...walk('resources/js/Health')];
        for (const file of files) {
            const text = readFileSync(file, 'utf8');
            expect(text, file).not.toMatch(/remember|v-html|localStorage|sessionStorage/);
        }
    });

    const states = {
        empty: () => ({ person: null, people: [] }),
        person: () => ({}),
        viewOnly: () => ({ person: { ...PERSON, canEdit: false }, canCreate: false }),
        panel: () => ({ panel: { kind: 'new-person', id: null } }),
    };
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
});
