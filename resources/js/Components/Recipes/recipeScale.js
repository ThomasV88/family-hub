import { INTL_LOCALES } from '../../i18n.js';
import { localeCode, unitLabel } from '../../Shopping/units.js';

// Servings recalculation for the recipe detail. Display only: nothing here is ever sent to the server or stored in the recipe.

/** quantity x target / base. A missing quantity or base servings (nothing to scale by) leaves the quantity unchanged. */
export function scaleQuantity(quantity, base, target) {
    if (quantity === null || quantity === undefined || quantity === '') return null;
    const q = Number(quantity);
    if (!Number.isFinite(q)) return null;
    const b = Number(base);
    const t = Number(target);
    if (base === null || base === undefined || !Number.isFinite(b) || b <= 0 || !Number.isFinite(t)) return q;

    return (q * t) / b;
}

const FRACTIONS = [[0.25, '1/4'], [1 / 3, '1/3'], [0.5, '1/2'], [2 / 3, '2/3'], [0.75, '3/4']];
const FRACTION_UNITS = ['tbsp', 'tsp'];
const TOLERANCE = 0.01;

// Fractions only for spoons and for no unit; pieces, grams and every other unit keep decimals.
const fractionUnit = (unit) => !unit || FRACTION_UNITS.includes(unit.key);

/** "1/2", "1 1/2", or null when the value is not close to a common fraction. */
function asFraction(value) {
    const whole = Math.floor(value);
    const rest = value - whole;
    const hit = FRACTIONS.find(([f]) => Math.abs(rest - f) <= TOLERANCE);
    if (!hit) return null;

    return whole > 0 ? `${whole} ${hit[1]}` : hit[1];
}

/** The number only: friendly fractions where they apply, otherwise up to 2 decimals in the locale (never grouped). */
export function displayNumber(value, unit, locale) {
    const n = Number(value);
    if (!Number.isFinite(n)) return '';
    if (fractionUnit(unit)) {
        const fraction = asFraction(n);
        if (fraction) return fraction;
    }

    return new Intl.NumberFormat(INTL_LOCALES[localeCode(locale)], { maximumFractionDigits: n > 0 && n < 0.01 ? 3 : 2, useGrouping: false }).format(n);
}

/**
 * "1/2 tsp", "1 1/2", "1,5 ks", "1500 g". `t` gives the unit label (see Shopping/units.js); without it the number alone is returned.
 * g and ml are never converted to kg or l here: the view shows what the author wrote.
 */
export function displayQuantity(value, unit, locale, t = null) {
    const number = displayNumber(value, unit, locale);
    if (number === '') return '';
    const label = unit && t ? unitLabel(unit, Number(value), t, locale) : '';

    return label ? `${number} ${label}` : number;
}
