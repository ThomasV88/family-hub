import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { createAppI18n } from '../../resources/js/i18n.js';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
const messages = { sk: load('sk'), pl: load('pl'), en: load('en') };

const AUDIT = [
    'medical_subject_created',
    'medical_subject_deleted',
    'medical_access_changed',
    'medical_admin_self_added',
    'medical_exported',
    'medical_consent_changed',
    'medical_change_granted',
    'medical_change_revoked',
    'medical_change_level',
    'medical_change_linked',
    'medical_change_unlinked',
    'medical_change_self_access_on',
    'medical_change_self_access_off',
    'medical_scope_person',
    'medical_scope_specialty',
    'medical_scope_emergency',
    'medical_format_html',
    'medical_format_json',
    'medical_format_md',
    'medical_value_on',
    'medical_value_off',
].map((k) => `audit.detail.${k}`);

const SPECIALTIES = [
    'general_practice', 'pediatrics', 'internal_medicine', 'gastroenterology', 'cardiology', 'pulmonology',
    'endocrinology', 'diabetology', 'nephrology', 'urology', 'gynecology', 'dermatology', 'ophthalmology', 'ent',
    'orthopedics', 'neurology', 'psychiatry', 'psychology', 'rheumatology', 'oncology', 'hematology', 'allergology',
    'infectious_diseases', 'surgery', 'rehabilitation', 'dentistry', 'radiology', 'laboratory', 'geriatrics', 'other',
];

const PLURALS = [
    'health.folder.visits',
    'health.age',
    'health.docs.attached',
    'health.vacc.due_days',
    'health.vacc.attention',
    'health.allergies.count',
    'health.access.can_view',
    'health.access.can_change',
    'health.upload.add_n',
    'health.results',
];

function flatten(obj, prefix = '') {
    return Object.entries(obj).flatMap(([k, v]) =>
        typeof v === 'object' && v !== null ? flatten(v, `${prefix}${k}.`) : [[`${prefix}${k}`, v]],
    );
}
const isPhase7 = (k) => k.startsWith('health.') || AUDIT.includes(k);
const phase7 = (l) => Object.fromEntries(flatten(messages[l]).filter(([k]) => isPhase7(k)));
const placeholders = (s) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();
const make = (l) => createAppI18n(l, messages[l]).global;
const segs = (l, key) => phase7(l)[key].split('|').length;

describe('Phase 7 copy parity', () => {
    const en = phase7('en');

    it('has the Health keys, the 30 specialties and the audit labels', () => {
        expect(Object.keys(en).filter((k) => k.startsWith('health.')).length).toBeGreaterThanOrEqual(300);
        for (const s of SPECIALTIES) expect(en[`health.specialty.${s}`], s).toBeTruthy();
        expect(Object.keys(en).filter((k) => k.startsWith('health.specialty.'))).toHaveLength(30);
        for (const k of AUDIT) expect(en[k], k).toBeTruthy();
        expect(en['health.specialty.other']).toBe('Other');
    });

    it.each(['sk', 'pl'])('%s has exactly the en key set, all non-empty', (l) => {
        const other = phase7(l);
        expect(Object.keys(other).sort()).toEqual(Object.keys(en).sort());
        for (const [k, v] of Object.entries(other)) expect(typeof v === 'string' && v.trim().length > 0, k).toBe(true);
    });

    it.each(['sk', 'pl'])('%s keeps every placeholder of en', (l) => {
        const other = phase7(l);
        for (const [k, v] of Object.entries(en)) {
            expect([...new Set(placeholders(other[k]))], k).toEqual([...new Set(placeholders(v))]);
        }
    });

    it.each(['en', 'sk', 'pl'])('%s has no exclamation marks, ellipses or markup', (l) => {
        for (const [k, v] of Object.entries(phase7(l))) {
            expect(v.includes('!'), k).toBe(false);
            expect(v.includes('...'), k).toBe(false);
            expect(v.includes('<'), k).toBe(false);
        }
    });

    it.each(['en', 'sk', 'pl'])('%s strings compile and render with params', (l) => {
        const i18n = make(l);
        const params = new Proxy({}, { get: (_, p) => (p === 'n' ? 2 : typeof p === 'string' ? `x${p}` : undefined) });
        for (const k of Object.keys(phase7(l))) {
            const out = PLURALS.includes(k) ? i18n.t(k, params, 2) : i18n.t(k, params);
            expect(out, k).not.toBe(k);
            expect(out.includes('{'), k).toBe(false);
        }
    });

    it('has no key that is both a string and an object', () => {
        const set = new Set(Object.keys(en));
        for (const k of set) {
            const parts = k.split('.');
            for (let i = 1; i < parts.length; i++) expect(set.has(parts.slice(0, i).join('.')), k).toBe(false);
        }
    });

    it('detects exactly the ten plural keys among health.* strings', () => {
        const pipes = Object.entries(en)
            .filter(([k, v]) => k.startsWith('health.') && !k.startsWith('health.ai.') && v.includes('|'))
            .map(([k]) => k);
        expect(pipes.sort()).toEqual([...PLURALS].sort());
    });

    it('never names a vaccine, diagnosis or person in the person-only strings', () => {
        expect(en['health.vaccination.next_due_hint']).toContain('names the person only');
    });
});

describe('Phase 7 plural forms', () => {
    it.each(PLURALS)('%s has 2 segments in en and 4 in sk and pl', (key) => {
        expect(segs('en', key)).toBe(2);
        expect(segs('sk', key)).toBe(4);
        expect(segs('pl', key)).toBe(4);
        // "Add document" (n = 1) legitimately has no number in its singular segment.
        if (key !== 'health.upload.add_n') {
            for (const l of ['en', 'sk', 'pl']) {
                for (const part of phase7(l)[key].split('|')) expect(part, `${l} ${key}`).toMatch(/\{n\}/);
            }
        }
    });

    const SK = { 0: 3, 1: 0, 2: 1, 4: 1, 5: 3, 12: 3, 22: 3, 25: 3 };
    const PL = { 0: 2, 1: 0, 2: 1, 4: 1, 5: 2, 12: 2, 22: 1, 25: 2 };
    const cases = [];
    for (const key of PLURALS) {
        for (const [l, map] of [['sk', SK], ['pl', PL]]) {
            for (const [n, idx] of Object.entries(map)) cases.push([key, l, Number(n), idx]);
        }
    }

    it.each(cases)('%s in %s at n=%i picks segment %i', (key, l, n, idx) => {
        const parts = phase7(l)[key].split('|').map((s) => s.trim());
        const out = make(l).t(key, { n }, n);
        expect(out).toBe(parts[idx].replace('{n}', String(n)));
        if (parts[idx].includes('{n}')) expect(out).toContain(String(n));
    });

    it.each([
        ['sk', 1, '1 návšteva'],
        ['sk', 2, '2 návštevy'],
        ['sk', 4, '4 návštevy'],
        ['sk', 5, '5 návštev'],
        ['sk', 22, '22 návštev'],
        ['pl', 1, '1 wizyta'],
        ['pl', 2, '2 wizyty'],
        ['pl', 5, '5 wizyt'],
        ['pl', 12, '12 wizyt'],
        ['pl', 22, '22 wizyty'],
        ['en', 1, '1 visit'],
        ['en', 2, '2 visits'],
    ])('%s visits at %i reads %s', (l, n, expected) => {
        expect(make(l).t('health.folder.visits', { n }, n)).toBe(expected);
    });
});
