import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { createAppI18n } from '../../resources/js/i18n.js';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
const messages = { sk: load('sk'), pl: load('pl'), en: load('en') };
const NAMESPACES = ['todo', 'notifications'];
const PLURALS = ['todo.column.count', 'todo.repeat.summary_days', 'todo.repeat.summary_weeks', 'todo.repeat.summary_months'];

function flatten(obj, prefix = '') {
    return Object.entries(obj).flatMap(([k, v]) =>
        typeof v === 'object' && v !== null ? flatten(v, `${prefix}${k}.`) : [[`${prefix}${k}`, v]],
    );
}
const phase3 = (l) =>
    Object.fromEntries(flatten(messages[l]).filter(([k]) => NAMESPACES.includes(k.split('.')[0])));
const placeholders = (s) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();
const make = (l) => createAppI18n(l, messages[l]).global;
const segs = (l, key) => phase3(l)[key].split('|').length;

describe('Phase 3 copy parity', () => {
    const en = phase3('en');

    it('has keys in every namespace', () => {
        for (const ns of NAMESPACES) expect(Object.keys(en).some((k) => k.startsWith(`${ns}.`))).toBe(true);
    });

    it.each(['sk', 'pl'])('%s has exactly the en key set, all non-empty', (l) => {
        const other = phase3(l);
        expect(Object.keys(other).sort()).toEqual(Object.keys(en).sort());
        for (const [k, v] of Object.entries(other)) expect(typeof v === 'string' && v.trim().length > 0, k).toBe(true);
    });

    it.each(['sk', 'pl'])('%s keeps every placeholder of en', (l) => {
        const other = phase3(l);
        for (const [k, v] of Object.entries(en)) {
            expect([...new Set(placeholders(other[k]))], k).toEqual([...new Set(placeholders(v))]);
        }
    });

    it.each(['en', 'sk', 'pl'])('%s has no exclamation marks', (l) => {
        for (const [k, v] of Object.entries(phase3(l))) expect(v.includes('!'), k).toBe(false);
    });

    it.each(['en', 'sk', 'pl'])('%s strings compile and render with params', (l) => {
        const i18n = make(l);
        const params = new Proxy({}, { get: (_, p) => (typeof p === 'string' ? `<${p}>` : undefined) });
        for (const [k, v] of Object.entries(phase3(l))) {
            const out = PLURALS.includes(k) ? i18n.t(k, { n: 2 }, 2) : i18n.t(k, params);
            expect(out, k).not.toBe(k);
            expect(out.includes('{'), k).toBe(false);
        }
    });

    it('keeps server-only strings out of the JSON', () => {
        expect(en['notifications.reminder_title']).toBeUndefined();
        expect(en['notifications.email_body']).toBeUndefined();
    });

    it('todo.delete is an object with an action key', () => {
        expect(typeof messages.en.todo.delete).toBe('object');
        expect(messages.en.todo.delete.action).toBe('Delete task');
    });
});

describe('Phase 3 plural forms', () => {
    it.each(PLURALS)('%s has 2 segments in en and 4 in sk and pl', (key) => {
        expect(segs('en', key)).toBe(2);
        expect(segs('sk', key)).toBe(4);
        expect(segs('pl', key)).toBe(4);
    });

    const t = (l, n) => make(l).t('todo.column.count', { n }, n);
    it.each([
        ['en', 1, '1 open task'],
        ['en', 5, '5 open tasks'],
        ['sk', 1, '1 otvorená úloha'],
        ['sk', 2, '2 otvorené úlohy'],
        ['sk', 5, '5 otvorených úloh'],
        ['pl', 1, '1 otwarte zadanie'],
        ['pl', 2, '2 otwarte zadania'],
        ['pl', 5, '5 otwartych zadań'],
    ])('%s with %i', (l, n, expected) => expect(t(l, n)).toBe(expected));
});
