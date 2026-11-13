import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const ALLOW = [
    /^build\/assets\/[^/]+\.(js|css|woff2)$/,
    /^icons\/[^/]+\.(png|svg)$/,
    /^offline\.html$/,
    /^manifest\.webmanifest$/,
];
const FORBIDDEN = ['registerRoute', 'runtimeCaching', 'NetworkFirst', 'StaleWhileRevalidate', 'CacheFirst', 'cache.put', 'caches.open'];

export function checkPrecache({ swSource, swBuilt }) {
    const violations = [];
    for (const token of FORBIDDEN) {
        if (swSource.includes(token)) violations.push(`forbidden token in worker source: ${token}`);
    }
    const raw = [...swBuilt.matchAll(/["']?url["']?\s*:\s*["']([^"']+)["']/g)].map((m) => m[1]);
    const urls = raw.map((u) => u.replace(/^\//, ''));
    if (urls.length === 0) violations.push('no precache urls found in built worker');
    urls.forEach((url, i) => {
        if (!ALLOW.some((re) => re.test(url))) violations.push(`precache url outside allowlist: ${raw[i]}`);
    });
    return { ok: violations.length === 0, urls, violations };
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    if (!existsSync('public/sw.js')) {
        console.error('SW-PRECACHE FAIL: public/sw.js is missing');
        process.exit(1);
    }
    const r = checkPrecache({
        swSource: readFileSync('resources/js/sw.js', 'utf8'),
        swBuilt: readFileSync('public/sw.js', 'utf8'),
    });
    console.log(r.urls.join('\n'));
    if (!r.ok) {
        console.error(`SW-PRECACHE FAIL:\n${r.violations.join('\n')}`);
        process.exit(1);
    }
    console.log('SW-PRECACHE OK');
}
