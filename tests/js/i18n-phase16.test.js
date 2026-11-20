import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';

const root = process.cwd();
const LOCALES = ['en', 'sk', 'pl'];
const lang = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(readFileSync(`${root}/lang/${l}.json`, 'utf8'))]));
const help = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(readFileSync(`${root}/resources/help/${l}.json`, 'utf8'))]));
const registry = JSON.parse(readFileSync(`${root}/resources/help/topics.json`, 'utf8'));
const get = (obj, path) => path.split('.').reduce((o, k) => (o && typeof o === 'object' ? o[k] : undefined), obj);
const leaves = (obj, prefix = '') => Object.entries(obj).flatMap(([k, v]) => (v !== null && typeof v === 'object' ? leaves(v, `${prefix}${k}.`) : [`${prefix}${k}`]));
const placeholders = (s) => [...new Set([...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]))].sort();

const TOASTS = ['group_created', 'group_saved', 'group_members_saved', 'group_left', 'group_deleted'].map((k) => `wishlist.toast.${k}`);
const KEYS = [...leaves(get(lang.en, 'wishlist.group')).map((k) => `wishlist.group.${k}`), ...TOASTS];
const TOPICS = ['wishlist.group_mode', 'wishlist.group_members'];

describe('phase 16 locale parity (lang)', () => {
    it('has the group keys and toasts', () => {
        expect(KEYS.length).toBeGreaterThan(50);
        expect(get(lang.en, 'wishlist.group.error.last_member')).toBeTruthy();
    });

    it('every key exists and is non-empty in en, sk and pl, and sk and pl add nothing', () => {
        for (const locale of LOCALES) {
            const missing = KEYS.filter((k) => { const v = get(lang[locale], k); return typeof v !== 'string' || v.trim() === ''; });
            expect({ locale, missing }).toEqual({ locale, missing: [] });
        }
        const en = new Set(leaves(get(lang.en, 'wishlist.group')));
        for (const locale of ['sk', 'pl']) {
            expect({ locale, extra: leaves(get(lang[locale], 'wishlist.group')).filter((k) => !en.has(k)) }).toEqual({ locale, extra: [] });
        }
    });

    it('placeholders match and sk and pl are not left in English', () => {
        for (const key of KEYS) {
            const base = placeholders(get(lang.en, key));
            for (const locale of ['sk', 'pl']) {
                expect({ locale, key, p: placeholders(get(lang[locale], key)) }).toEqual({ locale, key, p: base });
                if (['wishlist.group.error.save_failed'].includes(key)) continue;
                expect({ locale, key, same: get(lang[locale], key) === get(lang.en, key) }).toEqual({ locale, key, same: false });
            }
        }
    });

    it('carries the placeholders the components pass', () => {
        expect(placeholders(get(lang.en, 'wishlist.group.delete_body'))).toEqual(['name']);
        expect(placeholders(get(lang.en, 'wishlist.group.header_members'))).toEqual(['names']);
        for (const k of ['add', 'list_title', 'received_by', 'shopping_group']) expect(placeholders(get(lang.en, `wishlist.group.${k}`))).toEqual(['name']);
    });

    it('never contains the old app name', () => {
        for (const locale of LOCALES) expect(JSON.stringify(get(lang[locale], 'wishlist.group'))).not.toContain(['Family', 'Hub'].join(' '));
    });
});

describe('phase 16 help tips', () => {
    it('registers both topics on the group_panel screen', () => {
        for (const key of TOPICS) {
            const t = registry.topics.find((x) => x.key === key);
            expect(t, key).toMatchObject({ area: 'wishlist', screen: 'group_panel' });
        }
    });

    it('has titles and texts of at most 400 characters and the screen in every language', () => {
        for (const locale of LOCALES) {
            expect(help[locale].screens.group_panel, locale).toBeTruthy();
            for (const key of TOPICS) {
                const t = help[locale].topics[key];
                expect(t?.title, `${locale} ${key}`).toBeTruthy();
                expect(t.text.length, `${locale} ${key}`).toBeGreaterThan(20);
                expect(t.text.length, `${locale} ${key}`).toBeLessThanOrEqual(400);
            }
        }
    });

    it('rewords the reservation tip for group lists and keeps its length', () => {
        const hints = { en: 'group list', sk: 'spoločnom zozname', pl: 'liście wspólnej' };
        for (const locale of LOCALES) {
            const text = help[locale].topics['wishlist.reservation'].text;
            expect(text, locale).toContain(hints[locale]);
            expect(text.length).toBeLessThanOrEqual(400);
        }
    });
});
