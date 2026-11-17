import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';

const form = readFileSync('resources/js/Pages/Recipes/Form.vue', 'utf8');
const field = readFileSync('resources/js/Components/ui/UiField.vue', 'utf8');
const rule = (src, selector) => {
    const m = src.match(new RegExp(`^${selector.replace(/[.]/g, '\\.')}\\s*\\{([^}]*)\\}`, 'm'));
    return m ? m[1] : '';
};

describe('Recipe form layout (CSS source)', () => {
    it('the field label row wraps so a tag drops under the label instead of overlapping it', () => {
        const head = rule(field, '.ui-field__head');
        expect(head).toMatch(/flex-wrap:\s*wrap/);
        expect(head).toMatch(/min-width:\s*0/);
        expect(rule(field, '.ui-field__label')).toMatch(/overflow-wrap:\s*anywhere/);
    });

    it('the three number fields share equal tracks that stack when the container is narrow', () => {
        const basics = rule(form, '.rec-form__basics');
        expect(basics).toMatch(/display:\s*grid/);
        expect(basics).toMatch(/repeat\(auto-fill,\s*minmax\(min\(100%,\s*10rem\),\s*1fr\)\)/);
        expect(form).not.toMatch(/\.rec-form__servings/);
        expect(form).not.toMatch(/\.rec-form__basics\s*\{[^}]*repeat\(3/);
    });

    it('label-after is only used inside UiField, whose head wraps', () => {
        expect(form.match(/#label-after/g).length).toBe(5);
    });
});
