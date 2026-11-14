import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import AuditTable from '../../../resources/js/Components/Settings/AuditTable.vue';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
const SUBJECT = '8f14e45f-ceea-467a-9575-1c5a3d1b2f10';
const DOCUMENT = '3b9d7c1e-5a42-4f7e-8e0a-6d2f4c9a1b77';
const base = { occurredAt: '2026-05-12T10:00:00+00:00', actorLabel: 'Anna', group: 'health', module: 'health', targetLabel: null, ip: '203.0.113.7' };
const Events = [
    { id: 1, event: 'medical_subject_created', meta: { subject: SUBJECT } },
    { id: 2, event: 'medical_subject_deleted', meta: { subject: SUBJECT } },
    { id: 3, event: 'medical_access_changed', meta: { subject: SUBJECT, change: 'granted' } },
    { id: 4, event: 'medical_access_changed', meta: { subject: SUBJECT, change: 'self_access_off' } },
    { id: 5, event: 'medical_admin_self_added', meta: { subject: SUBJECT } },
    { id: 6, event: 'medical_exported', meta: { subject: SUBJECT, scope: 'specialty', format: 'json' } },
    { id: 7, event: 'medical_consent_changed', meta: { subject: SUBJECT, value: 'on' } },
    { id: 8, event: 'medical_ai_run_requested', meta: { subject: SUBJECT, document: DOCUMENT } },
    { id: 9, event: 'medical_ai_run_finished', meta: { subject: SUBJECT, document: DOCUMENT, status: 'succeeded' } },
    { id: 10, event: 'medical_ai_entry_decided', meta: { subject: SUBJECT, document: DOCUMENT, decision: 'accepted' } },
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
            'Started an AI run on a document in Health', 'An AI run on a document in Health finished', 'Decided on an AI suggestion in Health',
        ]) expect(text).toContain(expected);
    });

    it('never prints the subject uuid or unmapped meta', () => {
        mountTable(Events.map((e) => ({ ...e, meta: { ...e.meta, secret: 'LEAK-1234' } })).concat([
            { ...base, id: 99, event: 'medical_exported', meta: { subject: SUBJECT, scope: 'LEAK-SCOPE', format: 'LEAK-FMT' } },
        ]));
        expect(current.text()).not.toContain(SUBJECT);
        expect(current.text()).not.toContain(DOCUMENT);
        expect(current.text()).not.toContain('succeeded');
        expect(current.text()).not.toContain('accepted');
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
            expect(current.text()).toContain(d.medical_ai_run_requested);
            expect(current.text()).toContain(d.medical_ai_entry_decided);
        }
    });

    it.each([['en', 'light'], ['en', 'dark'], ['sk', 'light'], ['sk', 'dark'], ['pl', 'light'], ['pl', 'dark']])('has no axe violations in %s %s', async (locale, theme) => {
        document.documentElement.setAttribute('data-theme', theme);
        mountTable(Events, locale);
        await expectNoAxeViolations(current.element);
    });
});

describe('Audit table AI agent and bulk rows', () => {
    const ai = [
        { id: 20, event: 'ai_agent_setting_changed', meta: { agent: 'codex', field: 'enabled', value: 'off' } },
        { id: 21, event: 'ai_agent_setting_changed', meta: { agent: 'copilot', field: 'position', value: 1 } },
        { id: 22, event: 'ai_agent_setting_changed', meta: { agent: 'codex', field: 'model', value: '<b>gpt-5</b>' } },
        { id: 23, event: 'ai_bulk_run_changed', meta: { action: 'dry_run', scope: 'no_proposal', count: 12 } },
        { id: 24, event: 'ai_bulk_run_changed', meta: { action: 'started', scope: 'last_failed', count: 7 } },
        { id: 25, event: 'ai_bulk_run_changed', meta: { action: 'paused', scope: 'all', count: 7 } },
        { id: 26, event: 'ai_bulk_run_changed', meta: { action: 'resumed', scope: 'all', count: 7 } },
        { id: 27, event: 'ai_bulk_run_changed', meta: { action: 'cancelled', scope: 'all', count: 3 } },
        { id: 28, event: 'ai_bulk_run_changed', meta: { action: 'completed', scope: 'all', count: 7 } },
    ].map((e) => ({ ...base, ...e, targetLabel: null }));

    it('renders agent display names, field labels and values without a person', () => {
        mountTable(ai);
        const text = current.text();
        for (const expected of [
            'Changed an AI agent setting: OpenAI Codex, Use: Off',
            'Changed an AI agent setting: GitHub Copilot, Position: 1',
            'Checked a bulk AI re-generation: Documents without any suggestions yet. Documents: 12',
            'Started a bulk AI re-generation: Documents whose latest AI run failed. Documents: 7',
            'Paused a bulk AI re-generation: All documents. Documents: 7',
            'Resumed a bulk AI re-generation: All documents. Documents: 7',
            'Cancelled a bulk AI re-generation: All documents. Documents: 3',
            'A bulk AI re-generation finished: All documents. Documents: 7',
        ]) expect(text).toContain(expected);
    });

    it('renders a model string as literal text, never as markup', () => {
        mountTable(ai);
        expect(current.text()).toContain('Model: <b>gpt-5</b>');
        expect(current.find('tbody b').exists()).toBe(false);
    });

    it('ignores meta outside the allowlists', () => {
        mountTable([{ ...base, id: 30, event: 'ai_bulk_run_changed', meta: { action: 'started', scope: 'LEAK-SCOPE', count: 'LEAK', title: 'LEAK-TITLE' } }]);
        expect(current.text()).not.toContain('LEAK');
    });

    it('translates in SK and PL and passes axe', async () => {
        for (const locale of ['sk', 'pl']) {
            current?.unmount();
            mountTable(ai, locale);
            expect(current.text()).toContain(load(locale).audit.detail.ai_bulk_run_changed_started.split('{')[0].trim());
            await expectNoAxeViolations(current.element);
        }
    });
});
