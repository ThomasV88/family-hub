import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';

const calls = { post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() };
vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn(), post: (...a) => calls.post(...a), put: (...a) => calls.put(...a), patch: (...a) => calls.patch(...a), delete: (...a) => calls.delete(...a), visit: vi.fn(), on: vi.fn() },
}));

import OccasionPanel from '../../../resources/js/Wishlist/OccasionPanel.vue';

const MEMBERS = [
    { id: 2, name: 'Mira', birth_date: '1980-03-15', nameday: null, can_edit_dates: true },
    { id: 1, name: 'Adam', birth_date: null, nameday: null, can_edit_dates: false },
];
const TODAY = '2027-03-01';

let current;
let fetchMock;
function mountPanel(props = {}, { locale = 'en', messages } = {}) {
    current = mount(OccasionPanel, {
        props: { open: true, mode: 'custom', member: null, occasion: null, members: MEMBERS, today: TODAY, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] },
    });
    return current;
}
beforeEach(() => {
    Object.values(calls).forEach((f) => f.mockReset());
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    fetchMock = vi.fn(async () => ({ ok: true, json: async () => ({ results: [{ name: 'Ľubomír', month: 8, day: 13 }, { name: 'Ľuboslav', month: 1, day: 2 }] }) }));
    vi.stubGlobal('fetch', fetchMock);
});
afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    current?.unmount();
    current = null;
    document.body.innerHTML = '';
    delete document.documentElement.dataset.theme;
});

const btn = (text) => [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === text);
const field = (label) => [...document.body.querySelectorAll('label')].find((l) => l.textContent.replace('*', '').trim() === label);
const input = (label) => document.getElementById(field(label).getAttribute('for'));
async function type(el, value) {
    el.value = value;
    el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
    await flushPromises();
}
const heading = () => document.body.querySelector('h2.ui-panel__title').textContent;

describe('custom mode', () => {
    it('creates with the defaults, validates, and posts the payload', async () => {
        mountPanel();
        await vi.advanceTimersByTimeAsync(60);
        expect(heading()).toBe('Add occasion');
        expect(document.body.querySelector('input[type="checkbox"]').checked).toBe(true);
        expect(input('For').value).toBe('');

        btn('Save occasion').click();
        await flushPromises();
        expect(document.body.textContent).toContain('Enter a title for the occasion.');
        expect(document.body.textContent).toContain('Enter a valid date.');
        expect(calls.post).not.toHaveBeenCalled();

        await type(input('Title'), 'Wedding day');
        await type(input('Date'), '2027-06-12');
        await type(input('For'), '1');
        btn('Save occasion').click();
        await flushPromises();
        expect(calls.post).toHaveBeenCalledWith('/wishlist/occasions', { title: 'Wedding day', date: '2027-06-12', yearly: true, owner_id: 1 }, expect.anything());
    });

    it('edits with PATCH, prefilled, and confirms delete with Keep occasion as the cancel label', async () => {
        mountPanel({ occasion: { id: 9, title: 'Trip', month: 8, day: 1, year: 2027, owner: { id: 1, name: 'Adam' }, can_edit: true } });
        await flushPromises();
        expect(heading()).toBe('Edit occasion');
        expect(input('Title').value).toBe('Trip');
        expect(input('Date').value).toBe('2027-08-01');
        expect(document.body.querySelector('input[type="checkbox"]').checked).toBe(false);
        expect(input('For').value).toBe('1');

        await type(input('Title'), 'Trip 2');
        btn('Save occasion').click();
        await flushPromises();
        expect(calls.patch).toHaveBeenCalledWith('/wishlist/occasions/9', expect.objectContaining({ title: 'Trip 2', yearly: false }), expect.anything());

        btn('Delete occasion').click();
        await flushPromises();
        expect(btn('Keep occasion')).toBeDefined();
        expect(document.body.textContent).toContain('Delete "Trip 2"? This cannot be undone.'.replace('Trip 2', 'Trip'));
        expect(calls.delete).not.toHaveBeenCalled();
        [...document.body.querySelectorAll('button')].filter((b) => b.textContent.trim() === 'Delete occasion').pop().click();
        await flushPromises();
        expect(calls.delete).toHaveBeenCalledWith('/wishlist/occasions/9', expect.anything());
    });

    it('uses a Close button, never Cancel, and asks before discarding unsaved changes', async () => {
        const w = mountPanel();
        await flushPromises();
        expect(btn('Cancel')).toBeUndefined();

        btn('Close').click();
        await flushPromises();
        expect(w.emitted('close')).toHaveLength(1);

        await type(input('Title'), 'Draft');
        btn('Close').click();
        await flushPromises();
        expect(w.emitted('close')).toHaveLength(1);
        expect(document.body.textContent).toContain('Discard changes?');
        expect(btn('Keep editing')).toBeDefined();
        expect(btn('Cancel')).toBeUndefined();
        btn('Discard').click();
        await flushPromises();
        expect(w.emitted('close')).toHaveLength(2);
    });
});

describe('dates mode', () => {
    const member = (extra = {}) => ({ ...MEMBERS[0], ...extra });

    it('prefills the birth date, limits it to today and rejects a future date client-side', async () => {
        mountPanel({ mode: 'dates', member: member() });
        await flushPromises();
        expect(heading()).toBe('Edit dates');
        expect(input('Birth date').value).toBe('1980-03-15');
        expect(input('Birth date').getAttribute('max')).toBe(TODAY);
        expect(document.body.textContent).not.toContain('Search a name');

        await type(input('Birth date'), '2999-01-01');
        btn('Save dates').click();
        await flushPromises();
        expect(document.body.textContent).toContain('The birth date cannot be in the future.');
        expect(calls.put).not.toHaveBeenCalled();
    });

    it('searches names debounced, picks one and saves it', async () => {
        mountPanel({ mode: 'dates', member: member() });
        await flushPromises();
        await type(input('Name day calendar'), 'sk');
        await type(input('Search a name'), 'lub');
        expect(fetchMock).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(250);
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/wishlist/namedays?calendar=sk&q=lub');
        expect(init.headers.Accept).toBe('application/json');

        const radios = [...document.body.querySelectorAll('input[type="radio"]')];
        expect(radios).toHaveLength(2);
        expect(document.body.textContent).toContain('Ľubomír, 13 August');

        btn('Save dates').click();
        await flushPromises();
        expect(document.body.textContent).toContain('Choose a name day or set the calendar to None.');
        expect(calls.put).not.toHaveBeenCalled();

        radios[0].click();
        radios[0].dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();
        btn('Save dates').click();
        await flushPromises();
        expect(calls.put).toHaveBeenCalledWith('/wishlist/members/2/dates', {
            birth_date: '1980-03-15',
            nameday: { calendar: 'sk', name: 'Ľubomír', month: 8, day: 13 },
        }, expect.anything());
    });

    it('does not search below two characters, shows no-match, and the Polish attribution', async () => {
        fetchMock.mockImplementation(async () => ({ ok: true, json: async () => ({ results: [] }) }));
        mountPanel({ mode: 'dates', member: member() });
        await flushPromises();
        await type(input('Name day calendar'), 'pl');
        expect(document.body.textContent).toContain('Polish name days: Wikipedia contributors, CC BY-SA 4.0');
        const attribution = document.body.querySelector('[data-testid="pl-attribution"]');
        expect([...attribution.querySelectorAll('a')].map((a) => a.getAttribute('href'))).toEqual([
            'https://pl.wikipedia.org/wiki/Imieniny',
            'https://creativecommons.org/licenses/by-sa/4.0/',
        ]);
        expect(attribution.textContent).toContain('Adapted:');
        await type(input('Search a name'), 'a');
        await vi.advanceTimersByTimeAsync(300);
        expect(fetchMock).not.toHaveBeenCalled();
        await type(input('Search a name'), 'zzz');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();
        expect(document.body.textContent).toContain('No name found.');
    });

    it('clears the name day when the calendar is None', async () => {
        mountPanel({ mode: 'dates', member: member({ nameday: { calendar: 'sk', name: 'Adam', month: 12, day: 24 } }) });
        await flushPromises();
        expect(input('Name day calendar').value).toBe('sk');
        expect([...document.body.querySelectorAll('input[type="radio"]')].map((r) => r.checked)).toEqual([true]);
        await type(input('Name day calendar'), 'none');
        btn('Save dates').click();
        await flushPromises();
        expect(calls.put).toHaveBeenCalledWith('/wishlist/members/2/dates', { birth_date: '1980-03-15', nameday: null }, expect.anything());
    });
});

describe('accessibility', () => {
    beforeEach(() => { vi.useRealTimers(); });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`custom and dates panels have no axe violations (${locale}, ${dark ? 'dark' : 'light'})`, async () => {
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                mountPanel({}, { locale });
                await flushPromises();
                await expectNoAxeViolations(document.body);
                current.unmount();
                document.body.innerHTML = '';
                mountPanel({ mode: 'dates', member: { ...MEMBERS[0], nameday: { calendar: 'pl', name: 'Anna', month: 7, day: 26 } } }, { locale });
                await flushPromises();
                await expectNoAxeViolations(document.body);
            });
        }
    }

    it('renders pseudo-long copy without axe violations', async () => {
        mountPanel({ mode: 'dates', member: { ...MEMBERS[0], nameday: { calendar: 'pl', name: 'Anna', month: 7, day: 26 } } }, { locale: 'pl', messages: pseudoLong(loadMessages('pl')) });
        await flushPromises();
        await expectNoAxeViolations(document.body);
    });
});
