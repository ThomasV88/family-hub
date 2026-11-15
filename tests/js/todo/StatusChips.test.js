import { describe, it, expect, afterEach } from 'vitest';
import { expectNoAxeViolations } from '../helpers/axe.js';
import StatusChips from '../../../resources/js/Todo/StatusChips.vue';
import { mountUi } from '../helpers/mount.js';

let wrapper;
afterEach(() => { wrapper?.unmount(); document.body.innerHTML = ''; });

const mountChips = (props = {}, locale = 'en') => {
    wrapper = mountUi(StatusChips, { props: { modelValue: 'todo', ...props }, locale });
    return wrapper;
};
const chips = () => wrapper.findAll('button');

describe('StatusChips', () => {
    it('is a labelled radiogroup with one pressed chip and a roving tab stop', () => {
        mountChips({ modelValue: 'in_progress' });
        const group = wrapper.get('[role="radiogroup"]');
        expect(group.attributes('aria-label')).toBe('Status');
        expect(chips().map((c) => c.text())).toEqual(['To do', 'In progress', 'Done']);
        expect(chips().map((c) => c.attributes('aria-pressed'))).toEqual(['false', 'true', 'false']);
        expect(chips().map((c) => c.attributes('tabindex'))).toEqual(['-1', '0', '-1']);
    });

    it('selects on click, and a second click on the selected chip keeps it selected', async () => {
        mountChips({ modelValue: 'todo' });
        await chips()[2].trigger('click');
        await chips()[0].trigger('click');
        expect(wrapper.emitted('update:modelValue')).toEqual([['done'], ['todo']]);
    });

    it('moves and selects with Left, Right, Home and End', async () => {
        mountChips({ modelValue: 'in_progress' });
        await chips()[1].trigger('keydown', { key: 'ArrowRight' });
        await chips()[1].trigger('keydown', { key: 'ArrowLeft' });
        await chips()[1].trigger('keydown', { key: 'Home' });
        await chips()[1].trigger('keydown', { key: 'End' });
        expect(wrapper.emitted('update:modelValue')).toEqual([['done'], ['todo'], ['todo'], ['done']]);
    });

    it('does not change when disabled', async () => {
        mountChips({ disabled: true });
        await chips()[0].trigger('keydown', { key: 'ArrowRight' });
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
        expect(chips().every((c) => c.attributes('disabled') !== undefined)).toBe(true);
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations (%s)', async (locale) => {
        mountChips({}, locale);
        await expectNoAxeViolations(wrapper.element);
    });
});
