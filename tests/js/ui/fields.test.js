import { afterEach, describe, expect, it } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import { mountUi } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import UiField from '../../../resources/js/Components/ui/UiField.vue';
import UiInput from '../../../resources/js/Components/ui/UiInput.vue';
import UiTextarea from '../../../resources/js/Components/ui/UiTextarea.vue';
import UiSelect from '../../../resources/js/Components/ui/UiSelect.vue';

let wrapper;
afterEach(() => wrapper?.unmount());

const Demo = defineComponent({
    setup() {
        const email = ref('');
        const notes = ref('');
        const cat = ref('a');
        return () =>
            h('form', [
                h(UiField, { label: 'Email', hint: 'Hint text', error: 'Bad email' }, () =>
                    h(UiInput, { modelValue: email.value, 'onUpdate:modelValue': (v) => (email.value = v) }),
                ),
                h(UiField, { label: 'Notes' }, () => h(UiTextarea, { modelValue: notes.value, 'onUpdate:modelValue': (v) => (notes.value = v) })),
                h(UiField, { label: 'Category' }, () =>
                    h(UiSelect, { modelValue: cat.value, options: [{ value: 'a', label: 'A' }, { value: 'b', label: 'B' }], 'onUpdate:modelValue': (v) => (cat.value = v) }),
                ),
            ]);
    },
});

describe('UiField and text controls', () => {
    it('links label, hint and error to the input', () => {
        wrapper = mountUi(Demo);
        const label = wrapper.find('label');
        const input = wrapper.find('input');
        expect(label.attributes('for')).toBe(input.attributes('id'));
        const described = input.attributes('aria-describedby').split(' ');
        expect(described).toHaveLength(2);
        described.forEach((id) => expect(document.getElementById(id)).not.toBeNull());
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(wrapper.text()).toContain('Bad email');
        expect(wrapper.find('svg').exists()).toBe(true);
    });

    it('sets aria-invalid only with an error', () => {
        wrapper = mountUi(UiField, { props: { label: 'X' }, slots: { default: () => h(UiInput) } });
        expect(wrapper.find('input').attributes('aria-invalid')).toBeUndefined();
        expect(wrapper.find('input').attributes('aria-describedby')).toBeUndefined();
    });

    it('keeps a visually hidden label in the DOM', () => {
        wrapper = mountUi(UiField, { props: { label: 'Quick', labelVisuallyHidden: true }, slots: { default: () => h(UiInput) } });
        expect(wrapper.find('label').classes()).toContain('visually-hidden');
    });

    it('input v-model, type default, readonly and disabled', async () => {
        wrapper = mountUi(UiInput, { props: { readonly: true, disabled: true } });
        const input = wrapper.find('input');
        expect(input.attributes('type')).toBe('text');
        expect(input.attributes('readonly')).toBeDefined();
        expect(input.attributes('disabled')).toBeDefined();
        wrapper.unmount();
        wrapper = mountUi(UiInput, { props: { modelValue: '' } });
        await wrapper.find('input').setValue('hi');
        expect(wrapper.emitted('update:modelValue')[0]).toEqual(['hi']);
    });

    it('textarea and select emit updates', async () => {
        wrapper = mountUi(UiTextarea, { props: { modelValue: '' } });
        await wrapper.find('textarea').setValue('n');
        expect(wrapper.emitted('update:modelValue')[0]).toEqual(['n']);
        wrapper.unmount();
        wrapper = mountUi(UiSelect, { props: { modelValue: 'a', options: [{ value: 'a', label: 'A' }, { value: 'b', label: 'B' }] } });
        expect(wrapper.findAll('option')).toHaveLength(2);
        await wrapper.find('select').setValue('b');
        expect(wrapper.emitted('update:modelValue')[0]).toEqual(['b']);
    });

    it('works outside UiField with a generated id', () => {
        wrapper = mountUi(UiInput, { props: { 'aria-label': 'Solo' } });
        expect(wrapper.find('input').attributes('id')).toBeTruthy();
    });

    it('has no axe violations', async () => {
        wrapper = mountUi(Demo);
        await expectNoAxeViolations(wrapper.element);
    });
});
