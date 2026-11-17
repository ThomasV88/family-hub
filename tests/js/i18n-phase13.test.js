import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';

const root = process.cwd();
const LOCALES = ['en', 'sk', 'pl'];
const read = (path) => JSON.parse(readFileSync(`${root}/${path}`, 'utf8'));
const lang = Object.fromEntries(LOCALES.map((l) => [l, read(`lang/${l}.json`)]));
const get = (obj, path) => path.split('.').reduce((o, k) => (o && typeof o === 'object' ? o[k] : undefined), obj);
const leaves = (obj, prefix = '') => Object.entries(obj).flatMap(([k, v]) => (v !== null && typeof v === 'object' ? leaves(v, `${prefix}${k}.`) : [`${prefix}${k}`]));

// Later Phase 13 UI plans append their subtrees here.
const SUBTREES = ['health.search', 'health.document_text'];
// Strings that are plural in en: the number of forms per locale (en 2, sk 4, pl 4).
const FORMS = { en: 2, sk: 4, pl: 4 };
const PLURALS = ['health.search.heading'];
const placeholders = (s) => [...new Set([...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]))].sort();

describe('phase 13 locale parity (lang)', () => {
    for (const subtree of SUBTREES) {
        it(`${subtree}: every key exists and is non-empty in en, sk and pl, and sk and pl add nothing`, () => {
            const keys = leaves(get(lang.en, subtree)).map((k) => `${subtree}.${k}`);
            expect(keys.length).toBeGreaterThan(0);
            for (const locale of LOCALES) {
                const missing = keys.filter((k) => { const v = get(lang[locale], k); return typeof v !== 'string' || v.trim() === ''; });
                expect({ locale, missing }).toEqual({ locale, missing: [] });
            }
            const en = new Set(leaves(get(lang.en, subtree)));
            for (const locale of ['sk', 'pl']) {
                expect({ locale, extra: leaves(get(lang[locale], subtree) ?? {}).filter((k) => !en.has(k)) }).toEqual({ locale, extra: [] });
            }
        });

        it(`${subtree}: placeholders match in every language`, () => {
            for (const key of leaves(get(lang.en, subtree)).map((k) => `${subtree}.${k}`)) {
                const base = placeholders(get(lang.en, key));
                for (const locale of ['sk', 'pl']) expect({ locale, key, p: placeholders(get(lang[locale], key)) }).toEqual({ locale, key, p: base });
            }
        });
    }

    it.each(PLURALS)('%s has the locale number of plural forms', (key) => {
        for (const locale of LOCALES) expect({ locale, forms: get(lang[locale], key).split(' | ').length }).toEqual({ locale, forms: FORMS[locale] });
    });

    it('carries the placeholders the components pass ({n}, {total}, {title})', () => {
        expect(placeholders(get(lang.en, 'health.search.heading'))).toEqual(['n']);
        expect(placeholders(get(lang.en, 'health.document_text.tag_partial'))).toEqual(['n', 'total']);
        expect(placeholders(get(lang.en, 'health.document_text.partial_alert'))).toEqual(['n', 'total']);
        expect(placeholders(get(lang.en, 'health.document_text.region_aria'))).toEqual(['title']);
    });

    it('has no leftover English in the sk and pl search and text copy', () => {
        for (const locale of ['sk', 'pl']) {
            for (const subtree of SUBTREES) {
                for (const key of leaves(get(lang.en, subtree)).map((k) => `${subtree}.${k}`)) {
                    if (['health.search.submit', 'health.search.page'].includes(key)) continue;
                    expect({ locale, key, same: get(lang[locale], key) === get(lang.en, key) }).toEqual({ locale, key, same: false });
                }
            }
        }
    });
});

describe('phase 13 help tips (13-05)', () => {
    const topics = read('resources/help/topics.json').topics;
    const files = Object.fromEntries(LOCALES.map((l) => [l, read(`resources/help/${l}.json`)]));

    it('registers health.search and health.document_text on their own screens', () => {
        expect(topics.find((t) => t.key === 'health.search')).toEqual({ key: 'health.search', area: 'health', screen: 'document_search', field: 'search' });
        expect(topics.find((t) => t.key === 'health.document_text')).toEqual({ key: 'health.document_text', area: 'health', screen: 'document_text', field: 'recognised_text' });
    });

    it('has a screen label and a text of at most 400 characters in every language', () => {
        for (const locale of LOCALES) {
            for (const screen of ['document_search', 'document_text']) expect(files[locale].screens[screen]?.trim(), `${locale} ${screen}`).toBeTruthy();
            for (const key of ['health.search', 'health.document_text']) {
                const text = files[locale].topics[key]?.text ?? '';
                expect({ locale, key, ok: text.trim() !== '' && [...text].length <= 400 }).toEqual({ locale, key, ok: true });
                expect(files[locale].topics[key].title.trim()).not.toBe('');
            }
        }
    });

    it('is placed in the components: next to the idle hint and next to the Recognised text title', () => {
        expect(readFileSync(`${root}/resources/js/Health/Search/DocumentSearch.vue`, 'utf8')).toMatch(/<HelpTip topic="health\.search"/);
        expect(readFileSync(`${root}/resources/js/Health/Text/RecognisedText.vue`, 'utf8')).toMatch(/<HelpTip topic="health\.document_text"/);
    });

    it('says in every language where the typed words go and who can read the text', () => {
        expect(files.en.topics['health.search'].text).toContain('not saved');
        expect(files.en.topics['health.document_text'].text).toContain('Everyone who may open the document');
    });
});

describe('phase 13 manual (13-05)', () => {
    const manual = Object.fromEntries(LOCALES.map((l) => [l, read(`resources/manual/${l}/health.json`)]));
    const ids = (l) => manual[l].sections.map((s) => s.id);
    const links = (l, id) => [...JSON.stringify(manual[l].sections.find((s) => s.id === id)).matchAll(/\]\(([^)]+)\)/g)].map((m) => m[1]);
    const shape = (l, id) => manual[l].sections.find((s) => s.id === id).blocks.map((b) => `${b.type}${b.items ? b.items.length : ''}`).join(',');

    it('has the same section ids in the same order in every language, with search and recognised-text right after documents', () => {
        for (const locale of LOCALES) {
            expect(ids(locale)).toEqual(ids('en'));
            const at = ids(locale).indexOf('documents');
            expect({ locale, next: ids(locale).slice(at + 1, at + 3) }).toEqual({ locale, next: ['search', 'recognised-text'] });
        }
    });

    it('keeps the same block shapes and the same links in every language', () => {
        for (const id of ['search', 'recognised-text']) {
            expect(new Set(LOCALES.map((l) => shape(l, id))).size, `${id} block shapes differ`).toBe(1);
            for (const l of ['sk', 'pl']) expect(links(l, id), `${l} ${id} links`).toEqual(links('en', id));
        }
        expect(links('en', 'recognised-text')).toEqual(['health#ai']);
    });

    it('covers the facts the plan lists in the English source', () => {
        const search = JSON.stringify(manual.en.sections.find((s) => s.id === 'search'));
        for (const fact of ['at least two letters', 'All people', 'name only', 'may open', 'may contain mistakes', 'Only the first pages are searched', 'not saved']) expect(search, fact).toContain(fact);
        const text = JSON.stringify(manual.en.sections.find((s) => s.id === 'recognised-text'));
        for (const fact of ['Copy text', 'Remove recognised text', 'Copies in backups', 'deleted with the document', 'archived', 'turned off for a person', 'First 3 of 8 pages']) expect(text, fact).toContain(fact);
    });

    it('keeps the existing sections (including ai) untouched in id', () => {
        expect(ids('en')).toEqual(expect.arrayContaining(['people-access', 'visits', 'medications', 'allergies', 'vaccinations', 'documents', 'exports', 'emergency-card', 'archive-delete', 'ai']));
    });
});

describe('phase 13 sources (13-05)', () => {
    it('uses no raw health.search or health.document_text key that the language files lack', () => {
        for (const dir of ['resources/js/Health/Search', 'resources/js/Health/Text']) {
            for (const file of readdirSync(`${root}/${dir}`)) {
                const src = readFileSync(`${root}/${dir}/${file}`, 'utf8');
                for (const m of src.matchAll(/t\('(health\.(?:search|document_text)\.[a-z_]+)'/g)) {
                    for (const locale of LOCALES) expect({ file, key: m[1], locale, ok: typeof get(lang[locale], m[1]) === 'string' }).toEqual({ file, key: m[1], locale, ok: true });
                }
            }
        }
    });
});

// Phase 13 final parity (13-10): the keys of 13-08 and 13-09 and the help and manual files they touched.
describe('phase 13 final parity: notice, run states, consent sentence, bulk no_text (13-08, 13-09)', () => {
    const KEYS = [
        'health.ai.run.notice_text_body', 'health.ai.run.provider_text_codex', 'health.ai.run.provider_text_both', 'health.ai.run.provider_text_copilot',
        'health.ai.notice.multi_text_body', 'health.ai.notice.github_text_body',
        'health.ai.status.text_saved', 'health.ai.status.text_saved_detail', 'health.ai.status.text_saved_preparing',
        'health.ai.status.waiting_text_saved', 'health.ai.status.failed_text_saved', 'health.ai.status.announce_text_saved',
        'health.ai.off_title', 'health.ai.off_text_purge', 'health.ai.off_confirm', 'health.ai.off_keep',
        'settings.ai_bulk.scope_no_text', 'settings.ai_bulk.scope_no_text_hint', 'settings.ai_bulk.no_text_note',
        'settings.ai_bulk.zero_eligible_no_text', 'settings.ai_bulk.history_text_only',
        'audit.detail.medical_text_removed',
    ];

    it('every key exists and is non-empty in en, sk and pl, with the same placeholders', () => {
        for (const key of KEYS) {
            const base = placeholders(get(lang.en, key) ?? '');
            for (const locale of LOCALES) {
                const v = get(lang[locale], key);
                expect({ locale, key, ok: typeof v === 'string' && v.trim() !== '' }).toEqual({ locale, key, ok: true });
                expect({ locale, key, p: placeholders(v) }).toEqual({ locale, key, p: base });
            }
        }
    });

    it('sk and pl are not left in English', () => {
        for (const key of KEYS) {
            for (const locale of ['sk', 'pl']) expect({ locale, key, same: get(lang[locale], key) === get(lang.en, key) }).toEqual({ locale, key, same: false });
        }
    });

    it('has no ICU plural strings among them (so no plural form count to check)', () => {
        for (const key of KEYS) expect({ key, plural: get(lang.en, key).includes(' | ') }).toEqual({ key, plural: false });
    });

    it('keeps the failed run line consistent: the text line says only the suggestions are missing', () => {
        expect(get(lang.en, 'health.ai.status.failed_text_saved')).toContain('Only the suggestions are missing');
    });
});

// Phase 13 review fixes: the run lines for "text saved, no suggestions", the bulk notice bodies, the dropped-word hint and
// the plural forms of the partial-page strings (sk and pl have four forms like the other counted strings, en has two).
describe('phase 13 review keys', () => {
    const SINGLE = [
        'health.ai.status.failed_suggestions', 'health.ai.status.failed_suggestions_detail', 'health.ai.status.quota_timeout_suggestions_detail',
        'health.ai.status.quota_exhausted_suggestions_detail', 'health.ai.status.cancelled_suggestions_detail', 'health.ai.status.consent_suggestions_detail',
        'health.ai.status.announce_failed_suggestions',
        'settings.ai_bulk.text_notice_codex', 'settings.ai_bulk.text_notice_multi', 'settings.ai_bulk.text_notice_github',
    ];
    const PLURAL = ['health.search.partial', 'health.search.dropped_hint', 'health.document_text.tag_partial', 'health.document_text.partial_alert'];

    it('exist in en, sk and pl, are translated and keep the placeholders', () => {
        for (const key of [...SINGLE, ...PLURAL]) {
            const base = placeholders(get(lang.en, key) ?? '');
            for (const locale of LOCALES) {
                const v = get(lang[locale], key);
                expect({ locale, key, ok: typeof v === 'string' && v.trim() !== '' }).toEqual({ locale, key, ok: true });
            }
            for (const locale of ['sk', 'pl']) {
                expect({ locale, key, same: get(lang[locale], key) === get(lang.en, key) }).toEqual({ locale, key, same: false });
                expect({ locale, key, p: placeholders(get(lang[locale], key)) }).toEqual({ locale, key, p: base });
            }
        }
    });

    it('has two plural forms in en and four in sk and pl for the counted strings, the single strings none', () => {
        for (const key of PLURAL) {
            expect({ key, n: get(lang.en, key).split(' | ').length }).toEqual({ key, n: 2 });
            for (const locale of ['sk', 'pl']) expect({ locale, key, n: get(lang[locale], key).split(' | ').length }).toEqual({ locale, key, n: 4 });
        }
        for (const key of SINGLE) expect({ key, plural: get(lang.en, key).includes(' | ') }).toEqual({ key, plural: false });
    });

    it('says in the saved-text lines that no suggestions were saved and never "Nothing was saved"', () => {
        for (const key of SINGLE.filter((k) => k.endsWith('_suggestions_detail'))) {
            expect(get(lang.en, key)).toContain('No suggestions were saved');
            expect(get(lang.en, key)).not.toContain('Nothing was saved');
        }
    });
});

describe('phase 13 final parity: help and manual (13-05, 13-08, 13-09)', () => {
    const topics = read('resources/help/topics.json').topics;
    const files = Object.fromEntries(LOCALES.map((l) => [l, read(`resources/help/${l}.json`)]));

    it('registers and writes in every language the four Phase 13 help topics, each at most 400 characters', () => {
        for (const key of ['health.search', 'health.document_text', 'health.ai_run', 'settings.ai_bulk']) {
            expect(topics.find((t) => t.key === key), key).toBeTruthy();
            for (const locale of LOCALES) {
                const entry = files[locale].topics[key];
                expect({ locale, key, ok: !!entry && entry.title.trim() !== '' && entry.text.trim() !== '' && [...entry.text].length <= 400 }).toEqual({ locale, key, ok: true });
            }
        }
    });

    it('keeps identical section ids in the same order in health.json and admin.json in en, sk and pl', () => {
        for (const file of ['health', 'admin']) {
            const ids = (l) => read(`resources/manual/${l}/${file}.json`).sections.map((s) => s.id);
            for (const locale of ['sk', 'pl']) expect({ file, locale, ids: ids(locale) }).toEqual({ file, locale, ids: ids('en') });
        }
    });

    it('keeps the same block shapes in the touched admin sections (backups, ai-agents) and the health ai section', () => {
        const shape = (l, file, id) => read(`resources/manual/${l}/${file}.json`).sections.find((s) => s.id === id).blocks.map((b) => `${b.type}${b.items ? b.items.length : ''}`).join(',');
        for (const [file, id] of [['admin', 'backups'], ['admin', 'ai-agents'], ['health', 'ai'], ['health', 'recognised-text']]) {
            expect(new Set(LOCALES.map((l) => shape(l, file, id))).size, `${file}/${id}`).toBe(1);
        }
    });
});
