import { INTL_LOCALES } from '../../i18n.js';
import { localeCode, unitLabel } from '../../Shopping/units.js';

// Servings recalculation for the recipe detail. Display only: nothing here is ever sent to the server or stored in the recipe.

// Friendly fractions: quarters, thirds, sixths and eighths (the stored quantity is rounded to 2 decimals, so 1/3 is 0.33).
const FRACTIONS = [
    [1 / 8, '1/8'], [1 / 6, '1/6'], [1 / 4, '1/4'], [1 / 3, '1/3'], [3 / 8, '3/8'], [1 / 2, '1/2'],
    [5 / 8, '5/8'], [2 / 3, '2/3'], [3 / 4, '3/4'], [5 / 6, '5/6'], [7 / 8, '7/8'],
];
const FRACTION_UNITS = ['tbsp', 'tsp'];
// Display: a scaled value is within rounding noise of a fraction. Stored 2-decimal values are first made exact (STORED_TOLERANCE),
// so the scaled value is exact and this can stay tight; the older quarter/third/half keep their wider 0.01.
const TOLERANCE = 0.01;
const NEW_FRACTION_TOLERANCE = 0.004;
const STORED_TOLERANCE = 0.0051;
const WIDE = new Set([0.25, 1 / 3, 0.5, 2 / 3, 0.75]);

/** The closest friendly fraction of the fractional part (0 and 1 count), or null. `limit(f)` is the tolerance for fraction f. */
function nearestFraction(rest, limit) {
    let best = null;
    for (const [f, label] of [[0, ''], ...FRACTIONS, [1, '']]) {
        const diff = Math.abs(rest - f);
        if (diff <= limit(f) && (best === null || diff < best.diff)) best = { f, label, diff };
    }

    return best;
}

/** A stored 2-decimal quantity made exact when it is a friendly fraction (0.33 -> 1/3, 0.38 -> 3/8), so scaling carries no drift. */
function exactStored(q) {
    const whole = Math.floor(q);
    const hit = nearestFraction(q - whole, () => STORED_TOLERANCE);

    return hit ? whole + hit.f : q;
}

/** quantity x target / base. A missing quantity or base servings (nothing to scale by) leaves the quantity unchanged. */
export function scaleQuantity(quantity, base, target) {
    if (quantity === null || quantity === undefined || quantity === '') return null;
    const q = Number(quantity);
    if (!Number.isFinite(q)) return null;
    const b = Number(base);
    const t = Number(target);
    if (base === null || base === undefined || !Number.isFinite(b) || b <= 0 || !Number.isFinite(t)) return q;
    // The recipe's own servings show the author's number untouched.
    if (t === b) return q;

    return (exactStored(q) * t) / b;
}

// Fractions only for spoons and for no unit; pieces, grams and every other unit keep decimals.
const fractionUnit = (unit) => !unit || FRACTION_UNITS.includes(unit.key);

/** "1/2", "1 1/2", a snapped whole number ("2" for 1.99), or null when the value is not close to a friendly fraction. */
function asFraction(value) {
    const whole = Math.floor(value);
    const hit = nearestFraction(value - whole, (f) => (WIDE.has(f) || f === 0 || f === 1 ? TOLERANCE : NEW_FRACTION_TOLERANCE));
    if (!hit) return null;
    if (hit.label === '') {
        const nearest = whole + hit.f;

        return nearest > 0 ? String(nearest) : null;
    }

    return whole > 0 ? `${whole} ${hit.label}` : hit.label;
}

/** The number only: friendly fractions where they apply, otherwise up to 2 decimals in the locale (never grouped). */
export function displayNumber(value, unit, locale) {
    const n = Number(value);
    if (!Number.isFinite(n)) return '';
    if (fractionUnit(unit)) {
        const fraction = asFraction(n);
        if (fraction) return fraction;
    }
    // Any unit: 0.99 or 2.01 (a third scaled by three) is shown as the whole number it stands for.
    const whole = Math.round(n);
    if (whole > 0 && n !== whole && Math.abs(n - whole) <= TOLERANCE) {
        return new Intl.NumberFormat(INTL_LOCALES[localeCode(locale)], { maximumFractionDigits: 0, useGrouping: false }).format(whole);
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
