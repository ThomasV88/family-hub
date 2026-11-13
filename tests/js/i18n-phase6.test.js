import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { createAppI18n } from '../../resources/js/i18n.js';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
const messages = { sk: load('sk'), pl: load('pl'), en: load('en') };
const EXTRA = [
    'settings.section.security',
    'audit.event.security',
    'audit.detail.totp_enabled',
    'audit.detail.totp_disabled',
    'audit.detail.passkey_added',
    'audit.detail.passkey_renamed',
    'audit.detail.passkey_removed',
    'audit.detail.challenge_failed',
    'audit.detail.recovery_used',
    'audit.detail.recovery_regenerated',
    'audit.detail.admin_reset',
    'audit.detail.requirement_on',
    'audit.detail.requirement_off',
    'audit.detail.reauth_confirmed',
];
const PREFIXES = ['security.', 'twofactor.', 'passkey.', 'reauth.', 'members.'];
const isPhase6 = (k) => PREFIXES.some((p) => k.startsWith(p)) || EXTRA.includes(k);

function flatten(obj, prefix = '') {
    return Object.entries(obj).flatMap(([k, v]) =>
        typeof v === 'object' && v !== null ? flatten(v, `${prefix}${k}.`) : [[`${prefix}${k}`, v]],
    );
}
const phase6 = (l) => Object.fromEntries(flatten(messages[l]).filter(([k]) => isPhase6(k)));
const placeholders = (s) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();
const make = (l) => createAppI18n(l, messages[l]).global;
const segs = (l, key) => phase6(l)[key].split('|').length;
const pluralKeys = Object.entries(phase6('en'))
    .filter(([, v]) => v.includes('|'))
    .map(([k]) => k);

describe('Phase 6 copy parity', () => {
    const en = phase6('en');

    it('has the Phase 6 keys and the amendment keys', () => {
        expect(Object.keys(en).length).toBeGreaterThanOrEqual(120);
        for (const k of [...EXTRA, 'reauth.google', 'reauth.google_failed', 'security.confirm_first', 'members.security.confirm_first', 'members.security.reset_body_required']) {
            expect(en[k], k).toBeTruthy();
        }
        expect(Object.keys(en).some((k) => k.includes('reset_suffix'))).toBe(false);
    });

    it.each(['sk', 'pl'])('%s has exactly the en key set, all non-empty', (l) => {
        const other = phase6(l);
        expect(Object.keys(other).sort()).toEqual(Object.keys(en).sort());
        for (const [k, v] of Object.entries(other)) expect(typeof v === 'string' && v.trim().length > 0, k).toBe(true);
    });

    it.each(['sk', 'pl'])('%s keeps every placeholder of en', (l) => {
        const other = phase6(l);
        for (const [k, v] of Object.entries(en)) {
            expect([...new Set(placeholders(other[k]))], k).toEqual([...new Set(placeholders(v))]);
        }
    });

    it.each(['en', 'sk', 'pl'])('%s has no exclamation marks or ellipses', (l) => {
        for (const [k, v] of Object.entries(phase6(l))) {
            expect(v.includes('!'), k).toBe(false);
            expect(v.includes('...'), k).toBe(false);
        }
    });

    it.each(['en', 'sk', 'pl'])('%s strings compile and render with params', (l) => {
        const i18n = make(l);
        const params = new Proxy({}, { get: (_, p) => (p === 'n' ? 2 : typeof p === 'string' ? `<${p}>` : undefined) });
        for (const k of Object.keys(phase6(l))) {
            const out = pluralKeys.includes(k) ? i18n.t(k, params, 2) : i18n.t(k, params);
            expect(out, k).not.toBe(k);
            expect(out.includes('{'), k).toBe(false);
        }
    });

    it('has no key that is both a string and an object', () => {
        const keys = Object.keys(en);
        const set = new Set(keys);
        for (const k of keys) {
            const parts = k.split('.');
            for (let i = 1; i < parts.length; i++) expect(set.has(parts.slice(0, i).join('.')), k).toBe(false);
        }
    });
});

describe('Phase 6 plural forms', () => {
    it('detects the five plural keys', () => {
        expect(pluralKeys.sort()).toEqual(
            ['members.security.pending', 'passkey.count', 'twofactor.error.throttle', 'twofactor.recovery.remaining', 'twofactor.recovery.used'].sort(),
        );
    });

    it.each(pluralKeys)('%s has 2 segments in en and 4 in sk and pl', (key) => {
        expect(segs('en', key)).toBe(2);
        expect(segs('sk', key)).toBe(4);
        expect(segs('pl', key)).toBe(4);
    });

    const SK = { 0: 3, 1: 0, 2: 1, 4: 1, 5: 3, 12: 3, 22: 3, 25: 3 };
    const PL = { 0: 2, 1: 0, 2: 1, 4: 1, 5: 2, 12: 2, 22: 1, 25: 2 };
    const cases = [];
    for (const key of pluralKeys) {
        for (const [l, map] of [['sk', SK], ['pl', PL]]) {
            for (const [n, idx] of Object.entries(map)) cases.push([key, l, Number(n), idx]);
        }
    }

    it.each(cases)('%s in %s at n=%i picks segment %i', (key, l, n, idx) => {
        const parts = phase6(l)[key].split('|').map((s) => s.trim());
        const out = make(l).t(key, { n }, n);
        expect(out).toBe(parts[idx].replace('{n}', String(n)));
        expect(out).toContain(String(n));
    });

    it.each([
        [1, 'Too many attempts. Try again in 1 minute.'],
        [3, 'Too many attempts. Try again in 3 minutes.'],
    ])('en throttle at %i', (n, expected) => {
        expect(make('en').t('twofactor.error.throttle', { n }, n)).toBe(expected);
    });
});
