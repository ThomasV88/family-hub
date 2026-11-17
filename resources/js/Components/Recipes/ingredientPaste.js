import { normalize } from '../../Shopping/catalogSearch.js';
import { localeCode, unitLabel } from '../../Shopping/units.js';
import { parseQuantity, QUANTITY_MAX } from './quantityInput.js';

// Best-effort split of pasted lines ("200 g flour", "2 ks vajcia", "1/2 tsp salt", "salt to taste") into
// { quantity, unitId, name }. Whatever is not understood stays in the name.

export const MAX_LINES = 80;

// Words people write for the built-in units, in the three languages (compared accent- and case-insensitively).
const ALIASES = {
    pcs: ['pc', 'pcs', 'piece', 'pieces', 'ks', 'kus', 'kusy', 'kusov', 'szt', 'sztuka', 'sztuki', 'sztuk'],
    g: ['g', 'gr', 'gram', 'grams', 'gramy', 'gramov', 'gramow', 'gramow'],
    kg: ['kg', 'kilo', 'kilogram', 'kilogramy', 'kilogramov'],
    ml: ['ml', 'mililiter', 'mililitre', 'mililitrov', 'mililitry', 'mililitrow'],
    l: ['l', 'liter', 'litre', 'litres', 'litrov', 'litr', 'litry', 'litrow'],
    pack: ['pack', 'packs', 'package', 'packet', 'balenie', 'balenia', 'baleni', 'opakowanie', 'opakowania', 'opakowan'],
    bunch: ['bunch', 'bunches', 'zvazok', 'zvazky', 'zvazkov', 'peczek', 'peczki', 'peczkow'],
    tbsp: ['tbsp', 'tbs', 'tablespoon', 'tablespoons', 'lyzica', 'lyzice', 'lyzic', 'lyzicu', 'lyzka', 'lyzki', 'lyzek'],
    tsp: ['tsp', 'teaspoon', 'teaspoons', 'lyzicka', 'lyzicky', 'lyzicok', 'lyzeczka', 'lyzeczki', 'lyzeczek'],
};

// Words that stand for a built-in unit times a factor. "cl" is a centilitre (4 cl rum = 40 ml), never a teaspoon. "pl" is not an
// alias of anything: it is the code of the Polish language and no recipe writes it for a spoon.
const SCALED_ALIASES = { cl: { key: 'ml', factor: 10 } };

const VULGAR_CLASS = '½⅓⅔¼¾⅕⅖⅗⅘⅙⅚⅛⅜⅝⅞';
const QUANTITY_AT_START = new RegExp(
    `^(\\d+(?:[.,]\\d+)?\\s?[${VULGAR_CLASS}]|\\d+\\s+\\d+/\\d+|\\d+/\\d+|\\d+(?:[.,]\\d+)?|[${VULGAR_CLASS}])(?![\\d/])\\s*(.*)$`,
    'u',
);

/** Word (normalised, no trailing dot) -> unit id, from the aliases of built-in units and the labels of every unit. */
function unitIndex(units, t, locale) {
    const index = new Map();
    const add = (word, id) => {
        const key = normalize(word).replace(/\.$/, '');
        if (key !== '' && !index.has(key)) index.set(key, id);
    };
    const scaled = new Map();
    for (const unit of units) {
        if (unit.key) {
            add(unit.key, unit.id);
            for (const word of ALIASES[unit.key] ?? []) add(word, unit.id);
            for (const [word, { key, factor }] of Object.entries(SCALED_ALIASES)) if (unit.key === key) scaled.set(word, { id: unit.id, factor });
        }
        // The labels of the current language (every plural form) and of custom units.
        for (const n of [1, 2, 5]) if (t) add(unitLabel(unit, n, t, locale), unit.id);
        for (const name of Object.values(unit.names ?? {})) if (name) add(name, unit.id);
    }

    return { index, scaled };
}

/**
 * @param {string} text pasted text, one ingredient per line
 * @param {{ units?: Array<{id:number,key:string|null,names:object|null}>, t?: Function, locale?: string, nameMax?: number }} options
 * @returns {Array<{ quantity: number|null, unitId: number|null, name: string }>}
 */
export function parseIngredientLines(text, { units = [], t = null, locale = 'en', nameMax = 120 } = {}) {
    const { index, scaled } = unitIndex(units, t, localeCode(locale));
    const lines = String(text ?? '')
        .split(/\r\n|\r|\n/)
        .map((line) => line.replace(/^\s*(?:[-*•·–]|\[\s?\])\s*/, '').trim())
        .filter((line) => line !== '')
        .slice(0, MAX_LINES);

    return lines.map((line) => {
        const whole = { quantity: null, unitId: null, name: line.slice(0, nameMax) };
        const m = line.match(QUANTITY_AT_START);
        if (!m) return whole;
        const parsed = parseQuantity(m[1], locale);
        const { valid } = parsed;
        let { value } = parsed;
        if (!valid || value === null) return whole;

        let rest = m[2];
        let unitId = null;
        const word = rest.match(/^(\S+)(?:\s+(.*))?$/u);
        if (word) {
            const key = normalize(word[1]).replace(/\.$/, '');
            // The raw spelling decides for "cl": plain "cl" is a centilitre, while the Slovak "čl" (teaspoon) loses its accent in
            // `normalize` and would otherwise be taken for it.
            const raw = word[1].toLowerCase().replace(/\.$/, '');
            const id = scaled.has(raw) ? undefined : index.get(key);
            if (id !== undefined) {
                unitId = id;
                rest = word[2] ?? '';
            } else if (scaled.has(raw)) {
                const { id: scaledId, factor } = scaled.get(raw);
                unitId = scaledId;
                value = Math.round((value * factor + Number.EPSILON) * 100) / 100;
                if (value > QUANTITY_MAX) return whole;
                rest = word[2] ?? '';
            }
        }
        const name = rest.trim();

        // "200 g" alone or "2" alone is not an ingredient: keep the line as the name.
        return name === '' ? whole : { quantity: value, unitId, name: name.slice(0, nameMax) };
    });
}
