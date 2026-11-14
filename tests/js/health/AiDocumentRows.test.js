import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick, reactive } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages, mountUi } from '../helpers/mount.js';

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
vi.mock('../../../resources/js/Security/securityTransfer.js', () => ({ securityDownload: vi.fn(), securityUpload: vi.fn() }));
vi.mock('../../../resources/js/Health/healthRefusal.js', () => ({ handleHealthRefusal: vi.fn() }));

import TimelineList from '../../../resources/js/Health/TimelineList.vue';
import SpecialtyFolder from '../../../resources/js/Health/SpecialtyFolder.vue';
import Person from '../../../resources/js/Pages/Health/Person.vue';

const ID = '11111111-1111-4111-8111-111111111111';
const doc = (n, title) => ({ id: `aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa${n}`, kind: 'pdf', title, visitId: null, uploadedOn: `2025-06-0${n}`, sizeBytes: 100 });
const D = [doc(1, 'Queued doc'), doc(2, 'Running doc'), doc(3, 'Ready doc'), doc(4, 'Empty doc'), doc(5, 'Failed doc'), doc(6, 'Cancelled doc'), doc(7, 'Superseded doc'), doc(8, 'No run doc')];
const st = (status, extra = {}) => ({ status, extractionId: 'x', readyExtractionId: null, cancelReason: null, pagesRead: null, pagesTotal: null, pending: 0, ...extra });
const STATUSES = {
    [D[0].id]: st('queued'), [D[1].id]: st('running'), [D[2].id]: st('ready', { entryCount: 3 }), [D[3].id]: st('ready', { entryCount: 0 }),
    [D[4].id]: st('failed'), [D[5].id]: st('cancelled'), [D[6].id]: st('superseded'),
};
const entries = D.map((d) => ({ type: 'document', id: d.id, date: d.uploadedOn }));

let current;
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); vi.clearAllMocks(); });

const tags = () => [...document.querySelectorAll('[data-testid="ai-status-tag"]')];
const rowTags = () => [...document.querySelectorAll('[data-testid="timeline-entry"]')].map((r) => r.querySelector('[data-testid="ai-status-tag"]')?.textContent.trim() ?? null);

describe('TimelineList document rows', () => {
    it('shows the status tag per document and nothing for no run or superseded', () => {
        current = mountUi(TimelineList, { props: { entries, documents: D, aiStatuses: STATUSES } });
        expect(rowTags()).toEqual(['Waiting for AI', 'AI is reading', 'Suggestions ready', 'Nothing to suggest', 'AI could not read this', 'Cancelled', null, null]);
    });

    it('has no actions on rows and works without the prop', () => {
        current = mountUi(TimelineList, { props: { entries, documents: D } });
        expect(tags()).toHaveLength(0);
    });

    it.each(['en', 'sk', 'pl'])('is axe clean in %s, light and dark', async (locale) => {
        current = mountUi(TimelineList, { props: { entries, documents: D, aiStatuses: STATUSES }, locale });
        expect(tags()).toHaveLength(6);
        await expectNoAxeViolations(document.body);
        document.documentElement.classList.add('dark');
        await expectNoAxeViolations(document.body);
    });
});

describe('SpecialtyFolder unattached documents', () => {
    it('passes the statuses to the document rows', async () => {
        current = mountUi(SpecialtyFolder, { props: { documents: D, aiStatuses: STATUSES, defaultOpen: true } });
        await nextTick();
        expect(tags()).toHaveLength(6);
    });
});

describe('Person page wiring', () => {
    function mountPage(view, ai) {
        window.innerWidth = 1280;
        window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
        mockProps = reactive({
            locale: 'en', flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
            auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: 'none', health: 'view' } },
            app: { env: 'local', styleGuide: false },
        });
        current = mount(Person, {
            props: {
                people: [{ id: ID, name: 'Ada', initials: 'A' }], person: { id: ID, name: 'Ada', birthDate: null, ageYears: 11, sex: 'female', bloodType: null, hasAccount: false, aiConsent: true, canEdit: false, notes: null },
                canCreate: false, panel: null, visits: [], folders: [], timeline: entries, timelineFilters: ['documents'], view, filter: 'all', specialties: ['other'],
                allergies: [], medications: [], vaccinations: [], vaccinationAttention: 0, documents: D, unattachedDocumentIds: D.map((d) => d.id), limits: { maxMb: 10, filesAtOnce: 10 }, ai,
            },
            attachTo: document.body, global: { plugins: [createAppI18n('en', loadMessages('en'))] },
        });
    }
    const ai = { canRun: false, available: true, noticeAcknowledged: true, statuses: STATUSES };

    it('timeline view shows the tags from the page poller', () => {
        mountPage('timeline', ai);
        expect(tags().length).toBeGreaterThanOrEqual(6);
    });

    it('folder view shows the tags for unattached documents', () => {
        mountPage('folders', ai);
        expect(tags().length).toBeGreaterThanOrEqual(6);
    });

    it('shows no run controls on rows', () => {
        mountPage('timeline', ai);
        expect(document.querySelector('[data-testid="timeline-entry"] button')).toBeNull();
    });
});
