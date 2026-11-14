import { describe, it, expect } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { checkPrecache } from '../../../scripts/check-sw-precache.mjs';

const root = process.cwd();
const source = readFileSync(`${root}/resources/js/sw.js`, 'utf8');
const built = existsSync(`${root}/public/sw.js`) ? readFileSync(`${root}/public/sw.js`, 'utf8') : null;
const HEALTH = /\/?health/i;

describe('service worker and health', () => {
    it('registers no runtime caching route in the worker source', () => {
        expect(source).not.toMatch(/registerRoute|runtimeCaching|NetworkFirst|StaleWhileRevalidate|CacheFirst|cache\.put|caches\.open/);
    });

    it('mentions no health URL at all in the worker source', () => {
        expect(source).not.toMatch(HEALTH);
    });

    it('lists no health URL in the built precache manifest (when a build exists)', () => {
        if (built === null) return;
        const r = checkPrecache({ swSource: source, swBuilt: built });
        expect(r.violations).toEqual([]);
        expect(r.urls.filter((u) => HEALTH.test(u))).toEqual([]);
    });

    it('the precache check itself rejects a health page, a document file and a health route', () => {
        const clean = `import { precacheAndRoute } from 'workbox-precaching';\nprecacheAndRoute(self.__WB_MANIFEST);`;
        for (const url of ['health', '/health', 'health/people/3f2a', 'health/people/3f2a/documents/1/file', 'health/people/3f2a/emergency-card']) {
            const r = checkPrecache({ swSource: clean, swBuilt: `var x=[{url:"offline.html",revision:null},{url:"${url}",revision:null}]` });
            expect(r.ok).toBe(false);
            expect(r.violations.join('\n')).toMatch(/health or finance url/);
        }
        expect(checkPrecache({ swSource: `${clean}\nregisterRoute(/health/, h)`, swBuilt: 'var x=[{url:"offline.html"}]' }).ok).toBe(false);
    });

    it('the precache check rejects the Phase 8 AI urls: status poll, review page and page images', () => {
        const clean = `import { precacheAndRoute } from 'workbox-precaching';\nprecacheAndRoute(self.__WB_MANIFEST);`;
        for (const url of [
            'health/people/3f2a/ai-status',
            '/health/people/3f2a/documents/9b1c/review',
            'health/people/3f2a/documents/9b1c/pages/1',
            '/health/people/3f2a/ai/77aa/entries/visit-0/accept',
        ]) {
            const r = checkPrecache({ swSource: clean, swBuilt: `var x=[{url:"offline.html",revision:null},{url:"${url}",revision:null}]` });
            expect(r.ok, url).toBe(false);
            expect(r.violations.join('\n')).toMatch(/health or finance url/);
        }
    });

    it('registers no runtime route that could match an AI url', () => {
        expect(source).not.toMatch(/ai-status|\/review|\/pages\/|ai-calls/);
        if (built !== null) {
            const r = checkPrecache({ swSource: source, swBuilt: built });
            expect(r.urls.filter((u) => /ai-status|review|pages\/|ai-calls/.test(u))).toEqual([]);
        }
    });
});
