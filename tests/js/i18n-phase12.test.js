import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';

const root = process.cwd();
const LOCALES = ['en', 'sk', 'pl'];
const lang = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(readFileSync(`${root}/lang/${l}.json`, 'utf8'))]));

const get = (obj, path) => path.split('.').reduce((o, k) => (o && typeof o === 'object' ? o[k] : undefined), obj);

/** Every leaf of the subtree under a dotted path in English, as dotted keys. */
function leaves(obj, prefix = '') {
    return Object.entries(obj).flatMap(([k, v]) => (v !== null && typeof v === 'object' ? leaves(v, `${prefix}${k}.`) : [`${prefix}${k}`]));
}

// Later Phase 12 UI plans append their subtrees here.
const SUBTREES = ['recipes.overview', 'recipes.card', 'recipes.filter', 'recipes.visibility', 'recipes.toast'];
const KEYS = ['nav.recipes', 'module.recipes'];
// Strings that are plural in en: the number of forms per locale (en 2, sk 4, pl 4).
const FORMS = { en: 2, sk: 4, pl: 4 };
const PLURALS = ['recipes.overview.loaded_more', 'recipes.card.servings'];

describe('phase 12 locale parity (lang)', () => {
    for (const subtree of SUBTREES) {
        it(`${subtree}: every key exists and is non-empty in en, sk and pl`, () => {
            const keys = leaves(get(lang.en, subtree)).map((k) => `${subtree}.${k}`);
            expect(keys.length).toBeGreaterThan(0);
            for (const locale of LOCALES) {
                const missing = keys.filter((k) => {
                    const v = get(lang[locale], k);
                    return typeof v !== 'string' || v.trim() === '';
                });
                expect({ locale, missing }).toEqual({ locale, missing: [] });
            }
        });

        it(`${subtree}: sk and pl define no key that en lacks`, () => {
            const en = new Set(leaves(get(lang.en, subtree)));
            for (const locale of ['sk', 'pl']) {
                const extra = leaves(get(lang[locale], subtree) ?? {}).filter((k) => !en.has(k));
                expect({ locale, extra }).toEqual({ locale, extra: [] });
            }
        });

        it(`${subtree}: placeholders match in every language`, () => {
            const placeholders = (s) => [...new Set([...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]))].sort();
            for (const key of leaves(get(lang.en, subtree)).map((k) => `${subtree}.${k}`)) {
                const base = placeholders(get(lang.en, key));
                for (const locale of ['sk', 'pl']) expect({ locale, key, p: placeholders(get(lang[locale], key)) }).toEqual({ locale, key, p: base });
            }
        });
    }

    it('lists the explicit keys in every language', () => {
        for (const key of KEYS) {
            for (const locale of LOCALES) {
                const v = get(lang[locale], key);
                expect({ locale, key, ok: typeof v === 'string' && v.trim() !== '' }).toEqual({ locale, key, ok: true });
            }
        }
    });

    it.each(PLURALS)('%s has the locale number of plural forms', (key) => {
        for (const locale of LOCALES) {
            expect({ locale, forms: get(lang[locale], key).split(' | ').length }).toEqual({ locale, forms: FORMS[locale] });
        }
    });

    it('has no top-level recipes key clash (the namespace is an object)', () => {
        for (const locale of LOCALES) expect(typeof lang[locale].recipes).toBe('object');
    });
});
