import { describe, it, expect, afterEach, beforeEach } from 'vitest';
import { defineComponent, nextTick } from 'vue';
import { mountUi } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { installHelp } from '../helpers/help.js';
import UiField from '../../../resources/js/Components/ui/UiField.vue';
import UiInput from '../../../resources/js/Components/ui/UiInput.vue';
import ReminderField from '../../../resources/js/Todo/ReminderField.vue';
import VisibilityField from '../../../resources/js/Todo/VisibilityField.vue';
import { resetHelp } from '../../../resources/js/Help/helpStore.js';

let wrapper;
beforeEach(() => installHelp('en'));
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; resetHelp(); delete document.documentElement.dataset.theme; });

describe('tips placed in real components', () => {
    it('UiField puts the green mark after its label, outside the label element', () => {
        const Host = defineComponent({ components: { UiField, UiInput }, template: '<UiField label="Repeat" help="tasks.recurrence"><UiInput /></UiField>' });
        wrapper = mountUi(Host);
        const label = document.body.querySelector('label');
        expect(label.textContent.trim()).toBe('Repeat');
        expect(label.querySelector('.help-tip')).toBeNull();
        expect(document.body.querySelector('.ui-field__head .help-tip__button').getAttribute('aria-label')).toBe('Help: Repeat');
    });

    it('UiField without help is unchanged, and a hidden label shows no mark', () => {
        const Host = defineComponent({ components: { UiField, UiInput }, template: '<div><UiField label="A"><UiInput /></UiField><UiField label="B" label-visually-hidden help="tasks.recurrence"><UiInput /></UiField></div>' });
        wrapper = mountUi(Host);
        expect(document.body.querySelector('.help-tip')).toBeNull();
        expect(document.body.querySelector('.ui-field__head')).toBeNull();
    });

    it.each(['en', 'sk', 'pl'])('reminder and visibility fields have no axe violations with their tips (%s, light and dark)', async (locale) => {
        installHelp(locale);
        const Host = defineComponent({ components: { ReminderField, VisibilityField }, template: '<div><ReminderField :has-date="true" :has-time="true" /><VisibilityField model-value="family" :members="[]" /></div>' });
        for (const theme of ['light', 'dark']) {
            document.documentElement.dataset.theme = theme;
            wrapper = mountUi(Host, { locale });
            expect(document.body.querySelectorAll('.help-tip__button')).toHaveLength(2);
            document.body.querySelector('.help-tip__button').click();
            await nextTick();
            await expectNoAxeViolations(document.body);
            wrapper.unmount();
            wrapper = null;
        }
    });
});
