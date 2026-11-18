import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { resolve, join, relative } from 'node:path';

const root = resolve(__dirname, '../../..');
const OLD = 'Family Hub';

const walk = (dir) => {
    if (!existsSync(dir)) return [];
    return readdirSync(dir).flatMap((name) => {
        const full = join(dir, name);
        return statSync(full).isDirectory() ? walk(full) : [full];
    });
};

const files = [
    ...['lang', 'resources/manual', 'resources/help', 'resources/js', 'resources/views'].flatMap((d) =>
        walk(resolve(root, d)),
    ),
    ...['public/offline.html', 'vite.config.js', 'README.md'].map((f) => resolve(root, f)),
];

describe('product name', () => {
    it('does not contain the old name in user-visible sources', () => {
        const offenders = files
            .filter((f) => existsSync(f) && readFileSync(f, 'utf8').includes(OLD))
            .map((f) => relative(root, f));
        expect(offenders, `old name "${OLD}" found in: ${offenders.join(', ')}`).toEqual([]);
    });

    it.each(['lang/en.json', 'lang/sk.json', 'lang/pl.json', 'resources/manual/en/getting-started.json'])(
        '%s contains FamilyHUB',
        (f) => {
            expect(readFileSync(resolve(root, f), 'utf8')).toContain('FamilyHUB');
        },
    );
});
