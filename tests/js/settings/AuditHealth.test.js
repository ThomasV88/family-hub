import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import AuditTable from '../../../resources/js/Components/Settings/AuditTable.vue';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
const SUBJECT = '8f14e45f-ceea-467a-9575-1c5a3d1b2f10';
const base = { occurredAt: '2026-05-12T10:00:00+00:00', actorLabel: 'Anna', group: 'health', module: 'health', targetLabel: null, ip: '203.0.113.7' };
const Events = [
    { id: 1, event: 'medical_subject_created', meta: { subject: SUBJECT } },
    { id: 2, event: 'medical_subject_deleted', meta: { subject: SUBJECT } },
    { id: 3, event: 'medical_access_changed', meta: { subject: SUBJECT, change: 'granted' } },
    { id: 4, event: 'medical_access_changed', meta: { subject: SUBJECT, change: 'self_access_off' } },
    { id: 5, event: 'medical_admin_self_added', meta: { subject: SUBJECT } },
    { id: 6, event: 'medical_exported', meta: { subject: SUBJECT, scope: 'specialty', format: 'json' } },
    { id: 7, event: 'medical_consent_changed', meta: { subject: SUBJECT, value: 'on' } },
].map((e) => ({ ...base, ...e }));

let current;
const mountTable = (entries, locale = 'en') => {
    current = mount(AuditTable, { props: { entries }, attachTo: document.body, global: { plugins: [createAppI18n(locale, load(locale))] } });
    return current;
};
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.removeAttribute('data-theme'); });

describe('Audit table medical rows', () => {
    it('renders a label and the change, scope, format and value phrases', () => {
        mountTable(Events);
        const text = current.text();
        for (const expected of [
            'Added a person to Health', 'Deleted a person from Health', 'Changed access to a person in Health: Access granted',
            'Own-record access turned off', 'Gave themselves access to a person in Health',
            'Exported health records: One specialty: Data file (JSON)', 'Changed the AI setting for a person in Health: AI turned on',
        ]) expect(text).toContain(expected);
    });

    it('never prints the subject uuid or unmapped meta', () => {
        mountTable(Events.map((e) => ({ ...e, meta: { ...e.meta, secret: 'LEAK-1234' } })).concat([
            { ...base, id: 99, event: 'medical_exported', meta: { subject: SUBJECT, scope: 'LEAK-SCOPE', format: 'LEAK-FMT' } },
        ]));
        expect(current.text()).not.toContain(SUBJECT);
        expect(current.text()).not.toContain('LEAK');
    });

    it('uses a neutral tag with an icon and the Health group label', () => {
        mountTable(Events);
        const tag = current.find('tbody tr .ui-tag');
        expect(tag.classes()).toContain('ui-tag--neutral');
        expect(tag.find('svg').exists()).toBe(true);
        expect(tag.text()).toBe('Health data');
    });

    it('translates in SK and PL', () => {
        for (const locale of ['sk', 'pl']) {
            current?.unmount();
            mountTable(Events, locale);
            const d = load(locale).audit.detail;
            expect(current.text()).toContain(d.medical_subject_created);
            expect(current.text()).toContain(d.medical_change_granted);
            expect(current.text()).toContain(d.medical_format_json);
            expect(current.text()).toContain(d.medical_value_on);
        }
    });

    it.each([['en', 'light'], ['en', 'dark'], ['sk', 'light'], ['sk', 'dark'], ['pl', 'light'], ['pl', 'dark']])('has no axe violations in %s %s', async (locale, theme) => {
        document.documentElement.setAttribute('data-theme', theme);
        mountTable(Events, locale);
        await expectNoAxeViolations(current.element);
    });
});
