import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import topics from '../../resources/help/topics.json';

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
    for (const topic of ['shopping.catalog', 'shopping.store', 'shopping.units', 'shopping.roles', 'shopping.reminder', 'shopping.email', 'shopping.lock', 'shopping.carry_over']) {
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

describe('phase 11 PHP strings (mail and notifications)', () => {
    const php = (locale, file) => readFileSync(`${root}/lang/${locale}/${file}.php`, 'utf8');
    const keysIn = (src) => [...src.matchAll(/^\s*'([\w]+)'\s*=>\s*(?:'((?:[^'\\]|\\.)*)'|\[)/gm)].map((m) => [m[1], m[2]]);
    const mailBlock = (locale) => php(locale, 'mail').split("'shopping_list' => [")[1]?.split(/^    \],/m)[0] ?? '';

    it('shopping_list mail keys exist and are non-empty in en, sk and pl', () => {
        const base = keysIn(mailBlock('en'));
        expect(base.length).toBeGreaterThan(10);
        for (const locale of ['sk', 'pl']) {
            const found = new Map(keysIn(mailBlock(locale)));
            const missing = base.filter(([k, v]) => v !== undefined && !(found.get(k) ?? '').trim()).map(([k]) => k);
            const extra = [...found.keys()].filter((k) => !base.some(([b]) => b === k));
            expect({ locale, missing, extra }).toEqual({ locale, missing: [], extra: [] });
        }
    });

    it('shopping_reminder notification keys exist and are non-empty in en, sk and pl', () => {
        const pick = (locale) => new Map(keysIn(php(locale, 'notifications')).filter(([k]) => k.startsWith('shopping_reminder')));
        const base = pick('en');
        expect([...base.keys()].sort()).toEqual(['shopping_reminder_body', 'shopping_reminder_body_no_store', 'shopping_reminder_title']);
        for (const locale of ['sk', 'pl']) {
            const found = pick(locale);
            expect({ locale, keys: [...found.keys()].sort() }).toEqual({ locale, keys: [...base.keys()].sort() });
            for (const [k, v] of found) expect({ locale, k, ok: v.trim() !== '' }).toEqual({ locale, k, ok: true });
        }
    });

    it('mail and notification placeholders match across languages', () => {
        const ph = (s) => [...new Set([...(s ?? '').matchAll(/:(\w+)/g)].map((m) => m[1]))].sort();
        const en = new Map(keysIn(mailBlock('en')));
        for (const locale of ['sk', 'pl']) {
            const found = new Map(keysIn(mailBlock(locale)));
            for (const [k, v] of en) if (v !== undefined) expect({ locale, k, p: ph(found.get(k)) }).toEqual({ locale, k, p: ph(v) });
        }
    });
});

describe('phase 11 help registry and limits', () => {
    const TOPICS = ['shopping.catalog', 'shopping.store', 'shopping.units', 'shopping.roles', 'shopping.reminder', 'shopping.email', 'shopping.lock', 'shopping.carry_over'];
    it('registers all eight Shopping topics', () => {
        const keys = JSON.stringify(topics);
        for (const t of TOPICS) expect(keys, t).toContain(`"${t}"`);
    });
    it('keeps every Shopping help text under 400 characters in every language', () => {
        for (const locale of LOCALES)
            for (const t of TOPICS) expect({ locale, t, ok: (help[locale].topics[t].text ?? '').length <= 400 }).toEqual({ locale, t, ok: true });
    });
});

describe('phase 11 manual section order', () => {
    const IDS = ['overview', 'create-list', 'add-items', 'people', 'lock-and-shop', 'finish', 'copy', 'email', 'reminder', 'stores-catalog', 'units'];
    it('has the same section ids in the same order in en, sk and pl', () => {
        for (const locale of LOCALES) {
            const topic = JSON.parse(readFileSync(`${root}/resources/manual/${locale}/shopping.json`, 'utf8'));
            expect({ locale, ids: topic.sections.map((x) => x.id) }).toEqual({ locale, ids: IDS });
        }
    });
});
