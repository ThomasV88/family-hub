import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mountUi } from '../helpers/mount.js';
import UiToastRegion from '../../../resources/js/Components/ui/UiToastRegion.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

let wrapper;
beforeEach(() => { useToast().toasts.splice(0); });
afterEach(() => { wrapper?.unmount(); wrapper = null; });

describe('toast action', () => {
    it('renders a button, calls onSelect once and dismisses', async () => {
        const { show, toasts } = useToast();
        wrapper = mountUi(UiToastRegion);
        const onSelect = vi.fn();
        show('Ingredient removed', { action: { label: 'Undo', onSelect } });
        await wrapper.vm.$nextTick();
        const button = wrapper.findAll('button').find((b) => b.text() === 'Undo');
        expect(button).toBeTruthy();
        await button.trigger('click');
        expect(onSelect).toHaveBeenCalledTimes(1);
        expect(toasts).toHaveLength(0);
        expect(wrapper.findAll('.ui-toast')).toHaveLength(0);
    });

    it('renders toasts without an action as before', async () => {
        const { show } = useToast();
        wrapper = mountUi(UiToastRegion);
        show('Saved');
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Saved');
        expect(wrapper.findAll('button')).toHaveLength(1);
    });

    it('ignores an action without a handler', async () => {
        const { show, toasts } = useToast();
        wrapper = mountUi(UiToastRegion);
        show('Saved', { action: { label: 'Undo' } });
        expect(toasts[0].action).toBeNull();
    });
});
