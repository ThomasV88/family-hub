import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { createAppI18n } from '../../resources/js/i18n.js';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
const messages = { sk: load('sk'), pl: load('pl'), en: load('en') };
const NAMESPACES = ['wishlist', 'occasions'];
const isPhase4 = (k) =>
    NAMESPACES.includes(k.split('.')[0]) ||
    k.startsWith('notifications.gifts_') ||
    k.startsWith('settings.members.wishlist_managers.');
const PLURALS = ['wishlist.list.count', 'wishlist.card.links', 'wishlist.people', 'occasions.in_days'];

function flatten(obj, prefix = '') {
    return Object.entries(obj).flatMap(([k, v]) =>
        typeof v === 'object' && v !== null ? flatten(v, `${prefix}${k}.`) : [[`${prefix}${k}`, v]],
    );
}
const phase4 = (l) => Object.fromEntries(flatten(messages[l]).filter(([k]) => isPhase4(k)));
const placeholders = (s) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();
const make = (l) => createAppI18n(l, messages[l]).global;
const segs = (l, key) => phase4(l)[key].split('|').length;

describe('Phase 4 copy parity', () => {
    const en = phase4('en');

    it('has keys in every namespace and group', () => {
        for (const ns of NAMESPACES) expect(Object.keys(en).some((k) => k.startsWith(`${ns}.`))).toBe(true);
        expect(Object.keys(en).some((k) => k.startsWith('notifications.gifts_'))).toBe(true);
        expect(Object.keys(en).some((k) => k.startsWith('settings.members.wishlist_managers.'))).toBe(true);
    });

    it.each(['sk', 'pl'])('%s has exactly the en key set, all non-empty', (l) => {
        const other = phase4(l);
        expect(Object.keys(other).sort()).toEqual(Object.keys(en).sort());
        for (const [k, v] of Object.entries(other)) expect(typeof v === 'string' && v.trim().length > 0, k).toBe(true);
    });

    it.each(['sk', 'pl'])('%s keeps every placeholder of en', (l) => {
        const other = phase4(l);
        for (const [k, v] of Object.entries(en)) {
            expect([...new Set(placeholders(other[k]))], k).toEqual([...new Set(placeholders(v))]);
        }
    });

    it.each(['en', 'sk', 'pl'])('%s has no exclamation marks', (l) => {
        for (const [k, v] of Object.entries(phase4(l))) expect(v.includes('!'), k).toBe(false);
    });

    it.each(['en', 'sk', 'pl'])('%s strings compile and render with params', (l) => {
        const i18n = make(l);
        const params = new Proxy({}, { get: (_, p) => (typeof p === 'string' ? `<${p}>` : undefined) });
        for (const k of Object.keys(phase4(l))) {
            const out = PLURALS.includes(k) ? i18n.t(k, { n: 2 }, 2) : i18n.t(k, params);
            expect(out, k).not.toBe(k);
            expect(out.includes('{'), k).toBe(false);
        }
    });

    it('keeps server-only strings out of the JSON', () => {
        for (const l of ['en', 'sk', 'pl']) {
            const all = Object.fromEntries(flatten(messages[l]));
            expect(all['notifications.gift_title']).toBeUndefined();
            expect(all['notifications.gift_email_body']).toBeUndefined();
        }
    });

    it('resolves the key collisions with objects', () => {
        expect(typeof messages.en.wishlist.received).toBe('object');
        expect(typeof messages.en.wishlist.delete).toBe('object');
        expect(messages.en.wishlist.received.mark).toBe('Mark as received');
        expect(messages.en.wishlist.delete.action).toBe('Delete wish');
    });

    it('wishlist.cancel is plain Cancel', () => {
        expect(messages.en.wishlist.cancel).toBe('Cancel');
    });
});

describe('Phase 4 plural forms', () => {
    it.each(PLURALS)('%s has 2 segments in en and 4 in sk and pl', (key) => {
        expect(segs('en', key)).toBe(2);
        expect(segs('sk', key)).toBe(4);
        expect(segs('pl', key)).toBe(4);
    });

    it.each([
        ['sk', 'wishlist.list.count', 1, '1 želanie'],
        ['sk', 'wishlist.list.count', 2, '2 želania'],
        ['sk', 'wishlist.list.count', 5, '5 želaní'],
        ['pl', 'wishlist.list.count', 1, '1 życzenie'],
        ['pl', 'wishlist.list.count', 2, '2 życzenia'],
        ['pl', 'wishlist.list.count', 5, '5 życzeń'],
        ['sk', 'occasions.in_days', 1, 'o 1 deň'],
        ['sk', 'occasions.in_days', 3, 'o 3 dni'],
        ['sk', 'occasions.in_days', 5, 'o 5 dní'],
        ['en', 'wishlist.list.count', 1, '1 wish'],
        ['en', 'wishlist.list.count', 3, '3 wishes'],
    ])('%s %s with %i renders "%s"', (l, key, n, expected) => {
        expect(make(l).t(key, { n }, n)).toBe(expected);
    });
});
