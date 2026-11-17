import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { installHelp } from '../helpers/help.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { resetHelp } from '../../../resources/js/Help/helpStore.js';

const { calls, forms } = vi.hoisted(() => ({ calls: [], forms: [] }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        useForm: (data) => {
            const form = reactive({
                ...data,
                processing: false,
                errors: {},
                put: vi.fn((url, opts) => {
                    const plain = Object.fromEntries(Object.keys(data).map((k) => [k, JSON.parse(JSON.stringify(form[k]))]));
                    calls.push({ url, body: form.transformer ? form.transformer(plain) : plain });
                    opts?.onSuccess?.();
                }),
                transform(cb) { form.transformer = cb; return form; },
                clearErrors() { form.errors = {}; },
            });
            forms.push(form);
            return form;
        },
    };
});

import ListSettingsPanel from '../../../resources/js/Shopping/ListSettingsPanel.vue';

const ME = { id: 2, name: 'Mira Nováková', isChild: false };
const TOM = { id: 3, name: 'Tomáš', isChild: false };
const EVA = { id: 4, name: 'Eva', isChild: false };
const KID = { id: 5, name: 'Samko', isChild: true };
const LIST = {
    id: 5, name: 'Týždenný nákup', status: 'open', store: { id: 4, name: 'Tesco', logoVersion: null }, storeName: 'Tesco',
    creator: ME, responsible: { id: ME.id, name: ME.name }, responsibleFallback: null,
    remindAt: '2026-11-20T18:00:00+01:00', reminderPassed: false, reminderSent: false, lockedAt: null, doneAt: null, source: null,
};
const STORES = [{ id: 4, name: 'Tesco', logoVersion: null }, { id: 6, name: 'Lidl', logoVersion: null }];
const CANDIDATES = { responsible: [ME, TOM, EVA], contributors: [ME, TOM, EVA, KID], viewers: [ME, TOM, EVA, KID] };
const FULL = { editSettings: true, changeResponsible: true, setReminder: true, manageMembers: true };
const LOCKED_RESPONSIBLE = { editSettings: false, changeResponsible: true, setReminder: true, manageMembers: false };
const READONLY = { editSettings: false, changeResponsible: false, setReminder: false, manageMembers: false };

let current;
function mountPanel({ locale = 'en', messages = null, open = true, ...props } = {}) {
    installHelp(locale);
    current = mount(ListSettingsPanel, {
        props: { open, list: LIST, abilities: FULL, members: { contributors: [TOM], viewers: [KID] }, stores: STORES, candidates: CANDIDATES, responsiblePushEnabled: true, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] },
    });
    return current;
}
const body = () => document.body;
const panel = () => body().querySelector('[role="dialog"]');
const form = () => forms[forms.length - 1];
const save = () => body().querySelector('[data-testid="panel-save"]');
const CONTRIB = 'Can add and edit items';
const VIEW = 'Can only view';
const chips = (label) => body().querySelector(`[data-testid="people-list"][aria-label="${label}"]`)?.querySelectorAll('[data-testid="people-chip"]') ?? [];
const selectNamed = (label) => [...body().querySelectorAll('select')].find((s) => s.getAttribute('aria-label') === label);
async function choose(select, value) {
    select.value = String(value);
    select.dispatchEvent(new Event('change'));
    await nextTick();
}
const clickText = (label) => [...body().querySelectorAll('button')].find((b) => b.textContent.trim() === label);

beforeEach(() => { calls.length = 0; forms.length = 0; window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} }); });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; resetHelp(); delete document.documentElement.dataset.theme; });

describe('List settings panel', () => {
    it('lets the creator edit every field, keeps Save disabled until something changes and sends one PUT', async () => {
        mountPanel();
        expect(panel().querySelector('h2').textContent).toBe('List settings');
        expect(save().disabled).toBe(true);
        const name = body().querySelector('input[type="text"]');
        name.value = 'Víkend';
        name.dispatchEvent(new Event('input'));
        await nextTick();
        expect(save().disabled).toBe(false);
        await choose(selectNamed('Add person: Can add and edit items'), EVA.id);
        const reminder = body().querySelector('[data-testid="reminder-input"]');
        expect(reminder.value).toBe('2026-11-20T18:00');
        reminder.value = '2026-11-21T09:30';
        reminder.dispatchEvent(new Event('input'));
        await nextTick();
        save().click();
        await flushPromises();
        expect(calls).toHaveLength(1);
        expect(calls[0].url).toBe('/shopping/lists/5');
        expect(calls[0].body).toEqual({ name: 'Víkend', contributor_ids: [TOM.id, EVA.id], viewer_ids: [KID.id], remind_at: '2026-11-21T09:30' });
    });

    it('sends only the responsible person when only that changed, and removes them from the groups', async () => {
        mountPanel();
        const selects = [...body().querySelectorAll('select')];
        const responsible = selects.find((s) => [...s.options].some((o) => o.textContent === 'Eva') && !s.getAttribute('aria-label') && s.options.length === 3);
        await choose(responsible, TOM.id);
        // Tomáš was a contributor: moving him to responsible drops him from the contributors.
        expect(chips(CONTRIB)).toHaveLength(0);
        save().click();
        await flushPromises();
        expect(calls[0].body.responsible_id).toBe(TOM.id);
        expect(calls[0].body.contributor_ids).toEqual([]);
        expect(calls[0].body.viewer_ids).toEqual([KID.id]);
    });

    it('moves a person between the groups with a visible hint', async () => {
        mountPanel();
        await choose(selectNamed('Add person: Can only view'), TOM.id);
        expect(body().querySelector('[data-testid="panel-moved"]').textContent).toBe('Tomáš moved to viewers.');
        expect([...chips(VIEW)].map((c) => c.textContent)).toEqual(expect.arrayContaining([expect.stringContaining('Tomáš'), expect.stringContaining('Samko')]));
        expect(chips(CONTRIB)).toHaveLength(0);
        await choose(selectNamed('Add person: Can add and edit items'), KID.id);
        expect(body().querySelector('[data-testid="panel-moved"]').textContent).toContain('Samko moved to people who can add and edit items');
        save().click();
        await flushPromises();
        expect(calls[0].body.contributor_ids).toEqual([KID.id]);
        expect(calls[0].body.viewer_ids).toEqual([TOM.id]);
    });

    it('labels children with the Child tag, offers neither the creator nor the responsible person, and removes a chip', async () => {
        mountPanel();
        expect(chips(VIEW)[0].textContent).toContain('Child');
        const options = [...selectNamed('Add person: Can only view').options].map((o) => o.textContent);
        expect(options).not.toContain('Mira Nováková');
        expect(options.some((o) => o.startsWith('Samko'))).toBe(false); // already a viewer
        expect(options).toEqual(['Add person', 'Tomáš', 'Eva']);
        body().querySelector('button[aria-label="Remove Samko"]').click();
        await nextTick();
        expect(chips(VIEW)).toHaveLength(0);
    });

    it('lets only the responsible person change the responsible person and the reminder on a locked list', async () => {
        mountPanel({ list: { ...LIST, status: 'locked' }, abilities: LOCKED_RESPONSIBLE });
        expect(body().querySelector('input[type="text"]').readOnly).toBe(true);
        expect(body().querySelector('button[aria-label="Remove Samko"]')).toBeNull();
        expect(selectNamed('Add person: Can only view')).toBeUndefined();
        expect(body().querySelector('[data-testid="reminder-input"]').readOnly).toBe(false);
        const reminder = body().querySelector('[data-testid="reminder-input"]');
        reminder.value = '2026-11-22T10:00';
        reminder.dispatchEvent(new Event('input'));
        await nextTick();
        save().click();
        await flushPromises();
        expect(calls[0].body).toEqual({ remind_at: '2026-11-22T10:00' });
    });

    it('shows a contributor or viewer everything read-only with no Save, people still visible', () => {
        mountPanel({ abilities: READONLY, candidates: null, responsiblePushEnabled: null });
        expect(save()).toBeNull();
        expect(body().querySelector('[data-testid="panel-readonly"]')).not.toBeNull();
        expect(body().querySelector('input[type="text"]').readOnly).toBe(true);
        expect(body().querySelector('[data-testid="reminder-input"]').readOnly).toBe(true);
        expect(body().querySelector('[data-testid="reminder-remove"]')).toBeNull();
        expect(chips(CONTRIB)).toHaveLength(1);
        expect(chips(VIEW)).toHaveLength(1);
        expect(chips(CONTRIB)[0].textContent).toContain('Tomáš');
        expect(body().querySelectorAll('select')[1].disabled).toBe(true);
    });

    it('removes the reminder by sending null', async () => {
        mountPanel();
        body().querySelector('[data-testid="reminder-remove"]').click();
        await nextTick();
        expect(body().querySelector('[data-testid="reminder-input"]').value).toBe('');
        save().click();
        await flushPromises();
        expect(calls[0].body).toEqual({ remind_at: null });
    });

    it('shows the server error for a past reminder as a field error', async () => {
        mountPanel();
        form().errors = { remind_at: 'shopping.error.reminder_past' };
        await nextTick();
        expect(body().textContent).toContain('Choose a time in the future.');
        expect(body().querySelector('[data-testid="panel-failed"]')).not.toBeNull();
    });

    it('warns about missing push only when the server says the responsible person has none', async () => {
        mountPanel({ responsiblePushEnabled: false });
        expect(body().querySelector('[data-testid="reminder-push-off"]').textContent).toContain('Mira Nováková has no push notifications turned on');
        current.unmount();
        document.body.innerHTML = '';
        mountPanel({ responsiblePushEnabled: true });
        expect(body().querySelector('[data-testid="reminder-push-off"]')).toBeNull();
        current.unmount();
        document.body.innerHTML = '';
        mountPanel({ responsiblePushEnabled: null });
        expect(body().querySelector('[data-testid="reminder-push-off"]')).toBeNull();
    });

    it('explains the roles and the reminder with help tips', () => {
        mountPanel();
        expect(body().querySelectorAll('.help-tip__button').length).toBe(2);
    });

    it('renders names as text, not markup', () => {
        mountPanel({ members: { contributors: [{ id: 9, name: '<img src=x onerror=alert(1)>', isChild: false }], viewers: [] }, candidates: { ...CANDIDATES, contributors: [] } });
        expect(body().querySelector('[data-testid="people-chip"] img')).toBeNull();
        expect(chips(CONTRIB)[0].textContent).toContain('<img src=x onerror=alert(1)>');
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations (%s, light and dark, long text, editable and read-only)', async (locale) => {
        for (const theme of ['light', 'dark']) {
            document.documentElement.dataset.theme = theme;
            const messages = JSON.parse(JSON.stringify(loadMessages(locale)));
            for (const key of ['title', 'name', 'store', 'responsible', 'contributors', 'viewers', 'add_person', 'save']) messages.shopping.panel[key] = pseudoLong(messages.shopping.panel[key]);
            mountPanel({ locale, messages, responsiblePushEnabled: false });
            await expectNoAxeViolations(document.body);
            current.unmount();
            document.body.innerHTML = '';
            mountPanel({ locale, abilities: READONLY, candidates: null, responsiblePushEnabled: null });
            await expectNoAxeViolations(document.body);
            current.unmount();
            document.body.innerHTML = '';
        }
    });
});
