import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { createAppI18n } from '../../resources/js/i18n.js';
import { expectNoAxeViolations } from './helpers/axe.js';

const setThemePreference = vi.fn();
vi.mock('../../resources/js/theme.js', () => ({
    THEME_OPTIONS: ['system', 'light', 'dark'],
    getThemePreference: () => 'system',
    setThemePreference: (...a) => setThemePreference(...a),
}));

import ThemeSwitch from '../../resources/js/Components/ThemeSwitch.vue';

const en = JSON.parse(readFileSync(`${process.cwd()}/lang/en.json`, 'utf8'));
const mountIt = () => mount(ThemeSwitch, { attachTo: document.body, global: { plugins: [createAppI18n('en', en)] } });

beforeEach(() => {
    setThemePreference.mockClear();
    document.body.innerHTML = '';
});

describe('ThemeSwitch', () => {
    it('renders a fieldset with three radios and the current preference checked', () => {
        const w = mountIt();
        expect(w.find('legend').text()).toBe('Theme');
        const labels = w.findAll('label').map((l) => l.text());
        expect(labels).toEqual(['System', 'Light', 'Dark']);
        const radios = w.findAll('input[type="radio"]');
        expect(radios).toHaveLength(3);
        expect(radios[0].element.checked).toBe(true);
    });

    it('calls setThemePreference on change', async () => {
        const w = mountIt();
        await w.findAll('input[type="radio"]')[1].setValue();
        expect(setThemePreference).toHaveBeenCalledWith('light');
    });

    it('has no axe violations', async () => {
        const w = mountIt();
        await expectNoAxeViolations(w.element);
    });

    it('gives two instances in one app distinct radio groups', async () => {
        // useId() ids are unique per app, so both switches live in one app like on a real page.
        const Two = { components: { ThemeSwitch }, template: '<div><ThemeSwitch /><ThemeSwitch /></div>' };
        const w = mount(Two, { attachTo: document.body, global: { plugins: [createAppI18n('en', en)] } });
        const groups = w.findAllComponents(ThemeSwitch);
        const nameA = groups[0].find('input').attributes('name');
        const nameB = groups[1].find('input').attributes('name');
        expect(nameA).not.toBe(nameB);
        await groups[0].findAll('input')[2].setValue();
        // choosing in A must not uncheck B's selection
        expect(groups[1].findAll('input')[0].element.checked).toBe(true);
        await expectNoAxeViolations(document.body);
    });
});
