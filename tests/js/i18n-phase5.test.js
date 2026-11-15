import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { createAppI18n } from '../../resources/js/i18n.js';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
const messages = { sk: load('sk'), pl: load('pl'), en: load('en') };
const EXTRA = [
    'settings.section.finance_accounts',
    'audit.detail.finance_visibility_added',
    'audit.detail.finance_visibility_removed',
];
const isPhase5 = (k) => k.startsWith('finance.') || EXTRA.includes(k);

function flatten(obj, prefix = '') {
    return Object.entries(obj).flatMap(([k, v]) =>
        typeof v === 'object' && v !== null ? flatten(v, `${prefix}${k}.`) : [[`${prefix}${k}`, v]],
    );
}
const phase5 = (l) => Object.fromEntries(flatten(messages[l]).filter(([k]) => isPhase5(k)));
const placeholders = (s) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();
const make = (l) => createAppI18n(l, messages[l]).global;
const segs = (l, key) => phase5(l)[key].split('|').length;
const pluralKeys = Object.entries(phase5('en'))
    .filter(([, v]) => v.includes('|'))
    .map(([k]) => k);

describe('Phase 5 copy parity', () => {
    const en = phase5('en');

    it('has finance keys and the extra keys', () => {
        expect(Object.keys(en).filter((k) => k.startsWith('finance.')).length).toBeGreaterThan(300);
        for (const k of EXTRA) expect(en[k], k).toBeTruthy();
    });

    it.each(['sk', 'pl'])('%s has exactly the en key set, all non-empty', (l) => {
        const other = phase5(l);
        expect(Object.keys(other).sort()).toEqual(Object.keys(en).sort());
        for (const [k, v] of Object.entries(other)) expect(typeof v === 'string' && v.trim().length > 0, k).toBe(true);
    });

    it.each(['sk', 'pl'])('%s keeps every placeholder of en', (l) => {
        const other = phase5(l);
        for (const [k, v] of Object.entries(en)) {
            expect([...new Set(placeholders(other[k]))], k).toEqual([...new Set(placeholders(v))]);
        }
    });

    it.each(['en', 'sk', 'pl'])('%s has no exclamation marks', (l) => {
        for (const [k, v] of Object.entries(phase5(l))) expect(v.includes('!'), k).toBe(false);
    });

    it.each(['en', 'sk', 'pl'])('%s strings compile and render with params', (l) => {
        const i18n = make(l);
        const params = new Proxy({}, { get: (_, p) => (p === 'n' ? 2 : typeof p === 'string' ? `<${p}>` : undefined) });
        for (const k of Object.keys(phase5(l))) {
            const out = pluralKeys.includes(k) ? i18n.t(k, params, 2) : i18n.t(k, params);
            expect(out, k).not.toBe(k);
            expect(out.includes('{'), k).toBe(false);
        }
    });

    it('has no key that is both a string and an object', () => {
        const keys = Object.keys(phase5('en'));
        const set = new Set(keys);
        for (const k of keys) {
            const parts = k.split('.');
            for (let i = 1; i < parts.length; i++) expect(set.has(parts.slice(0, i).join('.')), k).toBe(false);
        }
    });

    it('locks the import limits and the default categories', () => {
        expect(messages.en.finance.imp.err_size).toContain('2 MB');
        for (const l of ['en', 'sk', 'pl']) {
            for (const [k, v] of Object.entries(phase5(l))) {
                if (k.startsWith('finance.imp.')) expect(v.includes('5 MB'), k).toBe(false);
            }
        }
        expect(Object.keys(messages.en.finance.category.default).sort()).toEqual(
            ['housing', 'groceries', 'transport', 'health', 'kids', 'leisure', 'savings', 'other', 'salary', 'benefits', 'other_income'].sort(),
        );
    });

    it.each(['en', 'sk', 'pl'])('%s has the immutable and budget toast keys', (l) => {
        const f = messages[l].finance;
        expect(f.error.immutable).toBeTruthy();
        expect(f.toast.budget_saved).toBeTruthy();
        expect(f.toast.budget_removed).toBeTruthy();
    });
});

describe('Phase 5 plural forms', () => {
    it('detects plural keys', () => {
        expect(pluralKeys).toContain('finance.still.count');
        expect(pluralKeys).toContain('finance.imp.undo_body');
        expect(pluralKeys).toContain('finance.toast.imp_undone_kept');
    });

    it.each(pluralKeys)('%s has 2 segments in en and 4 in sk and pl', (key) => {
        expect(segs('en', key)).toBe(2);
        expect(segs('sk', key)).toBe(4);
        expect(segs('pl', key)).toBe(4);
    });

    it.each([
        ['sk', 1, '1 platba'],
        ['sk', 2, '2 platby'],
        ['sk', 5, '5 platieb'],
        ['pl', 1, '1 płatność'],
        ['pl', 2, '2 płatności'],
        ['pl', 5, '5 płatności'],
        ['en', 1, '1 payment'],
        ['en', 3, '3 payments'],
    ])('%s finance.still.count with %i renders "%s"', (l, n, expected) => {
        expect(make(l).t('finance.still.count', { n }, n)).toBe(expected);
    });
});
