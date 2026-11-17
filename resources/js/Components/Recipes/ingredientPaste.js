import { normalize } from '../../Shopping/catalogSearch.js';
import { localeCode, unitLabel } from '../../Shopping/units.js';
import { parseQuantity } from './quantityInput.js';

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
    tbsp: ['tbsp', 'tbs', 'tablespoon', 'tablespoons', 'pl', 'lyzica', 'lyzice', 'lyzic', 'lyzicu', 'lyzka', 'lyzki', 'lyzek'],
    tsp: ['tsp', 'teaspoon', 'teaspoons', 'cl', 'lyzicka', 'lyzicky', 'lyzicok', 'lyzeczka', 'lyzeczki', 'lyzeczek'],
};

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
    for (const unit of units) {
        if (unit.key) {
            add(unit.key, unit.id);
            for (const word of ALIASES[unit.key] ?? []) add(word, unit.id);
        }
        // The labels of the current language (every plural form) and of custom units.
        for (const n of [1, 2, 5]) if (t) add(unitLabel(unit, n, t, locale), unit.id);
        for (const name of Object.values(unit.names ?? {})) if (name) add(name, unit.id);
    }

    return index;
}

/**
 * @param {string} text pasted text, one ingredient per line
 * @param {{ units?: Array<{id:number,key:string|null,names:object|null}>, t?: Function, locale?: string, nameMax?: number }} options
 * @returns {Array<{ quantity: number|null, unitId: number|null, name: string }>}
 */
export function parseIngredientLines(text, { units = [], t = null, locale = 'en', nameMax = 120 } = {}) {
    const index = unitIndex(units, t, localeCode(locale));
    const lines = String(text ?? '')
        .split(/\r\n|\r|\n/)
        .map((line) => line.replace(/^\s*(?:[-*•·–]|\[\s?\])\s*/, '').trim())
        .filter((line) => line !== '')
        .slice(0, MAX_LINES);

    return lines.map((line) => {
        const whole = { quantity: null, unitId: null, name: line.slice(0, nameMax) };
        const m = line.match(QUANTITY_AT_START);
        if (!m) return whole;
        const { value, valid } = parseQuantity(m[1]);
        if (!valid || value === null) return whole;

        let rest = m[2];
        let unitId = null;
        const word = rest.match(/^(\S+)(?:\s+(.*))?$/u);
        if (word) {
            const id = index.get(normalize(word[1]).replace(/\.$/, ''));
            if (id !== undefined) {
                unitId = id;
                rest = word[2] ?? '';
            }
        }
        const name = rest.trim();

        // "200 g" alone or "2" alone is not an ingredient: keep the line as the name.
        return name === '' ? whole : { quantity: value, unitId, name: name.slice(0, nameMax) };
    });
}
