import { describe, it, expect } from 'vitest';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { formatQuantity, localeCode, unitLabel } from '../../../resources/js/Shopping/units.js';

const T = (locale) => createAppI18n(locale, loadMessages(locale)).global.t;
const builtIn = (key) => ({ key, names: null });
const custom = { key: null, names: { en: 'tin', sk: 'plech', pl: 'puszka' } };

describe('unit labels and quantity formatting', () => {
    it('omits a quantity of one for pcs and for no unit ("Milk", not "1 pcs Milk")', () => {
        expect(formatQuantity('1.00', builtIn('pcs'), T('en'), 'en')).toBe('');
        expect(formatQuantity('1', null, T('en'), 'en')).toBe('');
    });

    it('formats whole and fractional quantities per locale', () => {
        expect(formatQuantity('3.00', builtIn('pcs'), T('en'), 'en')).toBe('3 pcs');
        expect(formatQuantity('1.50', builtIn('l'), T('sk'), 'sk')).toBe('1,5 l');
        expect(formatQuantity('1.50', builtIn('l'), T('en'), 'en')).toBe('1.5 l');
        expect(formatQuantity('0.25', builtIn('kg'), T('pl'), 'pl')).toBe('0,25 kg');
        expect(formatQuantity('2.00', null, T('en'), 'en')).toBe('2');
    });

    it('keeps one of another unit', () => {
        expect(formatQuantity('1.00', builtIn('kg'), T('en'), 'en')).toBe('1 kg');
    });

    it('follows the Slovak and Polish plural forms', () => {
        expect(formatQuantity('1.00', builtIn('pack'), T('sk'), 'sk')).toBe('1 balenie');
        expect(formatQuantity('2.00', builtIn('pack'), T('sk'), 'sk')).toBe('2 balenia');
        expect(formatQuantity('5.00', builtIn('pack'), T('sk'), 'sk')).toBe('5 balení');
        expect(formatQuantity('1.50', builtIn('pack'), T('sk'), 'sk')).toBe('1,5 balení');
        expect(formatQuantity('2.00', builtIn('pack'), T('pl'), 'pl')).toBe('2 opakowania');
        expect(formatQuantity('5.00', builtIn('pack'), T('pl'), 'pl')).toBe('5 opakowań');
        expect(formatQuantity('2.00', builtIn('pack'), T('en'), 'en')).toBe('2 packs');
        expect(formatQuantity('3.00', builtIn('pcs'), T('pl'), 'pl')).toBe('3 szt.');
        expect(formatQuantity('3.00', builtIn('pcs'), T('sk'), 'sk')).toBe('3 ks');
    });

    it('shows a custom unit in the current locale', () => {
        expect(formatQuantity('2.00', custom, T('sk'), 'sk')).toBe('2 plech');
        expect(formatQuantity('2.00', custom, T('pl'), 'pl')).toBe('2 puszka');
        expect(unitLabel(custom, 1, T('en'), 'en')).toBe('tin');
        // The vue-i18n locale key works as well as the app locale.
        expect(unitLabel(custom, 1, T('sk'), 'sk-SK')).toBe('plech');
    });

    it('returns an empty label without a unit and ignores invalid quantities', () => {
        expect(unitLabel(null, 1, T('en'), 'en')).toBe('');
        expect(formatQuantity('0', builtIn('kg'), T('en'), 'en')).toBe('');
        expect(formatQuantity('x', builtIn('kg'), T('en'), 'en')).toBe('');
        expect(localeCode('de')).toBe('en');
        expect(localeCode('pl-PL')).toBe('pl');
    });
});
