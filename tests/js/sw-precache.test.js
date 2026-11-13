import { describe, it, expect } from 'vitest';
import { checkPrecache } from '../../scripts/check-sw-precache.mjs';

const cleanSource = `import { precacheAndRoute } from 'workbox-precaching';\nprecacheAndRoute(self.__WB_MANIFEST);`;
const built = (urls, quoted = false) =>
    `var x=[${urls.map((u) => (quoted ? `{"url":"${u}","revision":null}` : `{url:"${u}",revision:null}`)).join(',')}];precacheAndRoute(x)`;
const good = ['build/assets/app-abc123.js', 'icons/pwa-192x192.png', 'offline.html'];

describe('checkPrecache', () => {
    it('accepts allowlisted urls (unquoted and quoted keys)', () => {
        for (const quoted of [false, true]) {
            const r = checkPrecache({ swSource: cleanSource, swBuilt: built(good, quoted) });
            expect(r.ok).toBe(true);
            expect(r.urls).toEqual(good);
            expect(r.violations).toEqual([]);
        }
    });

    it.each(['/', 'index.php', 'api/finance', 'build/manifest.json'])('rejects url %s', (url) => {
        const r = checkPrecache({ swSource: cleanSource, swBuilt: built([...good, url]) });
        expect(r.ok).toBe(false);
        expect(r.violations.join(' ')).toContain(url);
    });

    it('rejects a source with registerRoute', () => {
        const r = checkPrecache({ swSource: `${cleanSource}\nregisterRoute(/x/, y)`, swBuilt: built(good) });
        expect(r.ok).toBe(false);
        expect(r.violations.join(' ')).toContain('registerRoute');
    });

    it('rejects a source with runtimeCaching', () => {
        const r = checkPrecache({ swSource: `${cleanSource}\nconst runtimeCaching = []`, swBuilt: built(good) });
        expect(r.ok).toBe(false);
        expect(r.violations.join(' ')).toContain('runtimeCaching');
    });

    it('fails when the built worker lists no urls', () => {
        expect(checkPrecache({ swSource: cleanSource, swBuilt: 'nothing' }).ok).toBe(false);
    });
});
