import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick, reactive } from 'vue';
import { readFileSync, readdirSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

/*
 * Phase-wide Health axe sweep (UI-SPEC Tests paragraph): every Health screen and state in one place, SK/PL/EN, light and
 * dark (print views forced light), a 320px pass and a pseudo-long (+40%) run for sk and pl. Every state names the element
 * it must reach, so a state that silently renders something else fails instead of passing on an empty page. The per-slice
 * suites keep the detailed behaviour tests; this file proves the whole set together.
 *
 * jsdom computes no layout, so "no horizontal overflow at 320px" is asserted on what jsdom can see (no inline fixed
 * width, nothing forced wider than the viewport) plus a source scan of the Health styles for fixed widths above 320px
 * and nowrap/ellipsis, which would be the only ways a screen could overflow.
 */
let mockProps = {};
const { router, download, refusal } = vi.hoisted(() => ({
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn(() => vi.fn()) },
    download: vi.fn(),
    refusal: vi.fn(),
}));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps, get url() { return '/health'; } }),
    router,
    Head: { props: ['title'], setup: () => () => null },
    Link: { props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) },
}));
vi.mock('../../../resources/js/Security/securityTransfer.js', () => ({ securityDownload: download, securityUpload: vi.fn() }));
vi.mock('../../../resources/js/Health/healthRefusal.js', () => ({ handleHealthRefusal: refusal }));

import Person from '../../../resources/js/Pages/Health/Person.vue';
import EmergencyCard from '../../../resources/js/Pages/Health/EmergencyCard.vue';
import Export from '../../../resources/js/Pages/Health/Export.vue';
import ExportPrint from '../../../resources/js/Pages/Health/ExportPrint.vue';
import AiConsentControl from '../../../resources/js/Health/AiConsentControl.vue';
import HomeHealthCard from '../../../resources/js/Dashboard/HomeHealthCard.vue';
import HealthAccessCard from '../../../resources/js/Components/Settings/HealthAccessCard.vue';
import PersonAccessPanel from '../../../resources/js/Components/Settings/PersonAccessPanel.vue';

const ID = '11111111-1111-4111-8111-111111111111';
const PERSON = { id: ID, name: 'Ada Novak', birthDate: '2015-03-04', ageYears: 11, sex: 'female', bloodType: 'A+', hasAccount: false, aiConsent: false, canEdit: true, notes: 'Notes', contacts: [] };
const CONTACTS = [
    { name: 'Mum', relation: 'Mother', phone: '+421 900 111 222' },
    { name: 'Dad', relation: null, phone: '(02) 555-1234' },
    { name: 'Gran', relation: 'Grandmother', phone: '555 000' },
];
const V1 = { id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', visitedOn: '2026-02-01', doctor: 'Dr Heart', specialtyKey: 'cardiology', specialtyOther: null, diagnosis: 'Murmur', notes: 'Long notes', documentCount: 2 };
const V2 = { id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2', visitedOn: '2025-05-01', doctor: '', specialtyKey: 'cardiology', specialtyOther: null, diagnosis: null, notes: null, documentCount: 0 };
const V3 = { id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa3', visitedOn: '2026-03-01', doctor: 'Dr Sleep', specialtyKey: 'other', specialtyOther: 'Sleep clinic', diagnosis: null, notes: null, documentCount: 0 };
const SPECIALTIES = Object.keys(JSON.parse(readFileSync('lang/en.json', 'utf8')).health.specialty);
const FOLDERS = [
    { key: 'other', otherLabel: 'Sleep clinic', count: 1, latestOn: '2026-03-01', visitIds: [V3.id] },
    { key: 'cardiology', otherLabel: null, count: 2, latestOn: '2026-02-01', visitIds: [V1.id, V2.id] },
];
const TIMELINE = [V3, V1, V2].map((v) => ({ type: 'visit', id: v.id, date: v.visitedOn }));
const A1 = { id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb1', substance: 'Wasp venom', reaction: 'Swelling', severity: 'severe' };
const A2 = { id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2', substance: 'Latex', reaction: null, severity: 'moderate' };
const M1 = { id: 'cccccccc-cccc-4ccc-8ccc-ccccccccccc1', name: 'Ibuprofen', dose: '200 mg', schedule: 'Twice a day', startedOn: '2026-01-10', endedOn: null, notes: null, ongoing: true };
const M3 = { id: 'cccccccc-cccc-4ccc-8ccc-ccccccccccc3', name: 'Amoxicillin', dose: '500 mg', schedule: null, startedOn: '2026-02-01', endedOn: '2026-02-10', notes: null, ongoing: false };
const VA1 = { id: 'dddddddd-dddd-4ddd-8ddd-ddddddddddd1', vaccine: 'Tetanus booster', givenOn: '2016-01-10', nextDueOn: '2026-01-01', notes: null, status: 'overdue', dueInDays: -5 };
const VA2 = { id: 'dddddddd-dddd-4ddd-8ddd-ddddddddddd2', vaccine: 'MMR', givenOn: '2020-01-10', nextDueOn: '2026-06-20', notes: null, status: 'due_soon', dueInDays: 3 };
const VA4 = { id: 'dddddddd-dddd-4ddd-8ddd-ddddddddddd4', vaccine: 'Flu', givenOn: '2025-10-01', nextDueOn: null, notes: null, status: 'none', dueInDays: null };
const D1 = { id: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeee1', kind: 'pdf', title: 'Lab result', visitId: V1.id, uploadedOn: '2025-05-02', sizeBytes: 2048 };
const D2 = { id: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeee2', kind: 'image', title: 'X-ray photo', visitId: null, uploadedOn: '2025-06-02', sizeBytes: 5000 };
const D3 = { id: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeee3', kind: 'heic', title: 'Phone photo', visitId: null, uploadedOn: '2025-06-03', sizeBytes: 900000 };
const PEOPLE = [{ id: ID, name: 'Ada Novak', initials: 'AN' }, { id: '22222222-2222-4222-8222-222222222222', name: 'Boris', initials: 'B' }];

const FULL_PAGE = {
    people: PEOPLE, person: PERSON, canCreate: true, panel: null,
    visits: [V3, V1, V2], folders: FOLDERS,
    timeline: TIMELINE, timelineFilters: ['other', 'cardiology', 'documents'], view: 'folders', filter: 'all', specialties: SPECIALTIES,
    allergies: [A1, A2], medications: [M1, M3], vaccinations: [VA1, VA2, VA4], vaccinationAttention: 2,
    documents: [D1, D2, D3], unattachedDocumentIds: [D2.id, D3.id], limits: { maxMb: 10, filesAtOnce: 10 },
};
const page = (over = {}) => ({ ...FULL_PAGE, ...over });
const viewOnly = { ...PERSON, canEdit: false };

const CARD = {
    person: { id: ID, name: 'Ada Novak', ageYears: 11, birthDate: '2015-04-02', bloodType: 'A+' },
    allergies: [{ substance: 'Peanuts', reaction: 'Hives', severity: 'severe' }],
    medications: [{ name: 'Ibuprofen', dose: '200 mg', schedule: 'Twice a day' }],
    contacts: [{ name: 'Mum', relation: 'Mother', phone: '+421 900 111 222' }],
    printedOn: '2026-11-14',
};
const EXPORT = {
    format: 'family-hub-medical-export', format_version: 1, timezone: 'Europe/Bratislava', locale: 'en', taxonomy_version: 1, scope: 'all', unverified_notice: null,
    subject: { name: 'Ada Novak', birth_date: '2014-03-09', age_years: 12, sex: 'female', blood_type: 'A+', ai_consent: false },
    allergies: [{ substance: 'Peanuts', reaction: 'Hives', severity: 'severe', verification: 'verified' }],
    medications: [{ name: 'Ibuprofen', dose: '200 mg', schedule: 'Twice a day', started_on: '2026-01-01', ended_on: null, verification: 'verified' }],
    vaccinations: [{ vaccine: 'MMR', given_on: '2020-01-10', next_due_on: null, verification: 'verified' }],
    visits: [{ visited_on: '2026-01-10', doctor: 'Dr. Kova', specialty_label: 'Cardiology', diagnosis_text: 'Cold', notes: 'a\nb', verification: 'unverified' }],
    documents: [{ title: 'ECG scan', kind: 'pdf', uploaded_on: '2026-01-11', file_included: false, verification: 'verified' }],
};
const MEMBERS = [{ id: 1, name: 'Anna', access: { health: 'edit' } }, { id: 2, name: 'Bob', access: { health: 'view' } }, { id: 3, name: 'Cyril', access: { health: 'none' } }];
const ACCESS_PERSON = { id: 'p1', name: 'Eva', hasAccount: false, linkedUserId: null, selfAccessEnabled: true, members: { 1: 'edit', 2: 'view' } };

const $ = (sel) => document.querySelector(sel);
const click = async (sel) => { const el = typeof sel === 'string' ? $(sel) : sel; el.click(); await nextTick(); await nextTick(); };
const choose = async (sel) => { const el = $(sel); el.checked = true; el.dispatchEvent(new Event('change')); await nextTick(); };
const personState = (props, target, after) => ({ component: Person, props: () => page(props), target, after });

/** name -> { component, props(), target, after?, light? }: target is a selector that must exist when the state is reached. */
const states = {
    'Person folders expanded': personState({}, '[data-testid="folders"] .folder__header[aria-expanded="true"]'),
    'Person folders collapsed': personState({}, '[data-testid="folders"] .folder__header[aria-expanded="false"]', async () => { await click('.folder__header[aria-expanded="true"]'); }),
    'Person timeline': personState({ view: 'timeline' }, '[data-testid="timeline-results"]'),
    'Person timeline filtered': personState({ view: 'timeline', filter: 'cardiology', timeline: TIMELINE.slice(1) }, '[data-testid="timeline-filters"]'),
    'Person timeline filtered empty': personState({ view: 'timeline', filter: 'cardiology', timeline: [] }, '[data-testid="timeline-empty"]'),
    'Person no visits': personState({ visits: [], folders: [], timeline: [], timelineFilters: [], documents: [], unattachedDocumentIds: [] }, '[data-testid="person-main"]'),
    'Person no people': personState({ person: null, people: [], panel: null }, '[data-testid="empty-add"]'),
    'Person view-only': personState({ person: viewOnly }, '[data-testid="person-header"]'),
    'Person view-only no people': personState({ person: null, people: [], canCreate: false }, '[data-testid="health-person"]'),
    'Person header menu': personState({}, '[role="menu"]', async () => { await click('[data-testid="header-menu"] button'); }),
    'Person delete confirm': personState({}, '.ui-dialog', async () => { await click('[data-testid="header-menu"] button'); await click([...document.querySelectorAll('[role="menuitem"]')][1]); }),
    'Person panel new person': personState({ panel: { kind: 'new-person', id: null } }, '[data-testid="person-form"]'),
    'Person panel edit person 0 contacts': personState({ panel: { kind: 'edit-person', id: null } }, '[data-testid="person-form"]'),
    'Person panel edit person 3 contacts': personState({ person: { ...PERSON, contacts: CONTACTS }, panel: { kind: 'edit-person', id: null } }, '[data-testid="person-form"]'),
    'Visit panel create': personState({ panel: { kind: 'new-visit', id: null } }, '[data-testid="visit-form"]'),
    'Visit panel edit': personState({ panel: { kind: 'visit', id: V1.id } }, '[data-testid="visit-form"]'),
    'Visit panel read-only': personState({ person: viewOnly, panel: { kind: 'visit', id: V1.id } }, '[data-testid="visit-readonly"]'),
    'Visit delete confirm': personState({ panel: { kind: 'visit', id: V1.id } }, '.ui-dialog', async () => { await click('[data-testid="visit-delete"]'); }),
    'Visit attach dialog': personState({ panel: { kind: 'visit', id: V1.id } }, '[data-testid="attach-list"], [data-testid="attach-upload"]', async () => { await click('[data-testid="visit-attach"]'); }),
    'Allergy card with panel create': personState({ panel: { kind: 'new-allergy', id: null } }, '[data-testid="allergy-form"]'),
    'Allergy panel edit': personState({ panel: { kind: 'allergy', id: A2.id } }, '[data-testid="allergy-form"]'),
    'Allergy panel read-only': personState({ person: viewOnly, panel: { kind: 'allergy', id: A1.id } }, '[data-testid="allergy-readonly"]'),
    'Allergy empty': personState({ allergies: [] }, '[data-testid="allergy-empty"]'),
    'Medication panel create': personState({ panel: { kind: 'new-medication', id: null } }, '[data-testid="medication-form"]'),
    'Medication panel edit ended': personState({ panel: { kind: 'medication', id: M3.id } }, '[data-testid="medication-form"]'),
    'Medication panel read-only': personState({ person: viewOnly, panel: { kind: 'medication', id: M1.id } }, '[data-testid="medication-readonly"]'),
    'Medication ended expanded': personState({}, '[data-testid="medication-ended"]', async () => { await click('[data-testid="medication-toggle"]'); }),
    'Medication empty': personState({ medications: [] }, '[data-testid="medication-empty"]'),
    'Vaccination panel create': personState({ panel: { kind: 'new-vaccination', id: null } }, '[data-testid="vaccination-form"]'),
    'Vaccination panel edit': personState({ panel: { kind: 'vaccination', id: VA2.id } }, '[data-testid="vaccination-form"]'),
    'Vaccination panel read-only': personState({ person: viewOnly, panel: { kind: 'vaccination', id: VA1.id } }, '[data-testid="vaccination-readonly"]'),
    'Vaccination mark given': personState({ panel: { kind: 'vaccination', id: VA1.id } }, '[data-testid="vaccination-mark-form"]', async () => { await click('[data-testid="vaccination-mark-open"]'); }),
    'Vaccination empty': personState({ vaccinations: [], vaccinationAttention: 0 }, '[data-testid="vaccination-empty"]'),
    'Document panel pdf': personState({ panel: { kind: 'document', id: D1.id } }, '[data-testid="preview-card"]'),
    'Document panel image loading': personState({ panel: { kind: 'document', id: D2.id } }, '[data-testid="preview-image"]'),
    'Document panel image failed': personState({ panel: { kind: 'document', id: D2.id } }, '[data-testid="preview-failed"]', async () => { $('[data-testid="preview-image"]').dispatchEvent(new Event('error')); await nextTick(); }),
    'Document panel heic': personState({ panel: { kind: 'document', id: D3.id } }, '[data-testid="preview-card"]'),
    'Document panel read-only': personState({ person: viewOnly, panel: { kind: 'document', id: D1.id } }, '[data-testid="document-readonly"]'),
    'Document delete confirm': personState({ panel: { kind: 'document', id: D1.id } }, '.ui-dialog', async () => { await click('[data-testid="doc-delete"]'); }),
    'Document uploader': personState({ panel: { kind: 'new-document', id: null } }, '[data-testid="document-uploader"]'),
    'Emergency card': { component: EmergencyCard, props: () => CARD, target: '[data-testid="emergency-card"]' },
    'Emergency card empty': { component: EmergencyCard, props: () => ({ ...CARD, person: { ...CARD.person, bloodType: null, ageYears: null, birthDate: null }, allergies: [], medications: [], contacts: [] }), target: '[data-testid="emergency-card"]' },
    'Export form default': { component: Export, props: () => ({ person: { id: ID, name: 'Ada Novak' }, specialtiesWithVisits: [{ value: 'cardiology', label: 'Cardiology' }], hasUnverified: false }), target: '[data-testid="export-form"]' },
    'Export form every control': {
        component: Export, props: () => ({ person: { id: ID, name: 'Ada Novak' }, specialtiesWithVisits: [{ value: 'cardiology', label: 'Cardiology' }], hasUnverified: true }),
        target: '[data-testid="specialty-select"]', after: async () => { await choose('input[data-testid="scope-specialty"]'); await choose('input[data-testid="format-md"]'); },
    },
    'Export form failed': {
        component: Export, props: () => ({ person: { id: ID, name: 'Ada Novak' }, specialtiesWithVisits: [], hasUnverified: false }), target: '[data-testid="export-failed"]',
        after: async () => { download.mockResolvedValue({ ok: false, status: 500, error: 'x' }); await choose('input[data-testid="format-json"]'); await click('[data-testid="export-create"]'); await vi.waitFor(() => expect($('[data-testid="export-failed"]')).not.toBeNull()); },
    },
    'Export print': { component: ExportPrint, props: () => ({ person: { id: ID }, export: EXPORT }), target: '[data-testid="export-print"]', light: true },
    'Export print unverified': { component: ExportPrint, props: () => ({ person: { id: ID }, export: { ...EXPORT, unverified_notice: 'x' } }), target: '[data-testid="unverified-legend"]', light: true },
    'AI consent off': { component: AiConsentControl, props: () => ({ personId: ID, value: false, canEdit: true }), target: '[data-testid="ai-switch"]' },
    'AI consent on': { component: AiConsentControl, props: () => ({ personId: ID, value: true, canEdit: true }), target: '[data-testid="ai-switch"]' },
    'AI consent confirm': { component: AiConsentControl, props: () => ({ personId: ID, value: false, canEdit: true }), target: '[data-testid="ai-allow"]', after: async () => { await click('[data-testid="ai-switch"]'); } },
    'AI consent view-only': { component: AiConsentControl, props: () => ({ personId: ID, value: true, canEdit: false }), target: '[data-testid="ai-tag"]' },
    'Settings access card': { component: HealthAccessCard, props: () => ({ people: [ACCESS_PERSON] }), target: 'button' },
    'Settings access card empty': { component: HealthAccessCard, props: () => ({ people: [] }), target: 'section, div' },
    'Settings access panel': { component: PersonAccessPanel, props: () => ({ person: ACCESS_PERSON, members: MEMBERS }), target: 'input[name="level-1"]' },
    'Settings access panel linked': { component: PersonAccessPanel, props: () => ({ person: { ...ACCESS_PERSON, hasAccount: true, linkedUserId: 1 }, members: MEMBERS }), target: '[role="switch"]' },
    'Home card': { component: HomeHealthCard, props: () => ({}), target: '[data-testid="home-health"]' },
};

let current;
afterEach(() => {
    current?.unmount();
    current = null;
    document.body.innerHTML = '';
    document.documentElement.classList.remove('dark');
    vi.clearAllMocks();
});

async function reach(state, { locale, dark = false, width = 1280, pseudo = false }) {
    document.documentElement.classList.toggle('dark', dark && !state.light);
    window.innerWidth = width;
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    mockProps = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: 'none', health: 'edit' } },
        app: { env: 'local', styleGuide: false },
    });
    const messages = pseudo ? pseudoLong(loadMessages(locale)) : loadMessages(locale);
    current = mount(state.component, { props: state.props(), attachTo: document.body, global: { plugins: [createAppI18n(locale, messages)] } });
    await nextTick();
    await state.after?.();
    return state.target;
}

async function sweep(name, state, options) {
    const target = await reach(state, options);
    expect($(target), `${name}: target ${target} not reached`).not.toBeNull();
    await expectNoAxeViolations(document.body);
}

describe('health accessibility sweep', () => {
    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            for (const [name, state] of Object.entries(states)) {
                if (dark && state.light) continue; // print views are forced light, so a dark run adds nothing
                it(`${name} passes axe (${locale}${dark ? ', dark' : ''})`, async () => {
                    await sweep(name, state, { locale, dark });
                });
            }
        }
    }
});

describe('health at 320px', () => {
    for (const [name, state] of Object.entries(states)) {
        it(`${name} reaches its target and overflows nowhere at 320px (sk)`, async () => {
            await sweep(name, state, { locale: 'sk', width: 320 });
            expect(document.documentElement.scrollWidth).toBeLessThanOrEqual(320);
            for (const el of document.body.querySelectorAll('[style]')) {
                expect(el.getAttribute('style') ?? '', `${name}: inline fixed width`).not.toMatch(/(^|;)\s*(min-)?width:\s*(32[1-9]|3[3-9]\d|[4-9]\d\d|\d{4,})px/);
            }
        });
    }
});

describe('health with +40% text', () => {
    for (const locale of ['sk', 'pl']) {
        for (const [name, state] of Object.entries(states)) {
            it(`${name} passes axe with pseudo-long strings (${locale})`, async () => {
                await sweep(name, state, { locale, width: 360, pseudo: true });
                for (const el of document.body.querySelectorAll('[style]')) {
                    expect(el.getAttribute('style') ?? '').not.toMatch(/text-overflow|overflow:\s*hidden/);
                }
            });
        }
    }
});

describe('health styles cannot force horizontal overflow', () => {
    const dirs = ['resources/js/Health', 'resources/js/Pages/Health'];
    const files = dirs.flatMap((d) => readdirSync(d).filter((f) => f.endsWith('.vue')).map((f) => `${d}/${f}`));

    it('scans the Health components', () => {
        expect(files.length).toBeGreaterThanOrEqual(20);
    });

    it.each(files)('%s has no fixed width above 320px, no ellipsis and no nowrap on text', (file) => {
        const raw = [...readFileSync(file, 'utf8').matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map((m) => m[1]).join('\n');
        // Rules inside a container or media query only apply at widths where a fixed column fits (for example the 320px side column from 900px up).
        const css = raw.replace(/@(container|media)[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/g, '');
        expect(css).not.toMatch(/text-overflow:\s*ellipsis/);
        expect(css).not.toMatch(/(^|[;{\s])(min-)?width:\s*(32[1-9]|3[3-9]\d|[4-9]\d\d|\d{4,})px/);
    });
});
