import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';

const root = process.cwd();
const LOCALES = ['en', 'sk', 'pl'];
const load = (path) => Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(readFileSync(`${root}/${path.replace('{l}', l)}`, 'utf8'))]));
const lang = load('lang/{l}.json');
const help = load('resources/help/{l}.json');

const get = (obj, path) => path.split('.').reduce((o, k) => (o && typeof o === 'object' ? o[k] : undefined), obj);

/** Every leaf of the subtree under a dotted path in English, as dotted keys. */
function leaves(obj, prefix = '') {
    return Object.entries(obj).flatMap(([k, v]) => (v !== null && typeof v === 'object' ? leaves(v, `${prefix}${k}.`) : [`${prefix}${k}`]));
}

const SUBTREES = ['shopping'];
const KEYS = ['settings.section.shopping', 'permissions.module.shopping', 'nav.shopping', 'module.shopping', 'notifications.intro', 'audit.detail.shopping_list_mailed'];

describe('phase 11 locale parity (lang)', () => {
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

        it(`${subtree}: plural strings have the same placeholders in every language`, () => {
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

    it('has a label for every built-in unit with the plural forms Slovak and Polish need', () => {
        for (const key of ['pcs', 'g', 'kg', 'ml', 'l', 'pack', 'bunch', 'tbsp', 'tsp']) {
            for (const locale of LOCALES) expect(get(lang[locale], `shopping.unit.${key}`)?.trim(), `${locale} ${key}`).toBeTruthy();
        }
        for (const locale of ['sk', 'pl']) expect(get(lang[locale], 'shopping.unit.pack').split('|')).toHaveLength(3);
    });
});

describe('phase 11 notifications wording', () => {
    it('no longer says reminders are only about tasks', () => {
        expect(lang.en.notifications.intro).toBe('Choose how you want to be reminded about tasks and shopping lists.');
        for (const locale of LOCALES) expect(lang[locale].notifications.intro.length).toBeGreaterThan(20);
    });
});

describe('phase 11 help texts', () => {
    for (const topic of ['shopping.catalog', 'shopping.store', 'shopping.units', 'shopping.roles', 'shopping.reminder', 'shopping.email']) {
        it(`${topic} has a title and a text in en, sk and pl`, () => {
            for (const locale of LOCALES) {
                const entry = help[locale].topics?.[topic];
                expect({ locale, topic, title: !!entry?.title?.trim(), text: !!entry?.text?.trim() }).toEqual({ locale, topic, title: true, text: true });
            }
        });
    }

    it('the shopping area and the settings screen are named in every language', () => {
        for (const locale of LOCALES) {
            expect(help[locale].areas.shopping?.trim()).toBeTruthy();
            expect(help[locale].screens.shopping_settings?.trim()).toBeTruthy();
            expect(help[locale].screens.shopping_list_panel?.trim()).toBeTruthy();
            expect(help[locale].screens.shopping_email?.trim()).toBeTruthy();
        }
    });
});
