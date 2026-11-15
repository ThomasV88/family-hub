import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { createAppI18n } from '../../resources/js/i18n.js';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
const messages = { sk: load('sk'), pl: load('pl'), en: load('en') };
const NAMESPACES = ['auth', 'invite', 'settings', 'permissions', 'sessions', 'audit', 'account', 'access', 'common'];

function flatten(obj, prefix = '') {
    return Object.entries(obj).flatMap(([k, v]) =>
        typeof v === 'object' && v !== null ? flatten(v, `${prefix}${k}.`) : [[`${prefix}${k}`, v]],
    );
}
const phase2 = (l) =>
    Object.fromEntries(flatten(messages[l]).filter(([k]) => NAMESPACES.includes(k.split('.')[0])));
const placeholders = (s) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();
const make = (l) => createAppI18n(l, messages[l]).global;

describe('Phase 2 copy parity', () => {
    const en = phase2('en');

    it('has Phase 2 keys in every namespace', () => {
        for (const ns of NAMESPACES) expect(Object.keys(en).some((k) => k.startsWith(`${ns}.`))).toBe(true);
    });

    it.each(['sk', 'pl'])('%s has exactly the en key set, all non-empty', (l) => {
        const other = phase2(l);
        expect(Object.keys(other).sort()).toEqual(Object.keys(en).sort());
        for (const [k, v] of Object.entries(other)) expect(typeof v === 'string' && v.trim().length > 0, k).toBe(true);
    });

    it.each(['sk', 'pl'])('%s keeps every placeholder of en', (l) => {
        const other = phase2(l);
        for (const [k, v] of Object.entries(en)) {
            const want = [...new Set(placeholders(v))];
            const got = [...new Set(placeholders(other[k]))];
            expect(got, k).toEqual(want);
        }
    });

    it.each(['en', 'sk', 'pl'])('%s has no exclamation marks', (l) => {
        for (const [k, v] of Object.entries(phase2(l))) expect(v.includes('!'), k).toBe(false);
    });

    it.each(['en', 'sk', 'pl'])('%s strings compile and render with params', (l) => {
        const i18n = make(l);
        const params = new Proxy({}, { get: (_, p) => (typeof p === 'string' ? `<${p}>` : undefined) });
        for (const [k, v] of Object.entries(phase2(l))) {
            if (k === 'auth.error.throttle') continue;
            const out = i18n.t(k, params);
            expect(out, k).not.toBe(k);
            expect(out.includes('{'), k).toBe(false);
        }
    });
});

describe('auth.error.throttle plural forms', () => {
    const segs = (l) => messages[l].auth.error.throttle.split('|').length;
    it('has 2 segments in en and 4 in sk and pl', () => {
        expect(segs('en')).toBe(2);
        expect(segs('sk')).toBe(4);
        expect(segs('pl')).toBe(4);
    });

    const t = (l, n) => make(l).t('auth.error.throttle', { minutes: n }, n);
    it.each([
        ['en', 1, 'Too many attempts. Try again in 1 minute.'],
        ['en', 5, 'Too many attempts. Try again in 5 minutes.'],
        ['sk', 1, 'Príliš veľa pokusov. Skúste to znova o 1 minútu.'],
        ['sk', 3, 'Príliš veľa pokusov. Skúste to znova o 3 minúty.'],
        ['sk', 5, 'Príliš veľa pokusov. Skúste to znova o 5 minút.'],
        ['pl', 1, 'Zbyt wiele prób. Spróbuj ponownie za 1 minutę.'],
        ['pl', 2, 'Zbyt wiele prób. Spróbuj ponownie za 2 minuty.'],
        ['pl', 5, 'Zbyt wiele prób. Spróbuj ponownie za 5 minut.'],
    ])('%s with %i', (l, n, expected) => expect(t(l, n)).toBe(expected));
});
