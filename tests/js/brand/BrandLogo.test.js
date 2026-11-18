import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import BrandLogo from '../../../resources/js/Components/BrandLogo.vue';

const root = resolve(__dirname, '../../..');
const read = (...parts) => readFileSync(resolve(root, ...parts), 'utf8');

describe('BrandLogo', () => {
    it('renders the light and dark images with the same alt text', () => {
        const w = mount(BrandLogo, { props: { alt: 'FamilyHUB', height: 32 } });
        const light = w.find('.brand-logo__img--light');
        const dark = w.find('.brand-logo__img--dark');
        expect(light.attributes('src')).toBe('/icons/familyhub-logo.svg');
        expect(dark.attributes('src')).toBe('/icons/familyhub-logo-dark.svg');
        for (const img of [light, dark]) {
            expect(img.attributes('alt')).toBe('FamilyHUB');
            expect(img.attributes('height')).toBe('32');
            expect(img.attributes('decoding')).toBe('async');
        }
    });

    it('defaults the height to 32', () => {
        const w = mount(BrandLogo, { props: { alt: 'X' } });
        expect(w.find('img').attributes('height')).toBe('32');
    });

    it('has theme selectors mirroring tokens.css and no filter, shadow or opacity', () => {
        const src = read('resources/js/Components/BrandLogo.vue');
        expect(src).toContain(':root[data-theme="dark"]');
        expect(src).toContain('@media (prefers-color-scheme: dark)');
        expect(src).toContain(':root:not([data-theme="light"])');
        expect(src).not.toMatch(/filter\s*:|shadow\s*:|opacity\s*:/);
    });
});

describe('offline.html', () => {
    const html = read('public/offline.html');
    it('shows the FamilyHUB logo with a dark variant and brand colours', () => {
        expect(html).toContain('familyhub-logo-dark.svg');
        expect(html).toContain('prefers-color-scheme: dark');
        expect(html).toContain('alt="FamilyHUB"');
        expect(html).not.toContain('Family Hub');
        expect(html).not.toContain('#3b6ea8');
        expect(html).not.toContain('http');
    });
    it('references only existing icon files', () => {
        const paths = [...html.matchAll(/\/icons\/[\w.-]+/g)].map((m) => m[0]);
        expect(paths.length).toBeGreaterThan(0);
        for (const path of paths) expect(existsSync(resolve(root, 'public', path.slice(1)))).toBe(true);
    });
});
