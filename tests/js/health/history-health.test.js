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
    // Phase 8: the AI review page keeps proposals and edit drafts, the AI calls page lists names of people.
    'resources/js/Pages/Settings/AiCalls.vue',
];

// Inertia remember/rememberKey would keep form state (diagnoses, notes, names) in the history entry, where Back or a
// shared device could resurrect it after logout. Health state is local refs only; clearHistory on logout and on
// confirm-access is asserted in the 07-04 tests and not repeated here.
describe('history state in health screens', () => {
    it('scans every health page, component and the print layout', () => {
        expect(sources.length).toBeGreaterThanOrEqual(25);
        expect(sources).toContain('resources/js/Pages/Health/Person.vue');
        expect(sources).toContain('resources/js/Layouts/PrintLayout.vue');
        expect(sources).toContain('resources/js/Pages/Health/AiReview.vue');
        expect(sources).toContain('resources/js/Pages/Settings/AiCalls.vue');
        expect(sources.filter((p) => p.startsWith('resources/js/Health/Ai/')).length).toBeGreaterThanOrEqual(5);
    });

    it.each(sources)('%s uses no remember or rememberKey', (path) => {
        const text = readFileSync(join(root, path), 'utf8');
        expect(text).not.toMatch(/\bremember(Key)?\b/);
        expect(text).not.toMatch(/useRemember/);
    });
});

// Review drafts (an edit in progress) live in component memory only: no web storage, no router history state.
describe('AI review screens keep nothing in browser storage or history state', () => {
    const ai = sources.filter((p) => p.startsWith('resources/js/Health/Ai/') || p.endsWith('AiReview.vue') || p.endsWith('AiCalls.vue'));

    it.each(ai)('%s uses no web storage and writes no history state', (path) => {
        const text = readFileSync(join(root, path), 'utf8');
        expect(text).not.toMatch(/\b(localStorage|sessionStorage|indexedDB)\b/);
        // The review page switches panes with replaceState that passes the existing state through, so it stores nothing of its own.
        const stripped = text.replaceAll('window.history.replaceState(window.history.state,', '');
        expect(stripped).not.toMatch(/history\.(pushState|replaceState)|\bhistory\.state\b/);
    });
});
