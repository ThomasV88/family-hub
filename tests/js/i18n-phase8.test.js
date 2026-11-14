import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { createAppI18n } from '../../resources/js/i18n.js';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
const messages = { sk: load('sk'), pl: load('pl'), en: load('en') };

const PREFIXES = [
    'health.ai.run.',
    'health.ai.status.',
    'health.ai.review.',
    'health.ai.provenance.',
    'settings.ai_calls.',
    'settings.ai_login.',
    'settings.section.ai_calls',
    'audit.detail.medical_ai_',
];

const PLURALS = [
    'health.ai.review.count',
    'health.ai.review.left',
    'health.ai.status.ready_detail',
    'health.ai.status.pages_read',
    'settings.ai_calls.pages',
];

function flatten(obj, prefix = '') {
    return Object.entries(obj).flatMap(([k, v]) =>
        typeof v === 'object' && v !== null ? flatten(v, `${prefix}${k}.`) : [[`${prefix}${k}`, v]],
    );
}
const phase8 = (l) => Object.fromEntries(flatten(messages[l]).filter(([k]) => PREFIXES.some((p) => k.startsWith(p))));
const placeholders = (s) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();
const make = (l) => createAppI18n(l, messages[l]).global;
const segs = (l, key) => phase8(l)[key].split('|').length;

describe('Phase 8 copy parity', () => {
    const en = phase8('en');

    it('has the Phase 8 key groups', () => {
        const keys = Object.keys(en);
        for (const p of PREFIXES) expect(keys.some((k) => k.startsWith(p)), p).toBe(true);
        expect(keys.length).toBeGreaterThanOrEqual(150);
        for (const e of ['requested', 'finished']) expect(en[`audit.detail.medical_ai_run_${e}`]).toBeTruthy();
        expect(en['audit.detail.medical_ai_entry_decided']).toBeTruthy();
    });

    it.each(['sk', 'pl'])('%s has exactly the en key set, all non-empty', (l) => {
        const other = phase8(l);
        expect(Object.keys(other).sort()).toEqual(Object.keys(en).sort());
        for (const [k, v] of Object.entries(other)) expect(typeof v === 'string' && v.trim().length > 0, k).toBe(true);
    });

    it.each(['sk', 'pl'])('%s keeps every placeholder of en', (l) => {
        const other = phase8(l);
        for (const [k, v] of Object.entries(en)) {
            expect([...new Set(placeholders(other[k]))], k).toEqual([...new Set(placeholders(v))]);
        }
    });

    it.each(['en', 'sk', 'pl'])('%s has no exclamation marks, ellipses or markup', (l) => {
        for (const [k, v] of Object.entries(phase8(l))) {
            expect(v.includes('!'), k).toBe(false);
            expect(v.includes('...'), k).toBe(false);
            expect(v.includes('<'), k).toBe(false);
        }
    });

    it.each(['en', 'sk', 'pl'])('%s strings compile and render with params', (l) => {
        const i18n = make(l);
        const params = new Proxy({}, { get: (_, p) => (p === 'n' ? 2 : typeof p === 'string' ? `x${p}` : undefined) });
        for (const k of Object.keys(phase8(l))) {
            const out = PLURALS.includes(k) ? i18n.t(k, params, 2) : i18n.t(k, params);
            expect(out, k).not.toBe(k);
            expect(out.includes('{'), k).toBe(false);
        }
    });

    it('detects exactly the five plural keys among Phase 8 strings', () => {
        const pipes = Object.entries(en).filter(([, v]) => v.includes('|')).map(([k]) => k);
        expect(pipes.sort()).toEqual([...PLURALS].sort());
    });

    it('keeps the existing consent label string next to the new provenance group', () => {
        expect(typeof messages.en.health.ai.label).toBe('string');
        expect(messages.en.health.ai.provenance.ai_extracted).toBe('AI-extracted');
    });

    it('uses the checker follow-up wordings', () => {
        expect(messages.en.settings.ai_login.not_responding).toBe('AI service not responding');
        expect(messages.en.health.ai.run.notice_cancel).toBe('Keep document private');
        expect(messages.en.health.ai.review.discard_confirm).toBe('Discard edit');
        expect(messages.en.health.ai.status.ready_empty).toBe('Nothing to suggest');
        expect(messages.en.health.ai.run.sends_line).toBe('Sends this document to OpenAI.');
    });

    it('keeps the 07 not_available line for plan 08-16 to remove', () => {
        expect(messages.en.health.ai.not_available).toBeTruthy();
    });
});

describe('Phase 8 plural forms', () => {
    it.each(PLURALS)('%s has 2 segments in en and 4 in sk and pl, each with a count', (key) => {
        expect(segs('en', key)).toBe(2);
        expect(segs('sk', key)).toBe(4);
        expect(segs('pl', key)).toBe(4);
        for (const l of ['en', 'sk', 'pl']) {
            for (const part of phase8(l)[key].split('|')) expect(part, `${l} ${key}`).toMatch(/\{n\}/);
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
        const parts = phase8(l)[key].split('|').map((s) => s.trim());
        const out = make(l).t(key, { n }, n);
        expect(out).toBe(parts[idx].replace('{n}', String(n)));
        expect(out).toContain(String(n));
    });

    it.each([
        ['en', 'health.ai.review.count', 1, '1 suggestion'],
        ['en', 'health.ai.review.count', 2, '2 suggestions'],
        ['sk', 'health.ai.review.count', 4, '4 návrhy'],
        ['sk', 'health.ai.review.count', 5, '5 návrhov'],
        ['pl', 'health.ai.review.count', 22, '22 sugestie'],
        ['pl', 'health.ai.review.count', 12, '12 sugestii'],
    ])('%s %s at %i reads %s', (l, key, n, expected) => {
        expect(make(l).t(key, { n }, n)).toBe(expected);
    });
});
