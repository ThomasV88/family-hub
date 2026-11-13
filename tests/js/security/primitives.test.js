import { describe, it, expect, afterEach } from 'vitest';
import OtpInput from '../../../resources/js/Components/Security/OtpInput.vue';
import RecoveryCodeInput from '../../../resources/js/Components/Security/RecoveryCodeInput.vue';
import PasskeyButton from '../../../resources/js/Components/Security/PasskeyButton.vue';
import { mountUi } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';

let wrapper;
afterEach(() => {
    wrapper?.unmount();
    delete window.PublicKeyCredential;
    delete navigator.credentials;
});
const supportPasskeys = () => {
    window.PublicKeyCredential = function () {};
    Object.defineProperty(navigator, 'credentials', { value: {}, configurable: true });
};

describe('OtpInput', () => {
    it('renders one native one-time-code input with a label and hint', () => {
        wrapper = mountUi(OtpInput, { props: { hint: 'Enter the code' } });
        const inputs = wrapper.findAll('input');
        expect(inputs).toHaveLength(1);
        const el = inputs[0].element;
        expect(el.getAttribute('type')).toBe('text');
        expect(el.getAttribute('inputmode')).toBe('numeric');
        expect(el.getAttribute('autocomplete')).toBe('one-time-code');
        expect(el.getAttribute('pattern')).toBe('[0-9]*');
        expect(el.getAttribute('maxlength')).toBe('6');
        expect(el.getAttribute('autocapitalize')).toBe('none');
        expect(el.getAttribute('spellcheck')).toBe('false');
        expect(wrapper.find('label').text()).toBe('6-digit code');
        expect(wrapper.text()).toContain('Enter the code');
    });

    it('supports v-model, strips spaces, never auto-submits and exposes focus and clear', async () => {
        wrapper = mountUi(OtpInput, { props: { modelValue: '' } });
        await wrapper.find('input').setValue('123 456');
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['123456']);
        expect(Object.keys(wrapper.emitted())).not.toContain('submit');
        expect(typeof wrapper.vm.focus).toBe('function');
        wrapper.vm.clear();
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['']);
    });

    it('announces the error with role=alert', async () => {
        wrapper = mountUi(OtpInput, { props: { error: 'That code is not correct.' } });
        const alert = wrapper.find('[role="alert"]');
        expect(alert.text()).toBe('That code is not correct.');
        expect(wrapper.find('input').attributes('aria-invalid')).toBe('true');
        expect(wrapper.find('input').attributes('aria-describedby')).toBeTruthy();
    });

    it('has no axe violations in en', async () => {
        wrapper = mountUi(OtpInput, { props: { hint: 'Enter the code', error: 'Wrong' } });
        await expectNoAxeViolations(wrapper.element);
    });
});

describe('RecoveryCodeInput', () => {
    it('is autocomplete off, maxlength 32 and trims spaces', async () => {
        wrapper = mountUi(RecoveryCodeInput, { props: { modelValue: '' } });
        const input = wrapper.find('input');
        expect(input.attributes('autocomplete')).toBe('off');
        expect(input.attributes('maxlength')).toBe('32');
        expect(wrapper.find('label').text()).toBe('Recovery code');
        await input.setValue(' abcd efgh ');
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['abcdefgh']);
    });
});

describe('PasskeyButton', () => {
    it('shows only the unsupported line without PublicKeyCredential', () => {
        wrapper = mountUi(PasskeyButton);
        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.text()).toBe('This browser or device does not support passkeys.');
    });

    it('renders a native button that emits start', async () => {
        supportPasskeys();
        wrapper = mountUi(PasskeyButton);
        const button = wrapper.find('button');
        expect(button.element.tagName).toBe('BUTTON');
        expect(button.text()).toBe('Use a passkey');
        await button.trigger('click');
        expect(wrapper.emitted('start')).toHaveLength(1);
    });

    it('keeps its label and sets aria-busy while busy, ignoring clicks', async () => {
        supportPasskeys();
        wrapper = mountUi(PasskeyButton, { props: { busy: true, label: 'Add passkey' } });
        const button = wrapper.find('button');
        expect(button.attributes('aria-busy')).toBe('true');
        expect(button.text()).toBe('Add passkey');
        await button.trigger('click');
        expect(wrapper.emitted('start')).toBeUndefined();
    });

    it('has no axe violations in en', async () => {
        supportPasskeys();
        wrapper = mountUi(PasskeyButton);
        await expectNoAxeViolations(wrapper.element);
    });
});
