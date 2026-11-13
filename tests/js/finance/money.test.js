import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { toDecimalString, formatMinor, parseMoney, toInputString, CURRENCIES, exponentOf } from '../../../resources/js/Finance/money.js';

const ws = (s) => s.replace(/[\s  ]/g, ' ');
const ref = (loc, v) => new Intl.NumberFormat(loc, { style: 'currency', currency: 'EUR', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v);

describe('toDecimalString', () => {
    it.each([['123450', 2, '1234.50'], ['-5', 2, '-0.05'], ['0', 2, '0.00'], ['7', 0, '7'], ['90071992547409930', 2, '900719925474099.30']])(
        '%s/%s', (m, e, out) => expect(toDecimalString(m, e)).toBe(out),
    );
});

describe('formatMinor', () => {
    it.each([['en', 'en-GB'], ['sk', 'sk-SK'], ['pl', 'pl-PL']])('matches Intl for %s', (l, intl) => {
        expect(ws(formatMinor('123450', 'EUR', l))).toBe(ws(ref(intl, '1234.50')));
    });
    it('uses U+2212 for negatives', () => {
        const out = formatMinor('-123450', 'EUR', 'en');
        expect(out).toContain('−');
        expect(out).not.toContain('-');
    });
    it('prefixes + when signed', () => {
        expect(formatMinor('500', 'EUR', 'en', { signed: true }).startsWith('+')).toBe(true);
    });
    it('keeps two decimals and exactness above 2^53', () => {
        expect(ws(formatMinor('90071992547409930', 'EUR', 'en'))).toContain('.30');
    });
});

describe('parseMoney', () => {
    it.each([['1 234,50', '123450'], ['1234.5', '123450'], ['0,005', null], ['-3', null], ['abc', null], ['0', null], ['', null]])(
        '%s', (i, out) => expect(parseMoney(i, 2)).toBe(out),
    );
    it('allows negatives on request', () => expect(parseMoney('-3', 2, { allowNegative: true })).toBe('-300'));
    it('rejects over the bound', () => {
        expect(parseMoney('100000000000', 2)).toBe('10000000000000');
        expect(parseMoney('100000000000,01', 2)).toBeNull();
    });
});

describe('toInputString', () => {
    it('uses the locale separator', () => {
        expect(toInputString('123450', 2, 'sk')).toBe('1234,50');
        expect(toInputString('123450', 2, 'en')).toBe('1234.50');
    });
});

it('exposes the exponent table', () => {
    expect(CURRENCIES).toEqual({ EUR: 2, CZK: 2, PLN: 2, USD: 2, GBP: 2 });
    expect(exponentOf('EUR')).toBe(2);
});

it('contains no float conversion', () => {
    const src = readFileSync('resources/js/Finance/money.js', 'utf8');
    for (const t of ['parseFloat', 'Number(', 'toFixed', 'Math.round']) expect(src).not.toContain(t);
});
