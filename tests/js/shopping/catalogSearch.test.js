import { describe, it, expect } from 'vitest';
import { matches, normalize } from '../../../resources/js/Shopping/catalogSearch.js';

describe('catalog search', () => {
    it('normalises case, accents and surrounding space', () => {
        expect(normalize('  Mliečko ')).toBe('mliecko');
        expect(normalize('Żółć')).toBe('zolc');
        expect(normalize(null)).toBe('');
    });

    it('matches ignoring accents and case', () => {
        expect(matches('Mliečko', 'mliecko')).toBe(true);
        expect(matches('Jablká', 'JABLK')).toBe(true);
        expect(matches('Ser', 'ŠER')).toBe(true);
        expect(matches('Kiełbasa', 'kielb')).toBe(true);
    });

    it('matches any part of the name and refuses other text', () => {
        expect(matches('Ražný chlieb', 'chl')).toBe(true);
        expect(matches('Mlieko', 'chlieb')).toBe(false);
    });

    it('matches everything for an empty or blank query', () => {
        expect(matches('Mlieko', '')).toBe(true);
        expect(matches('Mlieko', '   ')).toBe(true);
    });
});
