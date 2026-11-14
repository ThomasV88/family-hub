import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();

function files(path) {
    const full = join(root, path);
    if (statSync(full).isFile()) return [path];
    return readdirSync(full).flatMap((name) => files(join(path, name)));
}

const sources = [
    ...files('resources/js/Pages/Health'),
    ...files('resources/js/Health'),
    'resources/js/Layouts/PrintLayout.vue',
];

// Inertia remember/rememberKey would keep form state (diagnoses, notes, names) in the history entry, where Back or a
// shared device could resurrect it after logout. Health state is local refs only; clearHistory on logout and on
// confirm-access is asserted in the 07-04 tests and not repeated here.
describe('history state in health screens', () => {
    it('scans every health page, component and the print layout', () => {
        expect(sources.length).toBeGreaterThanOrEqual(25);
        expect(sources).toContain('resources/js/Pages/Health/Person.vue');
        expect(sources).toContain('resources/js/Layouts/PrintLayout.vue');
    });

    it.each(sources)('%s uses no remember or rememberKey', (path) => {
        const text = readFileSync(join(root, path), 'utf8');
        expect(text).not.toMatch(/\bremember(Key)?\b/);
        expect(text).not.toMatch(/useRemember/);
    });
});
