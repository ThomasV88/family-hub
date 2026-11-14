import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick, reactive } from 'vue';
import { readFileSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

let mockProps = {};
const { router, download, refusal } = vi.hoisted(() => ({
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn() },
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

import Export from '../../../resources/js/Pages/Health/Export.vue';
import ExportPrint from '../../../resources/js/Pages/Health/ExportPrint.vue';
import PrintLayout from '../../../resources/js/Layouts/PrintLayout.vue';

const ID = '11111111-1111-4111-8111-111111111111';
const SPECIALTIES = [{ value: 'cardiology', label: 'Cardiology' }, { value: 'dermatology', label: 'Dermatology' }];
const form = (over = {}) => ({ person: { id: ID, name: 'Ada <b>Novak</b>' }, specialtiesWithVisits: SPECIALTIES, hasUnverified: false, ...over });

const EXPORT = {
    format: 'family-hub-medical-export', format_version: 1, timezone: 'Europe/Bratislava', locale: 'en', taxonomy_version: 1, scope: 'all', unverified_notice: null,
    subject: { name: 'Ada <b>Novak</b>', birth_date: '2014-03-09', age_years: 12, sex: 'female', blood_type: 'A+', ai_consent: false },
    allergies: [{ substance: 'Peanuts', reaction: 'Hives', severity: 'severe', verification: 'verified' }],
    medications: [{ name: 'Ibuprofen', dose: '200 mg', schedule: 'Twice a day', started_on: '2026-01-01', ended_on: null, verification: 'verified' }],
    vaccinations: [{ vaccine: 'MMR', given_on: '2020-01-10', next_due_on: null, verification: 'verified' }],
    visits: [{ visited_on: '2026-01-10', doctor: 'Dr. Kova', specialty_label: 'Cardiology', diagnosis_text: 'Cold', notes: 'line one\nline two', verification: 'verified' }],
    documents: [{ title: 'ECG scan', kind: 'pdf', uploaded_on: '2026-01-11', file_included: false, verification: 'verified' }],
};

let current;
function mountPage(component, props, { locale = 'en' } = {}) {
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    mockProps = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: 'none', health: 'edit' } },
        app: { env: 'local', styleGuide: false },
    });
    current = mount(component, { props, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return current;
}
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; vi.clearAllMocks(); });

const $ = (sel) => document.querySelector(sel);
const choose = async (sel) => { const el = $(sel); el.checked = true; el.dispatchEvent(new Event('change')); await nextTick(); };

describe('export form', () => {
    it('shows the person as text, the not-kept line and no specialty select or unverified checkbox at first', async () => {
        mountPage(Export, form());
        expect(document.querySelector('h1').textContent).toBe('Export records');
        expect(document.body.textContent).toContain('Person: Ada <b>Novak</b>');
        expect($('b')).toBeNull();
        expect(document.body.textContent).toContain('Exports are created when you ask and are not kept on the server.');
        expect($('[data-testid="specialty-select"]')).toBeNull();
        expect($('[data-testid="unverified-option"]')).toBeNull();
        expect($('[data-testid="ai-warning"]')).toBeNull();
        expect(document.querySelectorAll('[role="radiogroup"]')).toHaveLength(2);
        await expectNoAxeViolations(document.body);
    });

    it('reveals the specialty select of used specialties only', async () => {
        mountPage(Export, form());
        await choose('[data-testid="scope-specialty"] input, input[data-testid="scope-specialty"]');
        const select = $('[data-testid="specialty-select"]');
        expect([...select.options].map((o) => o.value)).toEqual(['cardiology', 'dermatology']);
        await expectNoAxeViolations(document.body);
    });

    it('shows the AI warning under For AI only, tied by aria-describedby', async () => {
        mountPage(Export, form());
        await choose('input[data-testid="format-md"]');
        const warning = $('[data-testid="ai-warning"]');
        expect(warning.textContent).toContain('may still mention the person by name');
        expect(warning.getAttribute('role')).toBe('note');
        expect($('input[data-testid="format-md"]').getAttribute('aria-describedby')).toContain(warning.id);
        await expectNoAxeViolations(document.body);
        await choose('input[data-testid="format-json"]');
        expect($('[data-testid="ai-warning"]')).toBeNull();
    });

    it('renders the unverified checkbox only when the server reports unverified items', async () => {
        mountPage(Export, form({ hasUnverified: true }));
        expect($('[data-testid="unverified-option"]').textContent).toContain('Include AI-suggested data nobody has checked yet');
        expect($('input[data-testid="include-unverified"]').checked).toBe(false);
        await expectNoAxeViolations(document.body);
    });

    it('navigates to the print URL for a doctor export', async () => {
        mountPage(Export, form({ hasUnverified: true }));
        await choose('input[data-testid="scope-specialty"]');
        $('select').value = 'dermatology';
        $('select').dispatchEvent(new Event('change'));
        await choose('input[data-testid="include-unverified"]');
        $('[data-testid="export-create"]').click();
        await nextTick();
        expect(router.visit).toHaveBeenCalledWith(`/health/people/${ID}/export/print?scope=specialty&specialty=dermatology&include_unverified=1`);
        expect(download).not.toHaveBeenCalled();
    });

    it('downloads a data file through securityDownload, hands it to the browser and revokes the URL only after a delay', async () => {
        const revoked = [];
        const delayed = [];
        const realSetTimeout = globalThis.setTimeout;
        vi.spyOn(globalThis, 'setTimeout').mockImplementation((fn, ms, ...rest) => (ms === 30_000 ? (delayed.push(fn), 0) : realSetTimeout(fn, ms, ...rest)));
        URL.createObjectURL = vi.fn(() => 'blob:x');
        URL.revokeObjectURL = vi.fn((u) => { revoked.push(u); });
        download.mockResolvedValue({ ok: true, status: 200, blob: new Blob(['{}']), filename: 'health-export-all-2026-11-14.json' });
        mountPage(Export, form());
        await choose('input[data-testid="format-json"]');
        $('[data-testid="export-create"]').click();
        await vi.waitFor(() => expect(delayed).toHaveLength(1));
        expect(revoked).toEqual([]);
        delayed[0]();
        expect(revoked).toEqual(['blob:x']);
        expect(download).toHaveBeenCalledWith(`/health/people/${ID}/export/download?scope=all&format=json`);
        await vi.waitFor(() => expect(document.body.textContent).toContain('Export downloaded'));
    });

    it('sends 423 to the shared refusal handler and shows the danger alert for other failures', async () => {
        download.mockResolvedValue({ ok: false, status: 423, error: 'reauth_required' });
        refusal.mockReturnValueOnce(true);
        mountPage(Export, form());
        await choose('input[data-testid="format-md"]');
        $('[data-testid="export-create"]').click();
        await vi.waitFor(() => expect(refusal).toHaveBeenCalledWith('reauth_required'));
        expect($('[data-testid="export-failed"]')).toBeNull();

        download.mockResolvedValue({ ok: false, status: 500, error: 'failed' });
        $('[data-testid="export-create"]').click();
        await vi.waitFor(() => expect($('[data-testid="export-failed"]')).not.toBeNull());
        expect($('[data-testid="export-failed"]').textContent).toContain('We could not create the export');
        await expectNoAxeViolations(document.body);
    });

    it.each(['sk', 'pl'])('is axe clean in %s with every control shown', async (locale) => {
        mountPage(Export, form({ hasUnverified: true }), { locale });
        await choose('input[data-testid="scope-specialty"]');
        await choose('input[data-testid="format-md"]');
        await expectNoAxeViolations(document.body);
    });
});

describe('print page', () => {
    const props = (over = {}) => ({ person: { id: ID }, export: { ...EXPORT, ...over } });

    it('renders one h1, text-only content, document titles and dates, and no shell landmarks', async () => {
        mountPage(ExportPrint, props());
        expect(document.querySelectorAll('h1')).toHaveLength(1);
        expect(document.querySelector('h1').textContent).toBe('Health records');
        expect($('[data-testid="export-person"]').textContent).toBe('Ada <b>Novak</b>');
        expect($('[data-testid="export-print"] b')).toBeNull();
        expect($('[data-testid="export-documents"]').textContent).toContain('ECG scan');
        expect($('[data-testid="export-allergies"]').textContent).toContain('Severe');
        expect($('[data-testid="export-visits"] td.xp__text').textContent).toBe('Cold');
        for (const sel of ['nav', 'aside', '[role="navigation"]', '[data-testid="fab"]', '[role="status"][aria-live]', '.fh-frame']) {
            expect($(sel), sel).toBeNull();
        }
        expect($('[data-testid="unverified-tag"]')).toBeNull();
        expect($('[data-testid="unverified-legend"]')).toBeNull();
        expect($('[data-testid="export-footer"]').textContent).toMatch(/Generated .* from Family Hub/);
        expect($('[data-testid="print-back"]').getAttribute('href')).toBe(`/health/people/${ID}`);
        expect($('[data-testid="print-back"]').textContent).toBe('Back to person');
        expect($('[data-testid="print-button"]').textContent).toBe('Print export');
        await expectNoAxeViolations(document.body);
    });

    it('labels unverified items and shows the legend', async () => {
        mountPage(ExportPrint, props({
            unverified_notice: 'x',
            allergies: [{ substance: 'Latex', reaction: null, severity: 'unknown', verification: 'unverified' }],
        }));
        expect($('[data-testid="unverified-tag"]').textContent).toContain('Unverified');
        expect($('[data-testid="unverified-legend"]').textContent).toContain('suggested by AI and have not been confirmed');
        await expectNoAxeViolations(document.body);
    });

    it('calls window.print() from the print button', async () => {
        window.print = vi.fn();
        mountPage(ExportPrint, props());
        $('[data-testid="print-button"]').click();
        expect(window.print).toHaveBeenCalledTimes(1);
    });

    it.each(['sk', 'pl'])('is axe clean in %s', async (locale) => {
        mountPage(ExportPrint, props({ unverified_notice: 'x', visits: [{ ...EXPORT.visits[0], verification: 'unverified' }] }), { locale });
        await expectNoAxeViolations(document.body);
        expect($('.print-layout').getAttribute('lang')).toBe(locale === 'sk' ? 'sk-SK' : 'pl-PL');
    });
});

describe('PrintLayout source', () => {
    const layout = readFileSync(`${process.cwd()}/resources/js/Layouts/PrintLayout.vue`, 'utf8');

    it('is bare, forced light, A4, and uses no inline script', () => {
        expect(layout).toContain('@page { size: A4 portrait; margin: 15mm; }');
        expect(layout).toContain('color-scheme: light');
        expect(layout).not.toMatch(/AppShell|ThemeSwitch|UiToastRegion|ShellAdd|v-html|<script[^>]*src/);
        expect(layout).toContain('window.print()');
        mountPage(PrintLayout, { backHref: '/x', backLabel: 'Back', printLabel: 'Print' });
        expect($('.print-layout')).not.toBeNull();
        expect($('nav')).toBeNull();
    });
});
