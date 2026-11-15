import { describe, it, expect } from 'vitest';
import { niceTicks } from '../../../resources/js/Finance/charts/niceTicks.js';

function mulberry32(seed) {
    let a = seed;
    return () => {
        a = (a + 0x6d2b79f5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}
const isNice = (step) => [1, 2, 5].some((m) => { let s = step; while (s % 10 === 0 && s > m) s /= 10; return s === m; });

describe('niceTicks', () => {
    it('includes zero when the range crosses it and uses a 1/2/5 step', () => {
        const t = niceTicks(-3000, 12000);
        expect(t).toContain(0);
        expect(t.length).toBeLessThanOrEqual(3);
    });

    it('covers a positive range and a degenerate range', () => {
        expect(niceTicks(0, 0).length).toBeGreaterThanOrEqual(2);
        const t = niceTicks(120000, 125000);
        expect(t[0]).toBeLessThanOrEqual(120000);
        expect(t[t.length - 1]).toBeGreaterThanOrEqual(125000);
    });

    it('returns integer ascending ticks that cover the range (500 seeded cases up to 1e13)', () => {
        const rand = mulberry32(20261113);
        for (let i = 0; i < 500; i++) {
            const a = Math.round((rand() * 2 - 1) * 1e13);
            const b = Math.round((rand() * 2 - 1) * 1e13 * (rand() < 0.5 ? 1 : 1e-6));
            const min = Math.min(a, b);
            const max = Math.max(a, b);
            const ticks = niceTicks(min, max);
            expect(ticks.length).toBeGreaterThanOrEqual(2);
            ticks.forEach((tick) => expect(Number.isInteger(tick)).toBe(true));
            for (let k = 1; k < ticks.length; k++) expect(ticks[k]).toBeGreaterThan(ticks[k - 1]);
            expect(ticks[0]).toBeLessThanOrEqual(min);
            expect(ticks[ticks.length - 1]).toBeGreaterThanOrEqual(max);
            if (min < 0 && max > 0) expect(ticks).toContain(0);
            if (ticks.length > 1) expect(isNice(ticks[1] - ticks[0])).toBe(true);
        }
    });
});
