import { describe, it, expect } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { checkPrecache } from '../../../scripts/check-sw-precache.mjs';

const root = process.cwd();
const source = readFileSync(`${root}/resources/js/sw.js`, 'utf8');
const built = existsSync(`${root}/public/sw.js`) ? readFileSync(`${root}/public/sw.js`, 'utf8') : null;
const FINANCE = /\/?finance|settings\/finance-accounts/i;

describe('service worker and finance', () => {
    it('registers no runtime caching route in the worker source', () => {
        expect(source).not.toMatch(/registerRoute|runtimeCaching|NetworkFirst|StaleWhileRevalidate|CacheFirst|cache\.put|caches\.open/);
    });

    it('mentions no finance URL at all in the worker source', () => {
        expect(source).not.toMatch(FINANCE);
    });

    it('lists no finance URL in the built precache manifest (when a build exists)', () => {
        if (built === null) return;
        const r = checkPrecache({ swSource: source, swBuilt: built });
        expect(r.violations).toEqual([]);
        expect(r.urls.filter((u) => FINANCE.test(u))).toEqual([]);
    });

    it('the precache check itself rejects a finance page and a finance route', () => {
        const clean = `import { precacheAndRoute } from 'workbox-precaching';\nprecacheAndRoute(self.__WB_MANIFEST);`;
        for (const url of ['finance', 'finance/transactions', 'settings/finance-accounts']) {
            const r = checkPrecache({ swSource: clean, swBuilt: `var x=[{url:"offline.html",revision:null},{url:"${url}",revision:null}]` });
            expect(r.ok).toBe(false);
        }
        expect(checkPrecache({ swSource: `${clean}\nregisterRoute(/finance/, h)`, swBuilt: 'var x=[{url:"offline.html"}]' }).ok).toBe(false);
    });
});
