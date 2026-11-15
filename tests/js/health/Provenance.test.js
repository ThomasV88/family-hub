import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick, reactive } from 'vue';
import { readFileSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

let mockProps = {};
const { router } = vi.hoisted(() => ({
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn() },
}));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps, get url() { return '/health'; } }),
    router,
    Head: { props: ['title'], setup: () => () => null },
    Link: { props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) },
}));

import Person from '../../../resources/js/Pages/Health/Person.vue';
import ExportPrint from '../../../resources/js/Pages/Health/ExportPrint.vue';
import ProvenanceTags from '../../../resources/js/Health/ProvenanceTags.vue';

const ID = '11111111-1111-4111-8111-111111111111';
const PERSON = { id: ID, name: 'Ada Novak', birthDate: null, ageYears: 11, sex: 'female', bloodType: null, hasAccount: false, aiConsent: false, canEdit: true, notes: null };
const UNV = { source: 'ai', verification: 'unverified' };
const VER = { source: 'ai', verification: 'verified' };
const MAN = { source: 'manual', verification: 'manual' };
const VISIT = { id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', visitedOn: '2026-02-01', doctor: 'Dr Heart', specialtyKey: 'cardiology', specialtyOther: null, diagnosis: 'Murmur', notes: null, documentCount: 0, provenance: UNV };
const VISIT_M = { ...VISIT, id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2', doctor: 'Dr Own', provenance: MAN };
const ALLERGY = { id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb1', substance: 'Latex', reaction: 'Rash', severity: 'severe', provenance: UNV };
const ALLERGY_M = { ...ALLERGY, id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2', substance: 'Pollen', provenance: MAN };
const MED = { id: 'cccccccc-cccc-4ccc-8ccc-ccccccccccc1', name: 'Ibuprofen', dose: '200 mg', schedule: null, startedOn: '2026-01-10', endedOn: null, notes: null, ongoing: true, provenance: UNV };
const MED_V = { ...MED, id: 'cccccccc-cccc-4ccc-8ccc-ccccccccccc2', name: 'Vitamin D', provenance: VER };
const VAC = { id: 'dddddddd-dddd-4ddd-8ddd-ddddddddddd1', vaccine: 'Flu', givenOn: '2025-10-01', nextDueOn: null, notes: null, status: 'none', dueInDays: null, provenance: UNV };
const SPECIALTIES = Object.keys(JSON.parse(readFileSync('lang/en.json', 'utf8')).health.specialty);
const base = (over = {}) => ({
    people: [{ id: ID, name: 'Ada Novak', initials: 'AN' }], person: PERSON, canCreate: true, panel: null,
    visits: [VISIT, VISIT_M],
    folders: [{ key: 'cardiology', otherLabel: null, count: 2, latestOn: '2026-02-01', visitIds: [VISIT.id, VISIT_M.id] }],
    timeline: [VISIT, VISIT_M].map((v) => ({ type: 'visit', id: v.id, date: v.visitedOn })),
    timelineFilters: ['cardiology'], view: 'folders', filter: 'all', specialties: SPECIALTIES,
    allergies: [ALLERGY, ALLERGY_M], medications: [MED, MED_V], vaccinations: [VAC], vaccinationAttention: 0,
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

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
const tagText = (el) => $$('.ui-tag', el).map((t) => t.textContent.trim());

describe('ProvenanceTags', () => {
    const mountTags = (provenance, extra = {}) => mount(ProvenanceTags, { props: { provenance, ...extra }, attachTo: document.body, global: { plugins: [createAppI18n('en', loadMessages('en'))] } });

    it('shows AI-extracted and Unverified, each with an icon', async () => {
        const w = mountTags(UNV);
        expect(tagText(w.element)).toEqual(['AI-extracted', 'Unverified']);
        expect(w.findAll('svg')).toHaveLength(2);
        await expectNoAxeViolations(document.body);
        w.unmount();
    });
    it('shows AI-extracted and Verified once verified', () => {
        const w = mountTags(VER);
        expect(tagText(w.element)).toEqual(['AI-extracted', 'Verified']);
        w.unmount();
    });
    it('renders nothing for a manual record or no provenance', () => {
        for (const p of [MAN, null, undefined]) {
            const w = mountTags(p);
            expect(w.find('[data-testid="provenance-tags"]').exists()).toBe(false);
            w.unmount();
        }
    });
    it('offers the verify button only for unverified records when allowed, and emits once per press', async () => {
        const w = mountTags(UNV, { canVerify: true });
        await w.find('[data-testid="provenance-verify"]').trigger('click');
        expect(w.emitted('verify')).toHaveLength(1);
        w.unmount();
        const v = mountTags(VER, { canVerify: true });
        expect(v.find('[data-testid="provenance-verify"]').exists()).toBe(false);
        v.unmount();
        const n = mountTags(UNV, { canVerify: false });
        expect(n.find('[data-testid="provenance-verify"]').exists()).toBe(false);
        n.unmount();
    });
});

describe('tags in record locations', () => {
    it('labels AI allergies after the severity tag and leaves manual ones bare', async () => {
        mountPage();
        const [ai, manual] = $$('[data-testid="allergy-row"]');
        const tags = $$('.ui-tag', ai).map((t) => t.textContent.trim());
        expect(tags).toEqual(['Severe', 'AI-extracted', 'Unverified']);
        expect($('[data-testid="provenance-tags"]', manual)).toBeNull();
        await expectNoAxeViolations(document.body);
    });
    it('labels medication rows (verified shows Verified)', () => {
        mountPage();
        const [unv, ver] = $$('[data-testid="medication-row"]');
        expect(tagText(unv)).toContain('Unverified');
        expect(tagText(ver)).toEqual(expect.arrayContaining(['AI-extracted', 'Verified']));
        expect(tagText(ver)).not.toContain('Unverified');
    });
    it('labels vaccination rows', () => {
        mountPage();
        expect(tagText($('[data-testid="vaccination-row"]'))).toEqual(['AI-extracted', 'Unverified']);
    });
    it('labels visit entries in the specialty folder and the timeline, not manual ones', () => {
        mountPage();
        const folder = $$('[data-testid="timeline-entry"]', $('[data-testid="specialty-folder"]'));
        expect(folder).toHaveLength(2);
        expect(tagText(folder[0])).toEqual(['AI-extracted', 'Unverified']);
        expect(tagText(folder[1])).toEqual([]);
        document.body.innerHTML = '';
    });
    it('labels visit entries in the timeline view', () => {
        mountPage({ view: 'timeline' });
        const entries = $$('[data-testid="timeline-entry"]');
        expect(entries.filter((e) => tagText(e).includes('Unverified'))).toHaveLength(1);
    });
    it.each(['sk', 'pl'])('is axe clean in %s', async (locale) => {
        mountPage({}, { locale });
        await expectNoAxeViolations(document.body);
    });
    it('is axe clean in dark mode', async () => {
        document.documentElement.classList.add('dark');
        mountPage();
        await expectNoAxeViolations(document.body);
    });
    it('lets long tag text wrap instead of truncating', () => {
        const src = readFileSync('resources/js/Health/ProvenanceTags.vue', 'utf8');
        expect(src).toMatch(/flex-wrap: wrap/);
        expect(src).toMatch(/white-space: normal/);
        expect(src).toMatch(/forced-colors: active/);
    });
});

describe('print export', () => {
    const EXPORT = {
        timezone: 'Europe/Bratislava', unverified_notice: 'x', subject: { name: 'Ada', birth_date: null, sex: null, blood_type: null },
        allergies: [{ substance: 'Latex', reaction: null, severity: 'unknown', verification: 'unverified' }, { substance: 'Pollen', reaction: null, severity: 'mild', verification: 'verified' }],
        medications: [], vaccinations: [], visits: [], documents: [],
    };
    it('prints "AI-extracted, Unverified" for unverified AI rows only', async () => {
        mockProps = reactive({ locale: 'en', flash: {}, availableLocales: ['en'], auth: { user: { id: 1, name: 'M', email: 'm@e.t', isAdmin: false }, access: {} }, app: { env: 'local', styleGuide: false } });
        current = mount(ExportPrint, { props: { person: { id: ID }, export: EXPORT }, attachTo: document.body, global: { plugins: [createAppI18n('en', loadMessages('en'))] } });
        const tags = $$('[data-testid="unverified-tag"]');
        expect(tags).toHaveLength(1);
        expect(tags[0].textContent.trim()).toBe('AI-extracted, Unverified');
        await expectNoAxeViolations(document.body);
    });
});

describe('verify action in the panels', () => {
    const PANELS = [
        ['visit', { panel: { kind: 'visit', id: VISIT.id } }, 'visits', VISIT.id, { visits: [VISIT, VISIT_M] }, { visits: [{ ...VISIT, provenance: VER }] }, { visits: [{ ...VISIT, provenance: MAN }] }],
        ['medication', { panel: { kind: 'medication', id: MED.id } }, 'medications', MED.id, {}, { medications: [{ ...MED, provenance: VER }] }, { medications: [{ ...MED, provenance: MAN }] }],
        ['allergy', { panel: { kind: 'allergy', id: ALLERGY.id } }, 'allergies', ALLERGY.id, {}, { allergies: [{ ...ALLERGY, provenance: VER }] }, { allergies: [{ ...ALLERGY, provenance: MAN }] }],
        ['vaccination', { panel: { kind: 'vaccination', id: VAC.id } }, 'vaccinations', VAC.id, {}, { vaccinations: [{ ...VAC, provenance: VER }] }, { vaccinations: [{ ...VAC, provenance: MAN }] }],
    ];
    const verifyButton = () => $('[data-testid="provenance-verify"]');

    it.each(PANELS)('%s panel: editor of an unverified record verifies once via the right route', async (kind, panel, route, id, _x) => {
        mountPage(panel);
        await nextTick();
        const header = $('[data-testid="panel-provenance"]');
        expect(tagText(header)).toEqual(expect.arrayContaining(['AI-extracted', 'Unverified']));
        expect(verifyButton().textContent.trim()).toBe('Mark as verified');
        await expectNoAxeViolations(document.body);
        verifyButton().click();
        await nextTick();
        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith(`/health/people/${ID}/${route}/${id}/verify`, {}, expect.objectContaining({ preserveScroll: true }));
    });

    it.each(PANELS)('%s panel: verified record shows Verified and no button', async (kind, panel, route, id, _x, verified) => {
        mountPage({ ...panel, ...verified });
        await nextTick();
        expect(tagText($('[data-testid="panel-provenance"]'))).toEqual(['AI-extracted', 'Verified']);
        expect(verifyButton()).toBeNull();
        await expectNoAxeViolations(document.body);
    });

    it.each(PANELS)('%s panel: manual record has no tags and no button', async (kind, panel, route, id, _x, _v, manual) => {
        mountPage({ ...panel, ...manual });
        await nextTick();
        expect($('[data-testid="panel-provenance"]')).toBeNull();
        expect(verifyButton()).toBeNull();
    });

    it.each(PANELS)('%s panel: a view-only member sees the tags but no button', async (kind, panel) => {
        mountPage({ ...panel, person: { ...PERSON, canEdit: false }, canCreate: false });
        await nextTick();
        expect(tagText($('[data-testid="panel-provenance"]'))).toEqual(expect.arrayContaining(['Unverified']));
        expect(verifyButton()).toBeNull();
        expect(router.post).not.toHaveBeenCalled();
    });

    it('saving an edit of an AI allergy does not verify it', async () => {
        mountPage({ panel: { kind: 'allergy', id: ALLERGY.id } });
        await nextTick();
        const input = $('[data-testid="allergy-form"] input');
        input.value = 'Latex gloves';
        input.dispatchEvent(new Event('input'));
        await nextTick();
        $('[data-testid="allergy-save"]').click();
        await nextTick();
        expect(router.patch).toHaveBeenCalledTimes(1);
        expect(router.post).not.toHaveBeenCalled();
        expect(router.patch.mock.calls[0][1]).not.toHaveProperty('verified_at');
        expect(router.patch.mock.calls[0][1]).not.toHaveProperty('verification');
        expect(tagText($('[data-testid="panel-provenance"]'))).toContain('Unverified');
    });
});

describe('copy', () => {
    it('uses the real health.ai.provenance keys, present in all three languages', () => {
        for (const locale of ['en', 'sk', 'pl']) {
            const p = loadMessages(locale).health.ai.provenance;
            for (const key of ['ai_extracted', 'unverified', 'verified', 'verify_action', 'verified_toast']) expect(p[key], `${locale}.${key}`).toBeTruthy();
        }
    });
});
