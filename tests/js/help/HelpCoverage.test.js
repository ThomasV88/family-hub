import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { readHelpLocale, readTopics } from '../helpers/help.js';

// Coverage of the contextual help registry (resources/help): no tip in a template points at a missing topic, no topic is dead,
// every language has a usable text for every key, every key has a placement.
const LOCALES = ['en', 'sk', 'pl'];
const MAX = 400;
const JS = `${process.cwd()}/resources/js`;

function vueFiles(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) return vueFiles(path);
        return path.endsWith('.vue') ? [path] : [];
    });
}

// Every literal topic a template hands to a HelpTip: topic="x", help="x" (UiField) and quoted keys inside :help="...".
function usedTopics() {
    const used = new Map();
    const add = (key, file) => used.set(key, [...(used.get(key) ?? []), file.replace(`${JS}/`, '')]);
    for (const file of vueFiles(JS)) {
        if (file.endsWith('Components/ui/HelpTip.vue')) continue;
        const src = readFileSync(file, 'utf8');
        for (const m of src.matchAll(/(?<![:\w-])topic="([^"]+)"/g)) add(m[1], file);
        for (const m of src.matchAll(/(?<![:\w-])help="([^"]+)"/g)) add(m[1], file);
        for (const m of src.matchAll(/:help="([^"]*)"/g)) for (const q of m[1].matchAll(/'([a-z0-9_]+(?:\.[a-z0-9_]+)+)'/g)) add(q[1], file);
    }
    return used;
}

const topics = readTopics();
const keys = topics.map((t) => t.key);
const files = Object.fromEntries(LOCALES.map((l) => [l, readHelpLocale(l)]));

describe('help registry', () => {
    it('has unique, well-formed keys and a complete placement for every topic', () => {
        expect(new Set(keys).size).toBe(keys.length);
        expect(keys.length).toBeGreaterThanOrEqual(40);
        for (const t of topics) {
            expect(t.key, t.key).toMatch(/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/);
            expect(t.key.length).toBeLessThanOrEqual(120);
            for (const f of ['area', 'screen', 'field']) {
                expect(typeof t[f] === 'string' && t[f].length > 0, `${t.key} has ${f}`).toBe(true);
                expect(t[f].length).toBeLessThanOrEqual(f === 'area' ? 40 : 60);
            }
        }
        const places = topics.map((t) => `${t.area}/${t.screen}/${t.field}`);
        expect(new Set(places).size).toBe(places.length);
    });

    it.each(LOCALES)('has a non-empty title and a text within the limit for every key in %s, and nothing extra', (l) => {
        expect(Object.keys(files[l].topics).sort()).toEqual([...keys].sort());
        for (const k of keys) {
            const e = files[l].topics[k];
            expect(e.title.trim().length, `${l} ${k} title`).toBeGreaterThan(0);
            expect(e.text.trim().length, `${l} ${k} text`).toBeGreaterThan(0);
            expect([...e.text].length, `${l} ${k} length`).toBeLessThanOrEqual(MAX);
            expect(e.text, `${l} ${k} plain text`).not.toMatch(/[<>]/);
            expect(e.text, `${l} ${k} no markdown`).not.toMatch(/(\*\*|__|`|\]\()/);
        }
    });

    it.each(LOCALES)('names every area and screen in %s', (l) => {
        for (const t of topics) {
            expect(files[l].areas[t.area]?.trim(), `${l} area ${t.area}`).toBeTruthy();
            expect(files[l].screens[t.screen]?.trim(), `${l} screen ${t.screen}`).toBeTruthy();
        }
    });

    it('has the same area and screen ids in every language', () => {
        for (const l of ['sk', 'pl']) {
            expect(Object.keys(files[l].areas).sort()).toEqual(Object.keys(files.en.areas).sort());
            expect(Object.keys(files[l].screens).sort()).toEqual(Object.keys(files.en.screens).sort());
        }
    });
});

describe('help topics used in templates', () => {
    const used = usedTopics();

    it('every topic used in a Vue template exists in the registry', () => {
        const unknown = [...used.keys()].filter((k) => !keys.includes(k));
        expect(unknown, `unknown topics: ${unknown.join(', ')}`).toEqual([]);
    });

    it('every registry key is used at least once (no dead tips)', () => {
        const dead = keys.filter((k) => !used.has(k));
        expect(dead, `dead topics: ${dead.join(', ')}`).toEqual([]);
    });

    it('sees the tips (guards the scanner itself)', () => {
        expect(used.size).toBeGreaterThanOrEqual(40);
        expect(used.get('tasks.recurrence')).toContain('Todo/RecurrenceFields.vue');
        expect(used.get('settings.reconfirm_idle')).toContain('Components/Settings/SecurityTimingsCard.vue');
    });
});
