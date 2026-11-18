import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';

const root = process.cwd();
const LOCALES = ['en', 'sk', 'pl'];
const lang = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(readFileSync(`${root}/lang/${l}.json`, 'utf8'))]));
const get = (obj, path) => path.split('.').reduce((o, k) => (o && typeof o === 'object' ? o[k] : undefined), obj);
const leaves = (obj, prefix = '') => Object.entries(obj).flatMap(([k, v]) => (v !== null && typeof v === 'object' ? leaves(v, `${prefix}${k}.`) : [`${prefix}${k}`]));
const placeholders = (s) => [...new Set([...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]))].sort();

const SUBTREES = ['health.batch'];
const FORMS = { en: 2, sk: 4, pl: 4 };
const PLURALS = ['health.batch.state.review', 'health.batch.undecided'];
const AUDIT = ['created', 'rescan', 'continued', 'removed', 'deleted', 'expired'].map((a) => `audit.detail.medical_upload_batch_changed_${a}`);
const COMMON_KEYS = ['start', 'progress', 'expires', 'err_full', 'err_duplicate', 'err_closed', 'continue', 'finished', 'state.paused', 'state.review', 'delete_body', 'adjust_note'];

describe('phase 15 locale parity (lang)', () => {
    for (const subtree of SUBTREES) {
        it(`${subtree}: every key exists and is non-empty in en, sk and pl, and sk and pl add nothing`, () => {
            const keys = leaves(get(lang.en, subtree)).map((k) => `${subtree}.${k}`);
            expect(keys.length).toBeGreaterThan(60);
            for (const locale of LOCALES) {
                const missing = keys.filter((k) => { const v = get(lang[locale], k); return typeof v !== 'string' || v.trim() === ''; });
                expect({ locale, missing }).toEqual({ locale, missing: [] });
            }
            const en = new Set(leaves(get(lang.en, subtree)));
            for (const locale of ['sk', 'pl']) {
                expect({ locale, extra: leaves(get(lang[locale], subtree) ?? {}).filter((k) => !en.has(k)) }).toEqual({ locale, extra: [] });
            }
        });

        it(`${subtree}: placeholders match in every language and sk and pl are not left in English`, () => {
            for (const key of leaves(get(lang.en, subtree)).map((k) => `${subtree}.${k}`)) {
                const base = placeholders(get(lang.en, key));
                for (const locale of ['sk', 'pl']) {
                    expect({ locale, key, p: placeholders(get(lang[locale], key)) }).toEqual({ locale, key, p: base });
                    if (['health.batch.edit', 'health.batch.previous'].includes(key)) continue;
                    expect({ locale, key, same: get(lang[locale], key) === get(lang.en, key) }).toEqual({ locale, key, same: false });
                }
            }
        });
    }

    it.each(PLURALS)('%s has the locale number of plural forms', (key) => {
        for (const locale of LOCALES) expect({ locale, forms: get(lang[locale], key).split(' | ').length }).toEqual({ locale, forms: FORMS[locale] });
    });

    it('has no other plural strings in the subtree', () => {
        for (const key of leaves(get(lang.en, 'health.batch')).map((k) => `health.batch.${k}`).filter((k) => !PLURALS.includes(k))) {
            expect({ key, plural: get(lang.en, key).includes(' | ') }).toEqual({ key, plural: false });
        }
    });

    it('carries the placeholders the components pass', () => {
        expect(placeholders(get(lang.en, 'health.batch.progress'))).toEqual(['done', 'total']);
        expect(placeholders(get(lang.en, 'health.batch.open_banner_body'))).toEqual(['date', 'done', 'total']);
        expect(placeholders(get(lang.en, 'health.batch.state.paused'))).toEqual(['time']);
        expect(placeholders(get(lang.en, 'health.batch.continue_done'))).toEqual(['approved', 'rejected']);
        expect(placeholders(get(lang.en, 'health.batch.files_hint'))).toEqual(['count', 'max']);
        expect(placeholders(get(lang.en, 'health.batch.err_full'))).toEqual(['count']);
        for (const key of COMMON_KEYS) for (const locale of LOCALES) expect(get(lang[locale], `health.batch.${key}`), `${locale} ${key}`).toBeTruthy();
    });

    it('never contains the old app name', () => {
        for (const locale of LOCALES) expect(JSON.stringify(get(lang[locale], 'health.batch'))).not.toContain(['Family', 'Hub'].join(' '));
    });

    it('has the audit labels for every batch action in every language', () => {
        for (const key of AUDIT) {
            const base = placeholders(get(lang.en, key));
            for (const locale of LOCALES) {
                expect({ locale, key, ok: typeof get(lang[locale], key) === 'string' && get(lang[locale], key).trim() !== '' }).toEqual({ locale, key, ok: true });
                expect({ locale, key, p: placeholders(get(lang[locale], key)) }).toEqual({ locale, key, p: base });
            }
        }
    });
});
