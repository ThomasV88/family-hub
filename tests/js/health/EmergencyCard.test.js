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

import EmergencyCard from '../../../resources/js/Pages/Health/EmergencyCard.vue';
import Person from '../../../resources/js/Pages/Health/Person.vue';

const ID = '11111111-1111-4111-8111-111111111111';
const CARD = {
    person: { id: ID, name: 'Ada <b>Novak</b>', ageYears: 11, birthDate: '2015-04-02', bloodType: 'A+' },
    allergies: [
        { substance: 'Peanuts', reaction: 'Hives', severity: 'severe' },
        { substance: 'Pollen', reaction: null, severity: 'mild' },
    ],
    medications: [{ name: 'Ibuprofen', dose: '200 mg', schedule: 'Twice a day' }, { name: 'Vitamin D', dose: null, schedule: null }],
    contacts: [{ name: 'Mum', relation: 'Mother', phone: '+421 900 111 222' }],
    printedOn: '2026-11-14',
};
const EMPTY = { ...CARD, person: { ...CARD.person, bloodType: null, ageYears: null, birthDate: null }, allergies: [], medications: [], contacts: [] };

const PERSON = { id: ID, name: 'Ada Novak', birthDate: null, ageYears: 11, sex: 'female', bloodType: 'A+', hasAccount: false, aiConsent: false, canEdit: true, notes: null, contacts: [] };
const pageProps = (over = {}) => ({
    people: [{ id: ID, name: 'Ada Novak', initials: 'AN' }], person: PERSON, canCreate: true, panel: null,
    visits: [], folders: [], timeline: [], timelineFilters: [], view: 'folders', filter: 'all', specialties: [],
    allergies: [{ id: 'a1', substance: 'Peanuts', reaction: null, severity: 'severe' }, { id: 'a2', substance: 'Pollen', reaction: null, severity: 'mild' }], ...over,
});

let current;
function mountPage(component, props, { locale = 'en' } = {}) {
    window.innerWidth = 1280;
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    mockProps = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: 'none', health: 'edit' } },
        app: { env: 'local', styleGuide: false },
    });
    current = mount(component, { props, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return current;
}
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); vi.clearAllMocks(); });

const $ = (sel) => document.querySelector(sel);

describe('emergency card page', () => {
    it('has one h1, the sections in order and everything as text', () => {
        mountPage(EmergencyCard, CARD);
        expect(document.querySelectorAll('h1')).toHaveLength(1);
        expect($('h1').textContent).toBe('Emergency card');
        expect([...document.querySelectorAll('section h2')].map((h2) => h2.textContent)).toEqual(['Allergies', 'Current medications', 'Emergency contacts']);
        expect($('[data-testid="emergency-name"]').textContent).toBe('Ada <b>Novak</b>');
        expect($('b')).toBeNull();
        expect($('[data-testid="emergency-blood"]').textContent).toContain('A+');
        expect(document.body.textContent).toContain('Blood type');
    });

    it('prints the severity word next to each allergy, severe first as sent', () => {
        mountPage(EmergencyCard, CARD);
        const items = [...document.querySelectorAll('[data-testid="emergency-allergies"] li')].map((li) => li.textContent.replace(/\s+/g, ' ').trim());
        expect(items[0]).toContain('Peanuts, Hives');
        expect(items[0]).toContain('Severe');
        expect(items[1]).toContain('Pollen');
        expect(items[1]).toContain('Mild');
        expect($('[data-severity="severe"] svg')).not.toBeNull();
    });

    it('lists medications, contacts with a tel link, and the printed footer', () => {
        mountPage(EmergencyCard, CARD);
        expect($('[data-testid="emergency-medications"]').textContent).toContain('Ibuprofen, 200 mg, Twice a day');
        expect($('[data-testid="emergency-medications"]').textContent).toContain('Vitamin D');
        expect($('[data-testid="emergency-contacts"]').textContent).toContain('Mum (Mother)');
        expect($('[data-testid="emergency-contacts"] a').getAttribute('href')).toBe('tel:+421900111222');
        expect($('[data-testid="emergency-footer"]').textContent).toContain('Printed');
        expect($('[data-testid="emergency-footer"]').textContent).toContain('Check that this is up to date.');
    });

    it('shows the empty sentences and Unknown blood type', () => {
        mountPage(EmergencyCard, EMPTY);
        expect(document.body.textContent).toContain('No allergies recorded');
        expect(document.body.textContent).toContain('No current medications recorded');
        expect(document.body.textContent).toContain('No emergency contacts recorded');
        expect($('[data-testid="emergency-blood"]').textContent).toContain('Unknown');
    });

    it('has the print toolbar, no shell landmarks and no share, link or QR control', () => {
        mountPage(EmergencyCard, CARD);
        const print = vi.spyOn(window, 'print').mockImplementation(() => {});
        expect($('[data-testid="print-back"]').textContent).toContain('Back to person');
        expect($('[data-testid="print-back"]').getAttribute('href')).toBe(`/health/people/${ID}`);
        expect($('[data-testid="print-button"]').textContent).toContain('Print card');
        $('[data-testid="print-button"]').click();
        expect(print).toHaveBeenCalledTimes(1);
        expect($('nav')).toBeNull();
        expect(document.querySelectorAll('main')).toHaveLength(1);
        expect(document.body.textContent).not.toMatch(/share|QR/i);
        const source = readFileSync('resources/js/Pages/Health/EmergencyCard.vue', 'utf8');
        expect(source).toContain('PrintLayout');
        expect(source).not.toMatch(/share|qrcode|QR/);
        expect(source).not.toContain('v-html');
    });
});

describe('person page', () => {
    it('shows the summary card first in the side column with blood type and allergy count', () => {
        mountPage(Person, pageProps());
        const side = $('[data-testid="person-side"]');
        expect(side.firstElementChild.getAttribute('data-testid')).toBe('emergency-summary');
        expect($('[data-testid="emergency-summary-line"]').textContent).toBe('Blood type A+ · 2 allergies');
        expect($('[data-testid="emergency-summary-open"]').getAttribute('href')).toBe(`/health/people/${ID}/emergency-card`);
        expect($('[data-testid="emergency-summary-open"]').textContent.trim()).toBe('Open emergency card');
    });

    it('adds the header button for editors and for view-only members', () => {
        mountPage(Person, pageProps());
        expect($('[data-testid="header-emergency"]').getAttribute('href')).toBe(`/health/people/${ID}/emergency-card`);
        expect($('[data-testid="header-emergency"]').textContent.trim()).toBe('Emergency card');
        current.unmount(); document.body.innerHTML = '';

        mountPage(Person, pageProps({ person: { ...PERSON, canEdit: false } }));
        expect($('[data-testid="header-emergency"]')).not.toBeNull();
        expect($('[data-testid="header-export"]')).toBeNull();
        expect($('[data-testid="person-header"]').textContent).toContain('You can view this record but not change it.');
        expect($('[data-testid="emergency-summary"]')).not.toBeNull();
    });

    it('shows Unknown and no allergies when there is no data', () => {
        mountPage(Person, pageProps({ person: { ...PERSON, bloodType: null }, allergies: [] }));
        expect($('[data-testid="emergency-summary-line"]').textContent).toBe('Blood type Unknown · 0 allergies');
    });
});

describe('accessibility', () => {
    for (const locale of ['en', 'sk', 'pl']) {
        it(`passes axe: card with data (${locale}, light)`, async () => {
            mountPage(EmergencyCard, CARD, { locale });
            await nextTick();
            await expectNoAxeViolations(document.body);
        });
        it(`passes axe: empty card (${locale}, light)`, async () => {
            mountPage(EmergencyCard, EMPTY, { locale });
            await nextTick();
            await expectNoAxeViolations(document.body);
        });
        for (const dark of [false, true]) {
            for (const [name, over] of [['editor', {}], ['view-only', { person: { ...PERSON, canEdit: false } }]]) {
                it(`passes axe: person page ${name} (${locale}${dark ? ', dark' : ''})`, async () => {
                    document.documentElement.classList.toggle('dark', dark);
                    mountPage(Person, pageProps(over), { locale });
                    await nextTick();
                    await expectNoAxeViolations(document.body);
                });
            }
        }
    }
});
