import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { contrastRatio } from '../../resources/js/design/contrast.js';
import { TOKEN_PAIRS } from '../../resources/js/design/tokenPairs.js';

const css = readFileSync(`${process.cwd()}/resources/css/tokens.css`, 'utf8');

function blockBody(openIndex) {
    let depth = 0;
    for (let i = openIndex; i < css.length; i++) {
        if (css[i] === '{') depth++;
        if (css[i] === '}' && --depth === 0) return css.slice(css.indexOf('{', openIndex) + 1, i);
    }
    throw new Error('Unbalanced braces');
}

function props(body) {
    const out = {};
    for (const m of body.matchAll(/--([\w-]+)\s*:\s*([^;]+);/g)) out[m[1]] = m[2].trim();
    return out;
}

const light = props(blockBody(css.indexOf(':root {')));
const dark = props(blockBody(css.indexOf(':root[data-theme="dark"]')));
const mediaOuter = blockBody(css.indexOf('@media (prefers-color-scheme: dark)'));
const media = props(blockBody(mediaOuter.indexOf(':root:not') >= 0 ? css.indexOf(':root:not([data-theme="light"])') : 0));

describe('contrast util', () => {
    it('matches reference values', () => {
        expect(contrastRatio('#ffffff', '#000000').toFixed(2)).toBe('21.00');
        const r = contrastRatio('#3b6ea8', '#ffffff');
        expect(r).toBeGreaterThan(5.2);
        expect(r).toBeLessThan(5.3);
        expect(contrastRatio('#fff', '#000')).toBeCloseTo(21, 5);
    });
});

describe('token pairs meet WCAG AA', () => {
    const themes = { light, dark: { ...light, ...dark } };
    for (const [name, tokens] of Object.entries(themes)) {
        for (const p of TOKEN_PAIRS) {
            it(`${name}: ${p.fg} on ${p.bg} (${p.usage}) >= ${p.min}`, () => {
                expect(tokens[p.fg], p.fg).toBeDefined();
                expect(tokens[p.bg], p.bg).toBeDefined();
                expect(contrastRatio(tokens[p.fg], tokens[p.bg])).toBeGreaterThanOrEqual(p.min);
            });
        }
    }
});

describe('dark blocks', () => {
    it('media block equals the explicit dark block', () => {
        expect(Object.keys(dark).length).toBeGreaterThan(15);
        expect(media).toEqual(dark);
    });
});
