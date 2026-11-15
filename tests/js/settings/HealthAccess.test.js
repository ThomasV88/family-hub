import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

const put = vi.fn();
vi.mock('@inertiajs/vue3', () => ({ router: { put: (...a) => put(...a) } }));

import HealthAccessCard from '../../../resources/js/Components/Settings/HealthAccessCard.vue';
import PersonAccessPanel from '../../../resources/js/Components/Settings/PersonAccessPanel.vue';

const MEMBERS = [
    { id: 1, name: 'Anna', access: { health: 'edit' } },
    { id: 2, name: 'Bob', access: { health: 'view' } },
    { id: 3, name: 'Cyril', access: { health: 'none' } },
];
const PERSON = { id: 'p1', name: 'Eva', hasAccount: false, linkedUserId: null, selfAccessEnabled: true, members: { 1: 'edit', 2: 'view' } };

let current;
function mountIt(component, props, locale = 'en') {
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(component, { props, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return current;
}
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; put.mockReset(); });

describe('HealthAccessCard', () => {
    it('lists names with view and change counts and the No account tag, axe clean in all locales', async () => {
        for (const locale of ['en', 'sk', 'pl']) {
            mountIt(HealthAccessCard, { people: [PERSON] }, locale);
            if (locale === 'en') {
                expect(current.text()).toContain('Eva');
                expect(current.text()).toContain('1 member can view');
                expect(current.text()).toContain('1 member can change');
                expect(current.text()).toContain('No account');
            }
            await expectNoAxeViolations(current.element);
            current.unmount();
        }
    });

    it('shows the empty state and emits manage', async () => {
        mountIt(HealthAccessCard, { people: [] });
        expect(current.text()).toContain('No people added yet');
        current.unmount();
        mountIt(HealthAccessCard, { people: [PERSON] });
        await current.find('button').trigger('click');
        expect(current.emitted('manage')[0]).toEqual(['p1']);
        await expectNoAxeViolations(current.element);
    });
});

describe('PersonAccessPanel', () => {
    it('disables members without the Health module and shows the hint', () => {
        mountIt(PersonAccessPanel, { person: PERSON, members: MEMBERS });
        const radios = (name) => [...document.querySelectorAll(`input[name="level-${name}"]`)];
        expect(radios(3).every((r) => r.disabled)).toBe(true);
        expect(radios(1).some((r) => r.disabled)).toBe(false);
        expect(document.body.textContent).toContain('Needs Health access in their permissions first.');
        expect(document.body.querySelector('[role="switch"]')).toBeNull();
    });

    it('saves only changed levels, then the link, in that order', async () => {
        mountIt(PersonAccessPanel, { person: PERSON, members: MEMBERS });
        await document.querySelector('input[name="level-2"][value="edit"]').dispatchEvent(new Event('change'));
        const select = document.querySelector('select');
        select.value = '1';
        select.dispatchEvent(new Event('change'));
        await current.vm.$nextTick();
        expect(document.body.querySelector('[role="switch"]')).not.toBeNull();

        const form = document.querySelector('form');
        form.dispatchEvent(new Event('submit'));
        await current.vm.$nextTick();
        expect(put).toHaveBeenCalledTimes(1);
        expect(put.mock.calls[0][0]).toBe('/settings/health-people/p1/members');
        expect(put.mock.calls[0][1]).toEqual({ levels: { 2: 'edit' } });

        put.mock.calls[0][2].onSuccess();
        expect(put).toHaveBeenCalledTimes(2);
        expect(put.mock.calls[1][0]).toBe('/settings/health-people/p1/link');
        expect(put.mock.calls[1][1]).toEqual({ user_id: 1, self_access_enabled: true });
    });

    it('shows the failure alert and the neutral link error without marking members', async () => {
        mountIt(PersonAccessPanel, { person: PERSON, members: MEMBERS });
        const select = document.querySelector('select');
        select.value = '2';
        select.dispatchEvent(new Event('change'));
        await current.vm.$nextTick();
        document.querySelector('form').dispatchEvent(new Event('submit'));
        await current.vm.$nextTick();
        put.mock.calls[0][2].onError({ user_id: 'health.access.link_failed' });
        await current.vm.$nextTick();
        expect(document.body.textContent).toContain('We could not save access. Nothing changed. Try again.');
        expect(document.body.textContent).toContain("This member can't be linked. Choose another.");
        expect([...select.options].map((o) => o.textContent.trim())).toEqual(['No account', 'Anna', 'Bob', 'Cyril']);
        await expectNoAxeViolations(document.body);
    });

    it('is axe clean when linked with the own-record switch', async () => {
        mountIt(PersonAccessPanel, { person: { ...PERSON, hasAccount: true, linkedUserId: 1 }, members: MEMBERS });
        expect(document.body.textContent).toContain('Own record');
        expect(document.body.querySelector('[role="switch"]')).not.toBeNull();
        await expectNoAxeViolations(document.body);
    });
});
