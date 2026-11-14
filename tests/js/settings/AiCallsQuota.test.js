import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import AiCallsFilters from '../../../resources/js/Settings/AiCalls/AiCallsFilters.vue';
import AiCallsTable from '../../../resources/js/Settings/AiCalls/AiCallsTable.vue';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
const ROWS = [
    { time: '2026-11-14T10:00:00+00:00', startedBy: 'Anna', person: 'Eva', agent: 'codex', model: 'gpt-5-codex', status: 'failed', durationMs: 2000, quota: true, bulk: false },
    { time: '2026-11-14T09:00:00+00:00', startedBy: 'Anna', person: 'Eva', agent: 'codex', model: 'default', status: 'failed', durationMs: 2000, quota: false, bulk: false },
    { time: '2026-11-14T08:00:00+00:00', startedBy: 'Anna', person: null, agent: 'codex', model: 'default', status: 'succeeded', durationMs: 2000, quota: false, bulk: true },
];
const OPTIONS = { people: [{ id: '11111111-1111-4111-8111-111111111111', name: 'Eva' }], members: [{ id: 1, name: 'Anna' }] };

let current;
function mountIt(comp, props, locale = 'en', messages) {
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(comp, { props, attachTo: document.body, global: { plugins: [createAppI18n(locale, messages ?? load(locale))] } });
    return current;
}
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; delete document.documentElement.dataset.theme; });

const LABELS = {
    en: ['Usage limit reached', 'Not shown (bulk run)'],
    sk: ['Limit používania dosiahnutý', 'Nezobrazuje sa (hromadné spracovanie)'],
    pl: ['Osiągnięto limit użycia', 'Nie pokazano (masowe przetwarzanie)'],
};

describe('AI calls: usage limit filter and tag', () => {
    it('lists the option and emits status=quota', async () => {
        mountIt(AiCallsFilters, { filters: { person: null, member: null, status: null }, options: OPTIONS });
        const label = current.findAll('label').find((l) => l.text() === 'Status');
        const select = current.find(`#${label.attributes('for')}`);
        expect(select.findAll('option').map((o) => o.text())).toContain('Usage limit reached');
        await select.setValue('quota');
        expect(current.emitted('change')[0][0]).toMatchObject({ status: 'quota' });
    });

    for (const locale of ['en', 'sk', 'pl']) {
        it(`renders the quota tag and masked bulk person in ${locale}`, () => {
            mountIt(AiCallsTable, { rows: ROWS }, locale);
            const [tagText, bulkText] = LABELS[locale];
            const rows = current.findAll('tbody tr');
            const tag = rows[0].find('.ui-tag');
            expect(tag.classes()).toContain('ui-tag--warn');
            expect(tag.text()).toContain(tagText);
            expect(tag.find('svg').exists()).toBe(true);
            expect(tag.find('svg').attributes('aria-hidden')).toBe('true');
            expect(rows[1].find('.ui-tag').classes()).toContain('ui-tag--danger');
            expect(rows[2].text()).toContain(bulkText);
            expect(rows[2].find('a').exists()).toBe(false);
            expect(rows[0].text()).toContain('Eva');
            expect(rows[1].text()).toContain('Eva');
        });
    }

    it('non-quota non-bulk rows render as before', () => {
        mountIt(AiCallsTable, { rows: [{ ...ROWS[1] }, { ...ROWS[1], person: null }] });
        const rows = current.findAll('tbody tr');
        expect(rows[0].text()).toContain('Failed');
        expect(rows[0].text()).not.toContain('Usage limit');
        expect(rows[1].text()).toContain('Removed person');
        expect(rows[1].text()).not.toContain('bulk run');
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const theme of ['light', 'dark']) {
            it(`axe clean in ${locale} ${theme}`, async () => {
                document.documentElement.dataset.theme = theme;
                mountIt(AiCallsTable, { rows: ROWS }, locale);
                await expectNoAxeViolations(current.element);
            });
        }
    }

    it('keeps the tag and masked cell wrapping with 40% longer strings', () => {
        const msgs = pseudoLong(load('sk'));
        mountIt(AiCallsTable, { rows: ROWS }, 'sk', msgs);
        const rows = current.findAll('tbody tr');
        expect(rows[0].text()).toContain(msgs.settings.ai_calls.status_quota);
        expect(rows[2].text()).toContain(msgs.settings.ai_calls.person_bulk);
        const src = readFileSync(`${process.cwd()}/resources/js/Settings/AiCalls/AiCallsTable.vue`, 'utf8');
        expect(src).toMatch(/\.ai-table__tag \{[^}]*white-space: normal/);
        expect(src).toMatch(/td\) \{ overflow-wrap: anywhere/);
    });
});
