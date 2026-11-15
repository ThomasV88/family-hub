import { afterEach, describe, expect, it } from 'vitest';
import { mountUi } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import UiCheckbox from '../../../resources/js/Components/ui/UiCheckbox.vue';
import UiRadio from '../../../resources/js/Components/ui/UiRadio.vue';
import UiSwitch from '../../../resources/js/Components/ui/UiSwitch.vue';
import Forms from '../../../resources/js/StyleGuide/Sections/30-Forms.vue';

let wrapper;
afterEach(() => wrapper?.unmount());

describe('choice controls', () => {
    it('checkbox toggles through its label and emits boolean', async () => {
        wrapper = mountUi(UiCheckbox, { props: { modelValue: false }, slots: { default: 'Remind me' } });
        expect(wrapper.find('label').element.contains(wrapper.find('input').element)).toBe(true);
        await wrapper.find('input').setValue(true);
        expect(wrapper.emitted('update:modelValue')[0]).toEqual([true]);
    });

    it('checkbox indeterminate sets the DOM property', async () => {
        wrapper = mountUi(UiCheckbox, { props: { modelValue: false, indeterminate: true }, slots: { default: 'Some' } });
        expect(wrapper.find('input').element.indeterminate).toBe(true);
        await wrapper.setProps({ indeterminate: false });
        expect(wrapper.find('input').element.indeterminate).toBe(false);
    });

    it('radio emits its value and keeps native grouping', async () => {
        wrapper = mountUi(UiRadio, { props: { modelValue: 'a', value: 'b', name: 'g' }, slots: { default: 'B' } });
        expect(wrapper.find('input').attributes('type')).toBe('radio');
        await wrapper.find('input').setValue(true);
        expect(wrapper.emitted('update:modelValue')[0]).toEqual(['b']);
    });

    it('switch has role switch and reflects checked', () => {
        wrapper = mountUi(UiSwitch, { props: { modelValue: true }, slots: { default: 'Notifications' } });
        const input = wrapper.find('input');
        expect(input.attributes('role')).toBe('switch');
        expect(input.element.checked).toBe(true);
    });

    it('disabled controls are not interactive', () => {
        wrapper = mountUi(UiCheckbox, { props: { modelValue: false, disabled: true }, slots: { default: 'X' } });
        expect(wrapper.find('input').attributes('disabled')).toBeDefined();
    });

    it('Forms section renders, shows the email error and passes axe', async () => {
        wrapper = mountUi(Forms);
        expect(wrapper.find('#forms').exists()).toBe(true);
        expect(wrapper.text()).toContain('Enter a valid email address');
        await expectNoAxeViolations(wrapper.element);
    });
});
