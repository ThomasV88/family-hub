import { formatQuantityInput } from './quantityInput.js';
import { scaleQuantity } from './recipeScale.js';

// Recipe quantity -> Shopping quantity. Pure helpers for the "Add to shopping list" dialog. Shopping accepts 0.01 to 999.99 with
// two decimals, so a scaled quantity is converted to kg or l when it reaches 1000 g or ml, rounded half-up and checked.

/** The largest quantity Shopping accepts. */
export const SHOPPING_MAX = 999.99;
const FLOOR = 0.01;
// Only these built-in units convert (g <-> kg, ml <-> l). Everything else (spoons, packs, custom units) stays as it is.
const FACTORS = { 'g>kg': 0.001, 'kg>g': 1000, 'ml>l': 0.001, 'l>ml': 1000 };
const UP = { g: 'kg', ml: 'l' };

/** Half-up to 2 decimals, never below 0.01 (a positive amount must not vanish). */
const round = (n) => Math.max(FLOOR, Math.round((n + Number.EPSILON) * 100) / 100);

/**
 * Scales `quantity` from `base` to `target` servings and returns { quantity, unitKey, valid }. No quantity: 1 pcs. g and ml of
 * 1000 or more become kg and l. `valid` is false when the result is above 999.99 (the row cannot be added as it is).
 */
export function toShopping(quantity, unitKey, base, target) {
    const scaled = scaleQuantity(quantity, base, target);
    if (scaled === null) return { quantity: 1, unitKey: 'pcs', valid: true };
    let value = scaled;
    let key = unitKey ?? null;
    if (key in UP && value >= 1000) {
        value *= FACTORS[`${key}>${UP[key]}`];
        key = UP[key];
    }
    const result = round(value);

    return { quantity: result, unitKey: key, valid: result <= SHOPPING_MAX };
}

/** g <-> kg and ml <-> l only; the same unit returns the quantity. Anything else is null (not converted). */
export function convertTo(quantity, fromKey, toKey) {
    const n = Number(quantity);
    if (!Number.isFinite(n)) return null;
    if (fromKey === toKey) return n;
    const factor = FACTORS[`${fromKey}>${toKey}`];

    return factor === undefined ? null : round(n * factor);
}

/** 1.5 -> "1,5" in Slovak and Polish, "1.5" in English. */
export const formatForInput = (n, locale) => formatQuantityInput(n, locale);
