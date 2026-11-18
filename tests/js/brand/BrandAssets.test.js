import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(__dirname, '../../..');
const p = (...parts) => resolve(root, ...parts);
const kit = p('.planning/phases/14-rebranding-issue-13/brand-kit');

const ihdr = (file) => {
    const b = readFileSync(file);
    return { w: b.readUInt32BE(16), h: b.readUInt32BE(20), colorType: b[25] };
};

const PNGS = {
    'familyhub-192x192.png': [192, 192],
    'familyhub-512x512.png': [512, 512],
    'familyhub-maskable-512x512.png': [512, 512],
    'familyhub-apple-touch-180x180.png': [180, 180],
};
const SVGS = {
    'familyhub-mark.svg': 'icon.svg',
    'familyhub-logo.svg': 'primary.svg',
    'familyhub-logo-dark.svg': 'mono-for-dark-bg.svg',
};

describe('brand assets', () => {
    it('keeps exactly the seven familyhub files in public/icons', () => {
        const expected = [...Object.keys(PNGS), ...Object.keys(SVGS)].sort();
        expect(readdirSync(p('public/icons')).sort()).toEqual(expected);
    });

    it.each(Object.entries(PNGS))('%s has the right size', (name, [w, h]) => {
        const r = ihdr(p('public/icons', name));
        expect([r.w, r.h]).toEqual([w, h]);
    });

    it.each(['familyhub-maskable-512x512.png', 'familyhub-apple-touch-180x180.png'])('%s is opaque RGB', (name) => {
        expect(ihdr(p('public/icons', name)).colorType).toBe(2);
    });

    it('favicon.ico equals the kit favicon', () => {
        expect(readFileSync(p('public/favicon.ico')).equals(readFileSync(`${kit}/favicon/favicon.ico`))).toBe(true);
    });

    it.each(Object.entries(SVGS))('%s is safe and keeps the kit viewBox', (name, src) => {
        const svg = readFileSync(p('public/icons', name), 'utf8');
        expect(svg).not.toMatch(/<script/i);
        expect(svg).not.toMatch(/\son[a-z]+\s*=/i);
        expect(svg).not.toMatch(/href\s*=/i);
        expect(svg).not.toContain('var(--');
        expect(svg).toContain('<title>FamilyHUB</title>');
        const vb = (s) => s.match(/viewBox="([^"]*)"/)[1];
        expect(vb(svg)).toBe(vb(readFileSync(`${kit}/logo/${src}`, 'utf8')));
    });
});

describe('manifest, head and service worker wiring', () => {
    const vite = readFileSync(p('vite.config.js'), 'utf8');
    const blade = readFileSync(p('resources/views/app.blade.php'), 'utf8');
    const sw = readFileSync(p('resources/js/sw.js'), 'utf8');

    it('manifest is named FamilyHUB and its icons exist', () => {
        expect(vite).toContain("name: 'FamilyHUB'");
        const srcs = [...vite.matchAll(/src:\s*'([^']+)'/g)].map((m) => m[1]);
        expect(srcs.length).toBe(3);
        for (const s of srcs) expect(existsSync(p('public', s.replace(/^\//, '')))).toBe(true);
    });

    it('app.blade.php references existing icons and a versioned favicon', () => {
        const links = [...blade.matchAll(/href="(\/icons\/[^"]+)"/g)].map((m) => m[1]);
        expect(links.length).toBeGreaterThan(0);
        for (const l of links) expect(existsSync(p('public', l.replace(/^\//, '')))).toBe(true);
        expect(blade).toContain('favicon.ico?v=');
    });

    it('sw.js uses the new icon', () => {
        expect(sw).not.toContain('pwa-192x192');
        expect(sw).toContain('familyhub-192x192');
    });
});
