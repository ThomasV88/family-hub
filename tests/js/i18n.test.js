import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { createAppI18n, SUPPORTED_LOCALES, INTL_LOCALES } from '../../resources/js/i18n.js';

const load = (l) => JSON.parse(readFileSync(new URL(`../../lang/${l}.json`, import.meta.url), 'utf8'));
const messages = { sk: load('sk'), pl: load('pl'), en: load('en') };

function make(locale) {
    return createAppI18n(locale, messages[locale]).global;
}
const checks = (locale, n) => make(locale).t('home.checks_count', n, { named: { count: n } });

function flatten(obj, prefix = '') {
    return Object.entries(obj).flatMap(([k, v]) =>
        typeof v === 'object' && v !== null ? flatten(v, `${prefix}${k}.`) : [[`${prefix}${k}`, v]],
    );
}

describe('constants', () => {
    it('exports supported locales and Intl locales', () => {
        expect(SUPPORTED_LOCALES).toEqual(['sk', 'pl', 'en']);
        expect(INTL_LOCALES).toEqual({ sk: 'sk-SK', pl: 'pl-PL', en: 'en-GB' });
    });
});

describe('plurals', () => {
    it.each([
        [0, '0 sprawdzeń'], [1, '1 sprawdzenie'], [2, '2 sprawdzenia'], [4, '4 sprawdzenia'],
        [5, '5 sprawdzeń'], [12, '12 sprawdzeń'], [14, '14 sprawdzeń'], [22, '22 sprawdzenia'], [25, '25 sprawdzeń'],
    ])('pl %i', (n, expected) => expect(checks('pl', n)).toBe(expected));

    it.each([
        [0, '0 kontrol'], [1, '1 kontrola'], [3, '3 kontroly'], [5, '5 kontrol'],
    ])('sk %i', (n, expected) => expect(checks('sk', n)).toBe(expected));

    it.each([[1, '1 check'], [5, '5 checks']])('en %i', (n, expected) => expect(checks('en', n)).toBe(expected));
});

describe('number, percent and date formats', () => {
    it('formats integers per locale', () => {
        expect(make('en').n(1440, 'integer')).toBe('1,440');
        expect(make('sk').n(1440, 'integer')).toBe('1 440');
        expect(make('pl').n(1440, 'integer')).toBe('1440');
        expect(make('pl').n(12345.5, 'decimal')).toBe('12 345,5');
    });

    it('formats percent per locale', () => {
        expect(make('en').n(0.999, 'percent')).toBe('99.9%');
        expect(make('sk').n(0.999, 'percent')).toBe('99,9 %');
        expect(make('pl').n(0.999, 'percent')).toBe('99,9%');
    });

    it('formats dates in Europe/Bratislava', () => {
        const d = new Date('2026-03-05T14:07:00Z');
        expect(make('en').d(d, 'short')).toBe('5 Mar 2026, 15:07');
        expect(make('sk').d(d, 'short')).toBe('5. 3. 2026, 15:07');
        expect(make('pl').d(d, 'short')).toBe('5 mar 2026, 15:07');
    });
});

describe('message files', () => {
    it('have identical keys and no empty values', () => {
        const keys = (l) => flatten(messages[l]).map(([k]) => k).sort();
        expect(keys('sk')).toEqual(keys('en'));
        expect(keys('pl')).toEqual(keys('en'));
        for (const l of ['en', 'sk', 'pl']) {
            for (const [k, v] of flatten(messages[l])) expect(v, `${l}:${k}`).not.toBe('');
        }
    });
});
