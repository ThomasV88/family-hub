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

const SUBTREES = ['profile', 'settings.members.child', 'settings.members.convert', 'profile.people'];

describe('phase 10 locale parity (lang)', () => {
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
    }

    it('the new audit detail entries exist in all three locales', () => {
        for (const key of ['profile_changed_photo', 'profile_changed_name', 'profile_changed_other', 'child_created', 'child_converted']) {
            for (const locale of LOCALES) {
                const v = get(lang[locale], `audit.detail.${key}`);
                expect({ locale, key, ok: typeof v === 'string' && v.trim() !== '' }).toEqual({ locale, key, ok: true });
            }
        }
    });
});

describe('phase 10 help texts', () => {
    for (const topic of ['profile.photo', 'profile.name', 'members.child', 'members.convert']) {
        it(`${topic} has a title and a text in en, sk and pl`, () => {
            for (const locale of LOCALES) {
                const entry = help[locale].topics?.[topic];
                expect({ locale, topic, title: !!entry?.title?.trim(), text: !!entry?.text?.trim() }).toEqual({ locale, topic, title: true, text: true });
            }
        });
    }
});
