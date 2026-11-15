import { describe, it, expect, vi, afterEach } from 'vitest';
import { mountUi } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { Plus } from '@lucide/vue';
import UiButton from '../../../resources/js/Components/ui/UiButton.vue';

let wrapper;
afterEach(() => { wrapper?.unmount(); vi.restoreAllMocks(); });
const m = (props = {}, label = 'Save') => (wrapper = mountUi(UiButton, { props, slots: { default: label } }));

describe('UiButton', () => {
    it('renders a type=button by default', () => {
        m();
        expect(wrapper.element.tagName).toBe('BUTTON');
        expect(wrapper.attributes('type')).toBe('button');
    });

    it.each(['primary', 'secondary', 'ghost', 'danger'])('applies the %s variant class', (v) => {
        m({ variant: v });
        expect(wrapper.classes()).toContain(`ui-button--${v}`);
    });

    it('disables when disabled', () => {
        m({ disabled: true });
        expect(wrapper.attributes('disabled')).toBeDefined();
    });

    it('loading sets aria-busy, disables and keeps the label', () => {
        m({ loading: true });
        expect(wrapper.attributes('aria-busy')).toBe('true');
        expect(wrapper.attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Save');
    });

    it('icon-only uses label as aria-label and warns without it', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        wrapper = mountUi(UiButton, { props: { iconOnly: true, icon: Plus, label: 'Close' } });
        expect(wrapper.attributes('aria-label')).toBe('Close');
        expect(warn).not.toHaveBeenCalled();
        wrapper.unmount();
        wrapper = mountUi(UiButton, { props: { iconOnly: true, icon: Plus } });
        expect(warn).toHaveBeenCalled();
    });

    it('emits click only when enabled', async () => {
        m();
        await wrapper.trigger('click');
        expect(wrapper.emitted('click')).toHaveLength(1);
        wrapper.unmount();
        m({ loading: true });
        await wrapper.trigger('click');
        expect(wrapper.emitted('click')).toBeUndefined();
    });

    it('renders an anchor when href is set', () => {
        m({ href: '/x' });
        expect(wrapper.element.tagName).toBe('A');
    });

    it('has no axe violations', async () => {
        m({ variant: 'primary' });
        await expectNoAxeViolations(wrapper.element);
    });
});
