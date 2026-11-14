import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick, reactive } from 'vue';
import { readFileSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

let mockProps = {};
const { fetchMock, refusal, router } = vi.hoisted(() => ({
    fetchMock: vi.fn(),
    refusal: vi.fn(),
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn() },
}));
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: fetchMock }));
vi.mock('../../../resources/js/Health/healthRefusal.js', () => ({ handleHealthRefusal: refusal }));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps, get url() { return '/health'; } }),
    router,
    Head: { props: ['title'], setup() { return () => null; } },
    Link: { props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) },
}));

import AiReview from '../../../resources/js/Pages/Health/AiReview.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const PID = '11111111-1111-4111-8111-111111111111';
const DOC_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
const EX = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeee1';

const visit = { visited_on: '2025-05-02', doctor: 'Dr Kova', specialty_key: 'cardiology', diagnosis: 'Mild murmur', notes: null };
const med = { name: 'Ibuprofen', dose: '200 mg', schedule: null, started_on: null, ended_on: null, notes: null };
const allergy = { substance: 'Penicillin', reaction: 'Rash', severity: 'unknown' };
const vaccine = { vaccine: 'MMR', given_on: '2024-01-10', next_due_on: null, notes: null };

const item = (key, fields, over = {}) => ({ key, fields, state: 'pending', recordId: null, edited: false, ...over });
const extraction = (over = {}) => ({
    id: EX, status: 'ready', runAt: '2026-11-14T09:30:00+00:00', pagesRead: null, pagesTotal: null, documentDate: null, specialtyKey: null,
    readOnly: false, pending: 4,
    sections: {
        visits: [item('visit-0', visit)],
        medications: [item('medication-0', med)],
        allergies: [item('allergy-0', allergy)],
        vaccinations: [item('vaccination-0', vaccine)],
    },
    ...over,
});
const baseProps = (over = {}) => ({
    person: { id: PID },
    document: { id: DOC_ID, kind: 'image', title: 'Discharge letter', pageCount: null },
    extraction: extraction(),
    newestExtractionId: null,
    consentOn: true,
    specialties: ['cardiology', 'other'],
    pane: null,
    ...over,
});

const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => [...document.querySelectorAll(sel)];
const flush = async () => { for (let i = 0; i < 5; i += 1) { await Promise.resolve(); await nextTick(); } };
const tick = async () => { await new Promise((r) => setTimeout(r, 5)); await flush(); };
const entryEl = (key) => $(`[data-entry-key="${key}"]`);
const action = (key, name) => entryEl(key).querySelector(`[data-action="${name}"]`);
const formOf = (key) => entryEl(key).querySelector('form');
const setValue = async (el, value) => { el.value = value; el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true })); await flush(); };
const labels = (key) => [...formOf(key).querySelectorAll('label')].map((l) => l.textContent.replace(/\s+/g, ' ').replace(' *', '').trim());

let wrapper;
function mountPage(over = {}, { locale = 'en', width = 1280 } = {}) {
    window.innerWidth = width;
    mockProps = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: 'none', health: 'edit' } },
        app: { env: 'local', styleGuide: false },
    });
    wrapper = mount(AiReview, { props: baseProps(over), attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return wrapper;
}
beforeEach(() => {
    fetchMock.mockReset();
    refusal.mockReset();
    useToast().toasts.splice(0);
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
});
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    document.documentElement.classList.remove('dark');
    vi.clearAllMocks();
    window.history.replaceState(null, '', '/');
});

const edit = async (key) => { action(key, 'edit').click(); await flush(); };
const invalid = (errors) => ({ ok: false, status: 422, error: 'invalid', errors, data: {} });

describe('edit form fields', () => {
    it('shows the Phase 7 visit fields prefilled with the first field focused and no Accept or Reject while open', async () => {
        mountPage();
        await edit('visit-0');
        expect(labels('visit-0')).toEqual(['Date (required)', 'Doctor', 'Specialty (required)', 'Diagnosis', 'Notes']);
        const inputs = formOf('visit-0').querySelectorAll('input, select, textarea');
        expect(inputs[0].value).toBe('2025-05-02');
        expect(inputs[1].value).toBe('Dr Kova');
        expect(inputs[2].value).toBe('cardiology');
        expect(inputs[3].value).toBe('Mild murmur');
        expect(document.activeElement).toBe(inputs[0]);
        expect(action('visit-0', 'accept')).toBeNull();
        expect(action('visit-0', 'reject')).toBeNull();
        expect(formOf('visit-0').textContent).not.toContain('Documents');
        expect(entryEl('visit-0').textContent).toContain('Add visit');
        expect(entryEl('visit-0').textContent).toContain('Cancel edit');
    });

    it('shows the other-specialty field only when the specialty is other', async () => {
        mountPage();
        await edit('visit-0');
        expect(labels('visit-0').some((l) => l.startsWith('Specialty name'))).toBe(false);
        await setValue(formOf('visit-0').querySelector('select'), 'other');
        expect(labels('visit-0').some((l) => l.startsWith('Specialty name'))).toBe(true);
        await setValue(formOf('visit-0').querySelector('select'), 'cardiology');
        expect(formOf('visit-0').querySelectorAll('input[type="text"], input:not([type])')).toHaveLength(2);
    });

    it('shows the medication, allergy and vaccination field sets prefilled', async () => {
        mountPage();
        await edit('medication-0');
        let inputs = formOf('medication-0').querySelectorAll('input, textarea');
        expect([...inputs].map((i) => i.value)).toEqual(['Ibuprofen', '200 mg', '', '', '', '']);
        expect(formOf('medication-0').querySelectorAll('label')).toHaveLength(6);
        await edit('allergy-0');
        expect(formOf('medication-0')).toBeNull();
        inputs = formOf('allergy-0').querySelectorAll('input, select');
        expect([...inputs].map((i) => i.value)).toEqual(['Penicillin', 'Rash', '']);
        expect(formOf('allergy-0').querySelector('select option[value=""]').textContent).toBeTruthy();
        await edit('vaccination-0');
        inputs = formOf('vaccination-0').querySelectorAll('input, textarea');
        expect([...inputs].map((i) => i.value)).toEqual(['MMR', '2024-01-10', '', '']);
    });

    it('renders literal HTML in the proposal as text inside the inputs', async () => {
        const sections = { visits: [], medications: [item('medication-0', { ...med, name: '<img src=x onerror=alert(1)>' })], allergies: [], vaccinations: [] };
        mountPage({ extraction: extraction({ sections }) });
        await edit('medication-0');
        expect(formOf('medication-0').querySelector('input').value).toBe('<img src=x onerror=alert(1)>');
        expect(formOf('medication-0').querySelector('img')).toBeNull();
    });
});

describe('saving an edit', () => {
    it('posts {values} to accept, shows Added to the record with the edited line and focuses the next pending Accept', async () => {
        mountPage();
        await edit('medication-0');
        await setValue(formOf('medication-0').querySelectorAll('input')[1], '400 mg');
        fetchMock.mockResolvedValueOnce({ ok: true, status: 200, data: { state: 'accepted', recordType: 'medication', recordId: 'rec-1', edited: true, pending: 3 } });
        entryEl('medication-0').querySelector('[data-testid="edit-save"]').click();
        await flush();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe(`/health/people/${PID}/ai/${EX}/entries/medication-0/accept`);
        expect(init.method).toBe('POST');
        expect(init.body).toEqual({ values: { name: 'Ibuprofen', dose: '400 mg', schedule: null, started_on: null, ended_on: null, notes: null } });
        expect(formOf('medication-0')).toBeNull();
        expect(entryEl('medication-0').querySelector('[data-testid="entry-tag"]').textContent).toBe('Added to the record');
        expect(entryEl('medication-0').querySelector('[data-testid="entry-edited"]').textContent).toBe('You edited this suggestion.');
        expect(useToast().toasts.some((x) => String(x.message ?? x.text ?? '').includes('Medication added to the record'))).toBe(true);
        expect(document.activeElement).toBe(action('allergy-0', 'accept'));
    });

    it('sends the visit with specialty_other only for other and null for empty fields', async () => {
        mountPage();
        await edit('visit-0');
        fetchMock.mockResolvedValueOnce({ ok: true, status: 200, data: { state: 'accepted', recordId: 'r', edited: true } });
        entryEl('visit-0').querySelector('[data-testid="edit-save"]').click();
        await flush();
        expect(fetchMock.mock.calls[0][1].body.values).toEqual({ visited_on: '2025-05-02', doctor: 'Dr Kova', specialty_key: 'cardiology', specialty_other: null, diagnosis: 'Mild murmur', notes: null });
    });

    it('keeps the form with its values and shows action_failed on a network error', async () => {
        mountPage();
        await edit('medication-0');
        await setValue(formOf('medication-0').querySelectorAll('input')[1], '1 g');
        fetchMock.mockResolvedValueOnce({ ok: false, status: 0, error: 'network', data: {} });
        entryEl('medication-0').querySelector('[data-testid="edit-save"]').click();
        await flush();
        expect(entryEl('medication-0').querySelector('[data-testid="entry-failed"]').textContent).toContain('We could not save this');
        expect(formOf('medication-0').querySelectorAll('input')[1].value).toBe('1 g');
    });

    it('shows the superseded banner and closes the form on 409', async () => {
        mountPage();
        await edit('medication-0');
        fetchMock.mockResolvedValueOnce({ ok: false, status: 409, error: 'failed', data: { state: 'superseded' } });
        entryEl('medication-0').querySelector('[data-testid="edit-save"]').click();
        await flush();
        expect($('[data-testid="superseded-banner"]')).not.toBeNull();
        expect(formOf('medication-0')).toBeNull();
    });

    it('hands a 423 to handleHealthRefusal, discards the draft and replays nothing', async () => {
        mountPage();
        await edit('medication-0');
        await setValue(formOf('medication-0').querySelectorAll('input')[1], 'secret dose');
        fetchMock.mockResolvedValueOnce({ ok: false, status: 423, error: 'reauth_required', data: { error: 'reauth_required' } });
        entryEl('medication-0').querySelector('[data-testid="edit-save"]').click();
        await flush();
        expect(refusal).toHaveBeenCalledWith('reauth_required');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(formOf('medication-0')).toBeNull();
        expect(document.body.textContent).not.toContain('secret dose');
        await edit('medication-0');
        expect(formOf('medication-0').querySelectorAll('input')[1].value).toBe('200 mg');
    });

    it('flags a server 422 inside an open form and focuses the field', async () => {
        mountPage();
        await edit('vaccination-0');
        fetchMock.mockResolvedValueOnce(invalid({ given_on: ['health.vaccination.err_given_on'] }));
        entryEl('vaccination-0').querySelector('[data-testid="edit-save"]').click();
        await flush();
        await tick();
        const flagged = formOf('vaccination-0').querySelector('[aria-invalid="true"]');
        expect(flagged).not.toBeNull();
        expect(document.activeElement).toBe(flagged);
        expect(flagged.getAttribute('aria-describedby')).toBeTruthy();
    });
});

describe('Accept failing validation opens the form flagged', () => {
    it('allergy without severity: severity flagged "Choose a severity to add this allergy." and focused, selectable and savable', async () => {
        mountPage();
        fetchMock.mockResolvedValueOnce(invalid({ severity: ['health.allergy.err_severity'] }));
        action('allergy-0', 'accept').click();
        await flush();
        await tick();
        const select = formOf('allergy-0').querySelector('select');
        expect(select.getAttribute('aria-invalid')).toBe('true');
        expect(document.getElementById(select.getAttribute('aria-describedby')).textContent).toContain('Choose a severity to add this allergy.');
        expect(document.activeElement).toBe(select);
        await setValue(select, 'severe');
        fetchMock.mockResolvedValueOnce({ ok: true, status: 200, data: { state: 'accepted', recordId: 'a1', edited: true } });
        entryEl('allergy-0').querySelector('[data-testid="edit-save"]').click();
        await flush();
        expect(fetchMock.mock.calls[1][1].body).toEqual({ values: { substance: 'Penicillin', reaction: 'Rash', severity: 'severe' } });
        expect(entryEl('allergy-0').querySelector('[data-testid="entry-tag"]').textContent).toBe('Added to the record');
        expect(entryEl('allergy-0').querySelector('[data-testid="entry-edited"]')).not.toBeNull();
    });

    it('vaccination without a date: the date field is flagged', async () => {
        const sections = { visits: [], medications: [], allergies: [], vaccinations: [item('vaccination-0', { vaccine: 'MMR', given_on: null, next_due_on: null, notes: null })] };
        mountPage({ extraction: extraction({ sections }) });
        fetchMock.mockResolvedValueOnce(invalid({ given_on: ['health.vaccination.err_given_on'] }));
        action('vaccination-0', 'accept').click();
        await flush();
        await tick();
        const flagged = formOf('vaccination-0').querySelector('[aria-invalid="true"]');
        expect(flagged.getAttribute('type')).toBe('date');
        expect(document.activeElement).toBe(flagged);
    });

    it('also flags a values.-prefixed key', async () => {
        mountPage();
        fetchMock.mockResolvedValueOnce(invalid({ 'values.severity': ['health.allergy.err_severity'] }));
        action('allergy-0', 'accept').click();
        await flush();
        expect(formOf('allergy-0').querySelector('select').getAttribute('aria-invalid')).toBe('true');
    });
});

describe('keyboard, cancel and discard', () => {
    it('does not submit on Enter in an input', async () => {
        mountPage();
        await edit('medication-0');
        const input = formOf('medication-0').querySelector('input');
        const event = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
        input.dispatchEvent(event);
        await flush();
        expect(event.defaultPrevented).toBe(true);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('collapses a clean form with Esc and focuses Edit', async () => {
        mountPage();
        await edit('medication-0');
        formOf('medication-0').querySelector('input').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flush();
        expect(formOf('medication-0')).toBeNull();
        expect(document.activeElement).toBe(action('medication-0', 'edit'));
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('collapses a clean form with Cancel edit and focuses Edit', async () => {
        mountPage();
        await edit('medication-0');
        entryEl('medication-0').querySelector('[data-testid="edit-cancel"]').click();
        await flush();
        expect(formOf('medication-0')).toBeNull();
        expect(document.activeElement).toBe(action('medication-0', 'edit'));
    });

    it('asks to discard a dirty form with default focus on Keep editing, and Discard edit collapses with no request', async () => {
        mountPage();
        await edit('medication-0');
        await setValue(formOf('medication-0').querySelector('input'), 'Changed');
        entryEl('medication-0').querySelector('[data-testid="edit-cancel"]').click();
        await flush();
        const dialog = $('[role="dialog"], [role="alertdialog"]');
        expect(dialog.textContent).toContain('Discard your edit?');
        const buttons = [...dialog.querySelectorAll('button')].map((b) => b.textContent.trim()).filter(Boolean);
        expect(buttons).toEqual(['Keep editing', 'Discard edit']);
        expect(document.activeElement.textContent.trim()).toBe('Keep editing');
        [...dialog.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Discard edit').click();
        await tick();
        expect(formOf('medication-0')).toBeNull();
        expect(document.activeElement).toBe(action('medication-0', 'edit'));
        expect(fetchMock).not.toHaveBeenCalled();
        await edit('medication-0');
        expect(formOf('medication-0').querySelector('input').value).toBe('Ibuprofen');
    });

    it('keeps the form and its values when Keep editing is chosen, with focus back in the form', async () => {
        mountPage();
        await edit('medication-0');
        await setValue(formOf('medication-0').querySelector('input'), 'Changed');
        formOf('medication-0').querySelector('input').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flush();
        const keep = [...$('[role="dialog"], [role="alertdialog"]').querySelectorAll('button')].find((b) => b.textContent.trim() === 'Keep editing');
        keep.click();
        await tick();
        expect(formOf('medication-0').querySelector('input').value).toBe('Changed');
        expect(formOf('medication-0').contains(document.activeElement)).toBe(true);
    });

    it('treats a value changed back to the original as clean', async () => {
        mountPage();
        await edit('medication-0');
        const input = formOf('medication-0').querySelector('input');
        await setValue(input, 'X');
        await setValue(input, 'Ibuprofen');
        entryEl('medication-0').querySelector('[data-testid="edit-cancel"]').click();
        await flush();
        expect($('[role="dialog"], [role="alertdialog"]')?.textContent ?? '').not.toContain('Discard your edit?');
        expect(formOf('medication-0')).toBeNull();
    });
});

describe('one edit at a time', () => {
    it('switches silently from a clean entry to another', async () => {
        mountPage();
        await edit('medication-0');
        await edit('allergy-0');
        expect(formOf('medication-0')).toBeNull();
        expect(formOf('allergy-0')).not.toBeNull();
        expect($$('form[data-testid^="edit-form"]')).toHaveLength(1);
        expect($('[role="dialog"]')).toBeNull();
    });

    it('asks first when the open entry is dirty, and Keep editing returns focus into it', async () => {
        mountPage();
        await edit('medication-0');
        await setValue(formOf('medication-0').querySelector('input'), 'Changed');
        action('allergy-0', 'edit').click();
        await flush();
        const dialog = $('[role="dialog"], [role="alertdialog"]');
        expect(dialog.textContent).toContain('Discard your edit?');
        expect(formOf('allergy-0')).toBeNull();
        [...dialog.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Keep editing').click();
        await tick();
        expect(formOf('medication-0').querySelector('input').value).toBe('Changed');
        expect(formOf('medication-0').contains(document.activeElement)).toBe(true);
        expect(formOf('allergy-0')).toBeNull();
    });

    it('discards the dirty draft and opens the other entry on Discard edit', async () => {
        mountPage();
        await edit('medication-0');
        await setValue(formOf('medication-0').querySelector('input'), 'Changed');
        action('allergy-0', 'edit').click();
        await flush();
        [...$('[role="dialog"], [role="alertdialog"]').querySelectorAll('button')].find((b) => b.textContent.trim() === 'Discard edit').click();
        await tick();
        expect(formOf('medication-0')).toBeNull();
        expect(formOf('allergy-0')).not.toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('keeps the open form, its values and focus when the layout crosses the 800 px threshold and when panes toggle', async () => {
        mountPage({}, { width: 600 });
        await edit('medication-0');
        const input = formOf('medication-0').querySelector('input');
        await setValue(input, 'Changed');
        input.focus();
        $('[data-testid="pane-toggle"] [data-value="document"]').click();
        await flush();
        $('[data-testid="pane-toggle"] [data-value="entries"]').click();
        await flush();
        expect(formOf('medication-0')).not.toBeNull();
        expect(formOf('medication-0').querySelector('input')).toBe(input);
        expect(input.value).toBe('Changed');
    });
});

describe('nothing is stored in the browser', () => {
    it('writes no draft to localStorage, sessionStorage or IndexedDB', async () => {
        const spies = [vi.spyOn(Storage.prototype, 'setItem')];
        mountPage();
        await edit('medication-0');
        await setValue(formOf('medication-0').querySelector('input'), 'Draft text');
        expect(spies[0]).not.toHaveBeenCalled();
        expect(window.localStorage.length).toBe(0);
        expect(window.sessionStorage.length).toBe(0);
        spies[0].mockRestore();
    });

    it('uses no storage API and no v-html in the edit files', () => {
        for (const f of ['Health/Ai/EntryEditForm.vue', 'Health/Ai/SuggestionEntry.vue', 'Pages/Health/AiReview.vue']) {
            const src = readFileSync(`${process.cwd()}/resources/js/${f}`, 'utf8');
            expect(src, f).not.toMatch(/localStorage|sessionStorage|indexedDB|v-html/);
        }
    });
});

describe('accessibility', () => {
    const types = ['visit-0', 'medication-0', 'allergy-0', 'vaccination-0'];
    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`is axe clean with each type open, with and without errors, in ${locale} ${dark ? 'dark' : 'light'}`, async () => {
                document.documentElement.classList.toggle('dark', dark);
                mountPage({}, { locale });
                for (const key of types) {
                    await edit(key);
                    await expectNoAxeViolations(document.body);
                }
                fetchMock.mockResolvedValueOnce(invalid({ vaccine: ['health.vaccination.err_vaccine'], given_on: ['health.vaccination.err_given_on'] }));
                entryEl('vaccination-0').querySelector('[data-testid="edit-save"]').click();
                await flush();
                await expectNoAxeViolations(document.body);
                await edit('allergy-0');
                fetchMock.mockResolvedValueOnce(invalid({ severity: ['health.allergy.err_severity'] }));
                entryEl('allergy-0').querySelector('[data-testid="edit-save"]').click();
                await flush();
                await expectNoAxeViolations(document.body);
            });
        }
    }

    it('is axe clean at 320 px stacked with +40% pseudo-long values and an open dirty-confirm', async () => {
        const long = (s) => `${s} ${s.slice(0, Math.ceil(s.length * 0.4))}`;
        const sections = { visits: [item('visit-0', { ...visit, doctor: long('Dr Kovalčíková-Nowakowska') })], medications: [item('medication-0', { ...med, name: long('Ibuprofen retard forte') })], allergies: [], vaccinations: [] };
        mountPage({ extraction: extraction({ sections }) }, { width: 320 });
        await edit('medication-0');
        await setValue(formOf('medication-0').querySelector('input'), 'x');
        await expectNoAxeViolations(document.body);
        action('visit-0', 'edit').click();
        await flush();
        await expectNoAxeViolations(document.body);
    });
});
