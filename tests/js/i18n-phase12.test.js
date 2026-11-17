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
const SUBTREES = [
    'recipes.overview', 'recipes.card', 'recipes.filter', 'recipes.visibility', 'recipes.toast',
    // 12-08: form
    'recipes.form', 'recipes.ingredient', 'recipes.step', 'recipes.category_starter', 'recipes.photos', 'recipes.image', 'recipes.error',
    // 12-09: detail
    'recipes.detail', 'recipes.gallery', 'recipes.servings', 'recipes.progress', 'recipes.cooking', 'recipes.notes', 'recipes.delete', 'recipes.reset', 'recipes.unavailable',
    // 12-10: add to shopping
    'recipes.shopping',
    // 12-15: import UI
    'recipes.new', 'recipes.import', 'recipes.review', 'recipes.drafts',
];
const KEYS = ['nav.recipes', 'module.recipes'];
// Strings that are plural in en: the number of forms per locale (en 2, sk 4, pl 4).
const FORMS = { en: 2, sk: 4, pl: 4 };
const PLURALS = ['recipes.overview.loaded_more', 'recipes.card.servings', 'recipes.photos.some_failed',
    'recipes.shopping.quantities_for', 'recipes.shopping.add_n', 'recipes.shopping.create_add_n', 'recipes.toast.items_added'];

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

    it('has a text for every starter category the server sends', () => {
        const starter = readFileSync(`${root}/config/recipes.php`, 'utf8').match(/'starter_categories' => \[([^\]]*)\]/)[1].match(/'(\w+)'/g).map((k) => k.replaceAll("'", ''));
        expect(starter.length).toBe(10);
        for (const locale of LOCALES) for (const key of starter) expect({ locale, key, ok: Boolean(get(lang[locale], `recipes.category_starter.${key}`)) }).toEqual({ locale, key, ok: true });
    });

    it('has no top-level recipes key clash (the namespace is an object)', () => {
        for (const locale of LOCALES) expect(typeof lang[locale].recipes).toBe('object');
    });
});

describe('phase 12 manual and help (12-08)', () => {
    const manual = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(readFileSync(`${root}/resources/manual/${l}/recipes.json`, 'utf8'))]));

    it('has the adding, photos and sharing sections in the same order in every language', () => {
        for (const locale of LOCALES) {
            const ids = manual[locale].sections.map((s) => s.id);
            // 12-15 inserted import-from-link right after adding.
            expect({ locale, order: ['overview', 'adding', 'import-from-link', 'photos', 'sharing'].map((id) => ids.indexOf(id)) }).toEqual({ locale, order: [0, 1, 2, 3, 4] });
        }
        for (const id of ['adding', 'photos', 'sharing']) {
            const counts = LOCALES.map((l) => manual[l].sections.find((s) => s.id === id).blocks.map((b) => b.type).join(','));
            expect(new Set(counts).size, `${id} block types differ between languages`).toBe(1);
        }
    });

    it('registers the sharing help tip on the recipe form with a text in every language', () => {
        const topics = JSON.parse(readFileSync(`${root}/resources/help/topics.json`, 'utf8')).topics;
        expect(topics.find((t) => t.key === 'recipes.sharing')).toEqual({ key: 'recipes.sharing', area: 'recipes', screen: 'recipe_form', field: 'visibility' });
        for (const locale of LOCALES) {
            const help = JSON.parse(readFileSync(`${root}/resources/help/${locale}.json`, 'utf8'));
            expect(help.areas.recipes?.trim()).toBeTruthy();
            expect(help.screens.recipe_form?.trim()).toBeTruthy();
            expect([...help.topics['recipes.sharing'].text].length).toBeLessThanOrEqual(400);
        }
    });
});

describe('phase 12 manual and help (12-10)', () => {
    const manual = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(readFileSync(`${root}/resources/manual/${l}/recipes.json`, 'utf8'))]));

    it('has the shopping section before deleting, with the same block types in every language', () => {
        for (const locale of LOCALES) {
            const ids = manual[locale].sections.map((s) => s.id);
            expect({ locale, ok: ids.indexOf('shopping') > ids.indexOf('notes-favourites') && ids.indexOf('shopping') < ids.indexOf('deleting') }).toEqual({ locale, ok: true });
        }
        const shapes = LOCALES.map((l) => manual[l].sections.find((s) => s.id === 'shopping').blocks.map((b) => `${b.type}${b.items ? b.items.length : ''}`).join(','));
        expect(new Set(shapes).size, 'shopping blocks differ between languages').toBe(1);
    });

    it('registers the shopping help tip on its own screen with a text in every language', () => {
        const topics = JSON.parse(readFileSync(`${root}/resources/help/topics.json`, 'utf8')).topics;
        expect(topics.find((t) => t.key === 'recipes.shopping')).toEqual({ key: 'recipes.shopping', area: 'recipes', screen: 'recipe_shopping', field: 'dialog_title' });
        for (const locale of LOCALES) {
            const help = JSON.parse(readFileSync(`${root}/resources/help/${locale}.json`, 'utf8'));
            expect(help.screens.recipe_shopping?.trim()).toBeTruthy();
            expect([...help.topics['recipes.shopping'].text].length).toBeLessThanOrEqual(400);
        }
    });
});

describe('phase 12 import UI strings, help and manual (12-15)', () => {
    const manual = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(readFileSync(`${root}/resources/manual/${l}/recipes.json`, 'utf8'))]));
    const admin = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(readFileSync(`${root}/resources/manual/${l}/admin.json`, 'utf8'))]));

    it('has the admin label for recipe runs in every language', () => {
        for (const locale of LOCALES) expect({ locale, v: get(lang[locale], 'settings.ai_calls.kind_recipe')?.trim() }).toEqual({ locale, v: expect.any(String) });
        expect(get(lang.en, 'settings.ai_calls.kind_recipe')).toBe('Recipe import');
    });

    it('states in every language that the page text goes to the AI provider', () => {
        for (const locale of LOCALES) {
            expect(get(lang[locale], 'recipes.import.notice_body'), locale).toBeTruthy();
            expect(get(lang[locale], 'recipes.import.hint'), locale).toBeTruthy();
        }
        expect(get(lang.en, 'recipes.import.notice_body')).toContain('text of the page is sent to the AI provider');
    });

    it('has import-from-link with the same block types in every language, and the admin guide a recipe import paragraph', () => {
        const types = LOCALES.map((l) => manual[l].sections.find((s) => s.id === 'import-from-link').blocks.map((b) => b.type).join(','));
        expect(new Set(types).size).toBe(1);
        for (const locale of LOCALES) {
            const section = admin[locale].sections.find((s) => s.id === 'ai-agents');
            expect({ locale, ok: JSON.stringify(section).includes('/settings/ai-calls') }).toEqual({ locale, ok: true });
        }
    });

    it('registers the import help tip on its own screen with a text in every language', () => {
        const topics = JSON.parse(readFileSync(`${root}/resources/help/topics.json`, 'utf8')).topics;
        expect(topics.find((t) => t.key === 'recipes.import')).toEqual({ key: 'recipes.import', area: 'recipes', screen: 'recipe_import', field: 'url_step' });
        for (const locale of LOCALES) {
            const help = JSON.parse(readFileSync(`${root}/resources/help/${locale}.json`, 'utf8'));
            expect(help.screens.recipe_import?.trim()).toBeTruthy();
            expect([...help.topics['recipes.import'].text].length).toBeLessThanOrEqual(400);
        }
    });
});
